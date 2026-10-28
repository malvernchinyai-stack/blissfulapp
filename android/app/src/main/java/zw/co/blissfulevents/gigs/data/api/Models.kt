package zw.co.blissfulevents.gigs.data.api

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

// All money values are Long minor units (cents). All timestamps are ISO-8601 UTC strings from the server.
// Field names are mapped from snake_case by the Json naming strategy in ApiClient.

@Serializable data class Me(
    val id: Long,
    val email: String,
    val phone: String? = null,
    val displayName: String,
    val roles: List<String> = emptyList(),
    val activeRole: String = "client",
    val hasMusicianProfile: Boolean = false,
    val emailVerified: Boolean = false,
    val timezone: String = "Africa/Harare",
    val isStaff: Boolean = false,
)

@Serializable data class TokenResponse(val accessToken: String, val refreshToken: String, val expiresIn: Int, val user: Me)
@Serializable data class StatusResponse(val status: String)
@Serializable data class Named(val id: Int, val name: String, val slug: String? = null)
@Serializable data class Rating(val average: String? = null, val count: Int = 0)
@Serializable data class Verification(val kind: String, val label: String, val explains: String)
@Serializable data class ReviewItem(val rating: Int, val comment: String? = null, val author: String, val createdAt: String)
@Serializable data class PortfolioItem(val id: Long, val kind: String, val title: String? = null, val url: String? = null)
@Serializable data class Items<T>(val items: List<T>)
@Serializable data class Paged<T>(val items: List<T>, val page: Int = 1, val hasMore: Boolean = false)

@Serializable data class Catalog(
    val instruments: List<Named>,
    val genres: List<Named>,
    val orgTypes: List<String>,
    val eventTypes: List<String>,
    val currencies: List<String>,
    val platformFeeBps: Int,
    val cancellationPolicy: JsonElement? = null,
    val disputeWindowHours: Int = 48,
)

@Serializable data class MusicianProfile(
    val userId: Long,
    val stageName: String,
    val bio: String? = null,
    val city: String,
    val area: String? = null,
    val travelRadiusKm: Int = 0,
    val yearsExperience: Int = 0,
    val typicalRateMinor: Long = 0,
    val currency: String = "USD",
    val equipmentProvided: String? = null,
    val equipmentRequired: String? = null,
    val acceptingBookings: Boolean = true,
    val photoUrl: String? = null,
    val instruments: List<Named> = emptyList(),
    val genres: List<Named> = emptyList(),
    val portfolio: List<PortfolioItem> = emptyList(),
    val rating: Rating = Rating(),
    val completedBookings: Int = 0,
    val verifications: List<Verification> = emptyList(),
    val recentReviews: List<ReviewItem> = emptyList(),
    val lat: Double? = null,
    val lng: Double? = null,
    val pendingVerifications: List<String> = emptyList(),
)

@Serializable data class ClientProfile(
    val userId: Long,
    val name: String,
    val orgType: String = "individual",
    val city: String? = null,
    val area: String? = null,
    val bio: String? = null,
    val photoUrl: String? = null,
    val rating: Rating = Rating(),
    val completedBookings: Int = 0,
    val verifications: List<Verification> = emptyList(),
    val recentReviews: List<ReviewItem> = emptyList(),
    val phone: String? = null,
)

@Serializable data class MyProfiles(val client: ClientProfile, val musician: MusicianProfile? = null)

@Serializable data class MusicianSummary(
    val userId: Long,
    val stageName: String,
    val city: String,
    val area: String? = null,
    val photoUrl: String? = null,
    val instruments: List<String> = emptyList(),
    val yearsExperience: Int = 0,
    val typicalRateMinor: Long = 0,
    val currency: String = "USD",
    val rating: Rating = Rating(),
    val completedBookings: Int = 0,
)

@Serializable data class Position(
    val id: Long,
    val instrumentId: Int,
    val instrument: String,
    val quantity: Int,
    val filled: Int,
    val budgetMinor: Long,
    val status: String,
    val notes: String? = null,
    val offerCount: Int? = null,
)

/** Used for both list summaries and full detail; detail-only fields are optional. */
@Serializable data class GigRequest(
    val id: Long,
    val title: String,
    val eventType: String,
    val status: String,
    val startsAt: String,
    val timezone: String,
    val durationMinutes: Int,
    val city: String,
    val area: String? = null,
    val currency: String,
    val offerDeadline: String,
    val clientName: String = "",
    val clientId: Long = 0,
    val positions: List<Position> = emptyList(),
    val totalBudgetMinor: Long = 0,
    val createdAt: String? = null,
    val distanceKm: Int? = null,
    val inTravelRange: Boolean? = null,
    val myOfferStatus: String? = null,
    val scheduleConflict: Boolean? = null,
    val description: String? = null,
    val setupMinutes: Int? = null,
    val rehearsalNotes: String? = null,
    val venueName: String? = null,
    val equipmentAvailable: String? = null,
    val travelNotes: String? = null,
    val client: ClientProfile? = null,
    val isOwner: Boolean = false,
    val venueAddress: String? = null,
)

@Serializable data class Breakdown(
    val priceMinor: Long,
    val extrasMinor: Long,
    val subtotalMinor: Long,
    val feeBps: Int,
    val feeMinor: Long,
    val totalMinor: Long,
    val musicianReceivesMinor: Long,
)

@Serializable data class OfferMusician(
    val userId: Long,
    val stageName: String,
    val city: String? = null,
    val photoUrl: String? = null,
    val yearsExperience: Int = 0,
    val completedBookings: Int = 0,
    val rating: Rating = Rating(),
    val verifications: List<Verification> = emptyList(),
    val instruments: List<String> = emptyList(),
)

@Serializable data class OfferHistory(
    val kind: String,
    val by: String,
    val priceMinor: Long,
    val extrasMinor: Long,
    val note: String? = null,
    val version: Int,
    val at: String,
)

@Serializable data class Offer(
    val id: Long,
    val requestId: Long,
    val positionId: Long,
    val musicianId: Long,
    val priceMinor: Long,
    val extrasMinor: Long = 0,
    val extrasNote: String? = null,
    val totalMinor: Long,
    val currency: String,
    val includedServices: String? = null,
    val message: String? = null,
    val counterPriceMinor: Long? = null,
    val counterNote: String? = null,
    val status: String,
    val version: Int,
    val updatedAt: String? = null,
    val breakdown: Breakdown? = null,
    val musician: OfferMusician? = null,
    val available: Boolean? = null,
    val requestTitle: String? = null,
    val startsAt: String? = null,
    val city: String? = null,
    val timezone: String? = null,
    val instrument: String? = null,
    val bookingId: Long? = null,
    val history: List<OfferHistory> = emptyList(),
)

@Serializable data class BookingSummary(
    val id: Long,
    val title: String,
    val city: String,
    val instrument: String,
    val status: String,
    val startsAt: String,
    val endsAt: String,
    val timezone: String,
    val totalMinor: Long,
    val currency: String,
    val counterpartName: String,
    val paymentStatus: String,
)

@Serializable data class Party(
    val userId: Long,
    val stageName: String? = null,
    val name: String? = null,
    val orgType: String? = null,
    val photoUrl: String? = null,
    val phone: String? = null,
)

@Serializable data class RescheduleInfo(val id: Long, val proposedByMe: Boolean, val newStartsAt: String, val newDurationMinutes: Int, val note: String? = null)
@Serializable data class DisputeInfo(val id: Long, val status: String, val reason: String, val openedByMe: Boolean, val resolution: String? = null)
@Serializable data class CancelQuote(val hoursBeforeStart: Int, val chargeBps: Int, val chargeMinor: Long, val refundIfPaidMinor: Long, val currency: String)
@Serializable data class BookingActions(
    val cancel: Boolean = false,
    val reschedule: Boolean = false,
    val markDone: Boolean = false,
    val dispute: Boolean = false,
    val review: Boolean = false,
    val pay: Boolean = false,
    val chat: Boolean = false,
)

@Serializable data class Booking(
    val id: Long,
    val requestId: Long,
    val offerId: Long,
    val title: String,
    val eventType: String,
    val status: String,
    val myRole: String,
    val startsAt: String,
    val endsAt: String,
    val setupMinutes: Int = 0,
    val timezone: String,
    val city: String,
    val area: String? = null,
    val venueName: String? = null,
    val venueAddress: String? = null,
    val equipmentAvailable: String? = null,
    val travelNotes: String? = null,
    val rehearsalNotes: String? = null,
    val price: Breakdown,
    val currency: String,
    val musician: Party,
    val client: Party,
    val clientMarkedDone: Boolean = false,
    val musicianMarkedDone: Boolean = false,
    val completedAt: String? = null,
    val disputeWindowEndsAt: String? = null,
    val cancelReason: String? = null,
    val cancelledByMe: Boolean? = null,
    val cancellationChargeMinor: Long? = null,
    val cancellationPreview: CancelQuote? = null,
    val pendingReschedule: RescheduleInfo? = null,
    val dispute: DisputeInfo? = null,
    val paymentStatus: String = "unpaid",
    val can: BookingActions = BookingActions(),
    val version: Int = 1,
)

@Serializable data class ChatMessage(val id: Long, val mine: Boolean, val body: String? = null, val hidden: Boolean = false, val createdAt: String)
@Serializable data class ChatPage(val items: List<ChatMessage>, val chatOpen: Boolean = true)
@Serializable data class UnreadItem(val bookingId: Long, val unread: Int)

@Serializable data class Payment(
    val id: Long,
    val bookingId: Long,
    val provider: String,
    val mode: String,
    val isTestPayment: Boolean,
    val reference: String,
    val amountMinor: Long,
    val currency: String,
    val status: String,
    val refundedMinor: Long = 0,
    val checkoutUrl: String? = null,
    val paidAt: String? = null,
    val createdAt: String? = null,
)

@Serializable data class AppNotification(
    val id: Long,
    val type: String,
    val title: String,
    val body: String,
    val data: JsonObject = JsonObject(emptyMap()),
    val read: Boolean,
    val createdAt: String,
)

@Serializable data class NotificationsPage(val items: List<AppNotification>, val nextBeforeId: Long? = null, val unreadCount: Int = 0)
@Serializable data class NotificationPref(val category: String, val pushEnabled: Boolean)
@Serializable data class BlockedUser(val userId: Long, val name: String, val since: String)
@Serializable data class UploadResult(val id: Long, val mime: String, val sizeBytes: Long, val url: String)
@Serializable data class CreatedId(val id: Long, val status: String? = null)

@Serializable data class ErrorDetail(val code: String, val message: String, val fields: Map<String, String>? = null)
@Serializable data class ErrorBody(val error: ErrorDetail)

// ---------------------------------------------------------------- request bodies

@Serializable data class RegisterBody(val email: String, val password: String, val displayName: String, val phone: String? = null, val asMusician: Boolean, val acceptTerms: Boolean)
@Serializable data class VerifyBody(val email: String, val code: String, val deviceName: String)
@Serializable data class EmailBody(val email: String)
@Serializable data class LoginBody(val email: String, val password: String, val deviceName: String)
@Serializable data class RefreshBody(val refreshToken: String)
@Serializable data class ResetBody(val email: String, val code: String, val newPassword: String)
@Serializable data class RoleBody(val role: String)
@Serializable data class PasswordChangeBody(val currentPassword: String, val newPassword: String)
@Serializable data class PasswordBody(val password: String)

@Serializable data class ClientProfileBody(
    val name: String,
    val orgType: String,
    val city: String? = null,
    val area: String? = null,
    val bio: String? = null,
    val phone: String? = null,
    val photoFileId: Long? = null,
)

@Serializable data class MusicianProfileBody(
    val stageName: String,
    val bio: String? = null,
    val city: String,
    val area: String? = null,
    val travelRadiusKm: Int,
    val yearsExperience: Int,
    val typicalRateMinor: Long,
    val currency: String = "USD",
    val equipmentProvided: String? = null,
    val equipmentRequired: String? = null,
    val acceptingBookings: Boolean = true,
    val photoFileId: Long? = null,
    val instrumentIds: List<Int>,
    val genreIds: List<Int> = emptyList(),
)

@Serializable data class PositionBody(val instrumentId: Int, val quantity: Int, val budgetMinor: Long, val notes: String? = null)

@Serializable data class CreateRequestBody(
    val title: String,
    val eventType: String,
    val description: String? = null,
    val startsAt: String,
    val timezone: String,
    val durationMinutes: Int,
    val setupMinutes: Int,
    val rehearsalNotes: String? = null,
    val city: String,
    val area: String? = null,
    val venueName: String? = null,
    val venueAddressPrivate: String? = null,
    val currency: String,
    val equipmentAvailable: String? = null,
    val travelNotes: String? = null,
    val offerDeadline: String,
    val positions: List<PositionBody>,
    val publish: Boolean,
)

@Serializable data class OfferBody(
    val positionId: Long,
    val acceptBudget: Boolean,
    val priceMinor: Long? = null,
    val extrasMinor: Long = 0,
    val extrasNote: String? = null,
    val includedServices: String? = null,
    val message: String? = null,
)

@Serializable data class EditOfferBody(val priceMinor: Long, val extrasMinor: Long, val extrasNote: String? = null, val includedServices: String? = null, val message: String? = null)
@Serializable data class CounterBody(val priceMinor: Long, val note: String? = null)
@Serializable data class CounterResponseBody(val accept: Boolean)
@Serializable data class AcceptBody(val expectedVersion: Int)
@Serializable data class ReasonBody(val reason: String)
@Serializable data class RescheduleBody(val newStartsAt: String, val newDurationMinutes: Int? = null, val note: String? = null)
@Serializable data class RescheduleActionBody(val action: String)
@Serializable data class DisputeBody(val reason: String, val details: String)
@Serializable data class ReviewBody(val rating: Int, val comment: String? = null)
@Serializable data class MessageBody(val body: String)
@Serializable data class ReadBody(val ids: List<Long> = emptyList(), val all: Boolean = false)
@Serializable data class PrefsBody(val prefs: List<NotificationPref>)
@Serializable data class DeviceBody(val token: String)
@Serializable data class ReportBody(val targetType: String, val targetId: Long, val reason: String, val details: String? = null)
@Serializable data class SupportBody(val subject: String, val message: String)
@Serializable data class PortfolioBody(val kind: String, val url: String? = null, val fileId: Long? = null, val title: String? = null)
@Serializable data class VerificationBody(val kind: String, val documentFileId: Long? = null)
@Serializable data class EmptyBody(val ok: Boolean = true)
