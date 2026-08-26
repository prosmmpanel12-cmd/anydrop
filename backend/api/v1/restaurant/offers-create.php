<?php
/**
 * POST /api/v1/restaurant/offers-create.php
 * Auth: Restaurant token
 * Request: {
 *   "offer_type": "quantity_deal"|"buy_x_for_y"|"buy_x_get_y"|
 *                 "percent_discount"|"flat_discount"|"free_delivery"|
 *                 "combo" (migration 50, docs/40),
 *   "title", "scope": "item"|"category"|"restaurant",
 *   "menu_item_id"?, "food_category_id"?,
 *   "required_qty"?, "get_qty"?, "offer_price"?,
 *   "discount_percent"?, "discount_flat"?, "max_discount_amount"?,
 *   "min_order_amount"?, "customer_eligibility"?,
 *   "start_date"?, "end_date"?, "start_time"?, "end_time"?, "weekdays"?,
 *   "daily_limit"?, "total_limit"?, "per_customer_limit"?,
 *   "apply_mode"?: "default"|"coupon_based" (default "default"),
 *   "code"?: required, unique, when apply_mode="coupon_based",
 *   "is_public"?: bool (default true, coupon_based only — see below),
 *   "combo_items"?: [{ "menu_item_id", "required_qty" }, ...] —
 *     required (2+ distinct items) when offer_type="combo", ignored
 *     otherwise. `scope` is forced to "restaurant" server-side for a
 *     combo regardless of what's sent (see migration 50).
 * }
 * Response: { "offer": {...format_offer()} }
 *
 * Always scoped to the calling restaurant (restaurant_id = the
 * caller, same as coupons-create.php — no platform-wide restaurant
 * offer concept exists, unlike coupons which admin can create with
 * restaurant_id NULL). Starts status='active' — v1 has no admin
 * pre-publish approval queue (migration 47's header explains why),
 * so a restaurant's offer goes live for real customers the instant
 * this call succeeds. An admin can pause/disable it afterward via
 * admin/offers.php.
 *
 * Per-offer_type field validation mirrors lib/offers.php's own
 * compute_offer_discount() switch — every field that function reads
 * for a given type must be present and sane here, or a restaurant
 * could create an offer the pricing engine silently treats as a
 * zero-discount no-op (a confusing, hard-to-debug failure mode for
 * the restaurant, worse than a clear validation_error now).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/offers.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['offer_type', 'title', 'scope']);

$validTypes = ['quantity_deal', 'buy_x_for_y', 'buy_x_get_y', 'percent_discount', 'flat_discount', 'free_delivery', 'combo'];
$offerType = (string) $body['offer_type'];
if (!in_array($offerType, $validTypes, true)) {
    respond_error('validation_error', 422, ['fields' => ['offer_type']]);
}

$title = trim((string) $body['title']);
if ($title === '' || mb_strlen($title) > 150) {
    respond_error('validation_error', 422, ['fields' => ['title']]);
}

$scope = (string) $body['scope'];
if (!in_array($scope, ['item', 'category', 'restaurant'], true)) {
    respond_error('validation_error', 422, ['fields' => ['scope']]);
}

// Quantity-mechanic offers only make sense against a specific item or
// category — see migration 47's own header comment on why this isn't
// a DB-level CHECK constraint.
$quantityTypes = ['quantity_deal', 'buy_x_for_y', 'buy_x_get_y'];
if (in_array($offerType, $quantityTypes, true) && $scope === 'restaurant') {
    respond_error('validation_error', 422, ['fields' => ['scope']]);
}

// Migration 50 / docs/40 — a combo's matching is entirely driven by
// offer_combo_items, not `scope` (see that migration's own header).
// `scope` is forced to 'restaurant' here regardless of what the
// client sent, same "column stays non-NULL but unused" contract the
// migration documents, so a combo never triggers the item/category
// ownership checks below for a value that wouldn't be read anyway.
if ($offerType === 'combo') {
    $scope = 'restaurant';
}

$db = Database::get();

$menuItemId = null;
$foodCategoryId = null;
if ($scope === 'item') {
    if (empty($body['menu_item_id'])) {
        respond_error('validation_error', 422, ['fields' => ['menu_item_id']]);
    }
    $menuItemId = (int) $body['menu_item_id'];
    // Ownership check — a restaurant can only scope an offer to its
    // own menu item, same boundary menu-items-update.php enforces.
    $checkStmt = $db->prepare('SELECT id FROM menu_items WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1');
    $checkStmt->execute(['id' => $menuItemId, 'rid' => $restaurantId]);
    if (!$checkStmt->fetch()) {
        respond_error('validation_error', 422, ['fields' => ['menu_item_id']]);
    }
} elseif ($scope === 'category') {
    if (empty($body['food_category_id'])) {
        respond_error('validation_error', 422, ['fields' => ['food_category_id']]);
    }
    $foodCategoryId = (int) $body['food_category_id'];
    $checkStmt = $db->prepare('SELECT id FROM food_categories WHERE id = :id LIMIT 1');
    $checkStmt->execute(['id' => $foodCategoryId]);
    if (!$checkStmt->fetch()) {
        respond_error('validation_error', 422, ['fields' => ['food_category_id']]);
    }
}

$requiredQty = null;
$getQty = null;
$offerPrice = null;
$discountPercent = null;
$discountFlat = null;

if (in_array($offerType, ['quantity_deal', 'buy_x_for_y'], true)) {
    if (empty($body['required_qty']) || empty($body['offer_price'])) {
        respond_error('validation_error', 422, ['fields' => ['required_qty', 'offer_price']]);
    }
    $requiredQty = (int) $body['required_qty'];
    $offerPrice = (float) $body['offer_price'];
    if ($requiredQty < 1 || $offerPrice <= 0) {
        respond_error('validation_error', 422, ['fields' => ['required_qty', 'offer_price']]);
    }
} elseif ($offerType === 'buy_x_get_y') {
    if (empty($body['required_qty']) || empty($body['get_qty'])) {
        respond_error('validation_error', 422, ['fields' => ['required_qty', 'get_qty']]);
    }
    $requiredQty = (int) $body['required_qty'];
    $getQty = (int) $body['get_qty'];
    if ($requiredQty < 1 || $getQty < 1) {
        respond_error('validation_error', 422, ['fields' => ['required_qty', 'get_qty']]);
    }
} elseif ($offerType === 'percent_discount') {
    if (empty($body['discount_percent'])) {
        respond_error('validation_error', 422, ['fields' => ['discount_percent']]);
    }
    $discountPercent = (float) $body['discount_percent'];
    if ($discountPercent <= 0 || $discountPercent > 100) {
        respond_error('validation_error', 422, ['fields' => ['discount_percent']]);
    }
} elseif ($offerType === 'flat_discount') {
    if (empty($body['discount_flat'])) {
        respond_error('validation_error', 422, ['fields' => ['discount_flat']]);
    }
    $discountFlat = (float) $body['discount_flat'];
    if ($discountFlat <= 0) {
        respond_error('validation_error', 422, ['fields' => ['discount_flat']]);
    }
} elseif ($offerType === 'combo') {
    // Migration 50 — offer_price is reused as the combo's fixed bundle
    // price, same field quantity_deal/buy_x_for_y already validate
    // above, just for a different offer_type.
    if (empty($body['offer_price'])) {
        respond_error('validation_error', 422, ['fields' => ['offer_price']]);
    }
    $offerPrice = (float) $body['offer_price'];
    if ($offerPrice <= 0) {
        respond_error('validation_error', 422, ['fields' => ['offer_price']]);
    }
}
// free_delivery needs none of the above — min_order_amount (below) is
// its only real lever, per doc 20 §2's own examples.

// combo_items — required only for offer_type='combo'. Each entry must
// name a distinct menu_item_id (ownership-checked, same boundary the
// scope='item' branch above enforces) with its own required_qty >= 1.
// De-duplicated by menu_item_id (last one wins) rather than rejected
// outright on a client-side duplicate, since the DB's own
// uniq_combo_offer_item index would otherwise turn an honest UI mistake
// into a raw SQL error instead of a clean validation_error.
$comboItems = [];
if ($offerType === 'combo') {
    $rawComboItems = $body['combo_items'] ?? null;
    if (!is_array($rawComboItems) || count($rawComboItems) < 2) {
        // A "combo" of fewer than 2 distinct items is just a regular
        // single-item offer wearing a combo costume — reject early
        // rather than let compute_offer_discount()'s combo case accept
        // a degenerate one-item bundle silently.
        respond_error('validation_error', 422, ['fields' => ['combo_items']]);
    }
    $byMenuItemId = [];
    foreach ($rawComboItems as $ci) {
        if (!is_array($ci) || empty($ci['menu_item_id']) || empty($ci['required_qty'])) {
            respond_error('validation_error', 422, ['fields' => ['combo_items']]);
        }
        $ciMenuItemId = (int) $ci['menu_item_id'];
        $ciRequiredQty = (int) $ci['required_qty'];
        if ($ciMenuItemId <= 0 || $ciRequiredQty < 1) {
            respond_error('validation_error', 422, ['fields' => ['combo_items']]);
        }
        $byMenuItemId[$ciMenuItemId] = $ciRequiredQty;
    }
    if (count($byMenuItemId) < 2) {
        respond_error('validation_error', 422, ['fields' => ['combo_items']]);
    }
    // Ownership check — every combo item must be this restaurant's own,
    // non-deleted menu item, same boundary scope='item' enforces above.
    // Batched into one IN() query rather than per-item round trips.
    $placeholders = implode(',', array_fill(0, count($byMenuItemId), '?'));
    $ownCheck = $db->prepare(
        "SELECT id FROM menu_items WHERE id IN ($placeholders) AND restaurant_id = ? AND deleted_at IS NULL"
    );
    $ownCheck->execute([...array_keys($byMenuItemId), $restaurantId]);
    $ownedIds = array_map(fn ($r) => (int) $r['id'], $ownCheck->fetchAll());
    if (count($ownedIds) !== count($byMenuItemId)) {
        respond_error('validation_error', 422, ['fields' => ['combo_items']]);
    }
    foreach ($byMenuItemId as $mid => $reqQty) {
        $comboItems[] = ['menu_item_id' => $mid, 'required_qty' => $reqQty];
    }
}

$maxDiscountAmount = isset($body['max_discount_amount']) && $body['max_discount_amount'] !== null
    ? (float) $body['max_discount_amount'] : null;
$minOrderAmount = isset($body['min_order_amount']) ? max(0.0, (float) $body['min_order_amount']) : 0.0;

$customerEligibility = (string) ($body['customer_eligibility'] ?? 'all');
if (!in_array($customerEligibility, ['all', 'new_customer', 'existing_customer'], true)) {
    respond_error('validation_error', 422, ['fields' => ['customer_eligibility']]);
}

$startDate = !empty($body['start_date']) ? (string) $body['start_date'] : null;
$endDate = !empty($body['end_date']) ? (string) $body['end_date'] : null;
$startTime = !empty($body['start_time']) ? (string) $body['start_time'] : null;
$endTime = !empty($body['end_time']) ? (string) $body['end_time'] : null;
// weekdays: CSV of 1-7, same convention as restaurants.working_days —
// re-serialized from whatever array/CSV shape the client sent so a
// stray space or trailing comma from the app's UI never lands
// verbatim in the DB and silently fails is_offer_time_eligible()'s
// in_array() string comparison later.
$weekdays = null;
if (!empty($body['weekdays'])) {
    $rawDays = is_array($body['weekdays']) ? $body['weekdays'] : explode(',', (string) $body['weekdays']);
    $cleanDays = array_values(array_unique(array_filter(array_map(function ($d) {
        $d = (int) trim((string) $d);
        return ($d >= 1 && $d <= 7) ? $d : null;
    }, $rawDays), fn ($d) => $d !== null)));
    if (!empty($cleanDays)) {
        $weekdays = implode(',', $cleanDays);
    }
}

$dailyLimit = isset($body['daily_limit']) && $body['daily_limit'] !== null ? (int) $body['daily_limit'] : null;
$totalLimit = isset($body['total_limit']) && $body['total_limit'] !== null ? (int) $body['total_limit'] : null;
$perCustomerLimit = isset($body['per_customer_limit']) && $body['per_customer_limit'] !== null ? (int) $body['per_customer_limit'] : null;

// Migration 48 — "allow this offer to also combine with a coupon"
// toggle. Defaults true (1) when omitted, matching the column's own
// DB default, so an older client that doesn't send this field yet
// keeps producing offers with today's always-stackable behavior.
$allowCouponStacking = array_key_exists('allow_coupon_stacking', $body)
    ? (int) (bool) $body['allow_coupon_stacking']
    : 1;

// Migration 49 — apply_mode: 'default' (today's unchanged auto-apply
// behavior, no code) vs 'coupon_based' (same offer mechanics, but only
// considered at checkout when the customer types `code` — see
// lib/offers.php's find_coupon_based_offer_by_code()). code/is_public
// only make sense for coupon_based; kept null/default for a 'default'
// offer the same way quantity/percent/flat fields above are only
// populated for their own relevant offer_type.
$applyMode = (string) ($body['apply_mode'] ?? 'default');
if (!in_array($applyMode, ['default', 'coupon_based'], true)) {
    respond_error('validation_error', 422, ['fields' => ['apply_mode']]);
}

$code = null;
$isPublic = 1;
if ($applyMode === 'coupon_based') {
    if (empty($body['code'])) {
        respond_error('validation_error', 422, ['fields' => ['code']]);
    }
    $code = strtoupper(trim((string) $body['code']));
    if ($code === '' || mb_strlen($code) > 50) {
        respond_error('validation_error', 422, ['fields' => ['code']]);
    }
    // Uniqueness pre-check — friendlier than letting the uniq_offer_code
    // index reject it with a raw SQL error; the index itself remains the
    // real guarantee against a race between this check and the insert.
    $dupStmt = $db->prepare('SELECT id FROM promo_offers WHERE code = :code LIMIT 1');
    $dupStmt->execute(['code' => $code]);
    if ($dupStmt->fetch()) {
        respond_error('validation_error', 422, ['fields' => ['code'], 'reason' => 'code_already_in_use']);
    }
    $isPublic = array_key_exists('is_public', $body) ? (int) (bool) $body['is_public'] : 1;
}

// docs/40 Step 3b — a combo needs two writes (promo_offers +
// offer_combo_items) that must succeed or fail together: a combo row
// with no items would silently price as a zero-discount no-op (see
// get_offer_combo_items()'s own kdoc), and an orphaned offer_combo_items
// row with no parent would violate its own FK. Every other offer_type
// only ever does the single promo_offers insert, so the transaction
// wraps both but costs those types nothing extra.
$db->beginTransaction();
try {
    $insert = $db->prepare(
        'INSERT INTO promo_offers (
            restaurant_id, offer_type, apply_mode, code, is_public, title, scope, menu_item_id, food_category_id,
            required_qty, get_qty, offer_price, discount_percent, discount_flat, max_discount_amount,
            min_order_amount, customer_eligibility, start_date, end_date, start_time, end_time, weekdays,
            daily_limit, total_limit, per_customer_limit, allow_coupon_stacking, status
        ) VALUES (
            :rid, :type, :applymode, :code, :ispublic, :title, :scope, :mid, :cid,
            :reqqty, :getqty, :oprice, :dpercent, :dflat, :maxdiscount,
            :minorder, :eligibility, :sdate, :edate, :stime, :etime, :weekdays,
            :daily, :total, :percustomer, :allowcoupon, \'active\'
        )'
    );
    $insert->execute([
        'rid' => $restaurantId,
        'type' => $offerType,
        'applymode' => $applyMode,
        'code' => $code,
        'ispublic' => $isPublic,
        'title' => $title,
        'scope' => $scope,
        'mid' => $menuItemId,
        'cid' => $foodCategoryId,
        'reqqty' => $requiredQty,
        'getqty' => $getQty,
        'oprice' => $offerPrice,
        'dpercent' => $discountPercent,
        'dflat' => $discountFlat,
        'maxdiscount' => $maxDiscountAmount,
        'minorder' => $minOrderAmount,
        'eligibility' => $customerEligibility,
        'sdate' => $startDate,
        'edate' => $endDate,
        'stime' => $startTime,
        'etime' => $endTime,
        'weekdays' => $weekdays,
        'daily' => $dailyLimit,
        'total' => $totalLimit,
        'percustomer' => $perCustomerLimit,
        'allowcoupon' => $allowCouponStacking,
    ]);
    $newId = (int) $db->lastInsertId();

    if ($offerType === 'combo') {
        $itemInsert = $db->prepare(
            'INSERT INTO offer_combo_items (offer_id, menu_item_id, required_qty) VALUES (:oid, :mid, :qty)'
        );
        foreach ($comboItems as $ci) {
            $itemInsert->execute(['oid' => $newId, 'mid' => $ci['menu_item_id'], 'qty' => $ci['required_qty']]);
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$fetchStmt = $db->prepare('SELECT * FROM promo_offers WHERE id = :id LIMIT 1');
$fetchStmt->execute(['id' => $newId]);

respond_ok(['offer' => format_offer($fetchStmt->fetch(), $db)], 201);
