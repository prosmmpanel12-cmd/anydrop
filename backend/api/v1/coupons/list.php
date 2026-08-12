<?php
/**
 * GET /api/v1/coupons/list.php?restaurant_id=&item_total=
 * Auth: Customer token
 *
 * features.md H5 — "View all offers & coupons" page on Checkout. Lists
 * every coupon usable for a given restaurant's order: platform-wide
 * (restaurant_id IS NULL) + that restaurant's own (restaurant_id = :rid),
 * active + currently in-date. Same eligibility columns
 * lib/orders.php's price_cart() already queries by, so a coupon shown
 * here as "usable" agrees with what actually applies at checkout — this
 * endpoint doesn't reimplement pricing, it just lists + flags.
 *
 * `item_total` is optional (Checkout always has it — the current cart's
 * pre-discount total — so the list can show "Add ₹40 more to use this"
 * instead of the code failing silently only once tapped). Without it,
 * every coupon is listed with is_eligible=true and the app can still
 * show it (min_order_amount is returned either way so the UI can decide).
 *
 * `usage_limit_total`/`usage_limit_per_user` eligibility (already-used-up
 * coupons) is checked the same way price_cart() does, so a coupon that
 * would immediately fail as coupon_usage_limit_reached at Apply time is
 * flagged not-usable here instead of listed as if it works.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = $owner['owner_id'];
$db = Database::get();

$restaurantId = isset($_GET['restaurant_id']) ? (int) $_GET['restaurant_id'] : 0;
if (!$restaurantId) {
    respond_error('validation_error', 422, ['fields' => ['restaurant_id']]);
}

$itemTotal = isset($_GET['item_total']) ? (float) $_GET['item_total'] : null;

$stmt = $db->prepare(
    'SELECT * FROM coupons WHERE is_active = 1
     AND (restaurant_id IS NULL OR restaurant_id = :rid)
     AND (valid_from IS NULL OR valid_from <= NOW())
     AND (valid_until IS NULL OR valid_until >= NOW())
     ORDER BY (restaurant_id IS NOT NULL) DESC, discount_value DESC'
);
$stmt->execute(['rid' => $restaurantId]);
$coupons = $stmt->fetchAll();

$result = array_map(function (array $c) use ($db, $customerId, $itemTotal) {
    $minOrder = (float) $c['min_order_amount'];

    $usageExhausted = false;
    if ($c['usage_limit_per_user'] !== null) {
        $uStmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid AND customer_id = :uid'
        );
        $uStmt->execute(['cid' => $c['id'], 'uid' => $customerId]);
        if ((int) $uStmt->fetch()['c'] >= (int) $c['usage_limit_per_user']) {
            $usageExhausted = true;
        }
    }
    if (!$usageExhausted && $c['usage_limit_total'] !== null) {
        $tStmt = $db->prepare('SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid');
        $tStmt->execute(['cid' => $c['id']]);
        if ((int) $tStmt->fetch()['c'] >= (int) $c['usage_limit_total']) {
            $usageExhausted = true;
        }
    }

    $belowMinOrder = $itemTotal !== null && $itemTotal < $minOrder;

    return [
        'code' => $c['code'],
        'discount_type' => $c['discount_type'],
        'discount_value' => (float) $c['discount_value'],
        'min_order_amount' => $minOrder,
        'max_discount_amount' => $c['max_discount_amount'] !== null ? (float) $c['max_discount_amount'] : null,
        'valid_until' => $c['valid_until'],
        'is_restaurant_specific' => $c['restaurant_id'] !== null,
        'is_eligible' => !$usageExhausted && !$belowMinOrder,
        'ineligible_reason' => $usageExhausted ? 'usage_limit_reached' : ($belowMinOrder ? 'below_min_order' : null),
        'amount_needed_to_unlock' => $belowMinOrder && $itemTotal !== null ? round($minOrder - $itemTotal, 2) : null,
    ];
}, $coupons);

respond_ok(['coupons' => $result]);
