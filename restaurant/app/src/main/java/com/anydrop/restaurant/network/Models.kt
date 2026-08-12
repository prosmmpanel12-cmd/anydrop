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
    @SerializedName("commission_owed") val commissionOwed: Double
)

data class DashboardResult(
    @SerializedName("pending_orders") val pendingOrders: Int,
    @SerializedName("active_orders") val activeOrders: Int,
    val today: TodaySummary,
    @SerializedName("current_due") val currentDue: Double
)
