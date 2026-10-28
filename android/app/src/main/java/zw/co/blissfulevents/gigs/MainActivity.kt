package zw.co.blissfulevents.gigs

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import zw.co.blissfulevents.gigs.ui.AppRoot
import zw.co.blissfulevents.gigs.ui.theme.BlissfulTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        // Deep links from push notifications carry only ids; the app re-fetches and re-authorises everything.
        val bookingId = intent?.getStringExtra("booking_id")?.toLongOrNull()
        val requestId = intent?.getStringExtra("request_id")?.toLongOrNull()
        setContent {
            BlissfulTheme {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    AppRoot(bookingId, requestId)
                }
            }
        }
    }
}
