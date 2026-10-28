package zw.co.blissfulevents.gigs.ui.profile

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.Flag
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import zw.co.blissfulevents.gigs.data.api.ClientProfile
import zw.co.blissfulevents.gigs.data.api.MusicianProfile
import zw.co.blissfulevents.gigs.data.api.ReviewItem
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.Avatar
import zw.co.blissfulevents.gigs.ui.common.ConfirmDialog
import zw.co.blissfulevents.gigs.ui.common.InfoRow
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.RatingText
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.VerificationBadges
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.ui.request.ReportDialog
import zw.co.blissfulevents.gigs.util.Labels
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat

@Composable
fun MusicianPublicProfileScreen(id: Long, onBack: () -> Unit, onOpenUrl: (String) -> Unit) {
    val c = container()
    val vm: ResourceViewModel<MusicianProfile> = viewModel(key = "musician-$id") { ResourceViewModel { c.api.musician(id) } }
    val state by vm.state.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    var reporting by remember { mutableStateOf(false) }
    var blocking by remember { mutableStateOf(false) }
    val isMe = c.session.me?.id == id
    AppScaffold("Musician", onBack = onBack, snackbar = snack, actions = {
        if (!isMe) {
            IconButton(onClick = { reporting = true }) { Icon(Icons.Filled.Flag, "Report") }
            IconButton(onClick = { blocking = true }) { Icon(Icons.Filled.Block, "Block") }
        }
    }) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { p ->
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Avatar(p.photoUrl, p.stageName, 72.dp)
                    Spacer(Modifier.width(16.dp))
                    Column {
                        Text(p.stageName, style = MaterialTheme.typography.headlineSmall)
                        Text(listOfNotNull(p.area, p.city).joinToString(", ") + " · travels up to ${p.travelRadiusKm} km", style = MaterialTheme.typography.bodySmall)
                        RatingText(p.rating)
                    }
                }
                if (p.verifications.isNotEmpty()) VerificationBadges(p.verifications)
                if (!p.acceptingBookings) Text("Not taking new bookings at the moment.", color = MaterialTheme.colorScheme.error)
                SectionCard {
                    InfoRow("Instruments & services", p.instruments.joinToString(", ") { it.name })
                    InfoRow("Genres", p.genres.joinToString(", ") { it.name })
                    InfoRow("Experience", "${p.yearsExperience} years · ${p.completedBookings} gigs completed on Blissful Gigs")
                    InfoRow("Typical rate", p.typicalRateMinor.takeIf { it > 0 }?.let { MoneyFormat.format(it, p.currency) + " per event" })
                    InfoRow("About", p.bio)
                    InfoRow("Brings", p.equipmentProvided)
                    InfoRow("Needs at the venue", p.equipmentRequired)
                }
                if (p.portfolio.isNotEmpty()) SectionCard(title = "Samples") {
                    p.portfolio.forEach { item ->
                        when (item.kind) {
                            "photo" -> AsyncImage(model = item.url, contentDescription = item.title ?: "Performance photo", modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp))
                            else -> Text("▶ " + (item.title ?: if (item.kind == "audio") "Audio sample" else "Video"), color = MaterialTheme.colorScheme.primary,
                                modifier = Modifier.padding(vertical = 6.dp).clickable { item.url?.let(onOpenUrl) })
                        }
                    }
                }
                Reviews(p.recentReviews)
            }
        }
    }
    if (reporting) ReportDialog("user", id) { reporting = false; if (it.isNotBlank()) vm.showMessage(it) }
    if (blocking) ConfirmDialog("Block this musician?", "They won't see your requests or message you, and you won't see them in search.", "Block",
        onConfirm = { blocking = false; vm.act("Blocked.", { c.api.block(id) }) }, onDismiss = { blocking = false }, destructive = true)
}

@Composable
fun ClientPublicProfileScreen(id: Long, onBack: () -> Unit) {
    val c = container()
    val vm: ResourceViewModel<ClientProfile> = viewModel(key = "client-$id") { ResourceViewModel { c.api.client(id) } }
    val state by vm.state.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    var reporting by remember { mutableStateOf(false) }
    var blocking by remember { mutableStateOf(false) }
    AppScaffold("Client", onBack = onBack, snackbar = snack, actions = {
        IconButton(onClick = { reporting = true }) { Icon(Icons.Filled.Flag, "Report") }
        IconButton(onClick = { blocking = true }) { Icon(Icons.Filled.Block, "Block") }
    }) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { p ->
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Avatar(p.photoUrl, p.name, 72.dp)
                    Spacer(Modifier.width(16.dp))
                    Column {
                        Text(p.name, style = MaterialTheme.typography.headlineSmall)
                        Text(Labels.human(p.orgType) + (p.city?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodySmall)
                        RatingText(p.rating)
                    }
                }
                if (p.verifications.isNotEmpty()) VerificationBadges(p.verifications)
                SectionCard {
                    InfoRow("Completed bookings", p.completedBookings.toString())
                    InfoRow("About", p.bio)
                }
                Reviews(p.recentReviews)
            }
        }
    }
    if (reporting) ReportDialog("user", id) { reporting = false; if (it.isNotBlank()) vm.showMessage(it) }
    if (blocking) ConfirmDialog("Block this client?", "You won't see their requests and they can't message you.", "Block",
        onConfirm = { blocking = false; vm.act("Blocked.", { c.api.block(id) }) }, onDismiss = { blocking = false }, destructive = true)
}

@Composable
private fun Reviews(reviews: List<ReviewItem>) {
    SectionCard(title = "Reviews") {
        if (reviews.isEmpty()) Text("No reviews yet. Reviews come only from completed bookings.", style = MaterialTheme.typography.bodySmall)
        reviews.forEach { r ->
            Column(Modifier.padding(vertical = 6.dp)) {
                Text("★".repeat(r.rating) + "☆".repeat(5 - r.rating) + "  " + r.author + " · " + TimeFormat.relative(r.createdAt), style = MaterialTheme.typography.labelMedium)
                r.comment?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
            }
        }
    }
}
