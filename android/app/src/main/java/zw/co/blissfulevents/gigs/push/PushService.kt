package zw.co.blissfulevents.gigs.push

import android.Manifest
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.BlissfulApp
import zw.co.blissfulevents.gigs.BuildConfig
import zw.co.blissfulevents.gigs.MainActivity
import zw.co.blissfulevents.gigs.R
import zw.co.blissfulevents.gigs.data.apiCall
import zw.co.blissfulevents.gigs.data.api.ApiService
import zw.co.blissfulevents.gigs.data.api.DeviceBody
import kotlin.coroutines.resume

/**
 * Firebase Cloud Messaging is optional. Without FIREBASE_* build values the app runs normally and
 * relies on the in-app notification history (fetched on open and on pull-to-refresh).
 */
object Push {
    @Volatile var enabled: Boolean = false
        private set
    @Volatile private var token: String? = null

    fun init(context: Context) {
        if (BuildConfig.FIREBASE_APP_ID.isBlank() || BuildConfig.FIREBASE_PROJECT_ID.isBlank()) return
        runCatching {
            if (FirebaseApp.getApps(context).isEmpty()) {
                FirebaseApp.initializeApp(
                    context,
                    FirebaseOptions.Builder()
                        .setApplicationId(BuildConfig.FIREBASE_APP_ID)
                        .setApiKey(BuildConfig.FIREBASE_API_KEY)
                        .setProjectId(BuildConfig.FIREBASE_PROJECT_ID)
                        .setGcmSenderId(BuildConfig.FIREBASE_SENDER_ID)
                        .build()
                )
            }
            FirebaseMessaging.getInstance().isAutoInitEnabled = true
            enabled = true
        }
    }

    fun currentToken(): String? = token

    suspend fun register(api: ApiService) {
        if (!enabled) return
        val t = suspendCancellableCoroutine<String?> { cont ->
            FirebaseMessaging.getInstance().token
                .addOnSuccessListener { cont.resume(it) }
                .addOnFailureListener { cont.resume(null) }
        } ?: return
        token = t
        apiCall { api.registerDevice(DeviceBody(t)) }
    }

    internal fun setToken(t: String) { token = t }
}

class PushService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        Push.setToken(token)
        val container = (application as BlissfulApp).container
        if (container.tokens.refreshToken != null) {
            CoroutineScope(Dispatchers.IO).launch { apiCall { container.api.registerDevice(DeviceBody(token)) } }
        }
    }

    @android.annotation.SuppressLint("MissingPermission") // checked explicitly below
    override fun onMessageReceived(message: RemoteMessage) {
        val n = message.notification ?: return
        if (android.os.Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            message.data["booking_id"]?.let { putExtra("booking_id", it) }
            message.data["request_id"]?.let { putExtra("request_id", it) }
        }
        val pi = PendingIntent.getActivity(this, message.messageId.hashCode(), intent, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val channel = if (message.data["type"] == "message") "messages" else "bookings"
        val notif = NotificationCompat.Builder(this, channel)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle(n.title)
            .setContentText(n.body)
            .setAutoCancel(true)
            .setContentIntent(pi)
            .build()
        runCatching { NotificationManagerCompat.from(this).notify(message.messageId.hashCode(), notif) }
    }
}
