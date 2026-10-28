package zw.co.blissfulevents.gigs.data

import kotlinx.serialization.SerializationException
import retrofit2.HttpException
import zw.co.blissfulevents.gigs.data.api.ApiJson
import zw.co.blissfulevents.gigs.data.api.ErrorBody
import java.io.IOException

sealed interface ApiResult<out T> {
    data class Ok<T>(val value: T) : ApiResult<T>
    data class Err(val failure: Failure) : ApiResult<Nothing>
}

/**
 * What went wrong, in user-facing terms. `offline` means the request may not have reached the server;
 * writes carry Idempotency-Keys, so retrying them is safe.
 */
data class Failure(
    val message: String,
    val code: String = "error",
    val httpStatus: Int? = null,
    val fields: Map<String, String> = emptyMap(),
    val offline: Boolean = false,
)

suspend fun <T> apiCall(block: suspend () -> T): ApiResult<T> = try {
    ApiResult.Ok(block())
} catch (e: HttpException) {
    ApiResult.Err(parseHttpError(e.code(), e.response()?.errorBody()?.string()))
} catch (e: IOException) {
    ApiResult.Err(Failure("Can't reach Blissful Gigs. Check your internet connection and try again.", "offline", offline = true))
} catch (e: SerializationException) {
    ApiResult.Err(Failure("The app received an unexpected response. Please update the app or try again later.", "bad_response"))
}

fun parseHttpError(status: Int, raw: String?): Failure {
    val parsed = raw?.let { runCatching { ApiJson.decodeFromString(ErrorBody.serializer(), it) }.getOrNull() }
    val fallback = when (status) {
        401 -> "Please sign in again."
        403 -> "You don't have permission to do that."
        404 -> "This item is no longer available."
        409 -> "This was changed by someone else. Refresh and try again."
        429 -> "Too many attempts. Please wait a moment."
        in 500..599 -> "Our server had a problem. Nothing was changed — please try again."
        else -> "Something went wrong (HTTP $status)."
    }
    return Failure(
        message = parsed?.error?.message ?: fallback,
        code = parsed?.error?.code ?: "http_$status",
        httpStatus = status,
        fields = parsed?.error?.fields.orEmpty(),
    )
}

inline fun <T> ApiResult<T>.onOk(f: (T) -> Unit): ApiResult<T> { if (this is ApiResult.Ok) f(value); return this }
inline fun <T> ApiResult<T>.onErr(f: (Failure) -> Unit): ApiResult<T> { if (this is ApiResult.Err) f(failure); return this }
