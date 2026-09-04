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
}
