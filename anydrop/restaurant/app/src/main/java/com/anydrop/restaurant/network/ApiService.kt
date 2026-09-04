package com.anydrop.restaurant.network

import okhttp3.MultipartBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query
import retrofit2.http.Streaming

interface ApiService {

    @POST("auth/restaurant-login.php")
    suspend fun login(@Body body: LoginBody): Response<ApiResponse<LoginResult>>

    // Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3) — sibling
    // login for a named staff account rather than the owner. See
    // backend/api/v1/auth/restaurant-staff-login.php's own kdoc for why
    // this is a separate endpoint rather than a flag on login() above.
    @POST("auth/restaurant-staff-login.php")
    suspend fun staffLogin(@Body body: StaffLoginBody): Response<ApiResponse<StaffLoginResult>>

    @GET("restaurant/staff-list.php")
    suspend fun listStaff(): Response<ApiResponse<StaffListResult>>

    @POST("restaurant/staff-create.php")
    suspend fun createStaff(@Body body: StaffCreateBody): Response<ApiResponse<StaffResult>>

    @POST("restaurant/staff-update.php")
    suspend fun updateStaff(@Query("id") staffId: Int, @Body body: StaffUpdateBody): Response<ApiResponse<StaffResult>>

    @POST("restaurant/staff-delete.php")
    suspend fun deleteStaff(@Query("id") staffId: Int): Response<ApiResponse<Map<String, Any>>>

    // Migration 64 — Staff Audit Trail (PENDING.md §7's last checkbox).
    // Same manage_staff gate as the CRUD endpoints above.
    @GET("restaurant/staff-audit-list.php")
    suspend fun listStaffAuditLog(): Response<ApiResponse<StaffAuditLogListResult>>

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

    @GET("restaurant/insights.php")
    suspend fun getInsights(@Query("range") range: String = "week"): Response<ApiResponse<InsightsResult>>

    // First @Streaming/raw-ResponseBody call in this app — every other
    // endpoint returns JSON wrapped in ApiResponse<T>, but a CSV export
    // is a raw file download, not a JSON envelope. @Streaming stops
    // Retrofit/OkHttp from buffering the whole body into memory before
    // handing it back, same reason any file-download call needs it.
    @Streaming
    @GET("restaurant/insights.php?export=csv")
    suspend fun exportInsightsCsv(
        @Query("range") range: String,
        @Query("from") from: String? = null,
        @Query("to") to: String? = null
    ): Response<ResponseBody>

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

    // Item tag picker (Pizza / Onion / Capsicum / ...) shown in the add/edit
    // menu item dialog — see MenuFragment.showItemDialog(). Backed by the
    // same food_categories table the Customer app's Home chip row reads.
    @GET("restaurant/food-tags-list.php")
    suspend fun getFoodTags(): Response<ApiResponse<FoodTagsListResult>>

    // ---- Item Customization / Add-on Groups (§1, today.md 2026-08-28) ----

    @GET("restaurant/addon-groups-list.php")
    suspend fun getAddonGroups(@Query("item_id") itemId: Int): Response<ApiResponse<AddonGroupsListResult>>

    @POST("restaurant/addon-groups-create.php")
    suspend fun createAddonGroup(@Body body: AddonGroupCreateBody): Response<ApiResponse<AddonGroupResult>>

    @POST("restaurant/addon-groups-update.php")
    suspend fun updateAddonGroup(@Query("id") groupId: Int, @Body body: AddonGroupUpdateBody): Response<ApiResponse<AddonGroupResult>>

    @POST("restaurant/addon-groups-delete.php")
    suspend fun deleteAddonGroup(@Query("id") groupId: Int): Response<ApiResponse<Map<String, Any>>>

    @POST("restaurant/addons-create.php")
    suspend fun createAddon(@Body body: AddonCreateBody): Response<ApiResponse<AddonResult>>

    @POST("restaurant/addons-update.php")
    suspend fun updateAddon(@Query("id") addonId: Int, @Body body: AddonUpdateBody): Response<ApiResponse<AddonResult>>

    // ---- Temp Closure / Holiday Scheduling (§3, today.md 2026-08-28,
    // doc 60/61) ----

    @GET("restaurant/closures-list.php")
    suspend fun getClosures(): Response<ApiResponse<ClosuresListResult>>

    @POST("restaurant/closures-create.php")
    suspend fun createClosure(@Body body: ClosureCreateBody): Response<ApiResponse<ClosureResult>>

    @POST("restaurant/closures-update.php")
    suspend fun updateClosure(@Query("id") closureId: Int, @Body body: ClosureUpdateBody): Response<ApiResponse<ClosureResult>>

    @POST("restaurant/closures-delete.php")
    suspend fun deleteClosure(@Query("id") closureId: Int): Response<ApiResponse<Map<String, Any>>>

    // ---- Restaurant Bank Details (PENDING.md §15, migration 59) ----

    @GET("restaurant/bank-details-get.php")
    suspend fun getBankDetails(): Response<ApiResponse<BankDetailsResult>>

    @POST("restaurant/bank-details-save.php")
    suspend fun saveBankDetails(@Body body: BankDetailsSaveBody): Response<ApiResponse<BankDetailsResult>>

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

    // ---- Restaurant Offers (doc 20 §1/§12/§14; backend built docs/29) ----

    @GET("restaurant/offers-list.php")
    suspend fun getOffers(): Response<ApiResponse<OffersListResult>>

    @POST("restaurant/offers-create.php")
    suspend fun createOffer(@Body body: OfferCreateBody): Response<ApiResponse<OfferResult>>

    @POST("restaurant/offers-update.php")
    suspend fun updateOffer(@Query("id") id: Int, @Body body: OfferUpdateBody): Response<ApiResponse<OfferResult>>

    // ---- Notification bell (Type 1 — system-generated, docs/Status.md
    // 2026-08-20). Mirrors the Customer App's ApiService entry of the same
    // name — called against notifications.php directly with the action/id
    // query params it expects, same convention as every other endpoint in
    // this file (the .htaccess pretty routes exist for direct-hit
    // completeness but the app talks to the .php file itself). ----

    @GET("restaurant/notifications.php")
    suspend fun getNotifications(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("unread_only") unreadOnly: String? = null
    ): Response<ApiResponse<NotificationsResult>>

    @POST("restaurant/notifications.php")
    suspend fun markNotificationRead(
        @Query("action") action: String = "read",
        @Query("id") id: Int
    ): Response<ApiResponse<MarkReadResult>>

    @POST("restaurant/notifications.php")
    suspend fun markAllNotificationsRead(
        @Query("action") action: String = "read-all"
    ): Response<ApiResponse<MarkAllReadResult>>

    // ---- Reviews reply (docs/restorent/00_Status.md, this session).
    // Calls reviews.php directly with the id/reply query+body params it
    // expects — same "app talks to the .php file, pretty route exists
    // for completeness" convention as notifications above. ----

    @GET("restaurant/reviews.php")
    suspend fun getReviews(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20,
        @Query("unreplied_only") unrepliedOnly: String? = null
    ): Response<ApiResponse<ReviewsResult>>

    @POST("restaurant/reviews.php")
    suspend fun replyToReview(
        @Query("id") id: Int,
        @Body body: ReviewReplyBody
    ): Response<ApiResponse<ReviewReplyResult>>

    // ---- Restaurant-side "Report review" (§7, today.md 2026-08-28). ----

    @POST("restaurant/report-review.php")
    suspend fun reportReview(@Body body: ReportReviewBody): Response<ApiResponse<ReportReviewResult>>

    // ---- App version / update check (§9, 2026-08-28). Mirrors the
    // Customer App's ApiService entry of the same name/signature. ----

    @GET("system/app-version.php")
    suspend fun getAppVersion(@Query("platform") platform: String = "restaurant"): Response<ApiResponse<AppVersionInfo>>

    // ---- FCM push token registration (this session) ----

    @POST("restaurant/fcm-token-update.php")
    suspend fun updateFcmToken(@Body body: FcmTokenBody): Response<ApiResponse<FcmTokenResult>>
}
