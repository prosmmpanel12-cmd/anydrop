package com.anydrop.restaurant.network

import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
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

    // ---- Menu Management (Tier 1, docs/18) ----

    @GET("restaurant/categories-list.php")
    suspend fun getCategories(): Response<ApiResponse<CategoriesListResult>>

    @POST("restaurant/categories-create.php")
    suspend fun createCategory(@Body body: CategoryCreateBody): Response<ApiResponse<CategoryResult>>

    @POST("restaurant/categories-update.php")
    suspend fun updateCategory(@Query("id") categoryId: Int, @Body body: CategoryUpdateBody): Response<ApiResponse<CategoryResult>>

    @POST("restaurant/categories-delete.php")
    suspend fun deleteCategory(@Query("id") categoryId: Int): Response<ApiResponse<Map<String, Any>>>

    @GET("restaurant/menu-items-list.php")
    suspend fun getMenuItems(
        @Query("category_id") categoryId: Int? = null,
        @Query("search") search: String? = null
    ): Response<ApiResponse<MenuItemsListResult>>

    @POST("restaurant/menu-items-create.php")
    suspend fun createMenuItem(@Body body: MenuItemCreateBody): Response<ApiResponse<MenuItemResult>>

    @POST("restaurant/menu-items-update.php")
    suspend fun updateMenuItem(@Query("id") itemId: Int, @Body body: MenuItemUpdateBody): Response<ApiResponse<MenuItemResult>>

    @POST("restaurant/menu-items-delete.php")
    suspend fun deleteMenuItem(@Query("id") itemId: Int): Response<ApiResponse<Map<String, Any>>>

    @Multipart
    @POST("restaurant/menu-item-photo-upload.php")
    suspend fun uploadMenuItemPhoto(@Part photo: MultipartBody.Part): Response<ApiResponse<MenuItemPhotoUploadResult>>

    @Multipart
    @POST("restaurant/category-photo-upload.php")
    suspend fun uploadCategoryPhoto(@Part photo: MultipartBody.Part): Response<ApiResponse<CategoryPhotoUploadResult>>

    // ---- Account tab / Edit Profile (docs/restorent/19 §7, §10 item 5) ----

    @GET("restaurant/profile-get.php")
    suspend fun getProfile(): Response<ApiResponse<ProfileResult>>

    @POST("restaurant/profile-update.php")
    suspend fun updateProfile(@Body body: ProfileUpdateBody): Response<ApiResponse<ProfileResult>>

    @Multipart
    @POST("restaurant/logo-upload.php")
    suspend fun uploadLogo(@Part logo: MultipartBody.Part): Response<ApiResponse<LogoUploadResult>>

    // ---- Restaurant banners (app-owner feedback item #3, 2026-08-17) ----

    @GET("restaurant/banners-list.php")
    suspend fun getBanners(): Response<ApiResponse<BannersListResult>>

    @Multipart
    @POST("restaurant/banner-upload.php")
    suspend fun uploadBanner(@Part banner: MultipartBody.Part): Response<ApiResponse<BannerUploadResult>>

    @POST("restaurant/banner-delete.php")
    suspend fun deleteBanner(@Body body: BannerDeleteBody): Response<ApiResponse<Map<String, Any>>>

    // ---- Restaurant coupons (doc 07 §2.1, this session) ----

    @GET("restaurant/coupons-list.php")
    suspend fun getCoupons(): Response<ApiResponse<CouponsListResult>>

    @POST("restaurant/coupons-create.php")
    suspend fun createCoupon(@Body body: CouponCreateBody): Response<ApiResponse<CouponResult>>

    @POST("restaurant/coupons-update.php")
    suspend fun updateCoupon(@Query("id") id: Int, @Body body: CouponUpdateBody): Response<ApiResponse<CouponResult>>
}
