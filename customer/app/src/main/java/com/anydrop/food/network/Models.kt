package com.anydrop.food.network

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
    @SerializedName("total_pages") val totalPages: Int,
    // app-owner ask, 2026-08-17: distinguishes "0 results because your
    // filters excluded everything" from "0 results because nothing is
    // within the (admin-configurable) delivery radius" — see
    // restaurants/list.php's out_of_range_count and HomeActivity's
    // empty-state handling. Nullable/defaulted since not every endpoint
    // reusing PageMeta (e.g. search) populates it.
    @SerializedName("out_of_range_count") val outOfRangeCount: Int? = null
)

// ---- Auth ----

data class RequestOtpBody(val email: String)
data class VerifyOtpBody(val email: String, val otp: String)

data class Customer(
    val id: Int,
    val name: String?,
    val email: String?,
    val mobile: String?
)

data class AuthResult(
    val customer: Customer?,
    val token: String?
)

// backend/api/v1/customer/complete-profile.php — one-time step after
// email-OTP signup to collect name + mobile (both nullable on signup).
// Not a general profile-edit endpoint; see the PHP file's own kdoc.
data class CompleteProfileBody(val name: String, val mobile: String)

data class CompleteProfileResult(val customer: Customer?)

data class MessageOnly(val message: String?)

// ---- App version ----

data class AppVersionInfo(
    @SerializedName("latest_version_code") val latestVersionCode: Int,
    @SerializedName("latest_version_name") val latestVersionName: String,
    @SerializedName("min_version_code") val minVersionCode: Int,
    @SerializedName("update_message") val updateMessage: String,
    @SerializedName("update_url") val updateUrl: String?
)

// ---- Splash / login config ----

data class SplashConfig(
    @SerializedName("banner_image_url") val bannerImageUrl: String?,
    @SerializedName("terms_url") val termsUrl: String?,
    @SerializedName("privacy_url") val privacyUrl: String?,
    @SerializedName("content_policy_url") val contentPolicyUrl: String?,
    @SerializedName("home_promo_enabled") val homePromoEnabled: Boolean = false,
    @SerializedName("home_promo_title") val homePromoTitle: String?,
    @SerializedName("home_promo_subtitle") val homePromoSubtitle: String?,
    @SerializedName("home_promo_image_url") val homePromoImageUrl: String?,
    @SerializedName("coupon_field_enabled") val couponEnabled: Boolean = true
)

// ---- Restaurants ----

data class RestaurantTag(
    val name: String,
    val slug: String
)

data class Restaurant(
    val id: Int,
    val name: String,
    val address: String?,
    val latitude: Double?,
    val longitude: Double?,
    @SerializedName("logo_url") val logoUrl: String?,
    @SerializedName("cover_url") val coverUrl: String?,
    @SerializedName("cuisine_tags") val cuisineTags: String?,
    @SerializedName("is_veg_only") val isVegOnly: Boolean,
    @SerializedName("min_order_amount") val minOrderAmount: Double,
    @SerializedName("rating_avg") val ratingAvg: Double,
    @SerializedName("rating_count") val ratingCount: Int = 0,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("estimated_delivery_minutes") val etaMinutes: Int?,
    @SerializedName("is_open_now") val isOpenNow: Boolean,
    // Part B follow-up — true when the restaurant is on-demand paused
    // (kitchen busy) rather than simply outside its fixed hours. Lets a
    // card show "Temporarily unavailable" instead of a plain "Closed".
    // Defaults false so older/other endpoints that don't send this yet
    // just fall back to the plain-Closed look, not a false positive.
    @SerializedName("is_paused") val isPaused: Boolean = false,
    @SerializedName("match_type") val matchType: String? = null,
    @SerializedName("matched_dish") val matchedDish: String? = null,
    @SerializedName("offer_badge_text") val offerBadgeText: String? = null,
    // Nullable despite always being present in the API contract: Gson builds
    // this object via reflection/Unsafe, not the constructor, so a missing
    // or null "tags" key in the JSON lands here as a real null at runtime
    // regardless of the `= emptyList()` default. Any endpoint that forgets
    // to emit "tags" (like search.php did) will null this out silently.
    // Use `.orEmpty()` at every call site instead of assuming non-null.
    val tags: List<RestaurantTag>? = emptyList(),
    @SerializedName("is_saved") val isSaved: Boolean = false,
    // Gallery photos for the auto-advancing card carousel (§2.7). Same
    // nullable-despite-always-present caveat as `tags` above (Gson +
    // missing JSON key = real null at runtime) — always read via
    // `.orEmpty()`, never assume non-null. Empty/absent gallery is also
    // the normal, expected case for a restaurant that hasn't uploaded
    // gallery photos yet; the carousel view falls back to a single static
    // image (coverUrl) when this is empty, so nothing breaks either way.
    val gallery: List<RestaurantGalleryPhoto>? = emptyList()
)

data class RestaurantGalleryPhoto(
    @SerializedName("image_url") val imageUrl: String,
    @SerializedName("dish_name") val dishName: String?,
    val price: Double?
)

// ---- Home categories (Pizza / Rolls / Burger chip row) ----

data class FoodCategory(
    val id: Int,
    val name: String,
    val slug: String,
    @SerializedName("icon_url") val iconUrl: String?
)

data class CategoryItemsResult(
    val category: FoodCategory,
    val items: List<SearchItem>,
    val meta: SearchMeta?
)

// ---- Search ----

/**
 * A menu item surfaced by search or by a category tap, always tagged with
 * the restaurant it comes from — used both for a searched restaurant's own
 * menu items and for "Also available at" cross-restaurant matches of the
 * same dish.
 */
data class SearchItem(
    val id: Int,
    val name: String,
    val description: String?,
    val price: Double,
    @SerializedName("discount_percent") val discountPercent: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("image_url") val imageUrl: String?,
    @SerializedName("is_recommended") val isRecommended: Boolean,
    @SerializedName("is_bestseller") val isBestseller: Boolean,
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_logo_url") val restaurantLogoUrl: String?,
    @SerializedName("restaurant_rating") val restaurantRating: Double,
    @SerializedName("restaurant_is_open_now") val restaurantIsOpenNow: Boolean,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("is_cross_restaurant_match") val isCrossRestaurantMatch: Boolean = false,
    @SerializedName("is_saved") val isSaved: Boolean = false,
    // Offers Engine badge (app owner ask, 2026-08-25) — same field
    // search.php now returns, mirrors MenuItem.offerTag's own defaulted-
    // null convention so an older cached response keeps deserializing.
    @SerializedName("offer_tag") val offerTag: String? = null
)

/**
 * CartManager.add()/decrease() are typed against MenuItem only (cart lines
 * are keyed by MenuItem.id and priced off MenuItem.price). SearchItem comes
 * from a different endpoint shape (search / category-items) and doesn't
 * carry variants/addons/prepTime, so it's converted at the call site rather
 * than widening CartManager's API — variants/addons default empty (inline
 * ADD from a dish card never offers variant/addon selection, same as how
 * the restaurant-menu ADD button behaves for a no-variant item), prepTime
 * defaults to 0 (unused anywhere cart/UI reads it today).
 */
fun SearchItem.toMenuItem(): MenuItem = MenuItem(
    id = id,
    name = name,
    description = description,
    price = price,
    discountPercent = discountPercent,
    isVeg = isVeg,
    imageUrl = imageUrl,
    isRecommended = isRecommended,
    isBestseller = isBestseller,
    prepTimeMinutes = 0,
    isSaved = isSaved,
    offerTag = offerTag
)

data class SearchResponse(
    val restaurants: List<Restaurant>,
    val items: List<SearchItem>,
    val meta: SearchMeta?
)

data class SearchMeta(
    val query: String?,
    @SerializedName("total_restaurants") val totalRestaurants: Int? = null,
    @SerializedName("total_items") val totalItems: Int? = null,
    val total: Int? = null
)

// ---- Menu ----

data class MenuVariant(
    val id: Int,
    val name: String,
    @SerializedName("price_delta") val priceDelta: Double
)

data class MenuAddon(
    val id: Int,
    val name: String,
    val price: Double
)

data class MenuItem(
    val id: Int,
    val name: String,
    val description: String?,
    val price: Double,
    @SerializedName("discount_percent") val discountPercent: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("image_url") val imageUrl: String?,
    @SerializedName("is_recommended") val isRecommended: Boolean,
    @SerializedName("is_bestseller") val isBestseller: Boolean,
    // features.md §1 "Filters and Sorting" sheet's dietary-preference chips.
    // Defaulted false so gson doesn't choke on older cached responses.
    @SerializedName("is_spicy") val isSpicy: Boolean = false,
    @SerializedName("is_kids_choice") val isKidsChoice: Boolean = false,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int,
    @SerializedName("is_saved") val isSaved: Boolean = false,
    // bugs.md §6.3 follow-up — out-of-stock. Previously the item just
    // never appeared at all (menu.php filtered it server-side); now it's
    // included and this flag tells the app to render it greyed out
    // instead. Defaulted true so an old cached response missing this
    // field (shouldn't happen post-rollout, but just in case) doesn't
    // wrongly grey out every item.
    @SerializedName("is_available") val isAvailable: Boolean = true,
    // Offers Engine badge (app owner feedback, 2026-08-24) — short
    // display text ("3 @ ₹50", "20% OFF") when an item/category/
    // restaurant-wide offer currently applies to this item, null
    // otherwise. Defaulted null so an older cached response missing
    // this field renders with no tag rather than crashing.
    @SerializedName("offer_tag") val offerTag: String? = null,
    val variants: List<MenuVariant> = emptyList(),
    val addons: List<MenuAddon> = emptyList()
)

data class MenuCategory(
    val id: Int?,
    val name: String,
    // Category icon set via the Restaurant app's category photo upload —
    // relative path, needs ApiClient.baseUrlForStaticFiles() prefix same
    // as every other image_url field, see MenuAdapter's HeaderVH.
    @SerializedName("image_url") val imageUrl: String? = null,
    // Offers Engine discount icon (app owner feedback, 2026-08-24) — true
    // when any item shown in this category currently carries an
    // offer_tag. Defaulted false for the same older-cached-response
    // reason as MenuItem.offerTag above.
    @SerializedName("has_active_offer") val hasActiveOffer: Boolean = false,
    val items: List<MenuItem>
)

/**
 * Restaurant header block returned alongside the menu (bug 1.1 fix) —
 * previously RestaurantDetailActivity only had id/name/cover passed from
 * Home and never fetched rating/cuisine/badge/tags itself. Now the single
 * GET /restaurants/{id}/menu call returns everything the detail screen
 * header needs.
 */
data class RestaurantDetail(
    val id: Int,
    val name: String,
    val address: String?,
    @SerializedName("logo_url") val logoUrl: String?,
    @SerializedName("cover_url") val coverUrl: String?,
    // Restaurant banners (app-owner feedback item #3, 2026-08-17) —
    // owner-curated promotional images from restaurant_banners, ordered
    // by sort_order server-side. Nullable rather than defaulted to
    // emptyList() so `.orEmpty()` at the one call site
    // (RestaurantDetailActivity.populate()) stays the single place that
    // decides "no banners", consistent with this file's other nullable
    // list-ish fields.
    val banners: List<String>? = null,
    @SerializedName("cuisine_tags") val cuisineTags: String?,
    @SerializedName("is_veg_only") val isVegOnly: Boolean,
    // bugs.md §6.3 follow-up — detail page previously had no open/paused
    // badge at all (Home cards and search results already did). Same
    // is_open_now/is_paused fields those two send, now computed by the
    // same shared compute_restaurant_status() on the backend.
    @SerializedName("is_open_now") val isOpenNow: Boolean = true,
    @SerializedName("is_paused") val isPaused: Boolean = false,
    @SerializedName("min_order_amount") val minOrderAmount: Double,
    @SerializedName("rating_avg") val ratingAvg: Double,
    @SerializedName("rating_count") val ratingCount: Int = 0,
    @SerializedName("offer_badge_text") val offerBadgeText: String?,
    // See the comment on Restaurant.tags above — same Gson-vs-Kotlin-default
    // caveat applies here, kept nullable and read via `.orEmpty()`.
    val tags: List<RestaurantTag>? = emptyList(),
    @SerializedName("is_saved") val isSaved: Boolean = false,
    // features.md §6 — restaurant detail header parity pass. Null unless
    // the caller passed lat/lng to menu.php (no GPS fix yet, permission
    // denied, or an older cached response) — same optional-until-resolved
    // contract as Restaurant.distanceKm above, always guard on null before
    // rendering rather than assuming it's present.
    @SerializedName("distance_km") val distanceKm: Double? = null,
    @SerializedName("estimated_delivery_minutes") val etaMinutes: Int? = null,
    // I4 — raw "HH:MM:SS" strings straight off the restaurants row, null if
    // this restaurant never had hours configured. Used only to bound the
    // "Schedule for later" slot list to today's remaining open hours; the
    // server re-validates the actual chosen slot independently in
    // orders/create.php, so a stale/wrong value here can't create a bad
    // order — worst case the picker offers a slot that then gets rejected.
    @SerializedName("opening_time") val openingTime: String? = null,
    @SerializedName("closing_time") val closingTime: String? = null,
    // Same nullable-despite-always-present Gson caveat as tags/gallery above.
    val offers: List<RestaurantOffer>? = emptyList()
)

data class RestaurantOffer(
    val id: Int,
    val title: String,
    val description: String?
)

data class MenuResponse(
    val restaurant: RestaurantDetail?,
    val categories: List<MenuCategory>
)

// ---- Cart / Checkout (Phase 3) ----

data class CartItemLine(
    @SerializedName("menu_item_id") val menuItemId: Int,
    @SerializedName("variant_id") val variantId: Int? = null,
    @SerializedName("addon_ids") val addonIds: List<Int>? = null,
    // §2.6 — per-item cooking request ("less spicy", "no onion"), distinct
    // from CreateOrderBody.deliveryInstructions (order-level). Backend reads
    // this in price_cart()/orders/create.php and stores it on order_items.
    @SerializedName("special_instructions") val specialInstructions: String? = null,
    val quantity: Int
)

data class CartValidateBody(
    @SerializedName("restaurant_id") val restaurantId: Int,
    val items: List<CartItemLine>,
    @SerializedName("coupon_code") val couponCode: String? = null,
    // Bug fix (2026-08-13) — sent whenever the cart already has a slot
    // picked, so the backend's open-hours check on this preview matches
    // the same "scheduled order, skip the right-now check" behavior as
    // the real POST /orders call. See lib/orders.php price_cart().
    @SerializedName("scheduled_for") val scheduledFor: String? = null
)

data class CartInvalidItem(
    @SerializedName("menu_item_id") val menuItemId: Int,
    val reason: String
)

data class CartTotals(
    @SerializedName("item_total") val itemTotal: Double,
    @SerializedName("discount_amount") val discountAmount: Double,
    @SerializedName("delivery_charge") val deliveryCharge: Double,
    @SerializedName("platform_fee") val platformFee: Double,
    @SerializedName("packing_charge") val packingCharge: Double,
    @SerializedName("tax_amount") val taxAmount: Double,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("invalid_items") val invalidItems: List<CartInvalidItem> = emptyList(),
    @SerializedName("min_order_amount") val minOrderAmount: Double? = null,
    val warning: String? = null,
    // recall.md Phase D item 28 / migration 47+48 — restaurant Offers
    // Engine preview (docs/29's own "checkout-screen offer strip" item,
    // app owner ask 2026-08-25). All defaulted so an older cached
    // response (or a build against a backend that hasn't deployed this
    // change yet) keeps deserializing exactly as before.
    @SerializedName("offer_id") val offerId: Int? = null,
    @SerializedName("offer_title") val offerTitle: String? = null,
    @SerializedName("offer_type") val offerType: String? = null,
    @SerializedName("offer_discount_amount") val offerDiscountAmount: Double = 0.0,
    // B1G1-style synthetic free-item row — only non-zero/non-null when
    // offerType == "buy_x_get_y". See lib/offers.php compute_offer_discount()'s
    // own kdoc for why only this offer type has a distinct free unit to
    // label (every other type discounts money off an existing line).
    @SerializedName("offer_free_units") val offerFreeUnits: Int = 0,
    @SerializedName("offer_free_item_label") val offerFreeItemLabel: String? = null,
    // Migration 48 — true when a coupon the customer applied got
    // dropped because the winning offer's allow_coupon_stacking = 0.
    @SerializedName("coupon_disabled_by_offer") val couponDisabledByOffer: Boolean = false,
    @SerializedName("free_delivery_offer_id") val freeDeliveryOfferId: Int? = null,
    @SerializedName("free_delivery_offer_title") val freeDeliveryOfferTitle: String? = null,
    @SerializedName("free_delivery_discount_amount") val freeDeliveryDiscountAmount: Double = 0.0
)

// ---- H5: Checkout "View all offers & coupons" page ----

data class CouponListResult(
    val coupons: List<CouponListItem>
)

data class CouponListItem(
    val code: String,
    // Migration 49 — "flat" | "percent" (real coupon) | "offer" (a
    // restaurant's coupon_based promo_offers row, folded into this
    // same list by coupons/list.php). "offer" has no single %/₹
    // discount_value the way a real coupon does — offerLabel carries
    // the human-readable badge (e.g. "Buy 1 Get 1 Free") instead; the
    // UI shows offerLabel when discountType == "offer", the usual
    // discountValue-based line otherwise.
    @SerializedName("discount_type") val discountType: String,
    @SerializedName("discount_value") val discountValue: Double,
    @SerializedName("offer_label") val offerLabel: String? = null,
    @SerializedName("min_order_amount") val minOrderAmount: Double,
    @SerializedName("max_discount_amount") val maxDiscountAmount: Double? = null,
    @SerializedName("valid_until") val validUntil: String? = null,
    @SerializedName("is_restaurant_specific") val isRestaurantSpecific: Boolean = false,
    @SerializedName("is_eligible") val isEligible: Boolean = true,
    @SerializedName("ineligible_reason") val ineligibleReason: String? = null,
    @SerializedName("amount_needed_to_unlock") val amountNeededToUnlock: Double? = null
)

// ---- Cart server-persistence (survives app kill/restart) ----

/** One synced item line — carries full MenuItem-ish fields on the GET
 * response (restore) so the app can rebuild a real [MenuItem] without a
 * second network call; POST (save) only needs menuItemId + quantity. */
data class CartSyncItem(
    @SerializedName("menu_item_id") val menuItemId: Int,
    val quantity: Int,
    // Present on GET (restore), ignored/omitted on POST (save).
    val name: String? = null,
    val description: String? = null,
    val price: Double? = null,
    @SerializedName("discount_percent") val discountPercent: Double? = null,
    @SerializedName("is_veg") val isVeg: Boolean? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("is_recommended") val isRecommended: Boolean? = null,
    @SerializedName("is_bestseller") val isBestseller: Boolean? = null,
    @SerializedName("prep_time_minutes") val prepTimeMinutes: Int? = null,
    // §2.6 — sent on POST (save) so a customized line's addons/notes
    // survive a sync; `addons` (the full priced addon list, needed to
    // rebuild MenuItem.addons so CartLine.unitPrice can be recomputed
    // client-side) is only ever present on GET (restore), same convention
    // as name/description/price above.
    val addons: List<MenuAddon>? = null,
    @SerializedName("addon_ids") val addonIds: List<Int>? = null,
    @SerializedName("special_instructions") val specialInstructions: String? = null
)

data class CartSyncRestaurant(
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String? = null,
    @SerializedName("coupon_code") val couponCode: String? = null,
    // I4 — "yyyy-MM-dd HH:mm:ss" or null ("Deliver Now"). Sent as-is on
    // save/restore, same convention as couponCode above; the server only
    // re-validates the real value once, at order-create time.
    @SerializedName("scheduled_for") val scheduledFor: String? = null,
    val items: List<CartSyncItem>
)

data class CartSyncBody(val carts: List<CartSyncRestaurant>)

data class CartSyncResult(val carts: List<CartSyncRestaurant> = emptyList())

data class CartSyncSaveResult(val synced: Boolean = false)

data class CreateOrderBody(
    @SerializedName("restaurant_id") val restaurantId: Int,
    val items: List<CartItemLine>,
    @SerializedName("delivery_address_id") val deliveryAddressId: Int,
    @SerializedName("payment_method") val paymentMethod: String, // "upi" | "cod"
    @SerializedName("coupon_code") val couponCode: String? = null,
    @SerializedName("delivery_instructions") val deliveryInstructions: String? = null,
    // I4 — "yyyy-MM-dd HH:mm:ss", same-day only; omit/null for a normal
    // "Deliver Now" order. Server re-validates independently (same-day,
    // 20-min minimum lead, within restaurant's open hours) — see
    // validate_scheduled_for() in backend/lib/orders.php.
    @SerializedName("scheduled_for") val scheduledFor: String? = null,
    // bugs.md #2.4 — client-generated UUID, one per place-order attempt.
    // A retried request (timeout-then-retry) that reuses the same key
    // gets the original order back instead of creating a duplicate. See
    // CheckoutActivity.placeOrder()'s kdoc for how the key's lifetime is
    // scoped.
    @SerializedName("idempotency_key") val idempotencyKey: String? = null
)

/** One addon snapshot as stored in order_items.addons_json at order time —
 * {id, name, price} per lib/orders.php's price_cart(). [id] is the actual
 * menu_item_addons.id, so Reorder (I1) can re-select the same addon on the
 * restaurant's *current* menu without a name-matching heuristic. */
data class OrderedAddonSnapshot(
    val id: Int,
    val name: String,
    val price: Double
)

data class OrderItemLine(
    val id: Int,
    // I1 (Reorder) needs this to re-match a past order's line against the
    // restaurant's *current* menu — null for any order placed before this
    // field existed, or if the menu item was later hard-deleted server-side
    // (format_order() reads it straight off order_items, which always had
    // this column; only ever null for pre-migration rows).
    @SerializedName("menu_item_id") val menuItemId: Int? = null,
    val name: String,
    @SerializedName("variant_name") val variantName: String?,
    val quantity: Int,
    @SerializedName("unit_price") val unitPrice: Double,
    // Addons the customer picked at order time (format_order() decodes
    // addons_json server-side, which stores {id, name, price} per addon) —
    // used by Reorder to re-select the same addons on the *current* menu
    // item; any addon id no longer offered by that item is silently dropped.
    val addons: List<OrderedAddonSnapshot> = emptyList(),
    val subtotal: Double
)

data class OrderStatusHistoryEntry(
    val status: String,
    @SerializedName("changed_by_type") val changedByType: String,
    val note: String?,
    @SerializedName("created_at") val createdAt: String
)

// Item 25 (Refund System, migration 42) — mirrors backend/lib/orders.php's
// format_order() $refund block exactly. `status` is one of:
// requested | under_review | approved | processing | refunded | rejected
// (refunds.status ENUM, migration 42). Null on the parent Order when no
// refund row exists for that order (the normal case).
data class RefundTimelineEntry(
    val status: String,
    val at: String
)

data class RefundInfo(
    val id: Int,
    val amount: Double,
    val reason: String,
    val status: String,
    val method: String?,
    val reference: String?,
    @SerializedName("reject_reason") val rejectReason: String?,
    @SerializedName("expected_by_date") val expectedByDate: String?,
    val timeline: List<RefundTimelineEntry> = emptyList()
)

data class Order(
    val id: Int,
    @SerializedName("order_code") val orderCode: String,
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String? = null,
    @SerializedName("rider_id") val riderId: Int? = null,
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
    // I4 — "yyyy-MM-dd HH:mm:ss" or null (a normal "Now" order).
    @SerializedName("scheduled_for") val scheduledFor: String? = null,
    @SerializedName("estimated_prep_minutes") val estimatedPrepMinutes: Int?,
    @SerializedName("created_at") val createdAt: String,
    val items: List<OrderItemLine> = emptyList(),
    @SerializedName("status_history") val statusHistory: List<OrderStatusHistoryEntry> = emptyList(),
    val refund: RefundInfo? = null
)

data class CreateOrderResult(
    val order: Order,
    @SerializedName("order_code") val orderCode: String
)

data class OrderDetailResult(val order: Order)

// ---- Native UPI Payment Gateway (doc 23, 2026-08-23) ----
// Mirrors backend/lib/payment/UpipeProvider.php's client_payload shape
// exactly — see PaymentService::initiatePayment()/rebuildClientPayloadFromRow().
// IMPORTANT: no `qr_url` field — there is deliberately no server-generated
// QR image (see UpipeProvider.php's own doc-comment on why). The QR is
// rendered on-device from `upiLink` — see UpiPaymentActivity's use of
// ZXing's QRCodeWriter.
data class UpiPaymentInitResult(
    val method: String, // "upi_qr" or "unavailable"
    @SerializedName("txn_ref") val txnRef: String? = null,
    @SerializedName("upi_link") val upiLink: String? = null,
    @SerializedName("upi_id") val upiId: String? = null,
    @SerializedName("payee_name") val payeeName: String? = null,
    val amount: Double? = null,
    @SerializedName("expires_in_sec") val expiresInSec: Int = 0,
    @SerializedName("utr_required") val utrRequired: Boolean = true,
    @SerializedName("utr_window_sec") val utrWindowSec: Int = 300,
    @SerializedName("poll_interval_sec") val pollIntervalSec: Int = 10,
    @SerializedName("is_test_mode") val isTestMode: Boolean = false,
    val instructions: List<String> = emptyList(),
    val message: String? = null, // populated when method == "unavailable"
    @SerializedName("already_paid") val alreadyPaid: Boolean = false
)

// GET .../payment/upi/status — polled every 10s. `status` is one of:
// not_started | initiated | utr_pending_window | utr_available |
// utr_submitted | success | failed | expired  (doc 23 §4).
// NEVER trust a client-side "I paid" claim — this endpoint (and this
// model) only ever reflects what the backend's own DB says; there is
// no field here the client can set to influence the result.
data class UpiPaymentStatusResult(
    val status: String,
    @SerializedName("utr_allowed_in_sec") val utrAllowedInSec: Int? = null,
    @SerializedName("reject_reason") val rejectReason: String? = null,
    @SerializedName("txn_ref") val txnRef: String? = null
)

data class SubmitUtrBody(val utr: String)

// status: utr_submitted | utr_window_not_open | invalid_utr | utr_already_used
data class SubmitUtrResult(val status: String)

data class TrackRider(
    val name: String?,
    val mobile: String?,
    val lat: Double?,
    val lng: Double?
)

data class OrderTrackResult(
    val status: String,
    val rider: TrackRider?,
    @SerializedName("eta_minutes") val etaMinutes: Int?,
    val otp: String?
)

// ---- Addresses (§1.8/§2.6 — structured form) ----

data class Address(
    val id: Int,
    val label: String?,
    @SerializedName("address_type") val addressType: String = "home", // home|work|other
    @SerializedName("full_address") val fullAddress: String,
    @SerializedName("house_flat_no") val houseFlatNo: String?,
    val floor: String?,
    val landmark: String?,
    @SerializedName("receiver_name") val receiverName: String?,
    @SerializedName("receiver_phone") val receiverPhone: String?,
    val latitude: Double?,
    val longitude: Double?,
    @SerializedName("is_default") val isDefault: Boolean,
    @SerializedName("photo_url") val photoUrl: String? = null
)

data class AddressListResult(val addresses: List<Address>)

data class AddAddressBody(
    val label: String? = null,
    @SerializedName("address_type") val addressType: String = "home",
    @SerializedName("full_address") val fullAddress: String,
    @SerializedName("house_flat_no") val houseFlatNo: String? = null,
    val floor: String? = null,
    val landmark: String? = null,
    @SerializedName("receiver_name") val receiverName: String? = null,
    @SerializedName("receiver_phone") val receiverPhone: String? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
    @SerializedName("is_default") val isDefault: Boolean = true,
    @SerializedName("photo_url") val photoUrl: String? = null
)

data class AddAddressResult(val id: Int)
data class UpdateAddressResult(val id: Int)
data class DeleteAddressResult(val deleted: Boolean)

// ---- recall.md Phase B item 15 — area-wise general payment method
// restrictions (backend/api/v1/customer/payment-methods.php). Coarse
// "is this method allowed here at all" gate — distinct from the
// finer COD-specific eligibility (backend/lib/cod_rules.php), wired
// in via CodEligibilityResult below (2026-08-23).
data class PaymentMethodsResult(
    @SerializedName("upi_allowed") val upiAllowed: Boolean = true,
    @SerializedName("cod_allowed") val codAllowed: Boolean = true,
    // item 26 §D.13 — unlike upiAllowed/codAllowed (area rail
    // restrictions), walletAllowed means "customer currently has a
    // positive balance worth showing a radio for", computed server-side
    // from wallet_balance > 0. Defaults false/0.0 so an old cached
    // response or a fresh PaymentMethodsResult() never shows a wallet
    // option it can't back up.
    @SerializedName("wallet_allowed") val walletAllowed: Boolean = false,
    @SerializedName("wallet_balance") val walletBalance: Double = 0.0
)

// Finer, per-customer COD rule (backend/lib/cod_rules.php via
// customer/cod-eligibility.php) — separate from PaymentMethodsResult's
// codAllowed, which only answers "is COD enabled in this area at all".
// A customer can be in a COD-enabled area and still be ineligible right
// now (e.g. needs N prepaid/UPI orders first, over the per-order cap,
// or hit today's COD-order limit) — `reason` is a ready-to-show string
// from the server (e.g. "Available after 3 prepaid orders"), no copy
// or threshold math needed on the app side.
data class CodEligibilityResult(
    val eligible: Boolean = true,
    val reason: String? = null,
    val rule: CodRule? = null
)

data class CodRule(
    @SerializedName("min_prepaid_orders") val minPrepaidOrders: Int? = null,
    @SerializedName("max_cod_order_amount") val maxCodOrderAmount: Double? = null,
    @SerializedName("max_cod_orders_per_day") val maxCodOrdersPerDay: Int? = null
)

// ---- H6 part 2 — door/building photo upload (map pin-drop screen) ----
data class AddressPhotoUploadResult(@SerializedName("photo_url") val photoUrl: String)

// ---- Promo carousel (§2.2) ----

data class PromoBanner(
    val id: Int,
    val title: String?,
    val subtitle: String?,
    @SerializedName("image_url") val imageUrl: String,
    @SerializedName("target_type") val targetType: String, // none|restaurant|category|url
    @SerializedName("target_value") val targetValue: String?
)

data class PromoBannersResult(val banners: List<PromoBanner>)

// ---- Popular dishes near you (§2.4) ----

data class PopularItem(
    val id: Int,
    val name: String,
    val description: String?,
    val price: Double,
    @SerializedName("discount_percent") val discountPercent: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("image_url") val imageUrl: String?,
    @SerializedName("is_recommended") val isRecommended: Boolean,
    @SerializedName("is_bestseller") val isBestseller: Boolean,
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_logo_url") val restaurantLogoUrl: String?,
    @SerializedName("restaurant_rating") val restaurantRating: Double,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("is_saved") val isSaved: Boolean = false,
    // Offers Engine badge (app owner ask, 2026-08-25) — same field
    // popular-items.php now returns, same defaulted-null convention.
    @SerializedName("offer_tag") val offerTag: String? = null
)

/** Same rationale as SearchItem.toMenuItem() above — PopularItem has an
 * identical field shape minus restaurantIsOpenNow/isCrossRestaurantMatch. */
fun PopularItem.toMenuItem(): MenuItem = MenuItem(
    id = id,
    name = name,
    description = description,
    price = price,
    discountPercent = discountPercent,
    isVeg = isVeg,
    imageUrl = imageUrl,
    isRecommended = isRecommended,
    isBestseller = isBestseller,
    prepTimeMinutes = 0,
    isSaved = isSaved,
    offerTag = offerTag
)

data class PopularItemsResult(val items: List<PopularItem>)

// ---- "Offers" category chip browse screen (docs/33/34) ----

/**
 * One restaurant section on the Offers screen — a restaurant currently
 * running at least one browsable offer, plus just the items on its menu
 * that offer actually badges. Mirrors offers-browse.php's response shape
 * exactly (docs/33's own spec, built docs/34).
 */
data class OfferBrowseRestaurant(
    val id: Int,
    val name: String,
    @SerializedName("logo_url") val logoUrl: String?,
    @SerializedName("rating_avg") val ratingAvg: Double,
    @SerializedName("distance_km") val distanceKm: Double?,
    @SerializedName("offer_titles") val offerTitles: List<String> = emptyList(),
    val items: List<OfferBrowseItem> = emptyList()
)

data class OfferBrowseItem(
    val id: Int,
    val name: String,
    @SerializedName("image_url") val imageUrl: String?,
    val price: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("offer_tag") val offerTag: String?
)

/** Same rationale as PopularItem/SearchItem's own toMenuItem() — this
 * screen's item cards need a MenuItem to hand to
 * ItemDetailBottomSheetFragment/CartAddHelper, same as every other
 * cross-restaurant listing in the app. offers-browse.php's row is
 * deliberately terser than MenuItem (no description/prepTimeMinutes/etc
 * — this screen doesn't need them), so the rest default the same way
 * PopularItem.toMenuItem() already defaults prepTimeMinutes. */
fun OfferBrowseItem.toMenuItem(): MenuItem = MenuItem(
    id = id,
    name = name,
    description = null,
    price = price,
    discountPercent = 0.0,
    isVeg = isVeg,
    imageUrl = imageUrl,
    isRecommended = false,
    isBestseller = false,
    prepTimeMinutes = 0,
    offerTag = offerTag
)

data class OffersBrowseResult(val restaurants: List<OfferBrowseRestaurant> = emptyList())

// ---- Favorites / bookmarks (§2.5) ----

data class ToggleFavoriteBody(
    @SerializedName("favorite_type") val favoriteType: String, // restaurant|menu_item
    @SerializedName("favorite_id") val favoriteId: Int
)

data class ToggleFavoriteResult(@SerializedName("is_saved") val isSaved: Boolean)

data class FavoriteRestaurant(
    val id: Int,
    val name: String,
    @SerializedName("logo_url") val logoUrl: String?,
    @SerializedName("cover_url") val coverUrl: String?,
    @SerializedName("rating_avg") val ratingAvg: Double
)

data class FavoriteItem(
    val id: Int,
    val name: String,
    @SerializedName("image_url") val imageUrl: String?,
    val price: Double,
    @SerializedName("is_veg") val isVeg: Boolean,
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String?
)

data class FavoritesResult(
    val restaurants: List<FavoriteRestaurant>,
    val items: List<FavoriteItem>
)

// ---- Order history (Profile → Order History, §2.7) ----

data class OrderHistoryEntry(
    val id: Int,
    @SerializedName("order_code") val orderCode: String,
    @SerializedName("restaurant_id") val restaurantId: Int,
    @SerializedName("restaurant_name") val restaurantName: String,
    @SerializedName("restaurant_cover_url") val restaurantCoverUrl: String?,
    val status: String,
    @SerializedName("grand_total") val grandTotal: Double,
    @SerializedName("payment_method") val paymentMethod: String,
    @SerializedName("item_count") val itemCount: Int,
    @SerializedName("is_rated") val isRated: Boolean = false,
    @SerializedName("has_rider") val hasRider: Boolean = false,
    @SerializedName("created_at") val createdAt: String
)

data class OrderHistoryResult(
    val orders: List<OrderHistoryEntry>,
    val page: Int,
    @SerializedName("per_page") val perPage: Int,
    val total: Int,
    @SerializedName("has_more") val hasMore: Boolean
)

// ---- Rating system (Part 13) ----

data class SubmitReviewBody(
    @SerializedName("order_id") val orderId: Int,
    @SerializedName("restaurant_rating") val restaurantRating: Int,
    @SerializedName("food_rating") val foodRating: Int? = null,
    @SerializedName("delivery_rating") val deliveryRating: Int? = null,
    val comment: String? = null
)

data class SubmitReviewResult(val id: Int)

data class ExistingReview(
    val id: Int,
    @SerializedName("order_id") val orderId: Int,
    @SerializedName("restaurant_rating") val restaurantRating: Int?,
    @SerializedName("food_rating") val foodRating: Int?,
    @SerializedName("delivery_rating") val deliveryRating: Int?,
    val comment: String?,
    @SerializedName("created_at") val createdAt: String
)

data class GetReviewResult(val review: ExistingReview?)

// ---- FAQs (§2.7) ----

data class FaqEntry(val id: Int, val question: String, val answer: String)
data class FaqsResult(val faqs: List<FaqEntry>)

// ---- Feedback (§2.7) ----

data class SubmitFeedbackBody(val message: String, val rating: Int? = null)
data class SubmitFeedbackResult(val id: Int)

// ---- Notification bell (docs/Status.md 2026-08-20 "Notification bell",
// Type 1 — system-generated only; see backend/lib/notifications.php) ----

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

// ---- item 26 §D.15 — Customer Wallet screen (Profile → Wallet).
// Field-for-field mirror of backend/api/v1/customer/wallet.php's
// response shape (confirmed by reading that file, not assumed) —
// same discipline as RefundInfo/RefundTimelineEntry mirroring
// format_order()'s refund object. type/reason are left as raw
// String rather than a Kotlin enum since wallet_transactions.reason's
// ENUM ('refund','admin_adjustment','cashback','order_payment') and
// type ('credit'/'debit') may grow server-side before this screen's
// next update — same "raw string, render defensively" choice
// RefundInfo.status/method already made.
data class WalletTransaction(
    val id: Int,
    @SerializedName("order_id") val orderId: Int?,
    val type: String, // "credit" | "debit"
    val amount: Double,
    val reason: String, // "refund" | "admin_adjustment" | "cashback" | "order_payment"
    val note: String?,
    @SerializedName("balance_after") val balanceAfter: Double,
    @SerializedName("created_at") val createdAt: String
)

data class WalletResult(
    val balance: Double,
    val transactions: List<WalletTransaction> = emptyList()
)
