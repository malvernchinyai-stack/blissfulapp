package zw.co.blissfulevents.gigs.util

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.flow.distinctUntilChanged

/** Emits true while the device has a validated internet connection. */
fun Context.onlineFlow(): Flow<Boolean> = callbackFlow {
    val cm = getSystemService(ConnectivityManager::class.java)
    fun current(): Boolean = cm.getNetworkCapabilities(cm.activeNetwork)
        ?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED) == true
    trySend(current())
    val cb = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) { trySend(current()) }
        override fun onLost(network: Network) { trySend(current()) }
        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) { trySend(current()) }
    }
    val req = NetworkRequest.Builder().addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET).build()
    cm.registerNetworkCallback(req, cb)
    awaitClose { cm.unregisterNetworkCallback(cb) }
}.distinctUntilChanged()
