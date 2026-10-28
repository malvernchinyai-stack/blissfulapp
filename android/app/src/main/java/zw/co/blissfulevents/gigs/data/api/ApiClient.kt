package zw.co.blissfulevents.gigs.data.api

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNamingStrategy
import okhttp3.Authenticator
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.Route
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import zw.co.blissfulevents.gigs.data.auth.TokenStore
import java.util.UUID
import java.util.concurrent.TimeUnit

@OptIn(ExperimentalSerializationApi::class)
val ApiJson: Json = Json {
    namingStrategy = JsonNamingStrategy.SnakeCase
    ignoreUnknownKeys = true
    explicitNulls = false
    encodeDefaults = true
    coerceInputValues = true
}

/**
 * Builds the HTTP stack:
 *  - attaches the bearer token,
 *  - transparently refreshes an expired access token once (single-flight) and retries,
 *  - adds an Idempotency-Key to writes that don't carry one, so OkHttp's automatic retries are safe,
 *  - never logs request/response bodies.
 */
class ApiClient(
    baseUrl: String,
    private val tokens: TokenStore,
    userAgent: String,
    timeoutSeconds: Long = 30,
) {
    private val _sessionExpired = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    /** Emits when refresh fails; the UI returns to sign-in. */
    val sessionExpired: SharedFlow<Unit> = _sessionExpired

    private val refreshLock = Any()
    private val jsonType = "application/json".toMediaType()
    private val refreshUrl = baseUrl.trimEnd('/') + "/auth/refresh"

    private val authInterceptor = Interceptor { chain ->
        val original = chain.request()
        val b = original.newBuilder().header("User-Agent", userAgent).header("Accept", "application/json")
        if (original.header("Authorization") == null && !original.url.encodedPath.contains("/auth/")) {
            tokens.accessToken?.let { b.header("Authorization", "Bearer $it") }
        }
        if (original.method in WRITE_METHODS && original.header("Idempotency-Key") == null) {
            b.header("Idempotency-Key", UUID.randomUUID().toString())
        }
        chain.proceed(b.build())
    }

    private val refreshingAuthenticator = Authenticator { _: Route?, response: Response ->
        val req = response.request
        if (req.url.encodedPath.contains("/auth/") || responseCount(response) >= 2) return@Authenticator null
        val failedToken = req.header("Authorization")?.removePrefix("Bearer ")
        synchronized(refreshLock) {
            val current = tokens.accessToken
            // Another request already refreshed while we waited.
            if (current != null && current != failedToken) {
                return@Authenticator req.newBuilder().header("Authorization", "Bearer $current").build()
            }
            val refresh = tokens.refreshToken ?: run { expire(); return@Authenticator null }
            val newAccess = refreshNow(refresh) ?: run { expire(); return@Authenticator null }
            req.newBuilder().header("Authorization", "Bearer $newAccess").build()
        }
    }

    private val bareClient: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(timeoutSeconds, TimeUnit.SECONDS)
        .readTimeout(timeoutSeconds, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .build()

    val http: OkHttpClient = bareClient.newBuilder()
        .addInterceptor(authInterceptor)
        .authenticator(refreshingAuthenticator)
        .build()

    val api: ApiService = Retrofit.Builder()
        .baseUrl(baseUrl)
        .client(http)
        .addConverterFactory(ApiJson.asConverterFactory(jsonType))
        .build()
        .create(ApiService::class.java)

    /**
     * Returns the new access token, or null if the server rejected the refresh token.
     * Network failures throw IOException (tokens are kept so the user can retry once back online).
     */
    private fun refreshNow(refresh: String): String? {
        val body = ApiJson.encodeToString(RefreshBody.serializer(), RefreshBody(refresh)).toRequestBody(jsonType)
        val request = Request.Builder().url(refreshUrl).post(body).header("Accept", "application/json").build()
        bareClient.newCall(request).execute().use { resp ->
            if (resp.code == 401 || resp.code == 403) return null
            if (!resp.isSuccessful) throw java.io.IOException("Refresh failed with HTTP ${resp.code}")
            val parsed = try {
                ApiJson.decodeFromString(TokenResponse.serializer(), resp.body?.string().orEmpty())
            } catch (e: Exception) {
                throw java.io.IOException("Unreadable refresh response", e)
            }
            tokens.save(parsed.accessToken, parsed.refreshToken)
            return parsed.accessToken
        }
    }

    private fun expire() {
        tokens.clear()
        _sessionExpired.tryEmit(Unit)
    }

    private fun responseCount(response: Response): Int {
        var r: Response? = response
        var n = 1
        while (r?.priorResponse != null) { n++; r = r.priorResponse }
        return n
    }

    companion object {
        private val WRITE_METHODS = setOf("POST", "PUT", "PATCH", "DELETE")
    }
}
