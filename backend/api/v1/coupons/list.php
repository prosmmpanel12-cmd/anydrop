<?php
/**
 * GET /api/v1/coupons/list.php?restaurant_id=&item_total=
 * Auth: Customer token
 *
 * features.md H5 — "View all offers & coupons" page on Checkout. Lists
 * every PUBLIC coupon usable for a given restaurant's order: platform-wide
 * (restaurant_id IS NULL) + that restaurant's own (restaurant_id = :rid),
 * active + is_public=1 + currently in-date. Same eligibility columns
 * lib/orders.php's price_cart() already queries by, so a coupon shown
 * here as "usable" agrees with what actually applies at checkout — this
 * endpoint doesn't reimplement pricing, it just lists + flags.
 *
 * Private coupons (is_public=0) are intentionally excluded here — they
 * never show on this "suggest a coupon" list, but remain fully
 * redeemable by typed code at Checkout, since price_cart() looks a
 * coupon up by `code` and does not filter on is_public at all.
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
 *
 * Migration 49 — a restaurant's own public coupon_based promo_offers
 * are now folded into this same list (second query below, reshaped
 * into the identical response object coupons already return) so the
 * checkout "View all offers" screen doesn't need a separate section —
 * to the customer, a coupon_based offer's code behaves exactly like a
 * coupon's code, only its origin table differs. Private (is_public=0)
 * coupon_based offers are excluded here for the identical reason
 * private coupons already are — still fully redeemable by typed code,
 * just never suggested.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/offers.php';

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
    'SELECT * FROM coupons WHERE is_active = 1 AND is_public = 1
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

// Migration 49 — public coupon_based promo_offers, reshaped into the
// exact same object shape as the coupons above. discount_type/
// discount_value are approximated from offer_badge_label()'s own
// per-type formatting logic (e.g. buy_x_get_y has no single
// "discount_value" number the way percent/flat coupons do) — this
// list is a *preview*, same "browse-time badge is approximate,
// checkout is authoritative" split get_browsable_offers_for_restaurant()
// itself documents; is_offer_eligible()/compute_offer_discount() at
// Apply time (price_cart(), via the checkout coupon-code box) remain
// the one real check.
$offerStmt = $db->prepare(
    "SELECT * FROM promo_offers
     WHERE restaurant_id = :rid AND apply_mode = 'coupon_based' AND is_public = 1
       AND status = 'active' AND deleted_at IS NULL
       AND (start_date IS NULL OR start_date <= CURDATE())
       AND (end_date IS NULL OR end_date >= CURDATE())"
);
$offerStmt->execute(['rid' => $restaurantId]);
$codeOffers = $offerStmt->fetchAll();

$offerResult = array_map(function (array $o) use ($db, $customerId, $itemTotal) {
    $minOrder = (float) $o['min_order_amount'];

    $usageExhausted = !is_offer_usage_available($db, $o, $customerId);
    $timeIneligible = !is_offer_time_eligible($o);
    $belowMinOrder = $itemTotal !== null && $itemTotal < $minOrder;

    $ineligibleReason = $usageExhausted
        ? 'usage_limit_reached'
        : ($timeIneligible ? 'not_available_right_now' : ($belowMinOrder ? 'below_min_order' : null));

    return [
        'code' => $o['code'],
        // No single discount_type/discount_value for every offer_type
        // (see offer_badge_label()'s own per-type switch) — 'offer' is
        // a distinct discount_type value the app's coupon UI treats as
        // "show offer_label instead of a %/₹ value", not a real coupon
        // discount_type from the enum coupons.discount_type uses.
        'discount_type' => 'offer',
        'discount_value' => 0.0,
        'offer_label' => offer_badge_label($o),
        'min_order_amount' => $minOrder,
        'max_discount_amount' => $o['max_discount_amount'] !== null ? (float) $o['max_discount_amount'] : null,
        'valid_until' => $o['end_date'],
        'is_restaurant_specific' => true,
        'is_eligible' => !$usageExhausted && !$timeIneligible && !$belowMinOrder,
        'ineligible_reason' => $ineligibleReason,
        'amount_needed_to_unlock' => $belowMinOrder && $itemTotal !== null ? round($minOrder - $itemTotal, 2) : null,
    ];
}, $codeOffers);

respond_ok(['coupons' => array_values(array_merge($result, $offerResult))]);
