package zw.co.blissfulevents.gigs.ui.musician

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
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
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.data.api.GigRequest
import zw.co.blissfulevents.gigs.data.api.MusicianProfile
import zw.co.blissfulevents.gigs.data.api.MusicianProfileBody
import zw.co.blissfulevents.gigs.data.api.MyProfiles
import zw.co.blissfulevents.gigs.data.api.Offer
import zw.co.blissfulevents.gigs.data.api.Paged
import zw.co.blissfulevents.gigs.data.api.PortfolioBody
import zw.co.blissfulevents.gigs.data.api.VerificationBody
import zw.co.blissfulevents.gigs.ui.client.RequestCard
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.Avatar
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SecondaryButton
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.StatusChip
import zw.co.blissfulevents.gigs.ui.common.UiState
import zw.co.blissfulevents.gigs.ui.common.uploadUri
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.util.Labels
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.TimeFormat

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FeedScreen(onOpen: (Long) -> Unit, onEditProfile: () -> Unit) {
    val c = container()
    val me = c.session.me
    var anywhere by rememberSaveable { mutableStateOf(false) }
    val vm: ResourceViewModel<Paged<GigRequest>> = viewModel(key = "feed-$anywhere") { ResourceViewModel { c.api.feed(anywhere = if (anywhere) "1" else null) } }
    val state by vm.state.collectAsState()
    AppScaffold("Gigs for you") { m ->
        Column(m.fillMaxSize()) {
            if (me?.hasMusicianProfile == false) {
                SectionCard(Modifier.padding(16.dp), title = "Set up your musician profile") {
                    Text("Add your instruments, area and rates so we can match you with requests.")
                    Spacer(Modifier.height(8.dp))
                    PrimaryButton("Create profile", onEditProfile)
                }
                return@Column
            }
            Row(Modifier.padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = !anywhere, onClick = { anywhere = false }, label = { Text("Within my travel range") })
                FilterChip(selected = anywhere, onClick = { anywhere = true }, label = { Text("Anywhere") })
            }
            PullToRefreshBox(isRefreshing = (state as? UiState.Ready)?.refreshing == true, onRefresh = { vm.load() }) {
                Stateful(state, onRetry = { vm.load() }) { page ->
                    if (page.items.isEmpty()) EmptyBox("No matching requests right now", "We'll notify you when a client needs your instrument nearby. Pull down to refresh.")
                    else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        items(page.items, key = { it.id }) { r -> RequestCard(r, onClick = { onOpen(r.id) }) }
                    }
                }
            }
        }
    }
}

@Composable
fun MyOffersScreen(onOpen: (Long) -> Unit) {
    val c = container()
    var status by rememberSaveable { mutableStateOf("submitted") }
    val vm: ResourceViewModel<Paged<Offer>> = viewModel(key = "my-offers-$status") { ResourceViewModel { c.api.myOffers(status) } }
    val state by vm.state.collectAsState()
    AppScaffold("My offers") { m ->
        Column(m.fillMaxSize()) {
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("submitted" to "Open", "accepted" to "Accepted", "rejected" to "Not selected", "withdrawn" to "Withdrawn", "expired" to "Expired").forEach { (v, l) ->
                    FilterChip(selected = status == v, onClick = { status = v }, label = { Text(l) })
                }
            }
            Stateful(state, onRetry = { vm.load() }) { page ->
                if (page.items.isEmpty()) EmptyBox("Nothing here", "Offers you send appear here so you can follow up, edit or withdraw them.")
                else LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(page.items, key = { it.id }) { o ->
                        Card(Modifier.fillMaxWidth().clickable { onOpen(o.id) }) {
                            Column(Modifier.padding(14.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(o.requestTitle ?: "Request", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                                    StatusChip(Labels.human(o.status))
                                }
                                Text("${o.instrument ?: ""} · ${o.startsAt?.let { TimeFormat.eventDateTime(it, o.timezone ?: "Africa/Harare") } ?: ""}",
                                    style = MaterialTheme.typography.bodySmall)
                                Text("Your offer: ${MoneyFormat.format(o.totalMinor, o.currency)}", style = MaterialTheme.typography.bodyMedium)
                                o.counterPriceMinor?.let {
                                    Text("Client proposed ${MoneyFormat.format(it, o.currency)} — tap to answer", color = MaterialTheme.colorScheme.secondary,
                                        style = MaterialTheme.typography.labelLarge)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun EditMusicianProfileScreen(onBack: () -> Unit) {
    val c = container()
    val context = LocalContext.current
    val catalog by c.session.catalog.collectAsState()
    val vm: ResourceViewModel<MyProfiles> = viewModel(key = "edit-musician") { ResourceViewModel { c.api.myProfiles() } }
    val state by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val fields by vm.fieldErrors.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }

    AppScaffold("Musician profile", onBack = onBack, snackbar = snack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { profiles ->
            val p: MusicianProfile? = profiles.musician
            var stage by rememberSaveable { mutableStateOf(p?.stageName ?: c.session.me?.displayName.orEmpty()) }
            var bio by rememberSaveable { mutableStateOf(p?.bio.orEmpty()) }
            var city by rememberSaveable { mutableStateOf(p?.city ?: "Harare") }
            var area by rememberSaveable { mutableStateOf(p?.area.orEmpty()) }
            var radius by rememberSaveable { mutableStateOf((p?.travelRadiusKm ?: 30).toString()) }
            var years by rememberSaveable { mutableStateOf((p?.yearsExperience ?: 0).toString()) }
            var rate by rememberSaveable { mutableStateOf(p?.typicalRateMinor?.takeIf { it > 0 }?.let { MoneyFormat.toInput(it) }.orEmpty()) }
            var eqProvided by rememberSaveable { mutableStateOf(p?.equipmentProvided.orEmpty()) }
            var eqRequired by rememberSaveable { mutableStateOf(p?.equipmentRequired.orEmpty()) }
            var accepting by rememberSaveable { mutableStateOf(p?.acceptingBookings ?: true) }
            var instruments by rememberSaveable { mutableStateOf(p?.instruments?.map { it.id }.orEmpty()) }
            var genres by rememberSaveable { mutableStateOf(p?.genres?.map { it.id }.orEmpty()) }
            var photoId by rememberSaveable { mutableStateOf<Long?>(null) }
            var photoUrl by rememberSaveable { mutableStateOf(p?.photoUrl) }
            var videoUrl by rememberSaveable { mutableStateOf("") }
            val scope = rememberCoroutineScope()

            val pickPhoto = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
                if (uri != null) vm.act("Photo uploaded — save to apply.", { uploadUri(context, c.api, uri, "avatar") }) { photoId = it.id; photoUrl = it.url }
            }
            val pickSamplePhoto = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
                if (uri != null) vm.act("Photo added.", {
                    val up = uploadUri(context, c.api, uri, "portfolio"); c.api.addPortfolio(PortfolioBody("photo", fileId = up.id)); c.api.myProfiles()
                }) { vm.replace(it) }
            }
            val pickAudio = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
                if (uri != null) vm.act("Audio sample added.", {
                    val up = uploadUri(context, c.api, uri, "portfolio"); c.api.addPortfolio(PortfolioBody("audio", fileId = up.id)); c.api.myProfiles()
                }) { vm.replace(it) }
            }
            val pickId = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
                if (uri != null) vm.act("ID submitted for review. Staff will check it and the document is deleted after review.", {
                    val up = uploadUri(context, c.api, uri, "verification")
                    c.api.requestVerification(VerificationBody("identity", up.id)); c.api.myProfiles()
                }) { vm.replace(it) }
            }

            Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                SectionCard(title = "About you") {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Avatar(photoUrl, stage, 64.dp)
                        Spacer(Modifier.width(12.dp))
                        TextButton(onClick = { pickPhoto.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) { Text("Change photo") }
                    }
                    Field(stage, { stage = it }, "Stage name", error = fields["stage_name"])
                    Field(bio, { bio = it }, "Biography", singleLine = false, minLines = 4, error = fields["bio"])
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Field(city, { city = it }, "City", Modifier.weight(1f), error = fields["city"])
                        Field(area, { area = it }, "Area", Modifier.weight(1f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Field(radius, { radius = it.filter(Char::isDigit).take(4) }, "Travel radius (km)", Modifier.weight(1f), keyboard = KeyboardType.Number)
                        Field(years, { years = it.filter(Char::isDigit).take(2) }, "Years experience", Modifier.weight(1f), keyboard = KeyboardType.Number)
                    }
                    Field(rate, { rate = it }, "Typical rate per event (US$)", keyboard = KeyboardType.Decimal,
                        error = if (rate.isNotBlank() && MoneyFormat.parse(rate) == null) "Enter an amount like 60 or 60.00" else fields["typical_rate_minor"])
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Accepting new bookings", Modifier.weight(1f))
                        Switch(checked = accepting, onCheckedChange = { accepting = it })
                    }
                }
                SectionCard(title = "Instruments & services") {
                    fields["instrument_ids"]?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        catalog?.instruments.orEmpty().forEach { i ->
                            FilterChip(selected = i.id in instruments, onClick = { instruments = if (i.id in instruments) instruments - i.id else instruments + i.id }, label = { Text(i.name) })
                        }
                    }
                }
                SectionCard(title = "Genres") {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        catalog?.genres.orEmpty().forEach { g ->
                            FilterChip(selected = g.id in genres, onClick = { genres = if (g.id in genres) genres - g.id else genres + g.id }, label = { Text(g.name) })
                        }
                    }
                }
                SectionCard(title = "Equipment") {
                    Field(eqProvided, { eqProvided = it }, "Equipment I bring", singleLine = false)
                    Field(eqRequired, { eqRequired = it }, "Equipment I need at the venue", singleLine = false)
                }
                PrimaryButton("Save profile", busy = busy, enabled = instruments.isNotEmpty() && (rate.isBlank() || MoneyFormat.parse(rate) != null), onClick = {
                    val body = MusicianProfileBody(stage.trim(), bio.ifBlank { null }, city.trim(), area.ifBlank { null }, radius.toIntOrNull() ?: 30, years.toIntOrNull() ?: 0,
                        MoneyFormat.parse(rate) ?: 0L, "USD", eqProvided.ifBlank { null }, eqRequired.ifBlank { null }, accepting, photoId, instruments, genres)
                    vm.act("Profile saved.", { c.api.updateMusician(body); c.api.myProfiles() }) { vm.replace(it); scope.launch { c.session.refreshMe() } }
                })
                if (p != null) {
                    SectionCard(title = "Samples (${p.portfolio.size}/20)") {
                        p.portfolio.forEach { item ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text("${Labels.human(item.kind)}: ${item.title ?: item.url.orEmpty().take(40)}", Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                                IconButton(onClick = { vm.act("Removed.", { c.api.deletePortfolio(item.id); c.api.myProfiles() }) { vm.replace(it) } }) {
                                    Icon(Icons.Filled.Delete, "Remove sample")
                                }
                            }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            TextButton(onClick = { pickSamplePhoto.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) { Text("+ Photo") }
                            TextButton(onClick = { pickAudio.launch("audio/*") }) { Text("+ Audio") }
                        }
                        Field(videoUrl, { videoUrl = it.trim() }, "Video link (https://…)", error = fields["url"])
                        TextButton(enabled = videoUrl.startsWith("https://"), onClick = {
                            vm.act("Video link added.", { c.api.addPortfolio(PortfolioBody("video_link", url = videoUrl)); c.api.myProfiles() }) { vm.replace(it); videoUrl = "" }
                        }) { Text("Add video link") }
                    }
                    SectionCard(title = "Verification") {
                        Text("Badges only appear after Blissful Studios staff complete a check. Each badge explains exactly what was checked.", style = MaterialTheme.typography.bodySmall)
                        p.verifications.forEach { v -> Text("✓ ${v.label}: ${v.explains}", style = MaterialTheme.typography.bodySmall) }
                        p.pendingVerifications.forEach { k -> Text("⏳ ${Labels.human(k)} check pending", style = MaterialTheme.typography.bodySmall) }
                        if (p.verifications.none { it.kind == "identity" } && "identity" !in p.pendingVerifications) {
                            SecondaryButton("Submit ID document for review", { pickId.launch("image/*") })
                        }
                        if (p.verifications.none { it.kind == "portfolio" } && "portfolio" !in p.pendingVerifications && p.portfolio.isNotEmpty()) {
                            SecondaryButton("Ask staff to review my samples", {
                                vm.act("Submitted for review.", { c.api.requestVerification(VerificationBody("portfolio")); c.api.myProfiles() }) { vm.replace(it) }
                            })
                        }
                    }
                }
                Spacer(Modifier.height(24.dp))
            }
        }
    }
}
