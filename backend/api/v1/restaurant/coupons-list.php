<?php
/**
 * GET /api/v1/restaurant/coupons-list.php
 * Auth: Restaurant token
 * Response: { "coupons": [{ id, code, discount_type, discount_value,
 *   min_order_amount, max_discount_amount, valid_from, valid_until,
 *   usage_limit_total, usage_limit_per_user, is_active, is_public,
 *   times_used }] }
 *
 * Scoped strictly to coupons.restaurant_id = the logged-in restaurant
 * (never platform-wide coupons, restaurant_id IS NULL rows — those are
 * admin-only, per 07_Phase_3.7_Bug_Tracker.md §2.1's own boundary: "My
 * Coupons" only ever shows/edits this restaurant's own codes). Same
 * ownership pattern as categories-list.php.
 *
 * times_used is a live COUNT against coupon_usages (01_Database_Schema.md
 * §6), same "never drifts out of sync" reasoning categories-list.php's
 * item_count subquery already uses for a different table.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();

$stmt = $db->prepare(
    'SELECT c.id, c.code, c.discount_type, c.discount_value,
            c.min_order_amount, c.max_discount_amount, c.valid_from,
            c.valid_until, c.usage_limit_total, c.usage_limit_per_user,
            c.is_active, c.is_public,
            (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id = c.id) AS times_used
     FROM coupons c
     WHERE c.restaurant_id = :rid
     ORDER BY c.id DESC'
);
$stmt->execute(['rid' => $restaurantId]);
$rows = $stmt->fetchAll();

$coupons = array_map(function ($r) {
    return [
        'id' => (int) $r['id'],
        'code' => $r['code'],
        'discount_type' => $r['discount_type'],
        'discount_value' => (float) $r['discount_value'],
        'min_order_amount' => (float) $r['min_order_amount'],
        'max_discount_amount' => $r['max_discount_amount'] !== null ? (float) $r['max_discount_amount'] : null,
        'valid_from' => $r['valid_from'],
        'valid_until' => $r['valid_until'],
        'usage_limit_total' => $r['usage_limit_total'] !== null ? (int) $r['usage_limit_total'] : null,
        'usage_limit_per_user' => $r['usage_limit_per_user'] !== null ? (int) $r['usage_limit_per_user'] : null,
        'is_active' => (bool) $r['is_active'],
        'is_public' => (bool) $r['is_public'],
        'times_used' => (int) $r['times_used'],
    ];
}, $rows);

respond_ok(['coupons' => $coupons]);
