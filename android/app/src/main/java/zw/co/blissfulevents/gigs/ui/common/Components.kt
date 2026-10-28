package zw.co.blissfulevents.gigs.ui.common

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.MusicNote
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.Verified
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import zw.co.blissfulevents.gigs.BuildConfig
import zw.co.blissfulevents.gigs.data.Failure
import zw.co.blissfulevents.gigs.data.api.Rating
import zw.co.blissfulevents.gigs.data.api.Verification
import zw.co.blissfulevents.gigs.ui.theme.Gold
import zw.co.blissfulevents.gigs.ui.theme.Ink
import zw.co.blissfulevents.gigs.util.MoneyFormat
import zw.co.blissfulevents.gigs.util.onlineFlow

/** Yellow strip on every non-production build so testers never confuse it with the real app. */
@Composable
fun EnvironmentBanner() {
    if (BuildConfig.ENVIRONMENT == "Production") return
    Box(Modifier.fillMaxWidth().background(Gold).padding(vertical = 2.dp), contentAlignment = Alignment.Center) {
        Text("${BuildConfig.ENVIRONMENT} · sample data · test payments only", color = Ink, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun OfflineBanner() {
    val context = LocalContext.current
    val online by remember { context.onlineFlow() }.collectAsState(initial = true)
    if (online) return
    Row(
        Modifier.fillMaxWidth().background(MaterialTheme.colorScheme.errorContainer).padding(horizontal = 16.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.CloudOff, null, tint = MaterialTheme.colorScheme.onErrorContainer, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text("You're offline. Changes can't be saved until you reconnect.", color = MaterialTheme.colorScheme.onErrorContainer, style = MaterialTheme.typography.bodySmall)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppScaffold(
    title: String,
    onBack: (() -> Unit)? = null,
    snackbar: SnackbarHostState? = null,
    actions: @Composable () -> Unit = {},
    bottomBar: @Composable () -> Unit = {},
    floating: @Composable () -> Unit = {},
    content: @Composable (Modifier) -> Unit,
) {
    Scaffold(
        topBar = {
            Column {
                EnvironmentBanner()
                TopAppBar(
                    title = { Text(title, maxLines = 1) },
                    navigationIcon = {
                        if (onBack != null) IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                    },
                    actions = { actions() },
                )
                OfflineBanner()
            }
        },
        bottomBar = bottomBar,
        floatingActionButton = floating,
        snackbarHost = { snackbar?.let { SnackbarHost(it) } },
    ) { padding -> content(Modifier.padding(padding)) }
}

/** Shows ViewModel messages once as a snackbar. */
@Composable
fun MessageEffect(message: String?, host: SnackbarHostState, consume: () -> Unit) {
    LaunchedEffect(message) {
        if (message != null) {
            host.showSnackbar(message)
            consume()
        }
    }
}

@Composable
fun LoadingBox(modifier: Modifier = Modifier, label: String = "Loading…") {
    Box(modifier.fillMaxSize().semantics { contentDescription = label }, contentAlignment = Alignment.Center) { CircularProgressIndicator() }
}

@Composable
fun ErrorBox(failure: Failure, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Icon(if (failure.offline) Icons.Filled.CloudOff else Icons.Filled.ErrorOutline, null, Modifier.size(48.dp), tint = MaterialTheme.colorScheme.error)
        Spacer(Modifier.height(12.dp))
        Text(failure.message, textAlign = TextAlign.Center, style = MaterialTheme.typography.bodyLarge)
        Spacer(Modifier.height(16.dp))
        Button(onClick = onRetry) { Text("Try again") }
    }
}

@Composable
fun EmptyBox(title: String, body: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null) {
    Column(modifier.fillMaxWidth().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(Icons.Filled.MusicNote, null, Modifier.size(48.dp), tint = MaterialTheme.colorScheme.secondary)
        Spacer(Modifier.height(12.dp))
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        Spacer(Modifier.height(6.dp))
        Text(body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
        if (action != null) { Spacer(Modifier.height(16.dp)); action() }
    }
}

/** Renders a UiState with loading / error+retry handling. */
@Composable
fun <T> Stateful(state: UiState<T>, onRetry: () -> Unit, modifier: Modifier = Modifier, content: @Composable (T) -> Unit) {
    when (state) {
        is UiState.Loading -> LoadingBox(modifier)
        is UiState.Failed -> ErrorBox(state.failure, onRetry, modifier)
        is UiState.Ready -> content(state.data)
    }
}

@Composable
fun SectionCard(modifier: Modifier = Modifier, title: String? = null, content: @Composable ColumnScope.() -> Unit) {
    Card(modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            if (title != null) { Text(title, style = MaterialTheme.typography.titleMedium); Spacer(Modifier.height(8.dp)) }
            content()
        }
    }
}

@Composable
fun InfoRow(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Column(Modifier.padding(vertical = 4.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(value, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
fun MoneyLine(label: String, minor: Long, currency: String, strong: Boolean = false) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label, style = if (strong) MaterialTheme.typography.titleSmall else MaterialTheme.typography.bodyMedium)
        Text(MoneyFormat.format(minor, currency), style = if (strong) MaterialTheme.typography.titleSmall else MaterialTheme.typography.bodyMedium,
            fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal)
    }
}

@Composable
fun StatusChip(text: String, color: Color = MaterialTheme.colorScheme.secondaryContainer) {
    Surface(color = color, shape = MaterialTheme.shapes.small) {
        Text(text, Modifier.padding(horizontal = 8.dp, vertical = 2.dp), style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
fun RatingText(r: Rating) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.semantics(mergeDescendants = true) {}) {
        Icon(Icons.Filled.Star, contentDescription = null, tint = Gold, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(2.dp))
        Text(if (r.count == 0) "New" else "${r.average} (${r.count})", style = MaterialTheme.typography.bodySmall)
    }
}

/** Only server-confirmed checks are shown, each with the exact explanation of what was checked. */
@Composable
fun VerificationBadges(items: List<Verification>) {
    var open by remember { mutableStateOf<Verification?>(null) }
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items.forEach { v ->
            AssistChip(
                onClick = { open = v },
                label = { Text(v.label) },
                leadingIcon = { Icon(Icons.Filled.Verified, null, Modifier.size(AssistChipDefaults.IconSize)) },
            )
        }
    }
    open?.let { v ->
        AlertDialog(onDismissRequest = { open = null }, confirmButton = { TextButton(onClick = { open = null }) { Text("OK") } },
            title = { Text(v.label) }, text = { Text(v.explains) })
    }
}

@Composable
fun Avatar(url: String?, name: String, size: Dp = 48.dp) {
    if (url != null) {
        AsyncImage(model = url, contentDescription = "Photo of $name", contentScale = ContentScale.Crop,
            modifier = Modifier.size(size).clip(CircleShape))
    } else {
        Box(Modifier.size(size).clip(CircleShape).background(MaterialTheme.colorScheme.primaryContainer), contentAlignment = Alignment.Center) {
            Text(name.trim().take(1).uppercase(), color = MaterialTheme.colorScheme.onPrimaryContainer, style = MaterialTheme.typography.titleMedium)
        }
    }
}

@Composable
fun Field(
    value: String,
    onChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    error: String? = null,
    help: String? = null,
    keyboard: KeyboardType = KeyboardType.Text,
    singleLine: Boolean = true,
    minLines: Int = 1,
    enabled: Boolean = true,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        isError = error != null,
        supportingText = if ((error ?: help) != null) ({ Text(error ?: help ?: "") }) else null,
        keyboardOptions = KeyboardOptions(keyboardType = keyboard),
        singleLine = singleLine,
        minLines = minLines,
        enabled = enabled,
        modifier = modifier.fillMaxWidth(),
    )
}

@Composable
fun PasswordField(value: String, onChange: (String) -> Unit, label: String = "Password", error: String? = null, help: String? = null) {
    var visible by rememberSaveable { mutableStateOf(false) }
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        isError = error != null,
        supportingText = if ((error ?: help) != null) ({ Text(error ?: help ?: "") }) else null,
        singleLine = true,
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        trailingIcon = {
            IconButton(onClick = { visible = !visible }) {
                Icon(if (visible) Icons.Filled.VisibilityOff else Icons.Filled.Visibility, if (visible) "Hide password" else "Show password")
            }
        },
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
fun PrimaryButton(text: String, onClick: () -> Unit, busy: Boolean = false, enabled: Boolean = true, modifier: Modifier = Modifier) {
    Button(onClick = onClick, enabled = enabled && !busy, modifier = modifier.fillMaxWidth().height(52.dp)) {
        if (busy) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
        else Text(text)
    }
}

@Composable
fun SecondaryButton(text: String, onClick: () -> Unit, enabled: Boolean = true, modifier: Modifier = Modifier) {
    OutlinedButton(onClick = onClick, enabled = enabled, modifier = modifier.fillMaxWidth().height(48.dp)) { Text(text) }
}

@Composable
fun FormError(failure: Failure?) {
    if (failure == null) return
    Surface(color = MaterialTheme.colorScheme.errorContainer, shape = MaterialTheme.shapes.small, modifier = Modifier.fillMaxWidth()) {
        Text(failure.message, Modifier.padding(12.dp), color = MaterialTheme.colorScheme.onErrorContainer)
    }
}

/** Confirmation dialog with an optional free-text field (cancellation reason, dispute details…). */
@Composable
fun ConfirmDialog(
    title: String,
    body: String,
    confirm: String,
    onConfirm: (String) -> Unit,
    onDismiss: () -> Unit,
    inputLabel: String? = null,
    minInput: Int = 0,
    destructive: Boolean = false,
) {
    var text by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                Text(body)
                if (inputLabel != null) {
                    Spacer(Modifier.height(12.dp))
                    OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text(inputLabel) }, minLines = 2, modifier = Modifier.fillMaxWidth())
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { onConfirm(text.trim()) }, enabled = inputLabel == null || text.trim().length >= minInput) {
                Text(confirm, color = if (destructive) MaterialTheme.colorScheme.error else Color.Unspecified)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Back") } },
    )
}
