package com.anydrop.rider.network

import com.google.gson.annotations.SerializedName

/** Standard envelope every Anydrop API endpoint responds with. */
data class ApiResponse<T>(
    val success: Boolean,
    val data: T?,
    val error: String?
)

// ---- Auth (backend/api/v1/auth/rider-*.php, docs 79-82) ----

data class RequestOtpBody(val email: String)

data class RequestOtpResult(
    val message: String,
    @SerializedName("debug_otp") val debugOtp: String? = null
)

data class VerifyOtpBody(val email: String, val otp: String)

/**
 * Mirrors rider-verify-otp.php exactly. This single call does double duty:
 * - New email -> accountExists=false, app routes to SignupActivity.
 * - Existing rider -> accountExists=true, token+rider+status are already
 *   here — this IS login, no separate call needed.
 */
data class VerifyOtpResult(
    val verified: Boolean,
    val email: String,
    @SerializedName("account_exists") val accountExists: Boolean,
    val rider: RiderProfile? = null,
    val token: String? = null,
    val status: String? = null
)

data class SignupBody(
    val name: String,
    val email: String,
    val mobile: String,
    @SerializedName("service_area_id") val serviceAreaId: Int? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    // Deliberately NOT sent from the signup form (app owner decision,
    // 2026-09-01) — vehicle_type/vehicle_number are collected later in
    // the post-approval "Complete Profile" step, not here. The backend
    // field exists and accepts these, they're just never populated from
    // this screen.
    @SerializedName("vehicle_type") val vehicleType: String? = null,
    @SerializedName("vehicle_number") val vehicleNumber: String? = null
)

data class SignupResult(
    val rider: RiderProfile,
    val status: String,
    @SerializedName("area_resolved") val areaResolved: Boolean = false,
    val area: ResolvedArea? = null,
    val token: String? = null
)

data class ResolvedArea(
    val id: Int,
    val name: String,
    val level: String
)

data class RiderProfile(
    val id: Int,
    val name: String,
    val email: String?,
    val mobile: String?,
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null
)

// ---- rider/me (backend/api/v1/rider/me.php) ----

/**
 * Response from GET /api/v1/rider/me — used by ApplicationStatusActivity's
 * Refresh button to re-check current status without forcing a full logout
 * + OTP re-login. Includes the service area name so the status screen
 * can show it as a confirmation of where the rider signed up.
 */
data class RiderMeResult(
    val rider: RiderMeProfile,
    val status: String
)

data class RiderMeProfile(
    val id: Int,
    val name: String,
    val email: String,
    val mobile: String?,
    val status: String,
    @SerializedName("rejection_reason") val rejectionReason: String? = null,
    @SerializedName("service_area_id") val serviceAreaId: Int? = null,
    @SerializedName("service_area_name") val serviceAreaName: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    // Phase 3 (doc 83) additions — additive only, everything above is unchanged.
    @SerializedName("is_online") val isOnline: Boolean = false,
    @SerializedName("vehicle_type") val vehicleType: String? = null,
    @SerializedName("vehicle_number") val vehicleNumber: String? = null,
    // Migration 75 (deep-plan §22, Rider Documents) additions — additive only.
    @SerializedName("documents_status") val documentsStatus: String = "not_submitted",
    @SerializedName("documents_reject_reason") val documentsRejectReason: String? = null,
    @SerializedName("profile_photo_url") val profilePhotoUrl: String? = null
)

// ---- Rider documents (deep-plan §22, migration 75) ----

/** Response from GET /api/v1/rider/documents-get.php. documentsStatus is
 *  one of "not_submitted" | "pending" | "verified" | "rejected" — see
 *  migration 75's own header for why this is a 4-state enum distinct
 *  from the rider's account-level `status`. hasIdDoc/hasVehicleDoc are
 *  presence flags, not URLs — see that endpoint's own kdoc for why the
 *  raw filenames are never sent to the client; SubmitDocumentsActivity
 *  fetches the actual bytes via documents-view.php when it needs to
 *  show a "here's what you submitted" preview. */
data class RiderDocumentsResult(
    @SerializedName("documents_status") val documentsStatus: String,
    @SerializedName("documents_reject_reason") val documentsRejectReason: String?,
    @SerializedName("has_id_doc") val hasIdDoc: Boolean,
    @SerializedName("has_vehicle_doc") val hasVehicleDoc: Boolean,
    @SerializedName("profile_photo_url") val profilePhotoUrl: String?,
    @SerializedName("vehicle_type") val vehicleType: String?,
    @SerializedName("vehicle_number") val vehicleNumber: String?
)

/** Response from POST /api/v1/rider/documents-upload.php (multipart —
 *  see ApiService.uploadRiderDocuments()'s own kdoc for the actual
 *  call shape; this is just the JSON body that comes back). */
data class RiderDocumentsUploadResult(
    @SerializedName("documents_status") val documentsStatus: String,
    @SerializedName("profile_photo_url") val profilePhotoUrl: String?
)

// ---- Phase 3: dashboard online toggle + location ping (doc 83) ----

data class OnlineStatusBody(val online: Boolean)

data class OnlineStatusResult(@SerializedName("is_online") val isOnline: Boolean)

/** orderId is optional — omit (null) for the plain online-toggle ping.
 *  Pass it during an active delivery (deep-plan §12-13, Phase 3 R4) so
 *  the backend also writes a `rider_locations` audit row on top of the
 *  usual `riders.last_lat/last_lng` cache update; see location.php's
 *  own kdoc for why a bad/foreign/stale orderId here is always a
 *  silent no-op server-side, never a client-visible error. */
data class LocationBody(
    val lat: Double,
    val lng: Double,
    @SerializedName("order_id") val orderId: Int? = null,
    @SerializedName("speed_kmh") val speedKmh: Double? = null
)

/** Generic {"ok": true} shape — used by endpoints with nothing else to return. */
data class OkResult(val ok: Boolean)

// ---- Phase 3 R3: assignment engine (doc 85) ----

data class OfferResult(val offer: Offer?)

data class Offer(
    @SerializedName("assignment_id") val assignmentId: Int,
    @SerializedName("order_id") val orderId: Int,
    @SerializedName("order_code") val orderCode: String,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_address") val restaurantAddress: String?,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("item_count") val itemCount: Int,
    @SerializedName("expires_at") val expiresAt: String,
    @SerializedName("expires_in_seconds") val expiresInSeconds: Int
)

data class CurrentOrderResult(val order: CurrentOrder?)

data class CurrentOrder(
    val id: Int,
    @SerializedName("order_code") val orderCode: String,
    val status: String,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_address") val restaurantAddress: String?,
    @SerializedName("delivery_address") val deliveryAddress: String?,
    @SerializedName("delivery_lat") val deliveryLat: Double?,
    @SerializedName("delivery_lng") val deliveryLng: Double?,
    @SerializedName("delivery_instructions") val deliveryInstructions: String?,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("item_count") val itemCount: Int,
    @SerializedName("delivery_otp_required") val deliveryOtpRequired: Boolean,
    @SerializedName("accepted_at") val acceptedAt: String?
)

data class AcceptOrderResult(@SerializedName("order_id") val orderId: Int, val status: String)

data class RejectOrderBody(val reason: String? = null)

// Rider Order Detail (deep-plan §9). Deliberately a separate model from
// CurrentOrder above, not an extension of it — CurrentOrder backs the
// dashboard's compact card and orders-current.php intentionally omits
// fields (restaurant lat/lng, phones, customer name, distance, cost
// breakdown) that this dedicated detail screen needs. Two backend
// endpoints, two models, matching each response shape exactly rather
// than forcing one bloated model to serve both screens.
data class OrderDetailResult(val order: OrderDetail?)

data class OrderDetail(
    val id: Int,
    @SerializedName("order_code") val orderCode: String,
    val status: String,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_address") val restaurantAddress: String?,
    @SerializedName("restaurant_lat") val restaurantLat: Double?,
    @SerializedName("restaurant_lng") val restaurantLng: Double?,
    @SerializedName("restaurant_phone") val restaurantPhone: String?,
    @SerializedName("customer_name") val customerName: String?,
    @SerializedName("customer_phone") val customerPhone: String?,
    @SerializedName("delivery_address") val deliveryAddress: String?,
    @SerializedName("house_flat_no") val houseFlatNo: String?,
    val floor: String?,
    val landmark: String?,
    @SerializedName("receiver_name") val receiverName: String?,
    @SerializedName("receiver_phone") val receiverPhone: String?,
    @SerializedName("address_photo_url") val addressPhotoUrl: String?,
    @SerializedName("delivery_lat") val deliveryLat: Double?,
    @SerializedName("delivery_lng") val deliveryLng: Double?,
    @SerializedName("delivery_instructions") val deliveryInstructions: String?,
    @SerializedName("item_count") val itemCount: Int,
    @SerializedName("item_total") val itemTotal: Double,
    @SerializedName("delivery_charge") val deliveryCharge: Double,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("cod_amount") val codAmount: Double?,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("delivery_otp_required") val deliveryOtpRequired: Boolean,
    @SerializedName("accepted_at") val acceptedAt: String?
)

// ---- Pickup / drop-off flow (this session — deep-plan §9-16) ----

data class PickupOrderResult(@SerializedName("order_id") val orderId: Int, val status: String)

/** otp is "" when the current order's deliveryOtpRequired is false —
 *  the backend ignores it entirely in that case (see orders-deliver.php kdoc). */
data class DeliverOrderBody(val otp: String)

data class DeliverOrderResult(
    @SerializedName("order_id") val orderId: Int,
    val status: String,
    // Nullable/defaulted: older cached responses or a mid-rollout server
    // without migration 73 applied yet still deserialize fine.
    @SerializedName("earning_amount") val earningAmount: Double? = null
)

// ---- Rider earnings (deep-plan §19-20, migration 73) ----

data class EarningsSummaryResult(
    @SerializedName("today_total") val todayTotal: Double,
    val balance: Double,
    @SerializedName("share_percent") val sharePercent: Double,
    // Deep-plan §17-18 — cash the rider is currently holding from COD
    // deliveries (owed TO the platform, opposite direction from
    // `balance`) and the admin-configurable ceiling before new COD
    // assignments get blocked server-side (dispatch.php). Kept as two
    // separate fields rather than a single "percent used" number so
    // EarningsActivity can show both the raw rupee amount and compute
    // its own warning threshold.
    @SerializedName("cod_cash_held") val codCashHeld: Double = 0.0,
    @SerializedName("cod_settlement_limit") val codSettlementLimit: Double = 2000.0,
    val recent: List<EarningsLedgerEntry>
)

data class EarningsLedgerEntry(
    val id: Int,
    @SerializedName("entry_type") val entryType: String,
    val amount: Double,
    @SerializedName("order_id") val orderId: Int?,
    @SerializedName("order_code") val orderCode: String?,
    val note: String?,
    @SerializedName("created_at") val createdAt: String
)

// ---- Rider payout requests (deep-plan §21, migration 74) ----
// Field shapes deliberately mirror the customer app's wallet-withdrawal
// models (BankDetailsResult/SaveBankDetailsBody/WalletWithdrawal/
// RequestWithdrawalBody) — same backend design, same JSON shape.

data class RiderBankDetails(
    @SerializedName("account_holder_name") val accountHolderName: String,
    @SerializedName("bank_name") val bankName: String?,
    @SerializedName("account_number_masked") val accountNumberMasked: String?,
    @SerializedName("ifsc_code") val ifscCode: String?,
    @SerializedName("upi_id") val upiId: String?,
    @SerializedName("updated_at") val updatedAt: String?
)

data class RiderBankDetailsResult(
    @SerializedName("bank_details") val bankDetails: RiderBankDetails?
)

data class SaveRiderBankDetailsBody(
    @SerializedName("payout_method") val payoutMethod: String, // "bank" | "upi"
    @SerializedName("account_holder_name") val accountHolderName: String,
    @SerializedName("bank_name") val bankName: String? = null,
    @SerializedName("account_number") val accountNumber: String? = null,
    @SerializedName("ifsc_code") val ifscCode: String? = null,
    @SerializedName("upi_id") val upiId: String? = null
)

data class RiderPayoutRequest(
    val id: Int,
    val amount: Double,
    @SerializedName("payout_method") val payoutMethod: String, // "bank" | "upi"
    val status: String, // "requested" | "approved" | "processing" | "completed" | "rejected"
    @SerializedName("payout_reference") val payoutReference: String?,
    @SerializedName("reject_reason") val rejectReason: String?,
    @SerializedName("requested_at") val requestedAt: String,
    @SerializedName("approved_at") val approvedAt: String?,
    @SerializedName("processing_at") val processingAt: String?,
    @SerializedName("completed_at") val completedAt: String?,
    @SerializedName("rejected_at") val rejectedAt: String?
)

data class RiderPayoutHistoryResult(
    val requests: List<RiderPayoutRequest> = emptyList()
)

data class RequestRiderPayoutBody(
    val amount: Double,
    @SerializedName("payout_method") val payoutMethod: String,
    @SerializedName("account_holder_name") val accountHolderName: String,
    @SerializedName("bank_name") val bankName: String? = null,
    @SerializedName("account_number") val accountNumber: String? = null,
    @SerializedName("ifsc_code") val ifscCode: String? = null,
    @SerializedName("upi_id") val upiId: String? = null
)

data class RequestRiderPayoutResult(
    @SerializedName("request_id") val requestId: Int,
    val balance: Double
)

// ---- Service areas (backend/api/v1/system/service-areas.php) ----

data class ServiceArea(
    val id: Int,
    @SerializedName("parent_id") val parentId: Int?,
    val level: String,
    val name: String
)

data class ServiceAreasResult(
    val areas: List<ServiceArea>
)

// ---- FCM push token registration + notification bell (doc 99/100's
// backend, this session's Android side). Field-for-field mirror of the
// customer/restaurant apps' own FcmTokenBody/FcmTokenResult/
// NotificationItem/NotificationsResult/MarkReadResult/MarkAllReadResult
// (see e.g. customer/app/.../network/Models.kt) — same response
// envelope shape, just re-declared here since this is a separate
// Gradle module with no shared code module in this project. ----

data class FcmTokenBody(@SerializedName("fcm_token") val fcmToken: String)
data class FcmTokenResult(val ok: Boolean)

data class NotificationItem(
    val id: Int,
    val title: String,
    val body: String?,
    val type: String, // "order" | "promo" | "system" | "security" — matches schema ENUM
    @SerializedName("is_read") val isRead: Boolean,
    val data: Map<String, Any?>?, // deep-link payload, e.g. {screen: "dashboard"}
    @SerializedName("created_at") val createdAt: String
)

data class NotificationsResult(
    val items: List<NotificationItem>,
    @SerializedName("has_more") val hasMore: Boolean,
    @SerializedName("unread_count") val unreadCount: Int
)

data class MarkReadResult(val id: Int, @SerializedName("is_read") val isRead: Boolean)
data class MarkAllReadResult(@SerializedName("marked_read") val markedRead: Int)
