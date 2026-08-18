<?php
/**
 * POST /api/v1/restaurant/coupons-update.php?id=
 * Auth: Restaurant token
 * Request: partial — any of { "is_active", "discount_value",
 *   "min_order_amount", "max_discount_amount", "valid_until",
 *   "usage_limit_total", "usage_limit_per_user" }. Same null-skip
 *   partial-update convention as menu-items-update.php — only fields
 *   present in the body are touched.
 * Response: { "coupon": {...} }
 *
 * Ownership-checked (coupon must belong to the calling restaurant), same
 * pattern as menu-items-update.php/categories-update.php. This is the
 * "on/off visibility toggle" doc 07 §2.1 asks for — the app's Coupon
 * Manager screen flips `is_active` here rather than deleting the row, so
 * usage history / coupon_usages stays intact either way.
 *
 * code and discount_type are deliberately not editable after creation —
 * changing either could retroactively confuse coupon_usages history tied
 * to the original code/type. Delete-and-recreate is the intended path if
 * either needs to change.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();

$existing = $db->prepare('SELECT * FROM coupons WHERE id = :id AND restaurant_id = :rid');
$existing->execute(['id' => $id, 'rid' => $restaurantId]);
$row = $existing->fetch();
if (!$row) {
    respond_error('not_found', 404);
}

$body = get_json_body();

$fieldsSql = [];
$params = ['id' => $id];

if (array_key_exists('is_active', $body)) {
    $fieldsSql[] = 'is_active = :is_active';
    $params['is_active'] = $body['is_active'] ? 1 : 0;
}
if (array_key_exists('discount_value', $body) && $body['discount_value'] !== null) {
    $fieldsSql[] = 'discount_value = :discount_value';
    $params['discount_value'] = (float) $body['discount_value'];
}
if (array_key_exists('min_order_amount', $body) && $body['min_order_amount'] !== null) {
    $fieldsSql[] = 'min_order_amount = :min_order_amount';
    $params['min_order_amount'] = (float) $body['min_order_amount'];
}
if (array_key_exists('max_discount_amount', $body)) {
    $fieldsSql[] = 'max_discount_amount = :max_discount_amount';
    $params['max_discount_amount'] = $body['max_discount_amount'] !== null ? (float) $body['max_discount_amount'] : null;
}
if (array_key_exists('valid_until', $body)) {
    $fieldsSql[] = 'valid_until = :valid_until';
    $params['valid_until'] = $body['valid_until'] !== null && $body['valid_until'] !== '' ? (string) $body['valid_until'] : null;
}
if (array_key_exists('usage_limit_total', $body)) {
    $fieldsSql[] = 'usage_limit_total = :usage_limit_total';
    $params['usage_limit_total'] = $body['usage_limit_total'] !== null ? (int) $body['usage_limit_total'] : null;
}
if (array_key_exists('usage_limit_per_user', $body)) {
    $fieldsSql[] = 'usage_limit_per_user = :usage_limit_per_user';
    $params['usage_limit_per_user'] = $body['usage_limit_per_user'] !== null ? (int) $body['usage_limit_per_user'] : null;
}

if (count($fieldsSql) > 0) {
    $sql = 'UPDATE coupons SET ' . implode(', ', $fieldsSql) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);
}

$fresh = $db->prepare(
    'SELECT c.*, (SELECT COUNT(*) FROM coupon_usages u WHERE u.coupon_id = c.id) AS times_used
     FROM coupons c WHERE c.id = :id'
);
$fresh->execute(['id' => $id]);
$r = $fresh->fetch();

respond_ok([
    'coupon' => [
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
    ],
]);
