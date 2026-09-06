package com.anydrop.rider.network

import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query


interface ApiService {

    @POST("auth/rider-request-otp.php")
    suspend fun requestOtp(@Body body: RequestOtpBody): Response<ApiResponse<RequestOtpResult>>

    @POST("auth/rider-verify-otp.php")
    suspend fun verifyOtp(@Body body: VerifyOtpBody): Response<ApiResponse<VerifyOtpResult>>

    @POST("auth/rider-signup.php")
    suspend fun signup(@Body body: SignupBody): Response<ApiResponse<SignupResult>>

    @GET("system/service-areas.php")
    suspend fun getServiceAreas(): Response<ApiResponse<ServiceAreasResult>>

    /** GET /api/v1/rider/me — re-checks current status without a full logout + OTP re-login.
     *  Requires Authorization: Bearer token (ApiClient attaches it from TokenManager). */
    @GET("rider/me.php")
    suspend fun getMe(): Response<ApiResponse<RiderMeResult>>

    /** POST /api/v1/rider/status — dashboard online/offline switch (Phase 3, doc 83).
     *  Backend rejects going online with 422 location_required if no location is on
     *  file yet — call updateLocation() first if this comes back with that error. */
    @POST("rider/status.php")
    suspend fun setOnlineStatus(@Body body: OnlineStatusBody): Response<ApiResponse<OnlineStatusResult>>

    /** POST /api/v1/rider/location — foreground location ping while dashboard is open
     *  and/or online (Phase 3, doc 83). No approval gate server-side. */
    @POST("rider/location.php")
    suspend fun updateLocation(@Body body: LocationBody): Response<ApiResponse<OkResult>>

    /** GET /api/v1/rider/orders-available — polled while online with no
     *  active delivery (Phase 3 R3, doc 85). */
    @GET("rider/orders-available.php")
    suspend fun getAvailableOffer(): Response<ApiResponse<OfferResult>>

    /** GET /api/v1/rider/orders-current — the rider's in-progress delivery,
     *  if any (Phase 3 R3, doc 85). */
    @GET("rider/orders-current.php")
    suspend fun getCurrentOrder(): Response<ApiResponse<CurrentOrderResult>>

    /** GET /api/v1/rider/orders-detail — the dedicated Rider Order Detail
     *  screen (deep-plan §9), built this session. Same active-delivery
     *  scope/ownership rule as getCurrentOrder() above but returns the
     *  fuller field set §9 lists (navigate/call info + distance). */
    @GET("rider/orders-detail.php")
    suspend fun getOrderDetail(@Query("id") orderId: Int): Response<ApiResponse<OrderDetailResult>>

    @POST("rider/orders-accept.php")
    suspend fun acceptOrder(@Query("id") orderId: Int): Response<ApiResponse<AcceptOrderResult>>

    @POST("rider/orders-reject.php")
    suspend fun rejectOrder(@Query("id") orderId: Int, @Body body: RejectOrderBody = RejectOrderBody()): Response<ApiResponse<OkResult>>

    /** POST /api/v1/rider/orders-pickup — confirms pickup at the restaurant.
     *  Deep-plan §11 V1: the backend advances rider_assigned straight to
     *  out_for_delivery in this one call, no separate "picked up" resting
     *  state on the wire (pickup/drop-off flow, this session). */
    @POST("rider/orders-pickup.php")
    suspend fun pickupOrder(@Query("id") orderId: Int): Response<ApiResponse<PickupOrderResult>>

    /** POST /api/v1/rider/orders-deliver — verifies the delivery OTP (if
     *  this order has one) and marks it delivered. Send an empty body
     *  when deliveryOtpRequired was false on the current-order card. */
    @POST("rider/orders-deliver.php")
    suspend fun deliverOrder(@Query("id") orderId: Int, @Body body: DeliverOrderBody): Response<ApiResponse<DeliverOrderResult>>

    /** GET /api/v1/rider/earnings-summary — today's earnings total + running
     *  balance owed to the rider (deep-plan §19-20). Backs the dashboard's
     *  "TODAY" card, previously a static ₹0 placeholder. */
    @GET("rider/earnings-summary.php")
    suspend fun getEarningsSummary(): Response<ApiResponse<EarningsSummaryResult>>

    // ---- Rider payout requests (deep-plan §21, migration 74). Direct-hit
    // .php filenames, same convention as every endpoint above — mirrors
    // the customer app's getWalletBankDetails()/saveWalletBankDetails()/
    // getWalletWithdrawalHistory()/requestWalletWithdrawal() exactly. ----

    @GET("rider/payout-bank-details-get.php")
    suspend fun getRiderBankDetails(): Response<ApiResponse<RiderBankDetailsResult>>

    @POST("rider/payout-bank-details-save.php")
    suspend fun saveRiderBankDetails(@Body body: SaveRiderBankDetailsBody): Response<ApiResponse<RiderBankDetailsResult>>

    @GET("rider/payout.php")
    suspend fun getRiderPayoutHistory(): Response<ApiResponse<RiderPayoutHistoryResult>>

    @POST("rider/payout.php")
    suspend fun requestRiderPayout(@Body body: RequestRiderPayoutBody): Response<ApiResponse<RequestRiderPayoutResult>>

    // ---- Rider documents (deep-plan §22, migration 75) ----

    /** GET /api/v1/rider/documents-get.php — current document-submission
     *  state (status/reject reason/presence flags/vehicle fields). Backs
     *  SubmitDocumentsActivity's initial load and ApplicationStatusActivity's
     *  "Manage Documents" button label. */
    @GET("rider/documents-get.php")
    suspend fun getRiderDocuments(): Response<ApiResponse<RiderDocumentsResult>>

    /** POST /api/v1/rider/documents-upload.php — multipart. idDoc is
     *  REQUIRED on every call, even a re-submission after a rejection —
     *  see documents-upload.php's own kdoc for why (an admin needs at
     *  least one document present to review at all). vehicleDoc/
     *  profilePhoto are optional file parts; vehicleType/vehicleNumber
     *  are optional plain-text parts, sent as "text/plain" RequestBody
     *  the same way any non-file multipart field is built with OkHttp —
     *  no prior example of this in the codebase (every other upload
     *  endpoint here is file-only), so this is the first of its kind. */
    @Multipart
    @POST("rider/documents-upload.php")
    suspend fun uploadRiderDocuments(
        @Part idDoc: MultipartBody.Part,
        @Part vehicleDoc: MultipartBody.Part? = null,
        @Part profilePhoto: MultipartBody.Part? = null,
        @Part("vehicle_type") vehicleType: RequestBody? = null,
        @Part("vehicle_number") vehicleNumber: RequestBody? = null
    ): Response<ApiResponse<RiderDocumentsUploadResult>>

    // ---- FCM push + notification bell (deep-plan §23, docs 99/100).
    // Four flat one-file-per-action endpoints, same split-by-action
    // convention as payout-bank-details-get.php/-save.php above — NOT
    // the customer/restaurant ?action=-routed notifications.php shape
    // (see doc 99/100's kdoc for why rider endpoints follow the flatter
    // convention). ----

    /** POST /api/v1/rider/fcm-token-update — called from
     *  RiderFirebaseMessagingService.onNewToken() and once right after
     *  login (a token minted before login has nothing to attach to). */
    @POST("rider/fcm-token-update.php")
    suspend fun updateFcmToken(@Body body: FcmTokenBody): Response<ApiResponse<FcmTokenResult>>

    @GET("rider/notifications-list.php")
    suspend fun getNotifications(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("unread_only") unreadOnly: String? = null
    ): Response<ApiResponse<NotificationsResult>>

    @POST("rider/notifications-read.php")
    suspend fun markNotificationRead(@Query("id") id: Int): Response<ApiResponse<MarkReadResult>>

    @POST("rider/notifications-read-all.php")
    suspend fun markAllNotificationsRead(): Response<ApiResponse<MarkAllReadResult>>
}
