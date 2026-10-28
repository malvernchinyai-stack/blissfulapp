package zw.co.blissfulevents.gigs.ui.auth

import android.os.Build
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Celebration
import androidx.compose.material.icons.filled.Handshake
import androidx.compose.material.icons.filled.LibraryMusic
import androidx.compose.material3.Checkbox
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.launch
import zw.co.blissfulevents.gigs.data.api.EmailBody
import zw.co.blissfulevents.gigs.data.api.LoginBody
import zw.co.blissfulevents.gigs.data.api.RegisterBody
import zw.co.blissfulevents.gigs.data.api.ResetBody
import zw.co.blissfulevents.gigs.data.api.VerifyBody
import zw.co.blissfulevents.gigs.ui.common.ActionViewModel
import zw.co.blissfulevents.gigs.ui.common.AppScaffold
import zw.co.blissfulevents.gigs.ui.common.EnvironmentBanner
import zw.co.blissfulevents.gigs.ui.common.Field
import zw.co.blissfulevents.gigs.ui.common.FormError
import zw.co.blissfulevents.gigs.ui.common.PasswordField
import zw.co.blissfulevents.gigs.ui.common.PrimaryButton
import zw.co.blissfulevents.gigs.ui.common.SecondaryButton
import zw.co.blissfulevents.gigs.ui.container
import zw.co.blissfulevents.gigs.ui.theme.Gold
import zw.co.blissfulevents.gigs.ui.theme.Plum

private val deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".take(100)

private data class Slide(val icon: androidx.compose.ui.graphics.vector.ImageVector, val title: String, val body: String)

private val slides = listOf(
    Slide(Icons.Filled.Celebration, "Live music for every occasion", "Church services, weddings, conferences and parties — post what you need and the right musicians come to you."),
    Slide(Icons.Filled.Handshake, "Compare offers, then choose", "Set your budget. Musicians respond with their price and message. Compare ratings, experience and availability."),
    Slide(Icons.Filled.LibraryMusic, "Musicians: get booked", "Show your instruments, samples and rates. Get notified about gigs near you and send offers in seconds."),
)

@Composable
fun WelcomeScreen(onSignUp: () -> Unit, onSignIn: () -> Unit) {
    val pager = rememberPagerState { slides.size }
    val scope = rememberCoroutineScope()
    Column(Modifier.fillMaxSize().background(Plum).systemBarsPadding()) {
        EnvironmentBanner()
        HorizontalPager(pager, Modifier.weight(1f)) { i ->
            val s = slides[i]
            Column(Modifier.fillMaxSize().padding(32.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.size(120.dp).background(Gold.copy(alpha = 0.18f), CircleShape), contentAlignment = Alignment.Center) {
                    Icon(s.icon, null, tint = Gold, modifier = Modifier.size(64.dp))
                }
                Spacer(Modifier.height(32.dp))
                Text(s.title, style = MaterialTheme.typography.headlineMedium, color = androidx.compose.ui.graphics.Color.White, textAlign = TextAlign.Center)
                Spacer(Modifier.height(12.dp))
                Text(s.body, style = MaterialTheme.typography.bodyLarge, color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.88f), textAlign = TextAlign.Center)
            }
        }
        Row(Modifier.fillMaxWidth().padding(8.dp), horizontalArrangement = Arrangement.Center) {
            repeat(slides.size) { i ->
                Box(Modifier.padding(4.dp).size(if (pager.currentPage == i) 10.dp else 8.dp)
                    .background(if (pager.currentPage == i) Gold else androidx.compose.ui.graphics.Color.White.copy(alpha = 0.4f), CircleShape))
            }
        }
        Column(Modifier.padding(24.dp)) {
            Text("Blissful Gigs · by Blissful Studios", color = Gold, style = MaterialTheme.typography.labelLarge, modifier = Modifier.align(Alignment.CenterHorizontally))
            Spacer(Modifier.height(16.dp))
            if (pager.currentPage < slides.size - 1) {
                PrimaryButton("Next", { scope.launch { pager.animateScrollToPage(pager.currentPage + 1) } })
            } else {
                PrimaryButton("Create an account", onSignUp)
            }
            Spacer(Modifier.height(8.dp))
            TextButton(onClick = onSignIn, modifier = Modifier.fillMaxWidth()) { Text("I already have an account", color = androidx.compose.ui.graphics.Color.White) }
        }
    }
}

@Composable
fun SignInScreen(onBack: () -> Unit, onForgot: () -> Unit, onNeedsVerify: (String) -> Unit, onSignUp: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "signin")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var email by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    AppScaffold("Sign in", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (c.session.expiredNotice) Text("You were signed out. Please sign in again.", color = MaterialTheme.colorScheme.error)
            FormError(error)
            Field(email, { email = it.trim() }, "Email", keyboard = KeyboardType.Email, error = error?.fields?.get("email"))
            PasswordField(password, { password = it })
            PrimaryButton("Sign in", busy = busy, enabled = email.isNotBlank() && password.isNotBlank(), onClick = {
                vm.act({ c.api.login(LoginBody(email, password, deviceName)) }) { c.session.signedIn(it) }
            })
            if (error?.code == "email_not_verified") {
                SecondaryButton("Enter my verification code", {
                    vm.act({ c.api.resendCode(EmailBody(email)) }) { onNeedsVerify(email) }
                })
            }
            TextButton(onClick = onForgot) { Text("Forgot password?") }
            TextButton(onClick = onSignUp) { Text("New here? Create an account") }
        }
    }
}

@Composable
fun SignUpScreen(onBack: () -> Unit, onVerify: (String) -> Unit, onOpenTerms: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "signup")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var name by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    var phone by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var role by rememberSaveable { mutableStateOf("client") }
    var accept by rememberSaveable { mutableStateOf(false) }
    val f = error?.fields.orEmpty()
    AppScaffold("Create account", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("How will you mainly use Blissful Gigs?", style = MaterialTheme.typography.titleMedium)
            listOf("client" to "I want to book musicians", "musician" to "I'm a musician offering my services").forEach { (value, label) ->
                Row(Modifier.fillMaxWidth().selectable(selected = role == value, onClick = { role = value }, role = Role.RadioButton).padding(vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically) {
                    RadioButton(selected = role == value, onClick = null)
                    Spacer(Modifier.size(8.dp))
                    Text(label)
                }
            }
            Text("You can use both roles with one account and switch any time.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            FormError(error?.takeIf { f.isEmpty() })
            Field(name, { name = it }, if (role == "client") "Your name or organisation" else "Your name", error = f["display_name"])
            Field(email, { email = it.trim() }, "Email", keyboard = KeyboardType.Email, error = f["email"])
            Field(phone, { phone = it }, "Mobile number (optional)", keyboard = KeyboardType.Phone, error = f["phone"],
                help = "Shared only with people you have a confirmed booking with.")
            PasswordField(password, { password = it }, error = f["password"], help = "At least 10 characters, with letters and a number.")
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.selectable(selected = accept, onClick = { accept = !accept }, role = Role.Checkbox)) {
                Checkbox(checked = accept, onCheckedChange = null)
                Text("I accept the terms of service and privacy policy")
            }
            TextButton(onClick = onOpenTerms) { Text("Read the terms and privacy policy") }
            PrimaryButton("Create account", busy = busy, enabled = accept && name.isNotBlank() && email.isNotBlank() && password.isNotBlank(), onClick = {
                vm.act({ c.api.register(RegisterBody(email, password, name.trim(), phone.ifBlank { null }, role == "musician", accept)) }) { onVerify(email) }
            })
        }
    }
}

@Composable
fun VerifyEmailScreen(email: String, onBack: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "verify-$email")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var code by rememberSaveable { mutableStateOf("") }
    var resent by rememberSaveable { mutableStateOf(false) }
    AppScaffold("Check your email", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("If $email can be used, we've sent a 6-digit code to it. Enter it below. Codes expire after 15 minutes.")
            FormError(error)
            Field(code, { v -> code = v.filter { it.isDigit() }.take(6) }, "6-digit code", keyboard = KeyboardType.NumberPassword)
            PrimaryButton("Verify and continue", busy = busy, enabled = code.length == 6, onClick = {
                vm.act({ c.api.verifyEmail(VerifyBody(email, code, deviceName)) }) { c.session.signedIn(it) }
            })
            TextButton(onClick = { vm.act({ c.api.resendCode(EmailBody(email)) }) { resent = true } }, enabled = !busy) {
                Text(if (resent) "Code sent again" else "Send a new code")
            }
        }
    }
}

@Composable
fun ForgotPasswordScreen(onBack: () -> Unit, onDone: () -> Unit) {
    val c = container()
    val vm: ActionViewModel = viewModel(key = "forgot")
    val busy by vm.busy.collectAsState()
    val error by vm.error.collectAsState()
    var email by rememberSaveable { mutableStateOf("") }
    var step by rememberSaveable { mutableStateOf(1) }
    var code by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var done by rememberSaveable { mutableStateOf(false) }
    AppScaffold("Reset password", onBack = onBack) { m ->
        Column(m.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            FormError(error)
            when {
                done -> {
                    Text("Your password has been changed and other devices were signed out. Sign in with your new password.")
                    PrimaryButton("Go to sign in", onDone)
                }
                step == 1 -> {
                    Text("Enter your account email. If it's registered, we'll send a reset code.")
                    Field(email, { email = it.trim() }, "Email", keyboard = KeyboardType.Email)
                    PrimaryButton("Send code", busy = busy, enabled = email.isNotBlank(), onClick = {
                        vm.act({ c.api.forgotPassword(EmailBody(email)) }) { step = 2 }
                    })
                }
                else -> {
                    Text("If $email is registered, a code is on its way.")
                    Field(code, { v -> code = v.filter { it.isDigit() }.take(6) }, "6-digit code", keyboard = KeyboardType.NumberPassword)
                    PasswordField(password, { password = it }, "New password", error = error?.fields?.get("new_password"),
                        help = "At least 10 characters, with letters and a number.")
                    PrimaryButton("Change password", busy = busy, enabled = code.length == 6 && password.isNotBlank(), onClick = {
                        vm.act({ c.api.resetPassword(ResetBody(email, code, password)) }) { done = true }
                    })
                }
            }
        }
    }
}
