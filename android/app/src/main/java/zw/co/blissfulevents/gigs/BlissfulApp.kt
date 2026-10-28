package zw.co.blissfulevents.gigs

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import zw.co.blissfulevents.gigs.push.Push

class BlissfulApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        createChannels()
        Push.init(this)
        container.session.restore()
    }

    private fun createChannels() {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel("bookings", "Bookings and offers", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "New requests, offers, confirmations, cancellations and reminders"
        })
        nm.createNotificationChannel(NotificationChannel("messages", "Messages", NotificationManager.IMPORTANCE_DEFAULT).apply {
            description = "Chat messages from bookings"
        })
    }
}
