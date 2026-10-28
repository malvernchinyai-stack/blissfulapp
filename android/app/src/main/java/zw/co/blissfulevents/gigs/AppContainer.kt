package zw.co.blissfulevents.gigs

import android.content.Context
import android.os.Build
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.data.ApiResult
import zw.co.blissfulevents.gigs.data.apiCall
import zw.co.blissfulevents.gigs.data.api.ApiClient
import zw.co.blissfulevents.gigs.data.api.ApiService
import zw.co.blissfulevents.gigs.data.api.Catalog
import zw.co.blissfulevents.gigs.data.api.DeviceBody
import zw.co.blissfulevents.gigs.data.api.Me
import zw.co.blissfulevents.gigs.data.api.RoleBody
import zw.co.blissfulevents.gigs.data.api.TokenResponse
import zw.co.blissfulevents.gigs.data.auth.KeystoreTokenStore
import zw.co.blissfulevents.gigs.data.auth.TokenStore
import zw.co.blissfulevents.gigs.push.Push

/** Manual dependency container (small app; avoids a DI framework). */
class AppContainer(private val context: Context) {
    val tokens: TokenStore = KeystoreTokenStore(context)
    val client = ApiClient(
        baseUrl = BuildConfig.API_BASE_URL,
        tokens = tokens,
        userAgent = "BlissfulGigs-Android/${BuildConfig.VERSION_NAME} (${BuildConfig.ENVIRONMENT}; Android ${Build.VERSION.RELEASE})",
    )
    val api: ApiService = client.api
    val scope = CoroutineScope(SupervisorJob())
    val session = Session(api, tokens, scope)

    init {
        scope.launch { client.sessionExpired.collect { session.onExpired() } }
    }
}

sealed interface AuthState {
    data object Unknown : AuthState
    data object SignedOut : AuthState
    data object Unreachable : AuthState
    data class SignedIn(val me: Me) : AuthState
}

class Session(private val api: ApiService, private val tokens: TokenStore, private val scope: CoroutineScope) {
    private val _state = MutableStateFlow<AuthState>(if (tokens.refreshToken == null) AuthState.SignedOut else AuthState.Unknown)
    val state: StateFlow<AuthState> = _state.asStateFlow()

    private val _catalog = MutableStateFlow<Catalog?>(null)
    val catalog: StateFlow<Catalog?> = _catalog.asStateFlow()

    /** Message shown once on the sign-in screen after an involuntary sign-out. */
    var expiredNotice: Boolean = false
        private set

    val me: Me? get() = (state.value as? AuthState.SignedIn)?.me

    fun restore() {
        if (tokens.refreshToken == null) { _state.value = AuthState.SignedOut; return }
        _state.value = AuthState.Unknown
        scope.launch {
            when (val r = apiCall { api.me() }) {
                is ApiResult.Ok -> { _state.value = AuthState.SignedIn(r.value); Push.register(api) }
                // Offline at launch: keep the tokens and show a retry screen rather than guessing the user's role.
                is ApiResult.Err -> _state.value = if (r.failure.offline) AuthState.Unreachable else AuthState.SignedOut
            }
        }
        loadCatalog()
    }

    fun loadCatalog() {
        scope.launch { (apiCall { api.catalog() } as? ApiResult.Ok)?.let { _catalog.value = it.value } }
    }

    fun signedIn(t: TokenResponse) {
        tokens.save(t.accessToken, t.refreshToken)
        expiredNotice = false
        _state.value = AuthState.SignedIn(t.user)
        loadCatalog()
        scope.launch { Push.register(api) }
    }

    fun update(me: Me) { _state.value = AuthState.SignedIn(me) }

    suspend fun switchRole(role: String): ApiResult<Me> {
        val r = apiCall { api.switchRole(RoleBody(role)) }
        if (r is ApiResult.Ok) update(r.value)
        return r
    }

    suspend fun refreshMe() {
        (apiCall { api.me() } as? ApiResult.Ok)?.let { update(it.value) }
    }

    suspend fun signOut() {
        Push.currentToken()?.let { tok -> apiCall { api.removeDevice(DeviceBody(tok)) } }
        apiCall { api.logout() }
        tokens.clear()
        _state.value = AuthState.SignedOut
    }

    fun localSignOut() {
        tokens.clear()
        _state.value = AuthState.SignedOut
    }

    fun onExpired() {
        if (_state.value is AuthState.SignedIn) expiredNotice = true
        _state.value = AuthState.SignedOut
    }
}
