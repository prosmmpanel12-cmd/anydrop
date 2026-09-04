package com.anydrop.rider.network

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST


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
}
