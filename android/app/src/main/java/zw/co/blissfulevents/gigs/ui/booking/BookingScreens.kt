package zw.co.blissfulevents.gigs.ui.booking

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
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.data.ApiResult
import zw.co.blissfulevents.gigs.data.apiCall
import zw.co.blissfulevents.gigs.data.api.Booking
import zw.co.blissfulevents.gigs.data.api.BookingSummary
import zw.co.blissfulevents.gigs.data.api.ChatMessage
import zw.co.blissfulevents.gigs.data.api.DisputeBody
import zw.co.blissfulevents.gigs.data.api.MessageBody
import zw.co.blissfulevents.gigs.data.api.Paged
import zw.co.blissfulevents.gigs.data.api.ReasonBody
import zw.co.blissfulevents.gigs.data.api.RescheduleActionBody
import zw.co.blissfulevents.gigs.data.api.RescheduleBody
import zw.co.blissfulevents.gigs.data.api.ReviewBody
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.ConfirmDialog
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.ErrorBox
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.InfoRow
import zw.co.blissfulevents.gigs.ui.common.LoadingBox
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.MoneyLine
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SecondaryButton
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.StatusChip
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.ui.request.ReportDialog
import zw.co.blissfulevents.gigs.util.Labels
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat
import java.time.Instant
import java.time.LocalTime
import java.time.ZoneOffset
import java.util.UUID

@Composable
fun BookingsScreen(onOpen: (Long) -> Unit) {
    val c = container()
    val role = c.session.me?.activeRole ?: "client"
    var scope by rememberSaveable { mutableStateOf("upcoming") }
    val vm: ResourceViewModel<Paged<BookingSummary>> = viewModel(key = "bookings-$role-$scope") { ResourceViewModel { c.api.bookings(role, scope) } }
    val state by vm.state.collectAsState()
    LifecycleResumeEffect(Unit) { vm.load(silent = true); onPauseOrDispose { } }
    AppScaffold("Bookings") { m ->
        Column(m.fillMaxSize()) {
            Row(Modifier.padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = scope == "upcoming", onClick = { scope = "upcoming" }, label = { Text("Upcoming") })
                FilterChip(selected = scope == "past", onClick = { scope = "past" }, label = { Text("Past") })
            }
            Stateful(state, onRetry = { vm.load() }) { page ->
                if (page.items.isEmpty()) EmptyBox("No ${scope} bookings",
                    if (role == "client") "When you accept an offer, the booking appears here." else "When a client accepts your offer, the booking appears here.")
                else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(page.items, key = { it.id }) { b ->
                        Card(Modifier.fillMaxWidth().clickable { onOpen(b.id) }) {
                            Column(Modifier.padding(14.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(b.title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                                    StatusChip(Labels.bookingStatus(b.status))
                                }
                                Text(TimeFormat.eventTimeRange(b.startsAt, b.endsAt, b.timezone), style = MaterialTheme.typography.bodySmall)
                                Text("${b.instrument} · ${b.counterpartName} · ${b.city}", style = MaterialTheme.typography.bodySmall)
                                Text("${MoneyFormat.format(b.totalMinor, b.currency)} · ${Labels.paymentStatus(b.paymentStatus)}", style = MaterialTheme.typography.labelLarge)
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BookingDetailScreen(
    id: Long,
    onBack: () -> Unit,
    onChat: (Long) -> Unit,
    onOpenMusician: (Long) -> Unit,
    onOpenClient: (Long) -> Unit,
    onOpenUrl: (String) -> Unit,
) {
    val c = container()
    val vm: ResourceViewModel<Booking> = viewModel(key = "booking-$id") { ResourceViewModel { c.api.booking(id) } }
    val state by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    // Coming back from the payment page (or any other app): refresh so payment status is current.
    LifecycleResumeEffect(Unit) { vm.load(silent = true); onPauseOrDispose { } }
    val payKey = rememberSaveable { UUID.randomUUID().toString() }
    var dialog by rememberSaveable { mutableStateOf<String?>(null) }

    AppScaffold("Booking", onBack = onBack, snackbar = snack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { b ->
            val isClient = b.myRole == "client"
            val other = if (isClient) b.musician else b.client
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(b.title, style = MaterialTheme.typography.headlineSmall, modifier = Modifier.weight(1f))
                    StatusChip(Labels.bookingStatus(b.status))
                }
                StatusTimeline(b.status)
                SectionCard {
                    InfoRow("When", TimeFormat.eventTimeRange(b.startsAt, b.endsAt, b.timezone))
                    InfoRow("Arrive for setup", if (b.setupMinutes > 0) "${b.setupMinutes} min before start" else null)
                    InfoRow("Where", listOfNotNull(b.venueName, b.area, b.city).joinToString(", "))
                    InfoRow("Address", b.venueAddress)
                    InfoRow(if (isClient) "Musician" else "Client", (other.stageName ?: other.name ?: "") + (other.phone?.let { " · $it" } ?: ""))
                    TextButton(onClick = { if (isClient) onOpenMusician(other.userId) else onOpenClient(other.userId) }) { Text("View profile") }
                    InfoRow("Equipment at venue", b.equipmentAvailable)
                    InfoRow("Rehearsal", b.rehearsalNotes)
                    InfoRow("Travel & parking", b.travelNotes)
                }
                SectionCard(title = "Agreed price") {
                    MoneyLine("Musician's price", b.price.priceMinor, b.currency)
                    if (b.price.extrasMinor > 0) MoneyLine("Additional charges", b.price.extrasMinor, b.currency)
                    MoneyLine("Service fee", b.price.feeMinor, b.currency)
                    HorizontalDivider(Modifier.padding(vertical = 4.dp))
                    MoneyLine("Total", b.price.totalMinor, b.currency, strong = true)
                    if (!isClient) MoneyLine("You receive", b.price.musicianReceivesMinor, b.currency)
                    Text("Payment: ${Labels.paymentStatus(b.paymentStatus)}", style = MaterialTheme.typography.labelLarge, modifier = Modifier.padding(top = 6.dp))
                }
                b.pendingReschedule?.let { p ->
                    SectionCard(title = if (p.proposedByMe) "You proposed a new time" else "New time proposed") {
                        Text(TimeFormat.eventDateTime(p.newStartsAt, b.timezone) + " · " + TimeFormat.duration(p.newDurationMinutes))
                        p.note?.let { Text("“$it”", style = MaterialTheme.typography.bodySmall) }
                        Spacer(Modifier.height(8.dp))
                        if (p.proposedByMe) SecondaryButton("Withdraw proposal", {
                            vm.act("Proposal withdrawn.", { c.api.answerReschedule(id, p.id, RescheduleActionBody("withdraw")) }) { vm.replace(it) }
                        }) else {
                            PrimaryButton("Accept new time", busy = busy, onClick = {
                                vm.act("Booking moved to the new time.", { c.api.answerReschedule(id, p.id, RescheduleActionBody("accept")) }) { vm.replace(it) }
                            })
                            SecondaryButton("Keep the original time", {
                                vm.act("Proposal declined.", { c.api.answerReschedule(id, p.id, RescheduleActionBody("decline")) }) { vm.replace(it) }
                            })
                        }
                    }
                }
                b.dispute?.let { d ->
                    SectionCard(title = "Problem reported") {
                        Text("Reason: ${Labels.human(d.reason)} · ${Labels.human(d.status)}")
                        Text(d.resolution ?: "Blissful Studios support is reviewing this booking and will contact both of you.", style = MaterialTheme.typography.bodySmall)
                    }
                }
                if (b.status == "cancelled") SectionCard(title = "Cancelled") {
                    Text(b.cancelReason.orEmpty())
                    b.cancellationChargeMinor?.let { MoneyLine("Cancellation charge", it, b.currency) }
                }
                if (b.can.chat) PrimaryButton("Message ${if (isClient) "musician" else "client"}", { onChat(id) })
                if (b.can.pay) PrimaryButton("Pay ${MoneyFormat.format(b.price.totalMinor, b.currency)}", busy = busy, onClick = {
                    vm.act(null, { c.api.startPayment(id, payKey) }) { p ->
                        if (p.checkoutUrl != null) onOpenUrl(p.checkoutUrl) else vm.load(silent = true)
                    }
                })
                if (b.paymentStatus == "pending" && isClient) SecondaryButton("Check payment status", { vm.load() })
                if (b.can.markDone) SecondaryButton(if (b.myRole == "client" && b.musicianMarkedDone || b.myRole == "musician" && b.clientMarkedDone) "Confirm it's complete" else "Mark as completed",
                    { vm.act("Thanks! Marked as done.", { c.api.markDone(id) }) { vm.replace(it) } })
                if (b.can.review) SecondaryButton("Leave a review", { dialog = "review" })
                if (b.can.reschedule && b.pendingReschedule == null) SecondaryButton("Propose a new time", { dialog = "reschedule" })
                if (b.can.cancel) SecondaryButton("Cancel booking", { dialog = "cancel" })
                if (b.can.dispute) TextButton(onClick = { dialog = "dispute" }) { Text("Report a problem with this booking") }
                b.disputeWindowEndsAt?.let { if (b.status == "completed") Text("Problems can be reported until ${TimeFormat.eventDateTime(it, b.timezone)}.", style = MaterialTheme.typography.bodySmall) }
                Spacer(Modifier.height(24.dp))
            }
            when (dialog) {
                "cancel" -> {
                    val q = b.cancellationPreview
                    val body = buildString {
                        append("The other party will be notified. ")
                        if (q != null && isClient) {
                            if (q.chargeMinor == 0L) append("No cancellation charge applies at this time.")
                            else append("Under the agreed policy a charge of ${MoneyFormat.format(q.chargeMinor, q.currency)} applies (${q.chargeBps / 100}%). If you've paid, ${MoneyFormat.format(q.refundIfPaidMinor, q.currency)} will be refunded.")
                        } else append("The client is not charged when a musician cancels, and any payment is refunded.")
                    }
                    ConfirmDialog("Cancel booking?", body, "Cancel booking", inputLabel = "Reason (required)", minInput = 5, destructive = true,
                        onConfirm = { reason -> dialog = null; vm.act("Booking cancelled.", { c.api.cancelBooking(id, ReasonBody(reason)) }) { vm.replace(it) } },
                        onDismiss = { dialog = null })
                }
                "dispute" -> DisputeDialog(onDismiss = { dialog = null }) { reason, details ->
                    dialog = null
                    vm.act("Reported. Support will be in touch.", { c.api.dispute(id, DisputeBody(reason, details)) }) { vm.replace(it) }
                }
                "review" -> ReviewDialog(onDismiss = { dialog = null }) { rating, comment ->
                    dialog = null
                    vm.act("Thanks for your review!", { c.api.review(id, ReviewBody(rating, comment)); c.api.booking(id) }) { vm.replace(it) }
                }
                "reschedule" -> RescheduleDialog(onDismiss = { dialog = null }) { iso, note ->
                    dialog = null
                    vm.act("New time sent. The booking changes only if they accept.", { c.api.proposeReschedule(id, RescheduleBody(iso, null, note)) }) { vm.replace(it) }
                }
            }
        }
    }
}

@Composable
private fun StatusTimeline(status: String) {
    val steps = listOf("confirmed" to "Confirmed", "in_progress" to "In progress", "completed" to "Completed")
    val index = steps.indexOfFirst { it.first == status }
    if (index < 0) return
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
        steps.forEachIndexed { i, (_, label) ->
            Surface(
                color = if (i <= index) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                shape = MaterialTheme.shapes.small, modifier = Modifier.weight(1f),
            ) {
                Text(label, Modifier.padding(6.dp), style = MaterialTheme.typography.labelSmall,
                    color = if (i <= index) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun DisputeDialog(onDismiss: () -> Unit, onSubmit: (String, String) -> Unit) {
    var reason by rememberSaveable { mutableStateOf("no_show") }
    var details by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Report a problem") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text("This pauses completion and payout while Blissful Studios reviews what happened.")
                listOf("no_show" to "No-show", "late" to "Very late", "quality" to "Service not as agreed", "payment" to "Payment issue",
                    "conduct" to "Behaviour", "other" to "Other").forEach { (v, l) ->
                    Row(Modifier.fillMaxWidth().clickable { reason = v }, verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(selected = reason == v, onClick = { reason = v }); Text(l)
                    }
                }
                OutlinedTextField(details, { details = it }, label = { Text("What happened? (10+ characters)") }, minLines = 3, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(enabled = details.trim().length >= 10, onClick = { onSubmit(reason, details.trim()) }) { Text("Submit") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
private fun ReviewDialog(onDismiss: () -> Unit, onSubmit: (Int, String?) -> Unit) {
    var rating by rememberSaveable { mutableStateOf(0) }
    var comment by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Leave a review") },
        text = {
            Column {
                Row {
                    (1..5).forEach { n ->
                        TextButton(onClick = { rating = n }) {
                            Text(if (n <= rating) "★" else "☆", style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.secondary)
                        }
                    }
                }
                Text(if (rating == 0) "Tap a star to rate" else "$rating of 5 stars", style = MaterialTheme.typography.bodySmall)
                OutlinedTextField(comment, { comment = it }, label = { Text("Comment (optional)") }, minLines = 3, modifier = Modifier.fillMaxWidth())
                Text("Reviews are public and can only be left once.", style = MaterialTheme.typography.bodySmall)
            }
        },
        confirmButton = { TextButton(enabled = rating > 0, onClick = { onSubmit(rating, comment.ifBlank { null }) }) { Text("Post review") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun RescheduleDialog(onDismiss: () -> Unit, onSubmit: (String, String?) -> Unit) {
    val dateState = rememberDatePickerState()
    var step by rememberSaveable { mutableStateOf(1) }
    val timeState = rememberTimePickerState(initialHour = 9, initialMinute = 0, is24Hour = true)
    var note by rememberSaveable { mutableStateOf("") }
    if (step == 1) {
        DatePickerDialog(
            onDismissRequest = onDismiss,
            confirmButton = { TextButton(enabled = dateState.selectedDateMillis != null, onClick = { step = 2 }) { Text("Next") } },
            dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
        ) { DatePicker(dateState) }
    } else {
        AlertDialog(
            onDismissRequest = onDismiss,
            title = { Text("New start time (Harare)") },
            text = {
                Column {
                    TimePicker(timeState)
                    OutlinedTextField(note, { note = it }, label = { Text("Reason (optional)") }, modifier = Modifier.fillMaxWidth())
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val d = Instant.ofEpochMilli(dateState.selectedDateMillis ?: 0).atZone(ZoneOffset.UTC).toLocalDate()
                    onSubmit(TimeFormat.toIso(d, LocalTime.of(timeState.hour, timeState.minute), "Africa/Harare"), note.ifBlank { null })
                }) { Text("Send proposal") }
            },
            dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
        )
    }
}

/**
 * Chat for one booking. Polls every 4 s while open (shared hosting has no websockets).
 * Unsent messages stay visible with a retry button; the idempotency key prevents duplicates on retry.
 */
@Composable
fun ChatScreen(bookingId: Long, onBack: () -> Unit) {
    val c = container()
    val messages = remember { mutableStateListOf<ChatMessage>() }
    val failed = remember { mutableStateListOf<Pair<String, String>>() } // (idempotency key, body)
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<zw.co.blissfulevents.gigs.data.Failure?>(null) }
    var chatOpen by remember { mutableStateOf(true) }
    var text by rememberSaveable { mutableStateOf("") }
    var reportId by remember { mutableStateOf<Long?>(null) }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    val snack = remember { SnackbarHostState() }

    suspend fun poll() {
        val after = messages.lastOrNull()?.id
        when (val r = apiCall { c.api.messages(bookingId, after = after) }) {
            is ApiResult.Ok -> {
                val known = messages.map { it.id }.toSet()
                messages.addAll(r.value.items.filter { it.id !in known })
                chatOpen = r.value.chatOpen
                error = null
            }
            is ApiResult.Err -> if (messages.isEmpty()) error = r.failure
        }
        loading = false
    }

    fun send(key: String, body: String) {
        failed.removeAll { it.first == key }
        scope.launch {
            when (val r = apiCall { c.api.sendMessage(bookingId, MessageBody(body), key) }) {
                is ApiResult.Ok -> { if (messages.none { it.id == r.value.id }) messages.add(r.value) }
                is ApiResult.Err -> { failed.add(key to body); snack.showSnackbar(r.failure.message) }
            }
        }
    }

    LaunchedEffect(bookingId) {
        while (true) { poll(); delay(4000) }
    }
    LaunchedEffect(messages.size) { if (messages.isNotEmpty()) list.animateScrollToItem(messages.size - 1) }

    AppScaffold("Messages", onBack = onBack, snackbar = snack) { m ->
        when {
            loading -> LoadingBox(m)
            error != null -> ErrorBox(error!!, onRetry = { loading = true; scope.launch { poll() } }, modifier = m)
            else -> Column(m.fillMaxSize().imePadding()) {
                Text("Keep payments and arrangements in the app so they're covered by Blissful Studios support.",
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(12.dp))
                LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = list, contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(messages, key = { it.id }) { msg -> Bubble(msg, onLongPress = { if (!msg.mine) reportId = msg.id }) }
                    items(failed, key = { it.first }) { (key, body) ->
                        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.End) {
                            Surface(color = MaterialTheme.colorScheme.errorContainer, shape = MaterialTheme.shapes.medium) {
                                Text(body, Modifier.padding(10.dp).widthIn(max = 280.dp))
                            }
                            TextButton(onClick = { send(key, body) }) { Text("Not sent — tap to retry") }
                        }
                    }
                }
                if (chatOpen) Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(text, { if (it.length <= 2000) text = it }, placeholder = { Text("Write a message") }, modifier = Modifier.weight(1f), maxLines = 4)
                    IconButton(enabled = text.isNotBlank(), onClick = { val body = text.trim(); text = ""; send(UUID.randomUUID().toString(), body) }) {
                        Icon(Icons.AutoMirrored.Filled.Send, "Send")
                    }
                } else Text("This conversation is closed.", Modifier.padding(16.dp), color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
    reportId?.let { mid -> ReportDialog("message", mid) { reportId = null; if (it.isNotBlank()) scope.launch { snack.showSnackbar(it) } } }
}

@Composable
private fun Bubble(msg: ChatMessage, onLongPress: () -> Unit) {
    Column(Modifier.fillMaxWidth(), horizontalAlignment = if (msg.mine) Alignment.End else Alignment.Start) {
        Box(
            Modifier.widthIn(max = 300.dp)
                .background(if (msg.mine) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant, MaterialTheme.shapes.medium)
                .clickable(onClickLabel = if (msg.mine) null else "Report message", onClick = onLongPress)
                .padding(10.dp)
        ) {
            Text(if (msg.hidden) "Message removed by moderators" else msg.body.orEmpty(),
                color = if (msg.mine) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Text(TimeFormat.relative(msg.createdAt), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
