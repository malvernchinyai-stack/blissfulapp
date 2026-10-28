package zw.co.blissfulevents.gigs.ui.request

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Flag
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import zw.co.blissfulevents.gigs.data.api.AcceptBody
import zw.co.blissfulevents.gigs.data.api.CounterBody
import zw.co.blissfulevents.gigs.data.api.CounterResponseBody
import zw.co.blissfulevents.gigs.data.api.EditOfferBody
import zw.co.blissfulevents.gigs.data.api.GigRequest
import zw.co.blissfulevents.gigs.data.api.Offer
import zw.co.blissfulevents.gigs.data.api.OfferBody
import zw.co.blissfulevents.gigs.data.api.Position
import zw.co.blissfulevents.gigs.data.api.ReportBody
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.Avatar
import zw.co.blissfulevents.gigs.ui.common.ConfirmDialog
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.InfoRow
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.MoneyLine
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.RatingText
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SecondaryButton
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.StatusChip
import zw.co.blissfulevents.gigs.ui.common.VerificationBadges
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.util.Labels
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat
import java.util.UUID

/** Request detail: the owner sees positions and offers; a musician sees the brief and can make or manage an offer. */
@Composable
fun RequestDetailScreen(
    id: Long,
    onBack: () -> Unit,
    onOpenOffer: (Long) -> Unit,
    onOpenMusician: (Long) -> Unit,
    onOpenClient: (Long) -> Unit,
    onOpenBooking: (Long) -> Unit,
) {
    val c = container()
    val vm: ResourceViewModel<GigRequest> = viewModel(key = "request-$id") { ResourceViewModel { c.api.request(id) } }
    val state by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    var confirmCancel by remember { mutableStateOf(false) }
    var reporting by remember { mutableStateOf(false) }

    AppScaffold("Request", onBack = onBack, snackbar = snack, actions = {
        val r = (state as? zw.co.blissfulevents.gigs.ui.common.UiState.Ready<*>)?.data as? GigRequest
        if (r != null && !r.isOwner) IconButton(onClick = { reporting = true }) { Icon(Icons.Filled.Flag, "Report this request") }
    }) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { r ->
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(r.title, style = MaterialTheme.typography.headlineSmall, modifier = Modifier.weight(1f))
                    StatusChip(Labels.human(r.status))
                }
                SectionCard {
                    InfoRow("When", TimeFormat.eventDateTime(r.startsAt, r.timezone) + " · " + TimeFormat.duration(r.durationMinutes))
                    InfoRow("Setup time", r.setupMinutes?.takeIf { it > 0 }?.let { "$it min before start" })
                    InfoRow("Event type", Labels.human(r.eventType))
                    InfoRow("Where", listOfNotNull(r.venueName, r.area, r.city).joinToString(", "))
                    InfoRow("Exact address", r.venueAddress)
                    if (!r.isOwner && r.venueAddress == null) Text("The exact address is shared once you're booked.", style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant)
                    InfoRow("Offers close", if (r.status == "open") TimeFormat.eventDateTime(r.offerDeadline, r.timezone) + " (" + TimeFormat.until(r.offerDeadline) + ")" else null)
                    InfoRow("Description", r.description)
                    InfoRow("Rehearsal", r.rehearsalNotes)
                    InfoRow("Equipment at venue", r.equipmentAvailable)
                    InfoRow("Travel & parking", r.travelNotes)
                }
                if (!r.isOwner) r.client?.let { cl ->
                    SectionCard(title = "Client") {
                        Row(Modifier.fillMaxWidth().clickable { onOpenClient(cl.userId) }, verticalAlignment = Alignment.CenterVertically) {
                            Avatar(cl.photoUrl, cl.name)
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(cl.name, style = MaterialTheme.typography.titleSmall)
                                Text(Labels.human(cl.orgType) + " · ${cl.completedBookings} bookings", style = MaterialTheme.typography.bodySmall)
                            }
                            RatingText(cl.rating)
                        }
                    }
                }
                if (r.isOwner) {
                    OwnerPositions(r, onOpenOffer, onOpenMusician)
                    if (r.status in listOf("draft", "open")) {
                        if (r.status == "draft") PrimaryButton("Publish request", busy = busy, onClick = {
                            vm.act("Published — matching musicians have been notified.", { c.api.publish(id) }) { vm.replace(it) }
                        })
                        SecondaryButton(if (r.positions.any { it.filled > 0 }) "Close remaining positions" else "Cancel request", { confirmCancel = true })
                    }
                } else {
                    MusicianOfferSection(r, onOpenOffer, onOpenBooking, onSubmitted = { vm.load(silent = true) })
                }
            }
        }
    }
    if (confirmCancel) ConfirmDialog(
        "Cancel this request?", "All open offers will be declined and musicians notified. Confirmed bookings are not affected.", "Cancel request",
        onConfirm = { confirmCancel = false; vm.act("Request closed.", { c.api.cancelRequest(id) }) { vm.replace(it) } },
        onDismiss = { confirmCancel = false }, destructive = true,
    )
    if (reporting) ReportDialog("request", id, onDone = { reporting = false; if (it.isNotBlank()) vm.showMessage(it) })
}

@Composable
private fun OwnerPositions(r: GigRequest, onOpenOffer: (Long) -> Unit, onOpenMusician: (Long) -> Unit) {
    val c = container()
    var sort by rememberSaveable { mutableStateOf("price") }
    val vm: ResourceViewModel<List<Offer>> = viewModel(key = "offers-${r.id}-$sort") { ResourceViewModel { c.api.offersFor(r.id, sort).items } }
    val state by vm.state.collectAsState()
    Text("Offers", style = MaterialTheme.typography.titleLarge)
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf("price" to "Lowest total", "rating" to "Top rated", "experience" to "Experience").forEach { (v, l) ->
            FilterChip(selected = sort == v, onClick = { sort = v }, label = { Text(l) })
        }
    }
    Stateful(state, onRetry = { vm.load() }) { offers ->
        r.positions.forEach { p ->
            val forPos = offers.filter { it.positionId == p.id }
            SectionCard(title = "${p.instrument} · ${p.filled}/${p.quantity} booked · budget ${MoneyFormat.format(p.budgetMinor, r.currency)} each") {
                if (p.status == "filled") Text("All places filled.", color = MaterialTheme.colorScheme.secondary)
                else if (forPos.isEmpty()) Text("No offers yet. Matching musicians have been notified.", style = MaterialTheme.typography.bodySmall)
                forPos.forEach { o -> OfferRow(o, onClick = { onOpenOffer(o.id) }, onMusician = { onOpenMusician(o.musicianId) }) }
            }
        }
    }
}

@Composable
private fun OfferRow(o: Offer, onClick: () -> Unit, onMusician: () -> Unit) {
    val mu = o.musician
    Card(Modifier.fillMaxWidth().padding(vertical = 4.dp).clickable(onClick = onClick)) {
        Column(Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (mu != null) Avatar(mu.photoUrl, mu.stageName, 40.dp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(mu?.stageName ?: "Musician", style = MaterialTheme.typography.titleSmall, modifier = Modifier.clickable(onClick = onMusician))
                    if (mu != null) Text("${mu.yearsExperience} yrs · ${mu.completedBookings} gigs", style = MaterialTheme.typography.bodySmall)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(MoneyFormat.format(o.breakdown?.totalMinor ?: o.totalMinor, o.currency), style = MaterialTheme.typography.titleMedium)
                    Text("incl. fee", style = MaterialTheme.typography.labelSmall)
                }
            }
            if (mu != null) { RatingText(mu.rating); if (mu.verifications.isNotEmpty()) VerificationBadges(mu.verifications) }
            o.message?.let { Text("“$it”", style = MaterialTheme.typography.bodyMedium, maxLines = 3) }
            if (o.available == false) Text("Now booked elsewhere at this time", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelMedium)
            if (o.counterPriceMinor != null) Text("Your counter of ${MoneyFormat.format(o.counterPriceMinor, o.currency)} is awaiting a reply",
                color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.labelMedium)
        }
    }
}

@Composable
private fun MusicianOfferSection(r: GigRequest, onOpenOffer: (Long) -> Unit, onOpenBooking: (Long) -> Unit, onSubmitted: () -> Unit) {
    val c = container()
    val myOffers: ResourceViewModel<List<Offer>> = viewModel(key = "mine-for-${r.id}") {
        ResourceViewModel { c.api.myOffers(status = "all").items.filter { it.requestId == r.id } }
    }
    val st by myOffers.state.collectAsState()
    Stateful(st, onRetry = { myOffers.load() }) { offers ->
        val active = offers.firstOrNull { it.status in listOf("submitted", "accepted") }
        when {
            active != null && active.status == "accepted" -> SectionCard(title = "You're booked for this event") {
                PrimaryButton("Open booking", { active.bookingId?.let(onOpenBooking) ?: onOpenOffer(active.id) })
            }
            active != null -> SectionCard(title = "Your offer: ${MoneyFormat.format(active.totalMinor, active.currency)}") {
                Text("Status: ${Labels.human(active.status)}" + (active.counterPriceMinor?.let { " · client proposed ${MoneyFormat.format(it, active.currency)}" } ?: ""))
                Spacer(Modifier.height(8.dp))
                PrimaryButton("View, edit or withdraw", { onOpenOffer(active.id) })
            }
            r.status != "open" -> Text("This request is no longer taking offers.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            else -> SubmitOfferForm(r, onSubmitted = { myOffers.load(); onSubmitted() })
        }
    }
}

@Composable
private fun SubmitOfferForm(r: GigRequest, onSubmitted: () -> Unit) {
    val c = container()
    val me = c.session.me
    val vm: ResourceViewModel<Unit> = viewModel(key = "submit-${r.id}") { ResourceViewModel { } }
    val busy by vm.busy.collectAsState()
    val fields by vm.fieldErrors.collectAsState()
    val catalog by c.session.catalog.collectAsState()
    val idemKey = rememberSaveable { UUID.randomUUID().toString() }
    val openPositions = r.positions.filter { it.status == "open" }
    var positionId by rememberSaveable { mutableStateOf(openPositions.firstOrNull()?.id ?: 0L) }
    val position: Position? = openPositions.firstOrNull { it.id == positionId }
    var acceptBudget by rememberSaveable { mutableStateOf(true) }
    var price by rememberSaveable { mutableStateOf("") }
    var extras by rememberSaveable { mutableStateOf("") }
    var extrasNote by rememberSaveable { mutableStateOf("") }
    var included by rememberSaveable { mutableStateOf("") }
    var message by rememberSaveable { mutableStateOf("") }
    val msg by vm.message.collectAsState()

    SectionCard(title = "Make an offer") {
        if (me?.hasMusicianProfile == false) Text("Complete your musician profile before making offers.", color = MaterialTheme.colorScheme.error)
        openPositions.forEach { p ->
            Row(Modifier.fillMaxWidth().clickable { positionId = p.id }, verticalAlignment = Alignment.CenterVertically) {
                androidx.compose.material3.RadioButton(selected = positionId == p.id, onClick = { positionId = p.id })
                Text("${p.instrument} — budget ${MoneyFormat.format(p.budgetMinor, r.currency)}")
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { acceptBudget = !acceptBudget }) {
            Checkbox(checked = acceptBudget, onCheckedChange = { acceptBudget = it })
            Text("Accept the client's budget" + (position?.let { " (${MoneyFormat.format(it.budgetMinor, r.currency)})" } ?: ""))
        }
        if (!acceptBudget) Field(price, { price = it }, "Your price (US$)", keyboard = KeyboardType.Decimal, error = fields["price_minor"])
        Field(extras, { extras = it }, "Additional charges (optional, US$)", keyboard = KeyboardType.Decimal, error = fields["extras_minor"])
        if ((MoneyFormat.parse(extras) ?: 0L) > 0) Field(extrasNote, { extrasNote = it }, "What are the additional charges for?", error = fields["extras_note"])
        Field(included, { included = it }, "What's included (e.g. rehearsal, own instrument)", singleLine = false)
        Field(message, { message = it }, "Message to the client", singleLine = false, minLines = 3, error = fields["message"])
        val priceMinor = if (acceptBudget) position?.budgetMinor else MoneyFormat.parse(price)
        val extrasMinor = MoneyFormat.parse(extras) ?: 0L
        if (priceMinor != null) {
            val fee = MoneyFormat.fee(priceMinor + extrasMinor, catalog?.platformFeeBps ?: 1000)
            MoneyLine("You receive", priceMinor + extrasMinor, r.currency, strong = true)
            MoneyLine("Client pays (incl. service fee)", priceMinor + extrasMinor + fee, r.currency)
        }
        msg?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Spacer(Modifier.height(8.dp))
        PrimaryButton("Send offer", busy = busy, enabled = position != null && priceMinor != null && me?.hasMusicianProfile != false, onClick = {
            val body = OfferBody(positionId, acceptBudget, if (acceptBudget) null else priceMinor, extrasMinor, extrasNote.ifBlank { null },
                included.ifBlank { null }, message.ifBlank { null })
            vm.act(null, { c.api.submitOffer(r.id, body, idemKey) }) { onSubmitted() }
        })
    }
}

/** Offer detail. Client: compare terms, accept (with final total), counter or decline. Musician: edit, withdraw, answer a counter. */
@Composable
fun OfferScreen(id: Long, onBack: () -> Unit, onOpenMusician: (Long) -> Unit, onBooked: (Long) -> Unit, onOpenRequest: (Long) -> Unit) {
    val c = container()
    val vm: ResourceViewModel<Offer> = viewModel(key = "offer-$id") { ResourceViewModel { c.api.offer(id) } }
    val state by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    val me = c.session.me
    var confirmAccept by remember { mutableStateOf(false) }
    var counterOpen by remember { mutableStateOf(false) }
    var editOpen by remember { mutableStateOf(false) }
    var confirmWithdraw by remember { mutableStateOf(false) }
    var confirmReject by remember { mutableStateOf(false) }
    val acceptKey = rememberSaveable { UUID.randomUUID().toString() }

    AppScaffold("Offer", onBack = onBack, snackbar = snack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { o ->
            val isMusician = o.musicianId == me?.id
            val b = o.breakdown
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(o.requestTitle ?: "Offer", style = MaterialTheme.typography.headlineSmall, modifier = Modifier.clickable { onOpenRequest(o.requestId) })
                Row { StatusChip(Labels.human(o.status)); Spacer(Modifier.width(8.dp)); Text("Version ${o.version}", style = MaterialTheme.typography.labelMedium) }
                if (!isMusician) TextButton(onClick = { onOpenMusician(o.musicianId) }) { Text("View musician's profile, samples and reviews") }
                SectionCard(title = "Price") {
                    if (b != null) {
                        MoneyLine("Musician's price", b.priceMinor, o.currency)
                        if (b.extrasMinor > 0) MoneyLine("Additional charges", b.extrasMinor, o.currency)
                        o.extrasNote?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                        MoneyLine("Service fee (${b.feeBps / 100.0}%)", b.feeMinor, o.currency)
                        HorizontalDivider(Modifier.padding(vertical = 4.dp))
                        MoneyLine(if (isMusician) "Client pays" else "You pay", b.totalMinor, o.currency, strong = true)
                        if (isMusician) MoneyLine("You receive", b.musicianReceivesMinor, o.currency)
                    }
                    o.counterPriceMinor?.let {
                        Spacer(Modifier.height(6.dp))
                        Text("Counter-proposal: ${MoneyFormat.format(it, o.currency)} (price before extras and fee)" + (o.counterNote?.let { n -> " — “$n”" } ?: ""),
                            color = MaterialTheme.colorScheme.secondary)
                    }
                }
                SectionCard {
                    InfoRow("Included", o.includedServices)
                    InfoRow("Message", o.message)
                }
                if (o.status == "submitted") {
                    if (isMusician) {
                        if (o.counterPriceMinor != null) {
                            PrimaryButton("Accept ${MoneyFormat.format(o.counterPriceMinor, o.currency)}", busy = busy, onClick = {
                                vm.act("Counter accepted. The client can now confirm.", { c.api.counterResponse(id, CounterResponseBody(true)) }) { vm.replace(it) }
                            })
                            SecondaryButton("Decline counter-proposal", { vm.act("Counter declined.", { c.api.counterResponse(id, CounterResponseBody(false)) }) { vm.replace(it) } })
                        }
                        SecondaryButton("Edit offer", { editOpen = true })
                        SecondaryButton("Withdraw offer", { confirmWithdraw = true })
                    } else {
                        PrimaryButton("Book this musician", busy = busy, enabled = o.counterPriceMinor == null, onClick = { confirmAccept = true })
                        if (o.counterPriceMinor != null) Text("Waiting for the musician to answer your counter-proposal.", style = MaterialTheme.typography.bodySmall)
                        SecondaryButton("Propose a different price", { counterOpen = true })
                        SecondaryButton("Decline offer", { confirmReject = true })
                    }
                }
                if (o.bookingId != null) PrimaryButton("Open booking", { onBooked(o.bookingId) })
                if (o.history.isNotEmpty()) SectionCard(title = "History") {
                    o.history.forEach { h ->
                        Text("${TimeFormat.relative(h.at)} · ${Labels.human(h.kind)} by ${h.by} · ${MoneyFormat.format(h.priceMinor + h.extrasMinor, o.currency)}",
                            style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            if (confirmAccept && b != null) AlertDialog(
                onDismissRequest = { confirmAccept = false },
                title = { Text("Confirm booking") },
                text = {
                    Column {
                        Text("You're accepting version ${o.version} of this offer.")
                        Spacer(Modifier.height(8.dp))
                        MoneyLine("Price + extras", b.subtotalMinor, o.currency)
                        MoneyLine("Service fee", b.feeMinor, o.currency)
                        MoneyLine("Total", b.totalMinor, o.currency, strong = true)
                        Spacer(Modifier.height(8.dp))
                        Text("The cancellation policy shown in your booking applies from now. You can pay after confirming.", style = MaterialTheme.typography.bodySmall)
                    }
                },
                confirmButton = {
                    TextButton(onClick = {
                        confirmAccept = false
                        vm.act(null, { c.api.acceptOffer(id, AcceptBody(o.version), acceptKey) }) { booking -> onBooked(booking.id) }
                    }) { Text("Confirm booking") }
                },
                dismissButton = { TextButton(onClick = { confirmAccept = false }) { Text("Back") } },
            )
            if (counterOpen) PriceDialog("Propose a price", "The musician must accept before you can book at this price.", MoneyFormat.toInput(o.priceMinor),
                onDismiss = { counterOpen = false }) { cents, note ->
                counterOpen = false
                vm.act("Counter-proposal sent.", { c.api.counter(id, CounterBody(cents, note)) }) { vm.replace(it) }
            }
            if (editOpen) PriceDialog("Edit your price", "The client will see your updated offer.", MoneyFormat.toInput(o.priceMinor), onDismiss = { editOpen = false }) { cents, note ->
                editOpen = false
                vm.act("Offer updated.", { c.api.editOffer(id, EditOfferBody(cents, o.extrasMinor, o.extrasNote, o.includedServices, note ?: o.message)) }) { vm.replace(it) }
            }
        }
    }
    if (confirmWithdraw) ConfirmDialog("Withdraw offer?", "The client will no longer see it. You can send a new offer while the request is open.", "Withdraw",
        onConfirm = { confirmWithdraw = false; vm.act("Offer withdrawn.", { c.api.withdrawOffer(id) }) { vm.replace(it) } }, onDismiss = { confirmWithdraw = false }, destructive = true)
    if (confirmReject) ConfirmDialog("Decline this offer?", "The musician will be told you've chosen not to go ahead.", "Decline",
        onConfirm = { confirmReject = false; vm.act("Offer declined.", { c.api.rejectOffer(id) }) { vm.replace(it) } }, onDismiss = { confirmReject = false }, destructive = true)
}

@Composable
private fun PriceDialog(title: String, body: String, initial: String, onDismiss: () -> Unit, onSubmit: (Long, String?) -> Unit) {
    var price by rememberSaveable { mutableStateOf(initial) }
    var note by rememberSaveable { mutableStateOf("") }
    val cents = MoneyFormat.parse(price)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(Modifier.imePadding()) {
                Text(body)
                Field(price, { price = it }, "Price (US$)", keyboard = KeyboardType.Decimal, error = if (cents == null) "Enter an amount like 80 or 80.00" else null)
                Field(note, { note = it }, "Note (optional)", singleLine = false)
            }
        },
        confirmButton = { TextButton(enabled = cents != null, onClick = { cents?.let { onSubmit(it, note.ifBlank { null }) } }) { Text("Send") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
fun ReportDialog(targetType: String, targetId: Long, onDone: (String) -> Unit) {
    val c = container()
    val vm: ResourceViewModel<Unit> = viewModel(key = "report-$targetType-$targetId") { ResourceViewModel { } }
    var reason by rememberSaveable { mutableStateOf("spam") }
    var details by rememberSaveable { mutableStateOf("") }
    val busy by vm.busy.collectAsState()
    val err by vm.message.collectAsState()
    AlertDialog(
        onDismissRequest = { onDone("") },
        title = { Text("Report") },
        text = {
            Column {
                Text("Tell Blissful Studios what's wrong. Reports are confidential.")
                listOf("spam" to "Spam", "abuse" to "Abusive or offensive", "fraud" to "Scam or fraud", "safety" to "Safety concern", "other" to "Something else").forEach { (v, l) ->
                    Row(Modifier.fillMaxWidth().clickable { reason = v }, verticalAlignment = Alignment.CenterVertically) {
                        androidx.compose.material3.RadioButton(selected = reason == v, onClick = { reason = v }); Text(l)
                    }
                }
                Field(details, { details = it }, "Details (optional)", singleLine = false)
                err?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                vm.act(null, { c.api.report(ReportBody(targetType, targetId, reason, details.ifBlank { null })) }) { onDone("Thanks — our team will review this.") }
            }) { Text("Send report") }
        },
        dismissButton = { TextButton(onClick = { onDone("") }) { Text("Cancel") } },
    )
}
