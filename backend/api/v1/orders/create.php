<?php
/**
 * POST /api/v1/orders
 * Auth: Customer token
 * Request:  { "restaurant_id", "items": [...], "delivery_address_id", "payment_method": "upi"|"cod",
 *             "coupon_code"?, "delivery_instructions"?, "scheduled_for"? }
 * Response: { "order": {...}, "order_code": "QRX-..." }
 *
 * Re-validates the cart server-side (never trusts client totals), checks the
 * restaurant is open & under its due limit, checks min order amount, writes
 * order_items + order_status_history, and (Phase 6) would trigger a push to
 * the restaurant — for now the restaurant app polls GET /restaurant/orders.
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
$customerId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['restaurant_id', 'items', 'payment_method']);

$restaurantId = (int) $body['restaurant_id'];
$items = is_array($body['items']) ? $body['items'] : [];
$paymentMethod = $body['payment_method'];
$addressId = isset($body['delivery_address_id']) ? (int) $body['delivery_address_id'] : null;
$couponCode = $body['coupon_code'] ?? null;
$instructions = isset($body['delivery_instructions']) ? trim((string) $body['delivery_instructions']) : null;
// I4 — optional same-day "Schedule for later" slot, "Y-m-d H:i:s" (or any
// strtotime-parseable string) from the app's slot picker. Validated below
// against the priced restaurant row, once we have it.
$scheduledForRaw = $body['scheduled_for'] ?? null;

if (!in_array($paymentMethod, ['upi', 'cod'], true)) {
    respond_error('validation_error', 422, ['fields' => ['payment_method']]);
}

$db = Database::get();

// Address must belong to this customer, if provided.
if ($addressId !== null) {
    $addrStmt = $db->prepare('SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
    $addrStmt->execute(['id' => $addressId, 'cid' => $customerId]);
    if (!$addrStmt->fetch()) {
        respond_error('validation_error', 422, ['fields' => ['delivery_address_id']]);
    }
}

$priced = price_cart($db, $restaurantId, $items, $couponCode, $customerId);

if ($priced['error']) {
    // H4 follow-up (2026-08-10) — attach min_order_amount + item_total
    // alongside below_min_order_amount so the app can show "Add ₹X more"
    // instead of a generic failure message. Harmless/no-op for every other
    // error code, which just gets the plain invalid_items payload as before.
    $errorData = ['invalid_items' => $priced['invalid_items']];
    if ($priced['error'] === 'below_min_order_amount') {
        $errorData['min_order_amount'] = $priced['min_order_amount'];
        $errorData['item_total'] = $priced['item_total'];
    }
    respond_error($priced['error'], 422, $errorData);
}

$otpRequired = $paymentMethod === 'upi' || (bool) get_setting('otp_required_for_cod', false);
$otpLength = (int) get_setting('otp_length', 4);

// I4 — validated after pricing (needs $priced['restaurant']'s open hours),
// before the insert transaction opens, so a bad slot fails fast with a
// normal 422 rather than a mid-transaction rollback.
$scheduleCheck = validate_scheduled_for($priced['restaurant'], $scheduledForRaw);
if ($scheduleCheck['error'] !== null) {
    respond_error($scheduleCheck['error'], 422, ['fields' => ['scheduled_for']]);
}
$scheduledFor = $scheduleCheck['value'];

try {
    $db->beginTransaction();

    $orderCode = generate_order_code($db);
    $deliveryOtp = null;
    if ($otpRequired) {
        $deliveryOtp = (string) random_int(
            (int) str_pad('1', $otpLength, '0'),
            (int) str_pad('', $otpLength, '9')
        );
        $deliveryOtp = str_pad($deliveryOtp, $otpLength, '0', STR_PAD_LEFT);
    }

    $insertOrder = $db->prepare(
        'INSERT INTO orders (
            order_code, customer_id, restaurant_id, status,
            item_total, delivery_charge, platform_fee, packing_charge, tax_amount, discount_amount,
            grand_total, commission_amount, payment_method, payment_status,
            delivery_address_id, delivery_instructions, scheduled_for, coupon_id, delivery_otp
        ) VALUES (
            :code, :cust, :rest, \'pending\',
            :item_total, :delivery_charge, :platform_fee, :packing_charge, :tax_amount, :discount_amount,
            :grand_total, :commission_amount, :payment_method, :payment_status,
            :address_id, :instructions, :scheduled_for, :coupon_id, :otp
        )'
    );
    $insertOrder->execute([
        'code' => $orderCode,
        'cust' => $customerId,
        'rest' => $restaurantId,
        'item_total' => $priced['item_total'],
        'delivery_charge' => $priced['delivery_charge'],
        'platform_fee' => $priced['platform_fee'],
        'packing_charge' => $priced['packing_charge'],
        'tax_amount' => $priced['tax_amount'],
        'discount_amount' => $priced['discount_amount'],
        'grand_total' => $priced['grand_total'],
        'commission_amount' => $priced['commission_amount'],
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentMethod === 'cod' ? 'pending' : 'pending',
        'address_id' => $addressId,
        'instructions' => $instructions,
        'scheduled_for' => $scheduledFor,
        'coupon_id' => $priced['coupon_id'],
        'otp' => $deliveryOtp,
    ]);
    $orderId = (int) $db->lastInsertId();

    $insertItem = $db->prepare(
        'INSERT INTO order_items (order_id, menu_item_id, item_name_snapshot, variant_name, quantity, unit_price, addons_json, special_instructions, subtotal)
         VALUES (:oid, :mid, :name, :variant, :qty, :price, :addons, :instructions, :subtotal)'
    );
    foreach ($priced['line_items'] as $line) {
        $insertItem->execute([
            'oid' => $orderId,
            'mid' => $line['menu_item_id'],
            'name' => $line['item_name_snapshot'],
            'variant' => $line['variant_name'],
            'qty' => $line['quantity'],
            'price' => $line['unit_price'],
            'addons' => $line['addons_json'],
            'instructions' => $line['special_instructions'] ?? null,
            'subtotal' => $line['subtotal'],
        ]);
    }

    insert_status_history($db, $orderId, 'pending', 'customer', $customerId, 'Order placed');

    if ($priced['coupon_id'] !== null) {
        $couponUse = $db->prepare(
            'INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (:cid, :uid, :oid)'
        );
        $couponUse->execute(['cid' => $priced['coupon_id'], 'uid' => $customerId, 'oid' => $orderId]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    respond_error('server_error', 500);
}

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
$order = $fetch->fetch();

respond_ok([
    'order' => format_order($db, $order),
    'order_code' => $orderCode,
], 201);
