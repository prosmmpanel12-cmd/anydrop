package com.anydrop.rider.network

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
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
}
