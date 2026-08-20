package com.anydrop.food.network

import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Query

interface ApiService {

    @GET("system/app-version.php")
    suspend fun getAppVersion(@Query("platform") platform: String = "customer"): Response<ApiResponse<AppVersionInfo>>

    @GET("system/splash-config.php")
    suspend fun getSplashConfig(): Response<ApiResponse<SplashConfig>>

    @POST("auth/customer-request-otp.php")
    suspend fun requestOtp(@Body body: RequestOtpBody): Response<ApiResponse<MessageOnly>>

    @POST("auth/customer-verify-otp.php")
    suspend fun verifyOtp(@Body body: VerifyOtpBody): Response<ApiResponse<AuthResult>>

    @GET("restaurants/list.php")
    suspend fun getRestaurants(
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null,
        @Query("filter") filter: String? = null,
        @Query("veg_only") vegOnly: String? = null,
        @Query("sort") sort: String? = "rating",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int? = null
    ): Response<ApiResponse<Paginated<Restaurant>>>

    // features.md §6 — lat/lng optional, drives restaurant.distance_km /
    // estimated_delivery_minutes in the response when present; menu.php
    // still returns the full menu without them (both default null).
    @GET("restaurants/menu.php")
    suspend fun getMenu(
        @Query("id") restaurantId: Int,
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null
    ): Response<ApiResponse<MenuResponse>>

    @GET("search/search.php")
    suspend fun search(
        @Query("q") query: String,
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null
    ): Response<ApiResponse<SearchResponse>>

    // ---- Home categories row (Pizza / Rolls / Burger...) ----

    @GET("home/categories.php")
    suspend fun getHomeCategories(): Response<ApiResponse<List<FoodCategory>>>

    @GET("home/category-items.php")
    suspend fun getCategoryItems(
        @Query("slug") slug: String,
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null,
        @Query("veg_only") vegOnly: String? = null,
        @Query("filter") filter: String? = null
    ): Response<ApiResponse<CategoryItemsResult>>

    // ---- Phase 3: Cart / Checkout ----

    @POST("cart/validate.php")
    suspend fun validateCart(@Body body: CartValidateBody): Response<ApiResponse<CartTotals>>

    // ---- H5: Checkout "View all offers & coupons" page ----

    @GET("coupons/list.php")
    suspend fun getCoupons(
        @Query("restaurant_id") restaurantId: Int,
        @Query("item_total") itemTotal: Double? = null
    ): Response<ApiResponse<CouponListResult>>

    @POST("orders/create.php")
    suspend fun createOrder(@Body body: CreateOrderBody): Response<ApiResponse<CreateOrderResult>>

    @GET("orders/detail.php")
    suspend fun getOrder(@Query("id") orderId: Int): Response<ApiResponse<OrderDetailResult>>

    @GET("orders/list.php")
    suspend fun getOrderHistory(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15
    ): Response<ApiResponse<OrderHistoryResult>>

    @GET("orders/track.php")
    suspend fun trackOrder(@Query("id") orderId: Int): Response<ApiResponse<OrderTrackResult>>

    @POST("orders/cancel.php")
    suspend fun cancelOrder(@Query("id") orderId: Int): Response<ApiResponse<OrderDetailResult>>

    // ---- Cart server-persistence ----

    @GET("customer/cart-sync.php")
    suspend fun getCartSync(): Response<ApiResponse<CartSyncResult>>

    @POST("customer/cart-sync.php")
    suspend fun saveCartSync(@Body body: CartSyncBody): Response<ApiResponse<CartSyncSaveResult>>

    // ---- Phase 3: Addresses ----

    @GET("customer/addresses.php")
    suspend fun getAddresses(): Response<ApiResponse<AddressListResult>>

    @POST("customer/addresses.php")
    suspend fun addAddress(@Body body: AddAddressBody): Response<ApiResponse<AddAddressResult>>

    @PUT("customer/addresses.php")
    suspend fun updateAddress(
        @Query("id") addressId: Int,
        @Body body: AddAddressBody
    ): Response<ApiResponse<UpdateAddressResult>>

    // H6 part 2 — door/building photo, map pin-drop screen. First (and
    // only, so far) multipart upload in the app — no existing base64/image
    // upload pattern was found in the backend to reuse (see
    // address-photo.php's kdoc), so this is a new plain multipart POST.
    @Multipart
    @POST("customer/address-photo.php")
    suspend fun uploadAddressPhoto(
        @Part photo: MultipartBody.Part
    ): Response<ApiResponse<AddressPhotoUploadResult>>

    @HTTP(method = "DELETE", path = "customer/addresses.php")
    suspend fun deleteAddress(@Query("id") addressId: Int): Response<ApiResponse<DeleteAddressResult>>

    // ---- Phase 3.6: Promo carousel ----

    @GET("home/promo-banners.php")
    suspend fun getPromoBanners(): Response<ApiResponse<PromoBannersResult>>

    // ---- Phase 3.6: Popular dishes near you ----

    @GET("home/popular-items.php")
    suspend fun getPopularItems(
        @Query("lat") lat: Double? = null,
        @Query("lng") lng: Double? = null,
        @Query("limit") limit: Int = 15
    ): Response<ApiResponse<PopularItemsResult>>

    // ---- Phase 3.6: Favorites / bookmarks ----

    @GET("customer/favorites.php")
    suspend fun getFavorites(): Response<ApiResponse<FavoritesResult>>

    @POST("customer/favorites.php")
    suspend fun addFavorite(@Body body: ToggleFavoriteBody): Response<ApiResponse<ToggleFavoriteResult>>

    @HTTP(method = "DELETE", path = "customer/favorites.php", hasBody = true)
    suspend fun removeFavorite(@Body body: ToggleFavoriteBody): Response<ApiResponse<ToggleFavoriteResult>>

    // ---- Phase 3.6: Profile — FAQs, Feedback ----

    @GET("customer/faqs.php")
    suspend fun getFaqs(): Response<ApiResponse<FaqsResult>>

    @POST("customer/feedback.php")
    suspend fun submitFeedback(@Body body: SubmitFeedbackBody): Response<ApiResponse<SubmitFeedbackResult>>

    // ---- Rating system (Part 13) ----

    @GET("customer/reviews.php")
    suspend fun getReview(@Query("order_id") orderId: Int): Response<ApiResponse<GetReviewResult>>

    @POST("customer/reviews.php")
    suspend fun submitReview(@Body body: SubmitReviewBody): Response<ApiResponse<SubmitReviewResult>>

    // ---- Notification bell (Type 1 — system-generated, docs/Status.md
    // 2026-08-20). Called against notifications.php directly with the
    // action/id query params it expects, same convention as every other
    // endpoint here (the .htaccess pretty routes exist for direct-hit
    // completeness but the app talks to the .php file itself). ----

    @GET("customer/notifications.php")
    suspend fun getNotifications(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("unread_only") unreadOnly: String? = null
    ): Response<ApiResponse<NotificationsResult>>

    @POST("customer/notifications.php")
    suspend fun markNotificationRead(
        @Query("action") action: String = "read",
        @Query("id") id: Int
    ): Response<ApiResponse<MarkReadResult>>

    @POST("customer/notifications.php")
    suspend fun markAllNotificationsRead(
        @Query("action") action: String = "read-all"
    ): Response<ApiResponse<MarkAllReadResult>>
}
