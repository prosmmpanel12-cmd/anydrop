package com.anydrop.restaurant.network

import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Query

interface ApiService {

    @POST("auth/restaurant-login.php")
    suspend fun login(@Body body: LoginBody): Response<ApiResponse<LoginResult>>

    @POST("auth/restaurant-request-otp.php")
    suspend fun requestSignupOtp(@Body body: RequestOtpBody): Response<ApiResponse<RequestOtpResult>>

    @POST("auth/restaurant-verify-otp.php")
    suspend fun verifySignupOtp(@Body body: VerifyOtpBody): Response<ApiResponse<VerifyOtpResult>>

    @POST("auth/restaurant-signup.php")
    suspend fun signup(@Body body: SignupBody): Response<ApiResponse<SignupResult>>

    @GET("restaurant/orders-list.php")
    suspend fun getOrders(
        @Query("status") status: String? = null,
        @Query("page") page: Int = 1
    ): Response<ApiResponse<Paginated<Order>>>

    @GET("restaurant/orders-detail.php")
    suspend fun getOrder(@Query("id") orderId: Int): Response<ApiResponse<OrderResult>>

    @POST("restaurant/orders-accept.php")
    suspend fun acceptOrder(@Query("id") orderId: Int, @Body body: AcceptBody = AcceptBody()): Response<ApiResponse<OrderResult>>

    @POST("restaurant/orders-reject.php")
    suspend fun rejectOrder(@Query("id") orderId: Int, @Body body: RejectBody): Response<ApiResponse<OrderResult>>

    @POST("restaurant/orders-status.php")
    suspend fun updateStatus(@Query("id") orderId: Int, @Body body: StatusUpdateBody): Response<ApiResponse<OrderResult>>

    @GET("restaurant/dashboard.php")
    suspend fun getDashboard(): Response<ApiResponse<DashboardResult>>

    @POST("restaurant/status-update.php")
    suspend fun updateOperationalStatus(@Body body: OperationalStatusUpdateBody): Response<ApiResponse<OperationalStatusResult>>
}
