<?php
/**
 * POST /api/v1/restaurant/offers-create.php
 * Auth: Restaurant token
 * Request: {
 *   "offer_type": "quantity_deal"|"buy_x_for_y"|"buy_x_get_y"|
 *                 "percent_discount"|"flat_discount"|"free_delivery",
 *   "title", "scope": "item"|"category"|"restaurant",
 *   "menu_item_id"?, "food_category_id"?,
 *   "required_qty"?, "get_qty"?, "offer_price"?,
 *   "discount_percent"?, "discount_flat"?, "max_discount_amount"?,
 *   "min_order_amount"?, "customer_eligibility"?,
 *   "start_date"?, "end_date"?, "start_time"?, "end_time"?, "weekdays"?,
 *   "daily_limit"?, "total_limit"?, "per_customer_limit"?
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

$validTypes = ['quantity_deal', 'buy_x_for_y', 'buy_x_get_y', 'percent_discount', 'flat_discount', 'free_delivery'];
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
}
// free_delivery needs none of the above — min_order_amount (below) is
// its only real lever, per doc 20 §2's own examples.

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

$insert = $db->prepare(
    'INSERT INTO promo_offers (
        restaurant_id, offer_type, title, scope, menu_item_id, food_category_id,
        required_qty, get_qty, offer_price, discount_percent, discount_flat, max_discount_amount,
        min_order_amount, customer_eligibility, start_date, end_date, start_time, end_time, weekdays,
        daily_limit, total_limit, per_customer_limit, allow_coupon_stacking, status
    ) VALUES (
        :rid, :type, :title, :scope, :mid, :cid,
        :reqqty, :getqty, :oprice, :dpercent, :dflat, :maxdiscount,
        :minorder, :eligibility, :sdate, :edate, :stime, :etime, :weekdays,
        :daily, :total, :percustomer, :allowcoupon, \'active\'
    )'
);
$insert->execute([
    'rid' => $restaurantId,
    'type' => $offerType,
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

$fetchStmt = $db->prepare('SELECT * FROM promo_offers WHERE id = :id LIMIT 1');
$fetchStmt->execute(['id' => $newId]);

respond_ok(['offer' => format_offer($fetchStmt->fetch())], 201);
