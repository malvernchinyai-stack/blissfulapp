package zw.co.blissfulevents.gigs.data.api

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/** Mirrors backend/src/Http/Api.php. Base URL ends with /api/. */
interface ApiService {
    // Auth
    @POST("auth/register") suspend fun register(@Body body: RegisterBody): StatusResponse
    @POST("auth/verify-email") suspend fun verifyEmail(@Body body: VerifyBody): TokenResponse
    @POST("auth/resend-code") suspend fun resendCode(@Body body: EmailBody): StatusResponse
    @POST("auth/login") suspend fun login(@Body body: LoginBody): TokenResponse
    @POST("auth/forgot-password") suspend fun forgotPassword(@Body body: EmailBody): StatusResponse
    @POST("auth/reset-password") suspend fun resetPassword(@Body body: ResetBody): StatusResponse
    @POST("auth/logout") suspend fun logout(): StatusResponse
    @GET("me") suspend fun me(): Me
    @POST("me/role") suspend fun switchRole(@Body body: RoleBody): Me
    @POST("me/password") suspend fun changePassword(@Body body: PasswordChangeBody): StatusResponse
    @POST("me/delete") suspend fun deleteAccount(@Body body: PasswordBody): StatusResponse

    // Catalogue & profiles
    @GET("catalog") suspend fun catalog(): Catalog
    @GET("profile") suspend fun myProfiles(): MyProfiles
    @PUT("profile/client") suspend fun updateClient(@Body body: ClientProfileBody): ClientProfile
    @PUT("profile/musician") suspend fun updateMusician(@Body body: MusicianProfileBody): MusicianProfile
    @GET("musicians") suspend fun searchMusicians(
        @Query("instrument_id") instrumentId: Int? = null,
        @Query("genre_id") genreId: Int? = null,
        @Query("city") city: String? = null,
        @Query("q") q: String? = null,
        @Query("min_rating") minRating: Int? = null,
        @Query("sort") sort: String? = null,
        @Query("page") page: Int = 1,
    ): Paged<MusicianSummary>
    @GET("musicians/{id}") suspend fun musician(@Path("id") id: Long): MusicianProfile
    @GET("clients/{id}") suspend fun client(@Path("id") id: Long): ClientProfile
    @POST("portfolio") suspend fun addPortfolio(@Body body: PortfolioBody): MusicianProfile
    @POST("verifications") suspend fun requestVerification(@Body body: VerificationBody): StatusResponse
    @GET("availability") suspend fun availability(): Items<AvailabilityBlock>
    @POST("availability") suspend fun addUnavailable(@Body body: UnavailableBody): Items<AvailabilityBlock>
    @DELETE("availability/{id}") suspend fun deleteUnavailable(@Path("id") id: Long): Items<AvailabilityBlock>
    @DELETE("portfolio/{id}") suspend fun deletePortfolio(@Path("id") id: Long): MusicianProfile

    @Multipart @POST("files")
    suspend fun upload(@Part("purpose") purpose: RequestBody, @Part file: MultipartBody.Part): UploadResult

    // Requests
    @POST("requests") suspend fun createRequest(@Body body: CreateRequestBody, @Header("Idempotency-Key") key: String): GigRequest
    @GET("requests/mine") suspend fun myRequests(@Query("status") status: String? = null, @Query("page") page: Int = 1): Paged<GigRequest>
    @GET("requests/feed") suspend fun feed(
        @Query("instrument_id") instrumentId: Int? = null,
        @Query("anywhere") anywhere: String? = null,
        @Query("page") page: Int = 1,
    ): Paged<GigRequest>
    @GET("requests/{id}") suspend fun request(@Path("id") id: Long): GigRequest
    @POST("requests/{id}/publish") suspend fun publish(@Path("id") id: Long): GigRequest
    @POST("requests/{id}/cancel") suspend fun cancelRequest(@Path("id") id: Long): GigRequest

    // Offers
    @GET("requests/{id}/offers") suspend fun offersFor(@Path("id") requestId: Long, @Query("sort") sort: String = "price", @Query("position_id") positionId: Long? = null): Items<Offer>
    @POST("requests/{id}/offers") suspend fun submitOffer(@Path("id") requestId: Long, @Body body: OfferBody, @Header("Idempotency-Key") key: String): Offer
    @GET("offers/mine") suspend fun myOffers(@Query("status") status: String = "submitted", @Query("page") page: Int = 1): Paged<Offer>
    @GET("offers/{id}") suspend fun offer(@Path("id") id: Long): Offer
    @PATCH("offers/{id}") suspend fun editOffer(@Path("id") id: Long, @Body body: EditOfferBody): Offer
    @POST("offers/{id}/withdraw") suspend fun withdrawOffer(@Path("id") id: Long): Offer
    @POST("offers/{id}/counter") suspend fun counter(@Path("id") id: Long, @Body body: CounterBody): Offer
    @POST("offers/{id}/counter-response") suspend fun counterResponse(@Path("id") id: Long, @Body body: CounterResponseBody): Offer
    @POST("offers/{id}/accept") suspend fun acceptOffer(@Path("id") id: Long, @Body body: AcceptBody, @Header("Idempotency-Key") key: String): Booking
    @POST("offers/{id}/reject") suspend fun rejectOffer(@Path("id") id: Long): Offer

    // Bookings
    @GET("bookings") suspend fun bookings(@Query("role") role: String, @Query("scope") scope: String, @Query("page") page: Int = 1): Paged<BookingSummary>
    @GET("bookings/{id}") suspend fun booking(@Path("id") id: Long): Booking
    @POST("bookings/{id}/done") suspend fun markDone(@Path("id") id: Long): Booking
    @POST("bookings/{id}/cancel") suspend fun cancelBooking(@Path("id") id: Long, @Body body: ReasonBody): Booking
    @POST("bookings/{id}/reschedule") suspend fun proposeReschedule(@Path("id") id: Long, @Body body: RescheduleBody): Booking
    @POST("bookings/{id}/reschedule/{pid}") suspend fun answerReschedule(@Path("id") id: Long, @Path("pid") pid: Long, @Body body: RescheduleActionBody): Booking
    @POST("bookings/{id}/dispute") suspend fun dispute(@Path("id") id: Long, @Body body: DisputeBody): Booking
    @POST("bookings/{id}/review") suspend fun review(@Path("id") id: Long, @Body body: ReviewBody): StatusOrId

    // Chat
    @GET("bookings/{id}/messages") suspend fun messages(@Path("id") id: Long, @Query("after") after: Long? = null, @Query("before") before: Long? = null): ChatPage
    @POST("bookings/{id}/messages") suspend fun sendMessage(@Path("id") id: Long, @Body body: MessageBody, @Header("Idempotency-Key") key: String): ChatMessage
    @GET("messages/unread") suspend fun unread(): Items<UnreadItem>

    // Payments
    @POST("bookings/{id}/payments") suspend fun startPayment(@Path("id") id: Long, @Header("Idempotency-Key") key: String): Payment
    @GET("bookings/{id}/payments") suspend fun payments(@Path("id") id: Long): Items<Payment>

    // Notifications
    @GET("notifications") suspend fun notifications(@Query("before") before: Long? = null): NotificationsPage
    @POST("notifications/read") suspend fun markRead(@Body body: ReadBody): StatusResponse
    @GET("notifications/prefs") suspend fun prefs(): Items<NotificationPref>
    @PUT("notifications/prefs") suspend fun updatePrefs(@Body body: PrefsBody): Items<NotificationPref>
    @POST("devices") suspend fun registerDevice(@Body body: DeviceBody): StatusResponse
    @POST("devices/remove") suspend fun removeDevice(@Body body: DeviceBody): StatusResponse

    // Safety & support
    @GET("blocks") suspend fun blocks(): Items<BlockedUser>
    @POST("blocks/{id}") suspend fun block(@Path("id") userId: Long): Items<BlockedUser>
    @DELETE("blocks/{id}") suspend fun unblock(@Path("id") userId: Long): Items<BlockedUser>
    @POST("reports") suspend fun report(@Body body: ReportBody): CreatedId
    @POST("support") suspend fun support(@Body body: SupportBody): StatusResponse
}

@kotlinx.serialization.Serializable
data class StatusOrId(val id: Long? = null, val status: String? = null, val rating: Int? = null)
