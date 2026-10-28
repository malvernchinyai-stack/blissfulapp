package zw.co.blissfulevents.gigs.ui.client

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Card
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import zw.co.blissfulevents.gigs.data.api.CreateRequestBody
import zw.co.blissfulevents.gigs.data.api.GigRequest
import zw.co.blissfulevents.gigs.data.api.MusicianSummary
import zw.co.blissfulevents.gigs.data.api.Named
import zw.co.blissfulevents.gigs.data.api.Paged
import zw.co.blissfulevents.gigs.data.api.PositionBody
import zw.co.blissfulevents.gigs.ui.common.ActionViewModel
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.Avatar
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.FormError
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.RatingText
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SecondaryButton
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.StatusChip
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.util.Labels
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneOffset
import java.util.UUID

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ClientRequestsScreen(onCreate: () -> Unit, onOpen: (Long) -> Unit) {
    val c = container()
    var filter by rememberSaveable { mutableStateOf("active") }
    val vm: ResourceViewModel<Paged<GigRequest>> = viewModel(key = "my-requests-$filter") { ResourceViewModel { c.api.myRequests(status = filter) } }
    val state by vm.state.collectAsState()
    AppScaffold("My requests", floating = {
        ExtendedFloatingActionButton(onClick = onCreate, icon = { Icon(Icons.Filled.Add, null) }, text = { Text("New request") })
    }) { m ->
        Column(m.fillMaxSize()) {
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("active" to "Active", "filled" to "Booked", "expired" to "Expired", "cancelled" to "Cancelled").forEach { (v, l) ->
                    FilterChip(selected = filter == v, onClick = { filter = v }, label = { Text(l) })
                }
            }
            PullToRefreshBox(isRefreshing = (state as? zw.co.blissfulevents.gigs.ui.common.UiState.Ready)?.refreshing == true, onRefresh = { vm.load() }) {
                Stateful(state, onRetry = { vm.load() }) { page ->
                    if (page.items.isEmpty()) {
                        EmptyBox("No requests here", "Post a request with your date, instruments and budget. Musicians will send you offers.") {
                            PrimaryButton("Post a request", onCreate)
                        }
                    } else {
                        LazyColumn(contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            items(page.items, key = { it.id }) { r -> RequestCard(r, onClick = { onOpen(r.id) }, showOffers = true) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun RequestCard(r: GigRequest, onClick: () -> Unit, showOffers: Boolean = false) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick)) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(r.title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                StatusChip(Labels.human(r.status))
            }
            Text(TimeFormat.eventDateTime(r.startsAt, r.timezone) + " · " + TimeFormat.duration(r.durationMinutes), style = MaterialTheme.typography.bodySmall)
            Text(listOfNotNull(r.area, r.city).joinToString(", ") + (r.distanceKm?.let { " · ~$it km away" } ?: ""), style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(6.dp))
            Text(r.positions.joinToString(" · ") { p -> "${p.instrument}${if (p.quantity > 1) " ×${p.quantity}" else ""} ${MoneyFormat.format(p.budgetMinor, r.currency)}" },
                style = MaterialTheme.typography.bodyMedium)
            if (showOffers) {
                val offers = r.positions.sumOf { it.offerCount ?: 0 }
                if (r.status == "open") Text("$offers open offer${if (offers == 1) "" else "s"} · ${TimeFormat.until(r.offerDeadline)}",
                    color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.labelLarge)
            }
            r.myOfferStatus?.let { Text("Your offer: ${Labels.human(it)}", color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.labelLarge) }
            if (r.scheduleConflict == true) Text("You have another booking at this time", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelMedium)
        }
    }
}

/** Draft state for one instrument position in the form. */
private class PositionDraft(instrument: Named? = null) {
    var instrument by mutableStateOf(instrument)
    var quantity by mutableStateOf("1")
    var budget by mutableStateOf("")
    var notes by mutableStateOf("")
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CreateRequestScreen(onBack: () -> Unit, onCreated: (Long) -> Unit) {
    val c = container()
    val catalog by c.session.catalog.collectAsState()
    val vm: ActionViewModel = viewModel(key = "create-request")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    val fe = error?.fields.orEmpty()
    // One idempotency key per form: a retry after a dropped connection can never create two requests.
    val idemKey = rememberSaveable { UUID.randomUUID().toString() }

    var title by rememberSaveable { mutableStateOf("") }
    var eventType by rememberSaveable { mutableStateOf("church_service") }
    var description by rememberSaveable { mutableStateOf("") }
    var date by rememberSaveable { mutableStateOf<String?>(null) }
    var time by rememberSaveable { mutableStateOf("09:00") }
    var duration by rememberSaveable { mutableStateOf("180") }
    var setup by rememberSaveable { mutableStateOf("30") }
    var rehearsal by rememberSaveable { mutableStateOf("") }
    var city by rememberSaveable { mutableStateOf("Harare") }
    var area by rememberSaveable { mutableStateOf("") }
    var venue by rememberSaveable { mutableStateOf("") }
    var address by rememberSaveable { mutableStateOf("") }
    var equipment by rememberSaveable { mutableStateOf("") }
    var travel by rememberSaveable { mutableStateOf("") }
    var deadlineDays by rememberSaveable { mutableStateOf("2") }
    var publish by rememberSaveable { mutableStateOf(true) }
    val positions = remember { mutableStateListOf(PositionDraft()) }
    var showDate by remember { mutableStateOf(false) }
    var showTime by remember { mutableStateOf(false) }
    val tz = "Africa/Harare"

    AppScaffold("New request", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            FormError(error?.takeIf { fe.isEmpty() })
            if (fe.isNotEmpty()) FormError(error?.copy(message = "Please fix the highlighted fields."))
            SectionCard(title = "The event") {
                Field(title, { title = it }, "Title", error = fe["title"], help = "e.g. Sunday service band, Wedding reception")
                Spacer(Modifier.height(8.dp))
                Dropdown("Event type", eventType, catalog?.eventTypes.orEmpty(), { eventType = it }, Labels::human)
                Spacer(Modifier.height(8.dp))
                Field(description, { description = it }, "Description (optional)", singleLine = false, minLines = 3, error = fe["description"])
            }
            SectionCard(title = "Date and time (Harare time)") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SecondaryButton(date ?: "Choose date", { showDate = true }, modifier = Modifier.weight(1f))
                    SecondaryButton(time, { showTime = true }, modifier = Modifier.weight(1f))
                }
                fe["starts_at"]?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Field(duration, { duration = it.filter(Char::isDigit) }, "Duration (min)", Modifier.weight(1f), keyboard = KeyboardType.Number, error = fe["duration_minutes"])
                    Field(setup, { setup = it.filter(Char::isDigit) }, "Setup (min)", Modifier.weight(1f), keyboard = KeyboardType.Number, error = fe["setup_minutes"])
                }
                Field(rehearsal, { rehearsal = it }, "Rehearsal needs (optional)", singleLine = false)
            }
            SectionCard(title = "Location") {
                Field(city, { city = it }, "City or town", error = fe["city"])
                Field(area, { area = it }, "Suburb / area (shown to musicians)")
                Field(venue, { venue = it }, "Venue name (optional)")
                Field(address, { address = it }, "Exact address (private)", help = "Only shared with musicians you book.", singleLine = false)
                Field(travel, { travel = it }, "Travel or parking notes (optional)", singleLine = false)
            }
            SectionCard(title = "Musicians needed") {
                Text("Add one position per instrument. Set how many players you need and your budget per player (USD).", style = MaterialTheme.typography.bodySmall)
                positions.forEachIndexed { i, p ->
                    Spacer(Modifier.height(8.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Position ${i + 1}", style = MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
                        if (positions.size > 1) IconButton(onClick = { positions.removeAt(i) }) { Icon(Icons.Filled.Delete, "Remove position ${i + 1}") }
                    }
                    Dropdown("Instrument or service", p.instrument?.name ?: "", catalog?.instruments.orEmpty().map { it.name },
                        { name -> p.instrument = catalog?.instruments?.firstOrNull { it.name == name } }) { it }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Field(p.quantity, { p.quantity = it.filter(Char::isDigit).take(2) }, "How many", Modifier.weight(1f), keyboard = KeyboardType.Number,
                            error = fe["positions.$i.quantity"])
                        Field(p.budget, { p.budget = it }, "Budget each (US$)", Modifier.weight(1.4f), keyboard = KeyboardType.Decimal,
                            error = fe["positions.$i.budget_minor"] ?: if (p.budget.isNotBlank() && MoneyFormat.parse(p.budget) == null) "Enter an amount like 50 or 50.00" else null)
                    }
                    fe["positions.$i.instrument_id"]?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                }
                if (positions.size < 10) TextButton(onClick = { positions.add(PositionDraft()) }) { Text("+ Add another instrument") }
                fe["positions"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                Field(equipment, { equipment = it }, "Equipment available at the venue", singleLine = false, help = "e.g. PA system, drum kit, keyboard stand")
            }
            SectionCard(title = "Offers") {
                Field(deadlineDays, { deadlineDays = it.filter(Char::isDigit).take(2) }, "Accept offers until (days before the event)",
                    keyboard = KeyboardType.Number, error = fe["offer_deadline"])
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Publish now (otherwise save as draft)", Modifier.weight(1f))
                    Switch(checked = publish, onCheckedChange = { publish = it })
                }
            }
            val totalBudget = positions.sumOf { (MoneyFormat.parse(it.budget) ?: 0L) * (it.quantity.toIntOrNull() ?: 1) }
            if (totalBudget > 0) Text("Total budget: ${MoneyFormat.format(totalBudget, "USD")} + ${(catalog?.platformFeeBps ?: 1000) / 100}% service fee on the offers you accept",
                style = MaterialTheme.typography.bodyMedium)
            PrimaryButton(if (publish) "Publish request" else "Save draft", busy = busy, onClick = {
                val d = date?.let { LocalDate.parse(it) }
                val t = runCatching { LocalTime.parse(time) }.getOrNull()
                val badPosition = positions.any { it.instrument == null || MoneyFormat.parse(it.budget) == null }
                when {
                    d == null || t == null -> vm.setError("Choose the event date and time.")
                    badPosition -> vm.setError("Choose an instrument and enter a budget for every position.")
                    else -> {
                        val startIso = TimeFormat.toIso(d, t, tz)
                        val deadline = TimeFormat.toIso(d.minusDays((deadlineDays.toLongOrNull() ?: 1L).coerceAtLeast(0)), t, tz)
                        val body = CreateRequestBody(
                            title = title.trim(), eventType = eventType, description = description.ifBlank { null }, startsAt = startIso, timezone = tz,
                            durationMinutes = duration.toIntOrNull() ?: 0, setupMinutes = setup.toIntOrNull() ?: 0, rehearsalNotes = rehearsal.ifBlank { null },
                            city = city.trim(), area = area.ifBlank { null }, venueName = venue.ifBlank { null }, venueAddressPrivate = address.ifBlank { null },
                            currency = "USD", equipmentAvailable = equipment.ifBlank { null }, travelNotes = travel.ifBlank { null }, offerDeadline = deadline,
                            positions = positions.map { PositionBody(it.instrument!!.id, it.quantity.toIntOrNull() ?: 1, MoneyFormat.parse(it.budget)!!, it.notes.ifBlank { null }) },
                            publish = publish,
                        )
                        vm.act({ c.api.createRequest(body, idemKey) }) { onCreated(it.id) }
                    }
                }
            })
            Spacer(Modifier.height(24.dp))
        }
    }

    if (showDate) {
        val state = rememberDatePickerState(initialSelectedDateMillis = date?.let { LocalDate.parse(it).atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli() })
        DatePickerDialog(
            onDismissRequest = { showDate = false },
            confirmButton = {
                TextButton(onClick = {
                    state.selectedDateMillis?.let { date = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString() }
                    showDate = false
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { showDate = false }) { Text("Cancel") } },
        ) { DatePicker(state) }
    }
    if (showTime) {
        val initial = runCatching { LocalTime.parse(time) }.getOrDefault(LocalTime.of(9, 0))
        val state = rememberTimePickerState(initialHour = initial.hour, initialMinute = initial.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = { showTime = false },
            confirmButton = { TextButton(onClick = { time = LocalTime.of(state.hour, state.minute).toString(); showTime = false }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { showTime = false }) { Text("Cancel") } },
            text = { TimePicker(state) },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun Dropdown(label: String, value: String, options: List<String>, onSelect: (String) -> Unit, display: (String) -> String) {
    var open by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = open, onExpandedChange = { open = it }) {
        OutlinedTextField(
            value = if (value.isBlank()) "" else display(value),
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = open) },
            modifier = Modifier.menuAnchor(MenuAnchorType.PrimaryNotEditable).fillMaxWidth(),
        )
        ExposedDropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            options.forEach { o -> DropdownMenuItem(text = { Text(display(o)) }, onClick = { onSelect(o); open = false }) }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SearchMusiciansScreen(onOpen: (Long) -> Unit) {
    val c = container()
    val catalog by c.session.catalog.collectAsState()
    var instrument by rememberSaveable { mutableStateOf<Int?>(null) }
    var q by rememberSaveable { mutableStateOf("") }
    var sort by rememberSaveable { mutableStateOf("rating") }
    var submittedQ by rememberSaveable { mutableStateOf("") }
    val vm: ResourceViewModel<Paged<MusicianSummary>> = viewModel(key = "search-$instrument-$sort-$submittedQ") {
        ResourceViewModel { c.api.searchMusicians(instrumentId = instrument, q = submittedQ.ifBlank { null }, sort = sort) }
    }
    val state by vm.state.collectAsState()
    val snack = remember { SnackbarHostState() }
    val msg by vm.message.collectAsState()
    MessageEffect(msg, snack) { vm.consumeMessage() }
    AppScaffold("Find musicians", snackbar = snack) { m ->
        Column(m.fillMaxSize()) {
            Row(Modifier.padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                Field(q, { q = it }, "Search by name", Modifier.weight(1f))
                TextButton(onClick = { submittedQ = q.trim() }) { Text("Search") }
            }
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = instrument == null, onClick = { instrument = null }, label = { Text("All") })
                catalog?.instruments.orEmpty().forEach { i -> FilterChip(selected = instrument == i.id, onClick = { instrument = i.id }, label = { Text(i.name) }) }
            }
            Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("rating" to "Top rated", "experience" to "Most experienced", "rate_low" to "Lowest rate").forEach { (v, l) ->
                    FilterChip(selected = sort == v, onClick = { sort = v }, label = { Text(l) })
                }
            }
            Stateful(state, onRetry = { vm.load() }) { page ->
                if (page.items.isEmpty()) EmptyBox("No musicians found", "Try another instrument, or post a request so musicians can come to you.")
                else LazyColumn(contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(page.items, key = { it.userId }) { mu ->
                        Card(Modifier.fillMaxWidth().clickable { onOpen(mu.userId) }) {
                            Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                Avatar(mu.photoUrl, mu.stageName)
                                Spacer(Modifier.width(12.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(mu.stageName, style = MaterialTheme.typography.titleMedium)
                                    Text(mu.instruments.joinToString(", "), style = MaterialTheme.typography.bodySmall)
                                    Text("${mu.city} · ${mu.yearsExperience} yrs · ${mu.completedBookings} gigs", style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                Column(horizontalAlignment = Alignment.End) {
                                    RatingText(mu.rating)
                                    if (mu.typicalRateMinor > 0) Text("from ${MoneyFormat.format(mu.typicalRateMinor, mu.currency)}", style = MaterialTheme.typography.labelMedium)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
