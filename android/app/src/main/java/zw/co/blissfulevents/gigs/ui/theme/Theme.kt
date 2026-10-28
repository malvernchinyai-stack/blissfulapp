package zw.co.blissfulevents.gigs.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// Blissful Gigs palette: deep plum (stage curtain) + warm gold (brass). Contrast ratios checked ≥ 4.5:1 for body text.
val Plum = Color(0xFF3B1F4A)
val PlumLight = Color(0xFF5A2F6E)
val Gold = Color(0xFFE0A526)
val GoldDark = Color(0xFF8A5A00)
val Ink = Color(0xFF1D1A20)
val Paper = Color(0xFFFBF8FC)
val Success = Color(0xFF2E7D32)
val Danger = Color(0xFFB3261E)

private val Light = lightColorScheme(
    primary = Plum, onPrimary = Color.White,
    primaryContainer = Color(0xFFF1E3F6), onPrimaryContainer = Plum,
    secondary = GoldDark, onSecondary = Color.White,
    secondaryContainer = Color(0xFFFFE8B8), onSecondaryContainer = Color(0xFF3A2600),
    tertiary = Color(0xFF00696E), onTertiary = Color.White,
    background = Paper, onBackground = Ink,
    surface = Color.White, onSurface = Ink,
    surfaceVariant = Color(0xFFF2EDF4), onSurfaceVariant = Color(0xFF4E4754),
    outline = Color(0xFF7F7686),
    error = Danger, onError = Color.White,
)

private val Dark = darkColorScheme(
    primary = Color(0xFFE2B8F2), onPrimary = Color(0xFF3B1F4A),
    primaryContainer = PlumLight, onPrimaryContainer = Color(0xFFF7E7FD),
    secondary = Gold, onSecondary = Color(0xFF3A2600),
    secondaryContainer = Color(0xFF5C4100), onSecondaryContainer = Color(0xFFFFDEA0),
    background = Color(0xFF151217), onBackground = Color(0xFFE9E0EC),
    surface = Color(0xFF1E1A21), onSurface = Color(0xFFE9E0EC),
    surfaceVariant = Color(0xFF2C2630), onSurfaceVariant = Color(0xFFCFC4D5),
    error = Color(0xFFFFB4AB), onError = Color(0xFF690005),
)

private val Type = Typography().let { t ->
    t.copy(
        headlineMedium = t.headlineMedium.copy(fontWeight = FontWeight.Bold, letterSpacing = (-0.5).sp),
        titleLarge = t.titleLarge.copy(fontWeight = FontWeight.SemiBold),
        titleMedium = t.titleMedium.copy(fontWeight = FontWeight.SemiBold),
        bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 24.sp),
        bodyMedium = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
        labelLarge = t.labelLarge.copy(fontWeight = FontWeight.SemiBold),
    )
}

private val AppShapes = Shapes(
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(20.dp),
)

@Composable
fun BlissfulTheme(dark: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (dark) Dark else Light, typography = Type, shapes = AppShapes, content = content)
}
