package zw.co.blissfulevents.gigs.ui

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AccountCircle
import androidx.compose.material.icons.filled.Event
import androidx.compose.material.icons.filled.LibraryMusic
import androidx.compose.material.icons.filled.LocalOffer
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.ViewList
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import zw.co.blissfulevents.gigs.AppContainer
import zw.co.blissfulevents.gigs.AuthState
import zw.co.blissfulevents.gigs.BlissfulApp
import zw.co.blissfulevents.gigs.BuildConfig
import zw.co.blissfulevents.gigs.push.Push
import zw.co.blissfulevents.gigs.ui.account.AccountScreen
import zw.co.blissfulevents.gigs.ui.account.BlockedUsersScreen
import zw.co.blissfulevents.gigs.ui.account.ChangePasswordScreen
import zw.co.blissfulevents.gigs.ui.account.DeleteAccountScreen
import zw.co.blissfulevents.gigs.ui.account.EditClientProfileScreen
import zw.co.blissfulevents.gigs.ui.account.NotificationPrefsScreen
import zw.co.blissfulevents.gigs.ui.account.SupportScreen
import zw.co.blissfulevents.gigs.ui.auth.ForgotPasswordScreen
import zw.co.blissfulevents.gigs.ui.auth.SignInScreen
import zw.co.blissfulevents.gigs.ui.auth.SignUpScreen
import zw.co.blissfulevents.gigs.ui.auth.VerifyEmailScreen
import zw.co.blissfulevents.gigs.ui.auth.WelcomeScreen
import zw.co.blissfulevents.gigs.ui.booking.BookingDetailScreen
import zw.co.blissfulevents.gigs.ui.booking.BookingsScreen
import zw.co.blissfulevents.gigs.ui.booking.ChatScreen
import zw.co.blissfulevents.gigs.ui.client.ClientRequestsScreen
import zw.co.blissfulevents.gigs.ui.client.CreateRequestScreen
import zw.co.blissfulevents.gigs.ui.client.SearchMusiciansScreen
import zw.co.blissfulevents.gigs.ui.common.ErrorBox
import zw.co.blissfulevents.gigs.ui.common.LoadingBox
import zw.co.blissfulevents.gigs.data.Failure
import zw.co.blissfulevents.gigs.ui.musician.EditMusicianProfileScreen
import zw.co.blissfulevents.gigs.ui.musician.FeedScreen
import zw.co.blissfulevents.gigs.ui.musician.MyOffersScreen
import zw.co.blissfulevents.gigs.ui.notifications.NotificationsScreen
import zw.co.blissfulevents.gigs.ui.profile.ClientPublicProfileScreen
import zw.co.blissfulevents.gigs.ui.profile.MusicianPublicProfileScreen
import zw.co.blissfulevents.gigs.ui.request.OfferScreen
import zw.co.blissfulevents.gigs.ui.request.RequestDetailScreen

@Composable
fun container(): AppContainer = (LocalContext.current.applicationContext as BlissfulApp).container

object Routes {
    const val WELCOME = "welcome"
    const val SIGN_IN = "signin"
    const val SIGN_UP = "signup"
    const val FORGOT = "forgot"
    const val VERIFY = "verify?email={email}"
    fun verify(email: String) = "verify?email=${Uri.encode(email)}"

    const val C_REQUESTS = "c/requests"
    const val C_SEARCH = "c/search"
    const val M_FEED = "m/feed"
    const val M_OFFERS = "m/offers"
    const val BOOKINGS = "bookings"
    const val INBOX = "inbox"
    const val ACCOUNT = "account"

    const val CREATE_REQUEST = "request/new"
    const val REQUEST = "request/{id}"
    fun request(id: Long) = "request/$id"
    const val OFFER = "offer/{id}"
    fun offer(id: Long) = "offer/$id"
    const val BOOKING = "booking/{id}"
    fun booking(id: Long) = "booking/$id"
    const val CHAT = "chat/{id}"
    fun chat(id: Long) = "chat/$id"
    const val MUSICIAN = "musician/{id}"
    fun musician(id: Long) = "musician/$id"
    const val CLIENT = "client/{id}"
    fun client(id: Long) = "client/$id"
    const val EDIT_MUSICIAN = "edit/musician"
    const val EDIT_CLIENT = "edit/client"
    const val PREFS = "settings/notifications"
    const val PASSWORD = "settings/password"
    const val BLOCKS = "settings/blocks"
    const val DELETE = "settings/delete"
    const val SUPPORT = "support"
}

private data class Tab(val route: String, val label: String, val icon: ImageVector)

private val clientTabs = listOf(
    Tab(Routes.C_REQUESTS, "Requests", Icons.Filled.ViewList),
    Tab(Routes.C_SEARCH, "Musicians", Icons.Filled.Search),
    Tab(Routes.BOOKINGS, "Bookings", Icons.Filled.Event),
    Tab(Routes.INBOX, "Inbox", Icons.Filled.Notifications),
    Tab(Routes.ACCOUNT, "Account", Icons.Filled.AccountCircle),
)
private val musicianTabs = listOf(
    Tab(Routes.M_FEED, "Gigs", Icons.Filled.LibraryMusic),
    Tab(Routes.M_OFFERS, "My offers", Icons.Filled.LocalOffer),
    Tab(Routes.BOOKINGS, "Bookings", Icons.Filled.Event),
    Tab(Routes.INBOX, "Inbox", Icons.Filled.Notifications),
    Tab(Routes.ACCOUNT, "Account", Icons.Filled.AccountCircle),
)

@Composable
fun AppRoot(deepLinkBookingId: Long?, deepLinkRequestId: Long?) {
    val c = container()
    val auth by c.session.state.collectAsState()
    when (val a = auth) {
        AuthState.Unknown -> LoadingBox(label = "Signing you in")
        AuthState.Unreachable -> ErrorBox(Failure("Can't reach Blissful Gigs. Check your connection.", "offline", offline = true), onRetry = { c.session.restore() })
        AuthState.SignedOut -> SignedOutNav()
        is AuthState.SignedIn -> androidx.compose.runtime.key(a.me.activeRole) { SignedInNav(a.me.activeRole, deepLinkBookingId, deepLinkRequestId) }
    }
}

@Composable
private fun SignedOutNav() {
    val nav = rememberNavController()
    val context = LocalContext.current
    NavHost(nav, startDestination = if (container().session.expiredNotice) Routes.SIGN_IN else Routes.WELCOME) {
        composable(Routes.WELCOME) { WelcomeScreen(onSignUp = { nav.navigate(Routes.SIGN_UP) }, onSignIn = { nav.navigate(Routes.SIGN_IN) }) }
        composable(Routes.SIGN_IN) {
            SignInScreen(onBack = { nav.popBackStack() }, onForgot = { nav.navigate(Routes.FORGOT) },
                onNeedsVerify = { nav.navigate(Routes.verify(it)) }, onSignUp = { nav.navigate(Routes.SIGN_UP) })
        }
        composable(Routes.SIGN_UP) {
            SignUpScreen(onBack = { nav.popBackStack() }, onVerify = { nav.navigate(Routes.verify(it)) },
                onOpenTerms = { openUrl(context, legalUrl("terms")) })
        }
        composable(Routes.VERIFY, arguments = listOf(navArgument("email") { type = NavType.StringType; defaultValue = "" })) { e ->
            VerifyEmailScreen(e.arguments?.getString("email").orEmpty(), onBack = { nav.popBackStack() })
        }
        composable(Routes.FORGOT) { ForgotPasswordScreen(onBack = { nav.popBackStack() }, onDone = { nav.popBackStack(Routes.SIGN_IN, inclusive = false) }) }
    }
}

@Composable
private fun SignedInNav(role: String, deepLinkBookingId: Long?, deepLinkRequestId: Long?) {
    val nav = rememberNavController()
    val tabs = if (role == "musician") musicianTabs else clientTabs
    val start = tabs.first().route
    val entry by nav.currentBackStackEntryAsState()
    val current = entry?.destination?.route

    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }
    LaunchedEffect(Unit) {
        if (Push.enabled && Build.VERSION.SDK_INT >= 33) permission.launch(Manifest.permission.POST_NOTIFICATIONS)
        deepLinkBookingId?.let { nav.navigate(Routes.booking(it)) }
        deepLinkRequestId?.let { nav.navigate(Routes.request(it)) }
    }

    Column(Modifier.fillMaxSize()) {
        androidx.compose.foundation.layout.Box(Modifier.weight(1f)) {
            AppNavHost(nav, start)
        }
        if (tabs.any { it.route == current }) {
            NavigationBar {
                tabs.forEach { t ->
                    NavigationBarItem(
                        selected = current == t.route,
                        onClick = {
                            nav.navigate(t.route) {
                                popUpTo(start) { saveState = true }
                                launchSingleTop = true
                                restoreState = true
                            }
                        },
                        icon = { Icon(t.icon, contentDescription = null) },
                        label = { Text(t.label) },
                    )
                }
            }
        }
    }
}

@Composable
private fun AppNavHost(nav: NavHostController, start: String) {
    val context = LocalContext.current
    val idArg = listOf(navArgument("id") { type = NavType.LongType })
    val back: () -> Unit = { nav.popBackStack() }
    NavHost(nav, startDestination = start, modifier = Modifier.padding(0.dp)) {
        composable(Routes.C_REQUESTS) { ClientRequestsScreen(onCreate = { nav.navigate(Routes.CREATE_REQUEST) }, onOpen = { nav.navigate(Routes.request(it)) }) }
        composable(Routes.C_SEARCH) { SearchMusiciansScreen(onOpen = { nav.navigate(Routes.musician(it)) }) }
        composable(Routes.M_FEED) { FeedScreen(onOpen = { nav.navigate(Routes.request(it)) }, onEditProfile = { nav.navigate(Routes.EDIT_MUSICIAN) }) }
        composable(Routes.M_OFFERS) { MyOffersScreen(onOpen = { nav.navigate(Routes.offer(it)) }) }
        composable(Routes.BOOKINGS) { BookingsScreen(onOpen = { nav.navigate(Routes.booking(it)) }) }
        composable(Routes.INBOX) {
            NotificationsScreen(
                onOpenBooking = { nav.navigate(Routes.booking(it)) },
                onOpenRequest = { nav.navigate(Routes.request(it)) },
                onOpenOffer = { nav.navigate(Routes.offer(it)) },
                onPrefs = { nav.navigate(Routes.PREFS) },
            )
        }
        composable(Routes.ACCOUNT) {
            AccountScreen(
                onEditClient = { nav.navigate(Routes.EDIT_CLIENT) },
                onEditMusician = { nav.navigate(Routes.EDIT_MUSICIAN) },
                onPrefs = { nav.navigate(Routes.PREFS) },
                onPassword = { nav.navigate(Routes.PASSWORD) },
                onBlocks = { nav.navigate(Routes.BLOCKS) },
                onDelete = { nav.navigate(Routes.DELETE) },
                onSupport = { nav.navigate(Routes.SUPPORT) },
                onPrivacy = { openUrl(context, legalUrl("privacy")) },
                onTerms = { openUrl(context, legalUrl("terms")) },
            )
        }
        composable(Routes.CREATE_REQUEST) { CreateRequestScreen(onBack = back, onCreated = { id -> nav.navigate(Routes.request(id)) { popUpTo(Routes.CREATE_REQUEST) { inclusive = true } } }) }
        composable(Routes.REQUEST, idArg) { e ->
            RequestDetailScreen(e.arguments!!.getLong("id"), onBack = back,
                onOpenOffer = { nav.navigate(Routes.offer(it)) },
                onOpenMusician = { nav.navigate(Routes.musician(it)) },
                onOpenClient = { nav.navigate(Routes.client(it)) },
                onOpenBooking = { nav.navigate(Routes.booking(it)) })
        }
        composable(Routes.OFFER, idArg) { e ->
            OfferScreen(e.arguments!!.getLong("id"), onBack = back,
                onOpenMusician = { nav.navigate(Routes.musician(it)) },
                onBooked = { nav.navigate(Routes.booking(it)) { popUpTo(Routes.OFFER) { inclusive = true } } },
                onOpenRequest = { nav.navigate(Routes.request(it)) })
        }
        composable(Routes.BOOKING, idArg) { e ->
            BookingDetailScreen(e.arguments!!.getLong("id"), onBack = back, onChat = { nav.navigate(Routes.chat(it)) },
                onOpenMusician = { nav.navigate(Routes.musician(it)) }, onOpenClient = { nav.navigate(Routes.client(it)) },
                onOpenUrl = { openUrl(context, it) })
        }
        composable(Routes.CHAT, idArg) { e -> ChatScreen(e.arguments!!.getLong("id"), onBack = back) }
        composable(Routes.MUSICIAN, idArg) { e -> MusicianPublicProfileScreen(e.arguments!!.getLong("id"), onBack = back, onOpenUrl = { openUrl(context, it) }) }
        composable(Routes.CLIENT, idArg) { e -> ClientPublicProfileScreen(e.arguments!!.getLong("id"), onBack = back) }
        composable(Routes.EDIT_MUSICIAN) { EditMusicianProfileScreen(onBack = back) }
        composable(Routes.EDIT_CLIENT) { EditClientProfileScreen(onBack = back) }
        composable(Routes.PREFS) { NotificationPrefsScreen(onBack = back) }
        composable(Routes.PASSWORD) { ChangePasswordScreen(onBack = back) }
        composable(Routes.BLOCKS) { BlockedUsersScreen(onBack = back) }
        composable(Routes.DELETE) { DeleteAccountScreen(onBack = back) }
        composable(Routes.SUPPORT) { SupportScreen(onBack = back) }
    }
}

fun legalUrl(page: String): String = BuildConfig.API_BASE_URL.removeSuffix("/").removeSuffix("/api") + "/" + page

/** Only https links are opened, in the user's browser (payment checkout, portfolio videos, legal pages). */
fun openUrl(context: android.content.Context, url: String) {
    if (!url.startsWith("https://") && !(BuildConfig.DEBUG && url.startsWith("http://10.0.2.2"))) return
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
}
