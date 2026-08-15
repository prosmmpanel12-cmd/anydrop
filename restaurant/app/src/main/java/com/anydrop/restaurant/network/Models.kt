package com.anydrop.restaurant.network

import com.google.gson.annotations.SerializedName

/** Standard envelope every Anydrop API endpoint responds with. */
data class ApiResponse<T>(
    val success: Boolean,
    val data: T?,
    val error: String?
)

data class Paginated<T>(
    val data: List<T>,
    val meta: PageMeta?
)

data class PageMeta(
    val page: Int,
    @SerializedName("per_page") val perPage: Int,
    val total: Int,
    @SerializedName("total_pages") val totalPages: Int
)

// ---- Auth ----

data class LoginBody(val email: String, val password: String)

// --- Restaurant Partner Signup (email OTP verify -> create pending account) ---

data class RequestOtpBody(val email: String)

data class RequestOtpResult(
    val message: String,
    @SerializedName("debug_otp") val debugOtp: String? = null
)

data class VerifyOtpBody(val email: String, val otp: String)

data class VerifyOtpResult(val verified: Boolean, val email: String)

data class SignupBody(
    val name: String,
    @SerializedName("owner_name") val ownerName: String,
    @SerializedName("owner_mobile") val ownerMobile: String,
    @SerializedName("owner_email") val ownerEmail: String,
    val password: String,
    val address: String? = null
)

data class SignupResult(
    val restaurant: RestaurantProfile,
    val status: String
)

data class RestaurantProfile(
    val id: Int,
    val name: String,
    @SerializedName("owner_email") val ownerEmail: String?,
    val status: String
)

data class LoginResult(
    val restaurant: RestaurantProfile?,
    val token: String?
)

// ---- Orders ----

data class OrderItemLine(
    val id: Int,
    val name: String,
    @SerializedName("variant_name") val variantName: String?,
    val quantity: Int,
    @SerializedName("unit_price") val unitPrice: Double,
    val subtotal: Double
)

data class OrderStatusHistoryEntry(
    val status: String,
    @SerializedName("changed_by_type") val changedByType: String,
    val note: String?,
    @SerializedName("created_at") val createdAt: String
)

data class Order(
    val id: Int,
    @SerializedName("order_code") val orderCode: String,
    @SerializedName("restaurant_id") val restaurantId: Int,
    val status: String,
    @SerializedName("item_total") val itemTotal: Double,
    @SerializedName("delivery_charge") val deliveryCharge: Double,
    @SerializedName("platform_fee") val platformFee: Double,
    @SerializedName("packing_charge") val packingCharge: Double,
    @SerializedName("tax_amount") val taxAmount: Double,
    @SerializedName("discount_amount") val discountAmount: Double,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("payment_status") val paymentStatus: String,
    @SerializedName("delivery_instructions") val deliveryInstructions: String?,
    @SerializedName("estimated_prep_minutes") val estimatedPrepMinutes: Int?,
    // I4 follow-up — "yyyy-MM-dd HH:mm:ss" or null (a normal ASAP order).
    // format_order() has always returned this restaurant-side too; the
    // Restaurant App just wasn't modeling it. See docs/16 Part A2.
    @SerializedName("scheduled_for") val scheduledFor: String? = null,
    @SerializedName("created_at") val createdAt: String,
    val items: List<OrderItemLine> = emptyList(),
    @SerializedName("status_history") val statusHistory: List<OrderStatusHistoryEntry> = emptyList()
)

data class OrderResult(val order: Order)

data class RejectBody(val reason: String)
data class StatusUpdateBody(val status: String)
data class AcceptBody(@SerializedName("estimated_prep_minutes") val estimatedPrepMinutes: Int? = null)

// ---- Dashboard ----

data class TodaySummary(
    @SerializedName("orders_count") val ordersCount: Int,
    val earnings: Double,
    @SerializedName("commission_owed") val commissionOwed: Double,
    // UI plan §4 "Today" snapshot strip — null until at least one order has
    // gone accepted -> ready today (see dashboard.php); the stat chip shows
    // a placeholder ("—") rather than a misleading "0 min" in that case.
    @SerializedName("avg_prep_minutes") val avgPrepMinutes: Int? = null
)

data class DashboardResult(
    @SerializedName("pending_orders") val pendingOrders: Int,
    @SerializedName("active_orders") val activeOrders: Int,
    val today: TodaySummary,
    @SerializedName("current_due") val currentDue: Double,
    @SerializedName("operational_status") val operationalStatus: String? = null
)

// ---- Part B — "Accepting orders" toggle (docs/16 Part B) ----

data class OperationalStatusUpdateBody(@SerializedName("operational_status") val operationalStatus: String)
data class OperationalStatusResult(@SerializedName("operational_status") val operationalStatus: String)

// ---- Menu Management (Tier 1, docs/18) ----

data class MenuCategory(
    val id: Int,
    val name: String,
    @SerializedName("sort_order") val sortOrder: Int,
    @SerializedName("is_active") val isActive: Boolean,
    @SerializedName("item_count") val itemCount: Int = 0
)

data class CategoriesListResult(val categories: List<MenuCategory>)
data class CategoryResult(val category: MenuCategory)

data class CategoryCreateBody(val name: String, @SerializedName("sort_order") val sortOrder: Int? = null)
data class CategoryUpdateBody(
    val name: String? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null,
    @SerializedName("is_active") val isActive: Boolean? = null
)

data class MenuItem(
    val id: Int,
    @SerializedName("category_id") val categoryId: Int,
    val name: String,
    val description: String?,
    val price: Double,
    @SerializedName("discount_percent") val discountPercent: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("image_url") val imageUrl: String?,
    @SerializedName("is_available") val isAvailable: Boolean,
    @SerializedName("is_recommended") val isRecommended: Boolean,
    @SerializedName("is_bestseller") val isBestseller: Boolean,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int
)

data class MenuItemsListResult(val items: List<MenuItem>)
data class MenuItemResult(val item: MenuItem)

data class MenuItemCreateBody(
    @SerializedName("category_id") val categoryId: Int,
    val name: String,
    val price: Double,
    val description: String? = null,
    @SerializedName("is_veg") val isVeg: Boolean = true,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int? = null
)

data class MenuItemUpdateBody(
    @SerializedName("category_id") val categoryId: Int? = null,
    val name: String? = null,
    val description: String? = null,
    val price: Double? = null,
    @SerializedName("is_veg") val isVeg: Boolean? = null,
    @SerializedName("is_available") val isAvailable: Boolean? = null,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int? = null
)
