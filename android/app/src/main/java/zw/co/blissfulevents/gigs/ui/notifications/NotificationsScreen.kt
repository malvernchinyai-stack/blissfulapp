package zw.co.blissfulevents.gigs.ui.notifications

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.serialization.json.JsonPrimitive
import zw.co.blissfulevents.gigs.data.api.AppNotification
import zw.co.blissfulevents.gigs.data.api.NotificationsPage
import zw.co.blissfulevents.gigs.data.api.ReadBody
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.UiState
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.util.TimeFormat

private fun AppNotification.longData(key: String): Long? = (data[key] as? JsonPrimitive)?.content?.toLongOrNull()

/** In-app history of everything that was (or would have been) pushed. Works even without push configured. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationsScreen(onOpenBooking: (Long) -> Unit, onOpenRequest: (Long) -> Unit, onOpenOffer: (Long) -> Unit, onPrefs: () -> Unit) {
    val c = container()
    val vm: ResourceViewModel<NotificationsPage> = viewModel(key = "inbox") { ResourceViewModel { c.api.notifications() } }
    val state by vm.state.collectAsState()
    LifecycleResumeEffect(Unit) { vm.load(silent = true); onPauseOrDispose { } }
    AppScaffold("Inbox", actions = {
        IconButton(onClick = onPrefs) { Icon(Icons.Filled.Settings, "Notification settings") }
    }) { m ->
        PullToRefreshBox(isRefreshing = (state as? UiState.Ready)?.refreshing == true, onRefresh = { vm.load() }, modifier = m) {
            Stateful(state, onRetry = { vm.load() }) { page ->
                if (page.items.isEmpty()) EmptyBox("All caught up", "Offers, bookings, messages and reminders will appear here.")
                else LazyColumn(contentPadding = PaddingValues(vertical = 8.dp)) {
                    if (page.unreadCount > 0) item {
                        TextButton(onClick = { vm.act(null, { c.api.markRead(ReadBody(all = true)); c.api.notifications() }) { vm.replace(it) } },
                            modifier = Modifier.padding(horizontal = 8.dp)) { Text("Mark all ${page.unreadCount} as read") }
                    }
                    items(page.items, key = { it.id }) { n ->
                        Row(
                            Modifier.fillMaxWidth().clickable {
                                if (!n.read) vm.act(null, { c.api.markRead(ReadBody(ids = listOf(n.id))) })
                                n.longData("booking_id")?.let { onOpenBooking(it); return@clickable }
                                n.longData("offer_id")?.let { if (n.type.startsWith("offer") || n.type.startsWith("counter")) { onOpenOffer(it); return@clickable } }
                                n.longData("request_id")?.let { onOpenRequest(it) }
                            }.padding(horizontal = 16.dp, vertical = 12.dp),
                            verticalAlignment = Alignment.Top,
                        ) {
                            Box(Modifier.padding(top = 6.dp).size(8.dp).background(if (n.read) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.secondary, CircleShape))
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(n.title, style = MaterialTheme.typography.titleSmall, fontWeight = if (n.read) FontWeight.Normal else FontWeight.Bold)
                                Text(n.body, style = MaterialTheme.typography.bodyMedium)
                                Text(TimeFormat.relative(n.createdAt), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        HorizontalDivider()
                    }
                }
            }
        }
    }
}
