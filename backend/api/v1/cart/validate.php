<?php
/**
 * POST /api/v1/cart/validate
 * Auth: Customer token
 * Request:  { "restaurant_id", "items": [{ "menu_item_id", "variant_id"?, "addon_ids"?: [], "quantity" }], "coupon_code"? }
 * Response: { item_total, discount_amount, delivery_charge, platform_fee, packing_charge,
 *             tax_amount, grand_total, invalid_items: [] }
 *
 * Client keeps cart locally; this is the authoritative price/availability check
 * called before showing the checkout screen's final total. Same pricing logic
 * as POST /orders (see lib/orders.php) so numbers never drift between the two.
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

$db = Database::get();
$priced = price_cart($db, $restaurantId, $items, $couponCode, $owner['owner_id']);

if ($priced['error'] && empty($priced['line_items'])) {
    respond_error($priced['error'], 422);
}

respond_ok([
    'item_total' => $priced['item_total'],
    'discount_amount' => $priced['discount_amount'],
    'delivery_charge' => $priced['delivery_charge'],
    'platform_fee' => $priced['platform_fee'],
    'packing_charge' => $priced['packing_charge'],
    'tax_amount' => $priced['tax_amount'],
    'grand_total' => $priced['grand_total'],
    'invalid_items' => $priced['invalid_items'],
    'min_order_amount' => $priced['min_order_amount'],
    'warning' => $priced['error'], // e.g. below_min_order_amount, set alongside partial data
]);
