package zw.co.blissfulevents.gigs.ui.account

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
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
import zw.co.blissfulevents.gigs.BuildConfig
import zw.co.blissfulevents.gigs.data.ApiResult
import zw.co.blissfulevents.gigs.data.api.BlockedUser
import zw.co.blissfulevents.gigs.data.api.ClientProfileBody
import zw.co.blissfulevents.gigs.data.api.MyProfiles
import zw.co.blissfulevents.gigs.data.api.NotificationPref
import zw.co.blissfulevents.gigs.data.api.PasswordBody
import zw.co.blissfulevents.gigs.data.api.PasswordChangeBody
import zw.co.blissfulevents.gigs.data.api.PrefsBody
import zw.co.blissfulevents.gigs.data.api.SupportBody
import zw.co.blissfulevents.gigs.ui.client.Dropdown
import zw.co.blissfulevents.gigs.ui.common.ActionViewModel
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.Avatar
import zw.co.blissfulevents.gigs.ui.common.ConfirmDialog
import zw.co.blissfulevents.gigs.ui.common.EmptyBox
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.FormError
import zw.co.blissfulevents.gigs.ui.common.MessageEffect
import zw.co.blissfulevents.gigs.ui.common.PasswordField
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.ResourceViewModel
import zw.co.blissfulevents.gigs.ui.common.SectionCard
import zw.co.blissfulevents.gigs.ui.common.Stateful
import zw.co.blissfulevents.gigs.ui.common.uploadUri
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.util.Labels

@Composable
fun AccountScreen(
    onEditClient: () -> Unit,
    onEditMusician: () -> Unit,
    onPrefs: () -> Unit,
    onPassword: () -> Unit,
    onBlocks: () -> Unit,
    onDelete: () -> Unit,
    onSupport: () -> Unit,
    onPrivacy: () -> Unit,
    onTerms: () -> Unit,
) {
    val c = container()
    val me = c.session.me
    val scope = rememberCoroutineScope()
    val snack = remember { SnackbarHostState() }
    var switching by remember { mutableStateOf(false) }
    var confirmSignOut by remember { mutableStateOf(false) }
    AppScaffold("Account", snackbar = snack) { m ->
        Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (me != null) SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Avatar(null, me.displayName, 56.dp)
                    Spacer(Modifier.width(12.dp))
                    Column {
                        Text(me.displayName, style = MaterialTheme.typography.titleMedium)
                        Text(me.email, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            SectionCard(title = "Mode") {
                val musician = me?.activeRole == "musician"
                Text(if (musician) "You're using Blissful Gigs as a musician." else "You're using Blissful Gigs as a client.")
                Spacer(Modifier.width(4.dp))
                PrimaryButton(if (musician) "Switch to booking musicians" else "Switch to musician mode", busy = switching, onClick = {
                    switching = true
                    scope.launch {
                        val r = c.session.switchRole(if (musician) "client" else "musician")
                        switching = false
                        if (r is ApiResult.Err) snack.showSnackbar(r.failure.message)
                    }
                })
            }
            SectionCard(title = "Profile") {
                MenuRow("Client profile (name, organisation, phone)", onEditClient)
                MenuRow(if (me?.hasMusicianProfile == true) "Musician profile, samples & verification" else "Create a musician profile", onEditMusician)
            }
            SectionCard(title = "Settings & privacy") {
                MenuRow("Notifications", onPrefs)
                MenuRow("Change password", onPassword)
                MenuRow("Blocked people", onBlocks)
                MenuRow("Privacy policy", onPrivacy)
                MenuRow("Terms of service", onTerms)
            }
            SectionCard(title = "Help") {
                MenuRow("Contact support", onSupport)
            }
            TextButton(onClick = { confirmSignOut = true }) { Text("Sign out") }
            TextButton(onClick = onDelete) { Text("Delete my account", color = MaterialTheme.colorScheme.error) }
            Text("Version ${BuildConfig.VERSION_NAME} · ${BuildConfig.ENVIRONMENT}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
    if (confirmSignOut) ConfirmDialog("Sign out?", "You'll stop receiving push notifications on this device.", "Sign out",
        onConfirm = { confirmSignOut = false; scope.launch { c.session.signOut() } }, onDismiss = { confirmSignOut = false })
}

@Composable
private fun MenuRow(label: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, Modifier.weight(1f))
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null)
    }
    HorizontalDivider()
}

@Composable
fun EditClientProfileScreen(onBack: () -> Unit) {
    val c = container()
    val context = LocalContext.current
    val catalog by c.session.catalog.collectAsState()
    val vm: ResourceViewModel<MyProfiles> = viewModel(key = "edit-client") { ResourceViewModel { c.api.myProfiles() } }
    val state by vm.state.collectAsState()
    val busy by vm.busy.collectAsState()
    val fields by vm.fieldErrors.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    AppScaffold("Client profile", onBack = onBack, snackbar = snack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { p ->
            val cp = p.client
            var name by rememberSaveable { mutableStateOf(cp.name) }
            var org by rememberSaveable { mutableStateOf(cp.orgType) }
            var city by rememberSaveable { mutableStateOf(cp.city.orEmpty()) }
            var area by rememberSaveable { mutableStateOf(cp.area.orEmpty()) }
            var bio by rememberSaveable { mutableStateOf(cp.bio.orEmpty()) }
            var phone by rememberSaveable { mutableStateOf(cp.phone.orEmpty()) }
            var photoId by rememberSaveable { mutableStateOf<Long?>(null) }
            var photoUrl by rememberSaveable { mutableStateOf(cp.photoUrl) }
            val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
                if (uri != null) vm.act("Photo uploaded — save to apply.", { uploadUri(context, c.api, uri, "avatar") }) { photoId = it.id; photoUrl = it.url }
            }
            Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Avatar(photoUrl, name, 64.dp)
                    TextButton(onClick = { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) { Text("Change photo") }
                }
                Field(name, { name = it }, "Name or organisation", error = fields["name"])
                Dropdown("Type", org, catalog?.orgTypes ?: listOf("individual", "church", "event_planner", "venue", "business", "organization"), { org = it }, Labels::human)
                Field(city, { city = it }, "City")
                Field(area, { area = it }, "Area")
                Field(phone, { phone = it }, "Mobile number", keyboard = KeyboardType.Phone, error = fields["phone"],
                    help = "Only shown to musicians you have a confirmed booking with.")
                Field(bio, { bio = it }, "About (optional)", singleLine = false, minLines = 3)
                PrimaryButton("Save", busy = busy, onClick = {
                    vm.act("Profile saved.", {
                        c.api.updateClient(ClientProfileBody(name.trim(), org, city.ifBlank { null }, area.ifBlank { null }, bio.ifBlank { null }, phone.ifBlank { null }, photoId))
                        c.api.myProfiles()
                    }) { vm.replace(it) }
                })
            }
        }
    }
}

@Composable
fun NotificationPrefsScreen(onBack: () -> Unit) {
    val c = container()
    val vm: ResourceViewModel<List<NotificationPref>> = viewModel(key = "prefs") { ResourceViewModel { c.api.prefs().items } }
    val state by vm.state.collectAsState()
    val msg by vm.message.collectAsState()
    val snack = remember { SnackbarHostState() }
    MessageEffect(msg, snack) { vm.consumeMessage() }
    val labels = mapOf("requests" to "New matching requests", "offers" to "Offers and counter-offers", "bookings" to "Booking updates and payments",
        "messages" to "Chat messages", "reminders" to "Event reminders", "disputes" to "Disputes", "account" to "Account and verification")
    AppScaffold("Notifications", onBack = onBack, snackbar = snack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { prefs ->
            Column(m.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
                if (!zw.co.blissfulevents.gigs.push.Push.enabled) Text("Push notifications are not set up in this test build. Everything still appears in your Inbox.",
                    color = MaterialTheme.colorScheme.secondary, style = MaterialTheme.typography.bodySmall)
                prefs.forEach { p ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(labels[p.category] ?: Labels.human(p.category), Modifier.weight(1f))
                        Switch(checked = p.pushEnabled, onCheckedChange = { on ->
                            vm.act(null, { c.api.updatePrefs(PrefsBody(listOf(NotificationPref(p.category, on)))).items }) { vm.replace(it) }
                        })
                    }
                    HorizontalDivider()
                }
                Text("Inbox history is always kept, whatever you choose here.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 12.dp))
            }
        }
    }
}

@Composable
fun ChangePasswordScreen(onBack: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "password")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var current by rememberSaveable { mutableStateOf("") }
    var next by rememberSaveable { mutableStateOf("") }
    var done by rememberSaveable { mutableStateOf(false) }
    AppScaffold("Change password", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (done) Text("Password changed. Your other devices have been signed out.", color = MaterialTheme.colorScheme.secondary)
            FormError(error?.takeIf { it.fields.isEmpty() })
            PasswordField(current, { current = it }, "Current password", error = error?.fields?.get("current_password"))
            PasswordField(next, { next = it }, "New password", error = error?.fields?.get("new_password"), help = "At least 10 characters, with letters and a number.")
            PrimaryButton("Change password", busy = busy, enabled = current.isNotBlank() && next.isNotBlank(), onClick = {
                vm.act({ c.api.changePassword(PasswordChangeBody(current, next)) }) { done = true; current = ""; next = "" }
            })
        }
    }
}

@Composable
fun BlockedUsersScreen(onBack: () -> Unit) {
    val c = container()
    val vm: ResourceViewModel<List<BlockedUser>> = viewModel(key = "blocks") { ResourceViewModel { c.api.blocks().items } }
    val state by vm.state.collectAsState()
    AppScaffold("Blocked people", onBack = onBack) { m ->
        Stateful(state, onRetry = { vm.load() }, modifier = m) { list ->
            if (list.isEmpty()) EmptyBox("Nobody blocked", "You can block someone from their profile.", m)
            else Column(m.padding(16.dp)) {
                list.forEach { u ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(u.name, Modifier.weight(1f))
                        TextButton(onClick = { vm.act("Unblocked.", { c.api.unblock(u.userId).items }) { vm.replace(it) } }) { Text("Unblock") }
                    }
                    HorizontalDivider()
                }
            }
        }
    }
}

@Composable
fun DeleteAccountScreen(onBack: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "delete")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var password by rememberSaveable { mutableStateOf("") }
    var confirm by remember { mutableStateOf(false) }
    AppScaffold("Delete account", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Deleting your account:", style = MaterialTheme.typography.titleMedium)
            Text("• removes your profiles, photos, samples and device tokens\n• cancels your open requests and withdraws open offers\n" +
                "• keeps booking, payment and review records in anonymised form for accounting and dispute purposes\n" +
                "• cannot be undone")
            Text("You need to cancel or complete active bookings first.", color = MaterialTheme.colorScheme.secondary)
            FormError(error?.takeIf { it.fields.isEmpty() })
            PasswordField(password, { password = it }, "Confirm with your password", error = error?.fields?.get("password"))
            PrimaryButton("Delete my account", busy = busy, enabled = password.isNotBlank(), onClick = { confirm = true })
        }
    }
    if (confirm) ConfirmDialog("Delete permanently?", "This can't be undone.", "Delete", destructive = true,
        onConfirm = { confirm = false; vm.act({ c.api.deleteAccount(PasswordBody(password)) }) { c.session.localSignOut() } },
        onDismiss = { confirm = false })
}

@Composable
fun SupportScreen(onBack: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "support")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var subject by rememberSaveable { mutableStateOf("") }
    var message by rememberSaveable { mutableStateOf("") }
    var sent by rememberSaveable { mutableStateOf(false) }
    AppScaffold("Support", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (sent) {
                Text("Message sent. Blissful Studios will reply to ${c.session.me?.email ?: "your email"}.")
                PrimaryButton("Done", onBack)
            } else {
                Text("For a problem with a specific booking, open the booking and use \"Report a problem\" — it pauses payout while we review.",
                    style = MaterialTheme.typography.bodySmall)
                FormError(error)
                Field(subject, { subject = it }, "Subject", error = error?.fields?.get("subject"))
                Field(message, { message = it }, "How can we help?", singleLine = false, minLines = 5, error = error?.fields?.get("message"))
                PrimaryButton("Send", busy = busy, enabled = subject.length >= 3 && message.length >= 10, onClick = {
                    vm.act({ c.api.support(SupportBody(subject.trim(), message.trim())) }) { sent = true }
                })
            }
        }
    }
}
