package zw.co.blissfulevents.gigs.ui.common

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.data.ApiResult
import zw.co.blissfulevents.gigs.data.Failure
import zw.co.blissfulevents.gigs.data.apiCall

sealed interface UiState<out T> {
    data object Loading : UiState<Nothing>
    data class Ready<T>(val data: T, val refreshing: Boolean = false) : UiState<T>
    data class Failed(val failure: Failure) : UiState<Nothing>
}

/**
 * Generic screen model: loads one resource, survives rotation, and runs user actions with a busy flag.
 * A failed action never shows a success message; its error is surfaced and the data is left unchanged.
 */
class ResourceViewModel<T>(private val loader: suspend () -> T) : ViewModel() {
    private val _state = MutableStateFlow<UiState<T>>(UiState.Loading)
    val state: StateFlow<UiState<T>> = _state.asStateFlow()
    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()
    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()
    private val _fieldErrors = MutableStateFlow<Map<String, String>>(emptyMap())
    val fieldErrors: StateFlow<Map<String, String>> = _fieldErrors.asStateFlow()
    private var loadJob: Job? = null

    init { load() }

    fun load(silent: Boolean = false) {
        loadJob?.cancel()
        val current = _state.value
        if (current is UiState.Ready) _state.value = current.copy(refreshing = true)
        else if (!silent) _state.value = UiState.Loading
        loadJob = viewModelScope.launch {
            when (val r = apiCall { loader() }) {
                is ApiResult.Ok -> _state.value = UiState.Ready(r.value)
                is ApiResult.Err -> _state.value = if (current is UiState.Ready) {
                    _message.value = r.failure.message; current.copy(refreshing = false)
                } else UiState.Failed(r.failure)
            }
        }
    }

    fun replace(data: T) { _state.value = UiState.Ready(data) }

    /** Runs a write. `onOk` receives the result; `success` is shown only after the server confirms. */
    fun <R> act(success: String? = null, block: suspend () -> R, onOk: (R) -> Unit = {}) {
        if (_busy.value) return
        _busy.value = true
        _fieldErrors.value = emptyMap()
        viewModelScope.launch {
            when (val r = apiCall { block() }) {
                is ApiResult.Ok -> { onOk(r.value); success?.let { _message.value = it } }
                is ApiResult.Err -> { _fieldErrors.value = r.failure.fields; _message.value = r.failure.message }
            }
            _busy.value = false
        }
    }

    fun consumeMessage() { _message.value = null }
    fun showMessage(m: String) { _message.value = m }
}

/** For forms that don't load anything first (sign-in, create request…). */
class ActionViewModel : ViewModel() {
    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()
    private val _error = MutableStateFlow<Failure?>(null)
    val error: StateFlow<Failure?> = _error.asStateFlow()

    fun <R> act(block: suspend () -> R, onOk: (R) -> Unit) {
        if (_busy.value) return
        _busy.value = true
        _error.value = null
        viewModelScope.launch {
            when (val r = apiCall { block() }) {
                is ApiResult.Ok -> onOk(r.value)
                is ApiResult.Err -> _error.value = r.failure
            }
            _busy.value = false
        }
    }

    fun clearError() { _error.value = null }
    fun setError(message: String) { _error.value = Failure(message, "local") }
}
