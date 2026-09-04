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
    val address: String? = null,
    // Optional — service-area auto-resolution (§0, 2026-08-28). Only sent
    // when the owner actually picked a pin on the signup screen; an old
    // client (or a skipped location step) simply omits these and the
    // backend falls back to admin-assigned area_id exactly as before.
    val latitude: Double? = null,
    val longitude: Double? = null
)

data class SignupResult(
    val restaurant: RestaurantProfile,
    val status: String,
    // area_id resolved automatically at signup time from the lat/lng above
    // (backend/lib/geo.php's resolve_service_area()). false is a normal,
    // expected outcome (new launch city not in service_areas yet) — not
    // an error, and never blocks the signup itself.
    @SerializedName("area_resolved") val areaResolved: Boolean = false,
    val area: ResolvedArea? = null
)

data class ResolvedArea(
    val id: Int,
    val name: String,
    val level: String
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

// ---- Restaurant Staff / RBAC (migration 63, PENDING.md item 3) ----

data class StaffLoginBody(val username: String, val password: String)

data class StaffProfile(
    val id: Int,
    val name: String,
    val username: String,
    // "manager" | "kitchen" | "cashier" — never "owner"; see migration
    // 63's own header for why the owner isn't represented here.
    val role: String,
    @SerializedName("is_active") val isActive: Boolean = true,
    @SerializedName("created_at") val createdAt: String? = null
)

data class StaffLoginResult(
    val restaurant: RestaurantProfile?,
    val staff: StaffProfile?,
    val token: String?
)

data class StaffListResult(val staff: List<StaffProfile>)

data class StaffResult(val staff: StaffProfile)

data class StaffCreateBody(
    val name: String,
    val username: String,
    val password: String,
    val role: String
)

/** All fields optional — only ones provided are changed, same partial-
 * update convention `staff-update.php` documents in its own kdoc.
 * Gson serializes an unset property here as JSON `null` rather than
 * omitting the key — that's fine: `staff-update.php`'s own `isset()`
 * checks treat an explicit `null` the same as a missing key, so no
 * special "omit nulls" Gson configuration is needed for this to work
 * correctly. */
data class StaffUpdateBody(
    val name: String? = null,
    val role: String? = null,
    @SerializedName("is_active") val isActive: Boolean? = null,
    val password: String? = null
)

// ---- Staff Audit Trail (migration 64, PENDING.md §7's last checkbox) ----

/** One row from staff-audit-list.php. [action] is one of
 * staff_created/staff_updated/staff_role_changed/staff_activated/
 * staff_deactivated/staff_deleted — see migration 64's own header.
 * [details] is the raw details_json map (name/username/role/
 * old_role/new_role/etc, varies per action) — kept as a generic map
 * rather than a per-action sealed class since this screen only ever
 * needs to read a couple of keys out of it for display, not branch
 * heavily on shape. */
data class StaffAuditLogEntry(
    val id: Int,
    val action: String,
    @SerializedName("target_staff_id") val targetStaffId: Int?,
    @SerializedName("acting_role") val actingRole: String,
    @SerializedName("acting_staff_id") val actingStaffId: Int?,
    val details: Map<String, Any?> = emptyMap(),
    @SerializedName("created_at") val createdAt: String? = null
)

data class StaffAuditLogListResult(val entries: List<StaffAuditLogEntry>)

// ---- Account tab / Edit Profile (docs/restorent/19 §7, §10 item 5) ----

/** Full restaurants row (minus password_hash) — profile-get.php /
 * profile-update.php's response shape, richer than the minimal
 * RestaurantProfile above used at login/signup time. Fields this app
 * doesn't yet edit (latitude/longitude, gst/fssai, commission, etc.) are
 * still modeled read-only here since profile-get.php returns the whole
 * row — kept nullable/defaulted so a future column addition on the
 * backend can't break deserialization of an older field set. */
data class RestaurantProfileDetail(
    val id: Int,
    val name: String,
    @SerializedName("owner_name") val ownerName: String? = null,
    @SerializedName("owner_mobile") val ownerMobile: String? = null,
    @SerializedName("owner_email") val ownerEmail: String? = null,
    val address: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    @SerializedName("cover_url") val coverUrl: String? = null,
    @SerializedName("cuisine_tags") val cuisineTags: String? = null,
    @SerializedName("opening_time") val openingTime: String? = null,
    @SerializedName("closing_time") val closingTime: String? = null,
    @SerializedName("working_days") val workingDays: String? = null,
    val description: String? = null,
    // today.md §3 — columns already existed on `restaurants`
    // (gst_number VARCHAR(20), fssai_number VARCHAR(30)) but were never
    // read here; profile-get.php does SELECT *, so these were always in
    // the response, just silently dropped by Gson until this field existed.
    @SerializedName("gst_number") val gstNumber: String? = null,
    @SerializedName("fssai_number") val fssaiNumber: String? = null,
    @SerializedName("upi_id") val upiId: String? = null,
    @SerializedName("current_due") val currentDue: Double = 0.0,
    val status: String? = null,
    @SerializedName("operational_status") val operationalStatus: String? = null,
    @SerializedName("rating_avg") val ratingAvg: Double = 0.0,
    // recall.md Phase B item 13 / migration 36 — restaurant's own min
    // order amount, floored server-side (profile-update.php) by
    // whatever area_pricing_rules/platform-default floor applies to
    // this restaurant's assigned area. Nullable like every other field
    // here in case an older cached row predates this column read.
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null
)

data class ProfileResult(val restaurant: RestaurantProfileDetail)

/** Partial update — only non-null fields are sent/changed, same convention
 * as MenuItemUpdateBody. logo_url is set via a separate upload call first
 * (logo-upload.php returns the path), then passed here like any other field. */
data class ProfileUpdateBody(
    val name: String? = null,
    val address: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerializedName("cuisine_tags") val cuisineTags: String? = null,
    @SerializedName("opening_time") val openingTime: String? = null,
    @SerializedName("closing_time") val closingTime: String? = null,
    @SerializedName("working_days") val workingDays: String? = null,
    val description: String? = null,
    // today.md §3 — see matching kdoc on RestaurantProfileDetail. Blank
    // string clears the field server-side (profile-update.php), same
    // convention as cuisine_tags/description above; null (omitted) means
    // "leave unchanged".
    @SerializedName("gst_number") val gstNumber: String? = null,
    @SerializedName("fssai_number") val fssaiNumber: String? = null,
    @SerializedName("logo_url") val logoUrl: String? = null,
    // recall.md Phase B item 13 / migration 36 — omitted (null) means
    // "don't change" per profile-update.php's array_key_exists check,
    // same convention as every other field here; a real value is
    // server-validated against the restaurant's area floor and
    // rejected (min_order_below_area_floor) if it's too low.
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null
)

data class LogoUploadResult(@SerializedName("logo_url") val logoUrl: String)

// ---- Restaurant banners (app-owner feedback item #3, 2026-08-17) ----

data class Banner(
    @SerializedName("id") val id: Int,
    @SerializedName("image_url") val imageUrl: String
)

data class BannersListResult(@SerializedName("banners") val banners: List<Banner>)

data class BannerUploadResult(
    @SerializedName("id") val id: Int,
    @SerializedName("image_url") val imageUrl: String
)

data class BannerDeleteBody(@SerializedName("id") val id: Int)

// ---- Restaurant coupons (doc 07 §2.1, this session) ----

data class Coupon(
    @SerializedName("id") val id: Int,
    @SerializedName("code") val code: String,
    @SerializedName("discount_type") val discountType: String, // "flat" | "percent"
    @SerializedName("discount_value") val discountValue: Double,
    @SerializedName("min_order_amount") val minOrderAmount: Double,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double?,
    @SerializedName("valid_from") val validFrom: String?,
    @SerializedName("valid_until") val validUntil: String?,
    @SerializedName("usage_limit_total") val usageLimitTotal: Int?,
    @SerializedName("usage_limit_per_user") val usageLimitPerUser: Int?,
    @SerializedName("is_active") val isActive: Boolean,
    @SerializedName("is_public") val isPublic: Boolean,
    // Archive state (migration 27, doc 22 follow-up: "also add off on
    // delete and other possible option") — independent of isActive, see
    // coupons-update.php's kdoc. Defaulted false so any older cached/
    // mocked Coupon JSON without this field still deserializes.
    @SerializedName("is_archived") val isArchived: Boolean = false,
    @SerializedName("times_used") val timesUsed: Int
)

data class CouponsListResult(@SerializedName("coupons") val coupons: List<Coupon>)

data class CouponResult(@SerializedName("coupon") val coupon: Coupon)

/** discount_type/code are create-only — see coupons-update.php's kdoc for why
 * they're excluded from CouponUpdateBody below. */
data class CouponCreateBody(
    val code: String,
    @SerializedName("discount_type") val discountType: String,
    @SerializedName("discount_value") val discountValue: Double,
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    @SerializedName("usage_limit_total") val usageLimitTotal: Int? = null,
    @SerializedName("usage_limit_per_user") val usageLimitPerUser: Int? = null,
    // doc 22 item 3 — "show on coupon screen" toggle at creation time.
    // Omitted (null) means the server falls back to its own default (0,
    // private) — see coupons-create.php's kdoc.
    @SerializedName("is_public") val isPublic: Boolean? = null
)

/** Partial update — only non-null-in-JSON fields change server-side; used
 * for the on/off visibility toggle (isActive alone), the archive/unarchive
 * action (isArchived alone), the is_public toggle (now editable from the
 * edit dialog too, per doc 22 item 3's follow-up answer), and for editing
 * the rest of an existing coupon's terms. */
data class CouponUpdateBody(
    @SerializedName("is_active") val isActive: Boolean? = null,
    @SerializedName("is_public") val isPublic: Boolean? = null,
    @SerializedName("is_archived") val isArchived: Boolean? = null,
    @SerializedName("discount_value") val discountValue: Double? = null,
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    @SerializedName("usage_limit_total") val usageLimitTotal: Int? = null,
    @SerializedName("usage_limit_per_user") val usageLimitPerUser: Int? = null
)

// ---- Restaurant Offers (doc 20 §1/§12/§14; backend built docs/29,
// this session finishes the Restaurant App "Offers" screen, docs/29
// "Not built" item 1) ----
//
// Mirrors backend/lib/offers.php's format_offer() field-for-field.
// Distinct from Coupon above — see migration 47's own header comment
// for why the two are separate concepts (auto-applied promo vs
// code-entry coupon) that nonetheless coexist and can stack together.
data class PromoOffer(
    val id: Int,
    @SerializedName("offer_type") val offerType: String, // quantity_deal|buy_x_for_y|buy_x_get_y|percent_discount|flat_discount|free_delivery|combo
    val title: String,
    val scope: String, // item|category|restaurant
    @SerializedName("menu_item_id") val menuItemId: Int?,
    @SerializedName("food_category_id") val foodCategoryId: Int?,
    @SerializedName("required_qty") val requiredQty: Int?,
    @SerializedName("get_qty") val getQty: Int?,
    @SerializedName("offer_price") val offerPrice: Double?,
    @SerializedName("discount_percent") val discountPercent: Double?,
    @SerializedName("discount_flat") val discountFlat: Double?,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double?,
    @SerializedName("min_order_amount") val minOrderAmount: Double,
    @SerializedName("customer_eligibility") val customerEligibility: String, // all|new_customer|existing_customer
    @SerializedName("start_date") val startDate: String?, // yyyy-MM-dd
    @SerializedName("end_date") val endDate: String?,
    @SerializedName("start_time") val startTime: String?, // HH:mm:ss
    @SerializedName("end_time") val endTime: String?,
    val weekdays: String?, // CSV "1,2,3" (1=Mon..7=Sun), null = every day
    @SerializedName("daily_limit") val dailyLimit: Int?,
    @SerializedName("total_limit") val totalLimit: Int?,
    @SerializedName("per_customer_limit") val perCustomerLimit: Int?,
    // migration 48 — restaurant-controlled: whether this offer's own
    // discount can combine with a coupon code at checkout (see
    // lib/orders.php's own kdoc on the enforcement side). Defaults true
    // server-side for any row created before migration 48 ran.
    @SerializedName("allow_coupon_stacking") val allowCouponStacking: Boolean = true,
    // Migration 49 — apply_mode: "default" (today's unchanged auto-apply
    // behavior, never has a code) vs "coupon_based" (same mechanics, but
    // only ever considered at checkout when the customer types [code] —
    // see lib/offers.php's find_coupon_based_offer_by_code()). Both
    // create-only server-side (offers-update.php rejects changing
    // either after creation, same "delete and recreate" boundary as
    // offer_type/scope). [isPublic] is the one migration-49 field that
    // stays editable post-creation, meaningful only when applyMode is
    // "coupon_based" — a "default" offer is never listed by is_public in
    // the first place, so its value there is irrelevant either way.
    @SerializedName("apply_mode") val applyMode: String = "default",
    val code: String? = null,
    @SerializedName("is_public") val isPublic: Boolean = true,
    val status: String, // active|paused|disabled — 'disabled' is admin-only, see offers-update.php
    @SerializedName("created_at") val createdAt: String?,
    // offers-list.php-only extras — absent (default) when this model is
    // deserialized from offers-create.php/offers-update.php's response,
    // which don't compute either.
    @SerializedName("times_used") val timesUsed: Int = 0,
    @SerializedName("is_currently_active") val isCurrentlyActive: Boolean = false,
    // Migration 50 / docs/40 — only ever non-empty for offer_type="combo"
    // (format_offer()'s own contract, see that function's kdoc); empty
    // list, never null, for every other type.
    @SerializedName("combo_items") val comboItems: List<ComboItem> = emptyList()
)

/** One row of a combo's fixed item list — mirrors offer_combo_items
 * (menu_item_id, required_qty) exactly, no item name (the backend
 * doesn't join menu_items here, see format_offer()'s kdoc) — callers
 * that need a display name resolve it locally via getMenuItems(). */
data class ComboItem(
    @SerializedName("menu_item_id") val menuItemId: Int,
    @SerializedName("required_qty") val requiredQty: Int
)

data class OffersListResult(@SerializedName("offers") val offers: List<PromoOffer>)
data class OfferResult(@SerializedName("offer") val offer: PromoOffer)

/** offer_type/scope/menu_item_id/food_category_id and every type-mechanic
 * field (required_qty/get_qty/offer_price/discount_percent/discount_flat)
 * are create-only — see offers-update.php's kdoc for why (an already-
 * redeemed offer's history would become impossible to interpret if the
 * mechanic changed underneath it), so they only appear here, never in
 * OfferUpdateBody below. */
data class OfferCreateBody(
    @SerializedName("offer_type") val offerType: String,
    val title: String,
    val scope: String,
    @SerializedName("menu_item_id") val menuItemId: Int? = null,
    @SerializedName("food_category_id") val foodCategoryId: Int? = null,
    @SerializedName("required_qty") val requiredQty: Int? = null,
    @SerializedName("get_qty") val getQty: Int? = null,
    @SerializedName("offer_price") val offerPrice: Double? = null,
    @SerializedName("discount_percent") val discountPercent: Double? = null,
    @SerializedName("discount_flat") val discountFlat: Double? = null,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double? = null,
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null,
    @SerializedName("customer_eligibility") val customerEligibility: String? = null,
    @SerializedName("start_date") val startDate: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
    val weekdays: String? = null,
    @SerializedName("daily_limit") val dailyLimit: Int? = null,
    @SerializedName("total_limit") val totalLimit: Int? = null,
    @SerializedName("per_customer_limit") val perCustomerLimit: Int? = null,
    // migration 48. Sent explicitly (not left to the server's own
    // omitted-value-means-true default) so the switch's on-screen state
    // always matches what actually gets stored, even though both agree
    // when the switch is left at its default-checked state.
    @SerializedName("allow_coupon_stacking") val allowCouponStacking: Boolean? = null,
    // Migration 49, create-only — see PromoOffer's own kdoc on the pair.
    // [code] is only read server-side (and only required) when
    // [applyMode] == "coupon_based"; left null for a "default" offer.
    @SerializedName("apply_mode") val applyMode: String? = null,
    val code: String? = null,
    @SerializedName("is_public") val isPublic: Boolean? = null,
    // Migration 50 / docs/40 — required (2+ distinct items) only when
    // offerType == "combo"; left null otherwise, matching offers-create.php's
    // own "only read for offer_type='combo'" gating (see that file's kdoc).
    @SerializedName("combo_items") val comboItems: List<ComboItemBody>? = null
)

/** Request-side mirror of ComboItem (Models.kt's response-side twin) —
 * kept as a separate class rather than reused, same "create body vs
 * response model are different shapes" split every other
 * OfferCreateBody field already follows relative to PromoOffer. */
data class ComboItemBody(
    @SerializedName("menu_item_id") val menuItemId: Int,
    @SerializedName("required_qty") val requiredQty: Int
)

/** Partial update — used for the Pause/Resume toggle (status alone), the
 * soft-delete action (isDeleted alone), and for editing the rest of an
 * existing offer's restriction fields. Same null-skip convention as
 * CouponUpdateBody; every field here maps 1:1 to what offers-update.php
 * actually reads (array_key_exists-gated server-side, not null-gated —
 * see that file's kdoc — so this class only ever sends fields the user
 * actually touched via the dialog's "send full current form state"
 * pattern, same reasoning submitCouponEdit() documents). */
data class OfferUpdateBody(
    val status: String? = null,
    val title: String? = null,
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double? = null,
    @SerializedName("start_date") val startDate: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    @SerializedName("start_time") val startTime: String? = null,
    @SerializedName("end_time") val endTime: String? = null,
    val weekdays: String? = null,
    @SerializedName("daily_limit") val dailyLimit: Int? = null,
    @SerializedName("total_limit") val totalLimit: Int? = null,
    @SerializedName("per_customer_limit") val perCustomerLimit: Int? = null,
    // migration 48 — editable after creation (unlike offer_type/scope/
    // mechanic fields above), same array_key_exists-gated convention as
    // every other field in this class.
    @SerializedName("allow_coupon_stacking") val allowCouponStacking: Boolean? = null,
    // Migration 49 — the one apply_mode-related field still editable
    // post-creation (apply_mode/code themselves are not — see
    // PromoOffer's kdoc). Only meaningful for an offer whose applyMode
    // is already "coupon_based"; sent as null for a "default" offer
    // since offers-update.php gates on array_key_exists, and there's
    // nothing here to change.
    @SerializedName("is_public") val isPublic: Boolean? = null,
    @SerializedName("is_deleted") val isDeleted: Boolean? = null
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

/** [resumeAt] is only meaningful (and only accepted server-side)
 * alongside operationalStatus = "temp_closed" — a "YYYY-MM-DD HH:mm:ss"
 * wire string, same format CouponManagerActivity's valid-until picker
 * already produces. Added §3 (today.md 2026-08-28, doc 60) so
 * AccountFragment's temp-closed switch can send an optional reopen
 * time instead of only ever sending resume_at: null. */
data class OperationalStatusUpdateBody(
    @SerializedName("operational_status") val operationalStatus: String,
    @SerializedName("resume_at") val resumeAt: String? = null
)
data class OperationalStatusResult(
    @SerializedName("operational_status") val operationalStatus: String,
    @SerializedName("temp_closed_until") val tempClosedUntil: String? = null
)

// ---- Insights tab (docs/restorent/19 §6) — backend: insights.php ----

data class InsightStats(
    @SerializedName("total_orders") val totalOrders: Int,
    @SerializedName("total_earnings") val totalEarnings: Double,
    @SerializedName("average_order_value") val averageOrderValue: Double,
    @SerializedName("cancellation_rate_percent") val cancellationRatePercent: Double
)

data class InsightDailyChartPoint(
    val date: String,
    @SerializedName("order_count") val orderCount: Int
)

data class InsightTopItem(
    @SerializedName("menu_item_id") val menuItemId: Int?,
    val name: String,
    @SerializedName("quantity_sold") val quantitySold: Int,
    val revenue: Double
)

data class InsightRepeatCustomers(
    val count: Int,
    @SerializedName("distinct_customers_in_range") val distinctCustomersInRange: Int,
    val percent: Double
)

// Peak hours heatmap (today.md §1 wishlist / PENDING.md item 3's own
// "Peak hours" line — held out of doc 49's original build pending a
// design decision; app owner chose the full hour × day-of-week
// heatmap over a single busiest-hour stat). Always the last 30 days,
// independent of InsightsResult's own `range`/`fromDate`/`toDate` —
// see insights.php's header for why. `dayOfWeek` is this project's
// existing ISO convention (1 Mon .. 7 Sun), same as every other
// day-of-week value already flowing through this codebase.
data class InsightPeakHourCell(
    @SerializedName("day_of_week") val dayOfWeek: Int,
    val hour: Int,
    @SerializedName("order_count") val orderCount: Int
)

data class InsightPeakSlot(
    @SerializedName("day_of_week") val dayOfWeek: Int,
    @SerializedName("day_name") val dayName: String,
    val hour: Int,
    @SerializedName("order_count") val orderCount: Int
)

data class InsightPeakHours(
    @SerializedName("from_date") val fromDate: String,
    @SerializedName("to_date") val toDate: String,
    @SerializedName("max_count") val maxCount: Int,
    // Null only when every cell is zero (no orders at all in the
    // window) — Gson leaves this null on a JSON `null` value fine,
    // no default needed since insights.php always sends the key.
    @SerializedName("peak_slot") val peakSlot: InsightPeakSlot?,
    // Always all 168 cells (7 days × 24 hours), zero-filled — see
    // insights.php's header for why the full grid is sent every time.
    val cells: List<InsightPeakHourCell>
)

data class InsightsResult(
    val range: String,
    @SerializedName("from_date") val fromDate: String,
    @SerializedName("to_date") val toDate: String,
    val stats: InsightStats,
    @SerializedName("daily_chart") val dailyChart: List<InsightDailyChartPoint>,
    @SerializedName("top_items") val topItems: List<InsightTopItem>,
    @SerializedName("repeat_customers") val repeatCustomers: InsightRepeatCustomers,
    @SerializedName("peak_hours") val peakHours: InsightPeakHours
)

// ---- Menu Management (Tier 1, docs/18) ----

data class MenuCategory(
    val id: Int,
    val name: String,
    @SerializedName("sort_order") val sortOrder: Int,
    @SerializedName("is_active") val isActive: Boolean,
    @SerializedName("item_count") val itemCount: Int = 0,
    @SerializedName("image_url") val imageUrl: String? = null,
    // doc 22 item 1 — bundled category icon picker. Mutually exclusive
    // with imageUrl server-side (see 28_migration_category_icon_key.sql /
    // categories-update.php's kdoc) — a category never has both set at
    // once, but both fields stay independently nullable here since the
    // server is what enforces that, not this model. Keys match
    // CategoryIcons.ALL's [CategoryIconOption.key].
    @SerializedName("icon_key") val iconKey: String? = null
)

data class CategoriesListResult(val categories: List<MenuCategory>)
data class CategoryResult(val category: MenuCategory)

data class CategoryCreateBody(
    val name: String,
    @SerializedName("sort_order") val sortOrder: Int? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("icon_key") val iconKey: String? = null
)
data class CategoryUpdateBody(
    val name: String? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null,
    @SerializedName("is_active") val isActive: Boolean? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("icon_key") val iconKey: String? = null
)

/** category-photo-upload.php's response shape, mirrors LogoUploadResult. */
data class CategoryPhotoUploadResult(@SerializedName("image_url") val imageUrl: String)

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
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int,
    // Item availability timing (today.md §1, migration 62) — optional
    // daily recurring window, "HH:MM:SS" or null (no restriction), same
    // raw-TIME-string convention as restaurants.opening_time/closing_time
    // elsewhere in this API. Both null on every item that predates this
    // migration and any item that never sets a window.
    @SerializedName("available_from") val availableFrom: String? = null,
    @SerializedName("available_until") val availableUntil: String? = null,
    // Food-category tags (Pizza / Onion / Capsicum / ...) — slugs, same
    // ones the Customer app's Home chip row filters by. Defaults to empty
    // list for backward-compat with any cached/older response shape.
    val tags: List<String> = emptyList()
)

data class MenuItemsListResult(val items: List<MenuItem>)
data class MenuItemResult(val item: MenuItem)

data class MenuItemCreateBody(
    @SerializedName("category_id") val categoryId: Int,
    val name: String,
    val price: Double,
    val description: String? = null,
    @SerializedName("is_veg") val isVeg: Boolean = true,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("available_from") val availableFrom: String? = null,
    @SerializedName("available_until") val availableUntil: String? = null,
    val tags: List<String>? = null
)

data class MenuItemUpdateBody(
    @SerializedName("category_id") val categoryId: Int? = null,
    val name: String? = null,
    val description: String? = null,
    val price: Double? = null,
    @SerializedName("is_veg") val isVeg: Boolean? = null,
    @SerializedName("is_available") val isAvailable: Boolean? = null,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    // Item availability timing (today.md §1, migration 62). Unlike every
    // other field here, an empty string "" is meaningful — it explicitly
    // clears a previously-set window to NULL (see
    // menu-items-update.php's kdoc) — while leaving the field null/absent
    // means "not touched," same convention as every other field on this
    // body. MenuFragment.showItemDialog()'s clear ("X") icon must send
    // "" here, not leave the field null, or a previously-set window would
    // never be removable.
    @SerializedName("available_from") val availableFrom: String? = null,
    @SerializedName("available_until") val availableUntil: String? = null,
    val tags: List<String>? = null
)

/** One selectable tag chip in the add/edit item dialog. */
data class FoodTag(
    val id: Int,
    val name: String,
    val slug: String,
    @SerializedName("icon_url") val iconUrl: String?
)

data class FoodTagsListResult(val tags: List<FoodTag>)

/** menu-item-photo-upload.php's response shape, mirrors LogoUploadResult. */
data class MenuItemPhotoUploadResult(@SerializedName("image_url") val imageUrl: String)

// ---- Item Customization / Add-on Groups (§1, today.md 2026-08-28,
// migration 57). See AddonGroupsActivity.kt's kdoc for the full picture —
// short version: menu_item_addons already existed (flat, ungrouped), this
// adds an optional group wrapper (min/max-select + required) a restaurant
// can create per menu item. addon_group_id null on an Addon means
// "ungrouped", same flat-checkbox behavior every addon already had. ----

data class Addon(
    val id: Int,
    @SerializedName("addon_group_id") val addonGroupId: Int? = null,
    val name: String,
    val price: Double,
    @SerializedName("is_active") val isActive: Boolean
)

data class AddonGroup(
    val id: Int,
    val name: String,
    @SerializedName("min_select") val minSelect: Int,
    @SerializedName("max_select") val maxSelect: Int,
    @SerializedName("is_required") val isRequired: Boolean,
    @SerializedName("sort_order") val sortOrder: Int,
    @SerializedName("is_active") val isActive: Boolean,
    val addons: List<Addon> = emptyList()
)

data class AddonGroupsListResult(
    val groups: List<AddonGroup>,
    @SerializedName("ungrouped_addons") val ungroupedAddons: List<Addon> = emptyList()
)
data class AddonGroupResult(val group: AddonGroup)
data class AddonResult(val addon: Addon)

data class AddonGroupCreateBody(
    @SerializedName("item_id") val itemId: Int,
    val name: String,
    @SerializedName("min_select") val minSelect: Int? = null,
    @SerializedName("max_select") val maxSelect: Int? = null,
    @SerializedName("is_required") val isRequired: Boolean? = null
)

data class AddonGroupUpdateBody(
    val name: String? = null,
    @SerializedName("min_select") val minSelect: Int? = null,
    @SerializedName("max_select") val maxSelect: Int? = null,
    @SerializedName("is_required") val isRequired: Boolean? = null
)

data class AddonCreateBody(
    @SerializedName("item_id") val itemId: Int,
    @SerializedName("addon_group_id") val addonGroupId: Int? = null,
    val name: String,
    val price: Double
)

/** Also doubles as this addon's remove/restore action — see
 * addons-update.php's kdoc — AddonGroupsActivity's "Remove" tap just
 * sends `AddonUpdateBody(isActive = false)`. */
data class AddonUpdateBody(
    val name: String? = null,
    val price: Double? = null,
    @SerializedName("is_active") val isActive: Boolean? = null
)

// ---- Temp Closure / Holiday Scheduling (§3, today.md 2026-08-28,
// migration 58, doc 60/61). Backs ClosureScheduleActivity — a
// restaurant's own list of scheduled multi-day/recurring closures,
// distinct from the plain on-demand "temp closed" switch above
// (OperationalStatusUpdateBody/-Result), which stays a single
// resume_at/temp_closed_until pair on the restaurant row itself. ----

data class Closure(
    val id: Int,
    @SerializedName("closure_type") val closureType: String, // "date_range" | "weekly_recurring"
    @SerializedName("start_date") val startDate: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    @SerializedName("day_of_week") val dayOfWeek: Int? = null, // 1=Mon..7=Sun
    val reason: String? = null,
    @SerializedName("is_active") val isActive: Boolean = true
)

data class ClosuresListResult(val closures: List<Closure>)
data class ClosureResult(val closure: Closure)

/** Shared by create and update — closures-update.php is a full
 * replace of the type-specific fields, not a partial patch, so both
 * endpoints take the same body shape (see that endpoint's kdoc). */
data class ClosureCreateBody(
    @SerializedName("closure_type") val closureType: String,
    @SerializedName("start_date") val startDate: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    @SerializedName("day_of_week") val dayOfWeek: Int? = null,
    val reason: String? = null
)

data class ClosureUpdateBody(
    @SerializedName("closure_type") val closureType: String,
    @SerializedName("start_date") val startDate: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    @SerializedName("day_of_week") val dayOfWeek: Int? = null,
    val reason: String? = null
)

// ---- Restaurant Bank Details (PENDING.md §15, migration 59). Backs
// BankDetailsActivity — a restaurant's own submission of its payout
// account, separate from the admin-entered version admin/settlements.php
// has had since migration 38. account_number never comes back from the
// server in full (bank-details-get.php/-save.php both mask it to last
// 4 digits, see lib/restaurant_bank.php's kdoc) — the model reflects
// that with accountNumberMasked, not accountNumber, so there's no
// field here a screen could mistakenly display expecting a full value. ----

data class BankDetails(
    @SerializedName("account_holder_name") val accountHolderName: String,
    @SerializedName("bank_name") val bankName: String,
    @SerializedName("account_number_masked") val accountNumberMasked: String,
    @SerializedName("ifsc_code") val ifscCode: String,
    @SerializedName("upi_id") val upiId: String? = null,
    @SerializedName("verification_status") val verificationStatus: String, // "pending" | "verified" | "rejected"
    @SerializedName("admin_remarks") val adminRemarks: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null
)

data class BankDetailsResult(@SerializedName("bank_details") val bankDetails: BankDetails?)

data class BankDetailsSaveBody(
    @SerializedName("account_holder_name") val accountHolderName: String,
    @SerializedName("bank_name") val bankName: String,
    @SerializedName("account_number") val accountNumber: String,
    @SerializedName("ifsc_code") val ifscCode: String,
    @SerializedName("upi_id") val upiId: String? = null
)

// ---- Notification bell (Type 1 — system-generated, docs/Status.md
// 2026-08-20). Mirrors the Customer App's network/Models.kt entry of the
// same name field-for-field — same backend lib (backend/lib/notifications.php)
// serves both apps, just scoped to actor_type='restaurant' here. `data`
// deserializes as a raw Map<String, Any?>? via Gson's default behavior,
// same as the Customer App — read-only keyed access, no custom adapter
// needed (e.g. data?.get("order_id")). ----

data class NotificationItem(
    val id: Int,
    val title: String,
    val body: String?,
    val type: String, // "order" | "promo" | "system" | "security" — matches schema ENUM
    @SerializedName("is_read") val isRead: Boolean,
    val data: Map<String, Any?>?, // deep-link payload, e.g. {order_id, screen}
    @SerializedName("created_at") val createdAt: String
)

data class NotificationsResult(
    val items: List<NotificationItem>,
    @SerializedName("has_more") val hasMore: Boolean,
    @SerializedName("unread_count") val unreadCount: Int
)

data class MarkReadResult(val id: Int, @SerializedName("is_read") val isRead: Boolean)
data class MarkAllReadResult(@SerializedName("marked_read") val markedRead: Int)

// ---- Reviews reply (docs/restorent/00_Status.md, this session).
// backend/api/v1/restaurant/reviews.php's response shape. `restaurantReply`
// is null until the restaurant has replied — that's the signal the UI
// uses to switch a row between "reply" input and "your reply" display,
// same null-as-state-flag convention `rejectionReason`/etc. use elsewhere
// in this codebase rather than a separate has-replied boolean. ----

data class Review(
    val id: Int,
    @SerializedName("order_id") val orderId: Int,
    @SerializedName("customer_name") val customerName: String?,
    @SerializedName("restaurant_rating") val restaurantRating: Int?,
    @SerializedName("food_rating") val foodRating: Int?,
    @SerializedName("delivery_rating") val deliveryRating: Int?,
    val comment: String?,
    @SerializedName("restaurant_reply") val restaurantReply: String?,
    @SerializedName("created_at") val createdAt: String
)

data class ReviewsResult(
    val items: List<Review>,
    val page: Int,
    @SerializedName("per_page") val perPage: Int,
    val total: Int,
    @SerializedName("has_more") val hasMore: Boolean
)

data class ReviewReplyBody(val reply: String)
data class ReviewReplyResult(val review: Review)

// ---- Restaurant-side "Report review" (§7, today.md 2026-08-28).
// Mirrors backend/api/v1/customer/report-review.php's shape (same
// SignupBody/etc @SerializedName convention this app follows elsewhere) —
// backend/api/v1/restaurant/report-review.php is the mirror endpoint,
// auth + ownership-check are the only difference from the customer path. ----

data class ReportReviewBody(
    @SerializedName("review_id") val reviewId: Int,
    val reason: String
)
data class ReportReviewResult(val reported: Boolean)

// ---- FCM push token registration (this session). Mirrors
// backend/api/v1/restaurant/fcm-token-update.php's request shape. ----

data class FcmTokenBody(@SerializedName("fcm_token") val fcmToken: String)
data class FcmTokenResult(val ok: Boolean)

// ---- App version / update check (§9, 2026-08-28) — Restaurant App had no
// version-check code at all (SplashActivity was logo-animation-only), so
// force-update never worked here even though the backend endpoint
// (/system/app-version.php?platform=restaurant) and app_settings keys
// already existed and are already used by the Customer App. Same shape
// as the Customer App's AppVersionInfo, copied field-for-field. ----

data class AppVersionInfo(
    @SerializedName("latest_version_code") val latestVersionCode: Int,
    @SerializedName("latest_version_name") val latestVersionName: String,
    @SerializedName("min_version_code") val minVersionCode: Int,
    @SerializedName("update_message") val updateMessage: String,
    @SerializedName("update_url") val updateUrl: String?,
    // Same maintenance fields as the Customer App's AppVersionInfo — see
    // that file's kdoc. Defaults keep this safely "not in maintenance" if
    // the field is ever missing from the response.
    @SerializedName("maintenance_mode") val maintenanceMode: Boolean = false,
    @SerializedName("maintenance_message") val maintenanceMessage: String? = null
)
