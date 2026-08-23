<?php
/**
 * POST /api/v1/cart/validate
 * Auth: Customer token
 * Request:  { "restaurant_id", "items": [{ "menu_item_id", "variant_id"?, "addon_ids"?: [], "quantity" }],
 *             "coupon_code"?, "delivery_address_id"? }
 * Response: { item_total, discount_amount, delivery_charge, platform_fee, packing_charge,
 *             tax_amount, grand_total, invalid_items: [] }
 *
 * Client keeps cart locally; this is the authoritative price/availability check
 * called before showing the checkout screen's final total. Same pricing logic
 * as POST /orders (see lib/orders.php) so numbers never drift between the two.
 *
 * recall.md Phase B item 14 / migration 36 — `delivery_address_id` is
 * optional (same as before this change) so an early preview before the
 * user has picked a delivery address still works exactly as it did —
 * price_cart() falls back to the flat delivery_charge_flat setting
 * whenever it has no delivery coordinates to compute a real distance
 * from. Once an address IS picked, passing its id here makes the
 * preview's delivery_charge match what orders/create.php will actually
 * charge, instead of showing a flat estimate that then jumps at
 * place-order time.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$body = get_json_body();
require_fields($body, ['restaurant_id', 'items']);

$restaurantId = (int) $body['restaurant_id'];
$items = is_array($body['items']) ? $body['items'] : [];
$couponCode = $body['coupon_code'] ?? null;
// Optional — same-day scheduled slot, if the checkout screen already has
// one picked. Passed through to price_cart() so this preview doesn't
// falsely reject a scheduled order just because the restaurant happens to
// be closed for a "Deliver Now" order right now (see lib/orders.php).
$scheduledForRaw = $body['scheduled_for'] ?? null;

$db = Database::get();

// Optional delivery address, for a real distance-based delivery_charge
// preview (see kdoc above). Ownership-checked the same way
// orders/create.php checks it — a customer can only preview against
// their own saved address, never someone else's by guessing an id.
$deliveryLat = null;
$deliveryLng = null;
if (!empty($body['delivery_address_id'])) {
    $addrStmt = $db->prepare('SELECT latitude, longitude FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
    $addrStmt->execute(['id' => (int) $body['delivery_address_id'], 'cid' => $owner['owner_id']]);
    $addressRow = $addrStmt->fetch();
    if ($addressRow) {
        $deliveryLat = $addressRow['latitude'] !== null ? (float) $addressRow['latitude'] : null;
        $deliveryLng = $addressRow['longitude'] !== null ? (float) $addressRow['longitude'] : null;
    }
    // A delivery_address_id that doesn't resolve (wrong id, someone
    // else's address) is silently ignored here rather than erroring —
    // this is only a preview; it just falls back to the flat estimate,
    // same as not sending one at all. orders/create.php is where an
    // invalid address id actually gets rejected.
}

$priced = price_cart($db, $restaurantId, $items, $couponCode, $owner['owner_id'], $scheduledForRaw, $deliveryLat, $deliveryLng);

if ($priced['error'] && empty($priced['line_items'])) {
    respond_error($priced['error'], 422);
}

respond_ok([
    'item_total' => $priced['item_total'],
    'discount_amount' => $priced['discount_amount'],
    'delivery_charge' => $priced['delivery_charge'],
    'delivery_distance_km' => $priced['delivery_distance_km'],
    'platform_fee' => $priced['platform_fee'],
    'packing_charge' => $priced['packing_charge'],
    'tax_amount' => $priced['tax_amount'],
    'grand_total' => $priced['grand_total'],
    'invalid_items' => $priced['invalid_items'],
    'min_order_amount' => $priced['min_order_amount'],
    'warning' => $priced['error'], // e.g. below_min_order_amount, set alongside partial data
]);

