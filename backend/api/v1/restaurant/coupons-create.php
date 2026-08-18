<?php
/**
 * POST /api/v1/restaurant/coupons-create.php
 * Auth: Restaurant token
 * Request: { "code", "discount_type": "flat"|"percent", "discount_value",
 *   "min_order_amount"?, "max_discount_amount"?, "valid_from"?, "valid_until"?,
 *   "usage_limit_total"?, "usage_limit_per_user"? }
 * Response: { "coupon": {...} }
 *
 * Per doc 07 §2.1: a restaurant-created coupon is always scoped to that
 * restaurant (restaurant_id = the caller, never NULL/platform-wide — only
 * an admin tool, not built, could create those) and always starts
 * is_public = 0 (18_migration_coupon_is_public.sql's own kdoc already
 * flags this as the intended write-path default) and is_active = 1 —
 * "visibility toggle" (doc 07's own phrase) is what coupons-update.php's
 * is_active flip is for, not is_public; is_public instead controls
 * whether coupons/list.php auto-suggests it on the customer "view all
 * offers" screen versus needing the exact code typed in. Both together
 * give the restaurant real on/off control without silently exposing a
 * private/targeted code the moment it's created.
 *
 * code is upper-cased and must be unique across ALL coupons (platform-
 * wide + every restaurant) since coupons.code has a UNIQUE index
 * (01_Database_Schema.md §6) and /cart/validate looks a code up with no
 * restaurant scoping until after the row is found.
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

$body = get_json_body();
require_fields($body, ['code', 'discount_type', 'discount_value']);

$code = strtoupper(trim((string) $body['code']));
if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,20}$/', $code)) {
    respond_error('validation_error', 422, ['fields' => ['code']]);
}

$discountType = (string) $body['discount_type'];
if (!in_array($discountType, ['flat', 'percent'], true)) {
    respond_error('validation_error', 422, ['fields' => ['discount_type']]);
}

$discountValue = (float) $body['discount_value'];
if ($discountValue <= 0 || ($discountType === 'percent' && $discountValue > 100)) {
    respond_error('validation_error', 422, ['fields' => ['discount_value']]);
}

$minOrderAmount = isset($body['min_order_amount']) ? (float) $body['min_order_amount'] : 0.0;
$maxDiscountAmount = isset($body['max_discount_amount']) && $body['max_discount_amount'] !== null
    ? (float) $body['max_discount_amount'] : null;
$validFrom = isset($body['valid_from']) && $body['valid_from'] !== '' ? (string) $body['valid_from'] : date('Y-m-d H:i:s');
$validUntil = isset($body['valid_until']) && $body['valid_until'] !== '' ? (string) $body['valid_until'] : null;
$usageLimitTotal = isset($body['usage_limit_total']) && $body['usage_limit_total'] !== null ? (int) $body['usage_limit_total'] : null;
$usageLimitPerUser = isset($body['usage_limit_per_user']) && $body['usage_limit_per_user'] !== null ? (int) $body['usage_limit_per_user'] : null;

$db = Database::get();

$dupe = $db->prepare('SELECT id FROM coupons WHERE code = :code');
$dupe->execute(['code' => $code]);
if ($dupe->fetch()) {
    respond_error('coupon_code_taken', 409, ['fields' => ['code']]);
}

$insert = $db->prepare(
    'INSERT INTO coupons
        (code, restaurant_id, discount_type, discount_value, min_order_amount,
         max_discount_amount, valid_from, valid_until, usage_limit_total,
         usage_limit_per_user, is_active, is_public)
     VALUES
        (:code, :rid, :dtype, :dvalue, :minorder, :maxdiscount, :vfrom, :vuntil,
         :limtotal, :limuser, 1, 0)'
);
$insert->execute([
    'code' => $code,
    'rid' => $restaurantId,
    'dtype' => $discountType,
    'dvalue' => $discountValue,
    'minorder' => $minOrderAmount,
    'maxdiscount' => $maxDiscountAmount,
    'vfrom' => $validFrom,
    'vuntil' => $validUntil,
    'limtotal' => $usageLimitTotal,
    'limuser' => $usageLimitPerUser,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'coupon' => [
        'id' => $newId,
        'code' => $code,
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'min_order_amount' => $minOrderAmount,
        'max_discount_amount' => $maxDiscountAmount,
        'valid_from' => $validFrom,
        'valid_until' => $validUntil,
        'usage_limit_total' => $usageLimitTotal,
        'usage_limit_per_user' => $usageLimitPerUser,
        'is_active' => true,
        'is_public' => false,
        'times_used' => 0,
    ],
], 201);
