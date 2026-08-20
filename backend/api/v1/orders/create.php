<?php
/**
 * POST /api/v1/orders
 * Auth: Customer token
 * Request:  { "restaurant_id", "items": [...], "delivery_address_id", "payment_method": "upi"|"cod",
 *             "coupon_code"?, "delivery_instructions"?, "scheduled_for"?, "idempotency_key"? }
 * Response: { "order": {...}, "order_code": "QRX-..." }
 *
 * Re-validates the cart server-side (never trusts client totals), checks the
 * restaurant is open & under its due limit, checks min order amount, writes
 * order_items + order_status_history, and (Phase 6) would trigger a push to
 * the restaurant — for now the restaurant app polls GET /restaurant/orders.
 *
 * `idempotency_key` (bugs.md #2.4) — optional client-generated string,
 * stable across retries of the same place-order attempt. A repeated
 * request with the same (customer, key) returns the original order
 * instead of creating a duplicate. Safe to omit (older clients / no key
 * sent) — falls back to today's un-deduplicated behaviour.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';

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
// bugs.md #2.4 (server-side half) — client-generated key, stable across
// retries of the same place-order attempt (timeout-then-retry), fresh for
// every genuinely new attempt. Optional: null/absent falls back to
// today's behaviour (no dedup) rather than rejecting the request, since
// older app builds won't send this yet.
$idempotencyKey = isset($body['idempotency_key']) ? trim((string) $body['idempotency_key']) : null;
if ($idempotencyKey === '') {
    $idempotencyKey = null;
}

if (!in_array($paymentMethod, ['upi', 'cod'], true)) {
    respond_error('validation_error', 422, ['fields' => ['payment_method']]);
}

$db = Database::get();

// bugs.md #2.4 — if this exact (customer, key) already created an order,
// this is a retry (timeout-then-retry, double-submit past the client-side
// button-disable), not a new order. Return the original instead of
// re-pricing/re-inserting. Checked before price_cart() runs at all, so a
// retry doesn't even re-validate the cart/coupon/restaurant-hours.
if ($idempotencyKey !== null) {
    $existingStmt = $db->prepare(
        'SELECT id, order_code FROM orders WHERE customer_id = :cid AND idempotency_key = :key LIMIT 1'
    );
    $existingStmt->execute(['cid' => $customerId, 'key' => $idempotencyKey]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        $fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $fetch->execute(['id' => $existing['id']]);
        $order = $fetch->fetch();
        respond_ok([
            'order' => format_order($db, $order),
            'order_code' => $existing['order_code'],
        ], 201);
    }
}

// Address must belong to this customer, if provided.
if ($addressId !== null) {
    $addrStmt = $db->prepare('SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
    $addrStmt->execute(['id' => $addressId, 'cid' => $customerId]);
    if (!$addrStmt->fetch()) {
        respond_error('validation_error', 422, ['fields' => ['delivery_address_id']]);
    }
}

// scheduledForRaw passed in so price_cart() knows whether to check the
// restaurant's hours against right-now (a "Deliver Now" order) or skip
// that check (a scheduled order — validate_scheduled_for() below checks
// its own target slot against the restaurant's hours instead).
$priced = price_cart($db, $restaurantId, $items, $couponCode, $customerId, $scheduledForRaw);

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
            order_code, customer_id, idempotency_key, restaurant_id, status,
            item_total, delivery_charge, platform_fee, packing_charge, tax_amount, discount_amount,
            grand_total, commission_amount, payment_method, payment_status,
            delivery_address_id, delivery_instructions, scheduled_for, coupon_id, delivery_otp
        ) VALUES (
            :code, :cust, :idem, :rest, \'pending\',
            :item_total, :delivery_charge, :platform_fee, :packing_charge, :tax_amount, :discount_amount,
            :grand_total, :commission_amount, :payment_method, :payment_status,
            :address_id, :instructions, :scheduled_for, :coupon_id, :otp
        )'
    );
    $insertOrder->execute([
        'code' => $orderCode,
        'cust' => $customerId,
        'idem' => $idempotencyKey,
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
        // bugs.md #1.3 fix — price_cart()'s usage_limit_per_user /
        // usage_limit_total check runs before this transaction opens, so
        // two near-simultaneous requests (double-tap "Place Order", same
        // user on two devices) could both pass that check before either
        // insert below landed, both succeed, and a usage_limit_per_user=1
        // coupon gets used twice. A blanket UNIQUE KEY on
        // (coupon_id, customer_id) isn't safe here since usage_limit_per_user
        // can legitimately be >1 or NULL (unlimited) — so instead, re-check
        // the same limits here, inside the transaction, with a locking read
        // (SELECT ... FOR UPDATE) immediately before the insert. Two
        // concurrent transactions now serialize on this lock: the second
        // one to reach it sees the first one's already-committed-or-pending
        // usage row and fails cleanly instead of both slipping through.
        $couponLockStmt = $db->prepare(
            'SELECT usage_limit_per_user, usage_limit_total FROM coupons WHERE id = :cid LIMIT 1 FOR UPDATE'
        );
        $couponLockStmt->execute(['cid' => $priced['coupon_id']]);
        $couponRow = $couponLockStmt->fetch();

        if ($couponRow) {
            if ($couponRow['usage_limit_per_user'] !== null) {
                $recheckUser = $db->prepare(
                    'SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid AND customer_id = :uid'
                );
                $recheckUser->execute(['cid' => $priced['coupon_id'], 'uid' => $customerId]);
                if ((int) $recheckUser->fetch()['c'] >= (int) $couponRow['usage_limit_per_user']) {
                    throw new RuntimeException('coupon_usage_limit_reached');
                }
            }
            if ($couponRow['usage_limit_total'] !== null) {
                $recheckTotal = $db->prepare('SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid');
                $recheckTotal->execute(['cid' => $priced['coupon_id']]);
                if ((int) $recheckTotal->fetch()['c'] >= (int) $couponRow['usage_limit_total']) {
                    throw new RuntimeException('coupon_usage_limit_reached');
                }
            }
        }

        $couponUse = $db->prepare(
            'INSERT INTO coupon_usages (coupon_id, customer_id, order_id) VALUES (:cid, :uid, :oid)'
        );
        $couponUse->execute(['cid' => $priced['coupon_id'], 'uid' => $customerId, 'oid' => $orderId]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    if ($e->getMessage() === 'coupon_usage_limit_reached') {
        respond_error('coupon_usage_limit_reached', 422);
    }
    // bugs.md #2.4 — the early idempotency-key lookup above has its own
    // race: two near-simultaneous requests with the same key can both
    // pass that SELECT before either INSERT lands (same TOCTOU shape as
    // bug #1.3). The uniq_customer_idempotency_key constraint added in
    // migration 20 makes the loser's INSERT fail here instead of silently
    // creating a duplicate order — recognize that specific failure and
    // hand back the winner's order rather than a generic 500.
    if ($idempotencyKey !== null && str_contains($e->getMessage(), 'uniq_customer_idempotency_key')) {
        $raceStmt = $db->prepare(
            'SELECT id, order_code FROM orders WHERE customer_id = :cid AND idempotency_key = :key LIMIT 1'
        );
        $raceStmt->execute(['cid' => $customerId, 'key' => $idempotencyKey]);
        $winner = $raceStmt->fetch();
        if ($winner) {
            $fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $fetch->execute(['id' => $winner['id']]);
            $order = $fetch->fetch();
            respond_ok([
                'order' => format_order($db, $order),
                'order_code' => $winner['order_code'],
            ], 201);
        }
    }
    respond_error('server_error', 500);
}

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
$order = $fetch->fetch();

// Notify the restaurant of the new order — after commit, never inside it,
// so a notification-write failure can never roll back a real order (see
// lib/notifications.php's kdoc). OrderPollingService already covers the
// urgent sound/alarm path on the restaurant app; this is the persistent
// "you can look back at this later" record the bell list is for.
create_notification(
    'restaurant',
    $restaurantId,
    'New order received',
    "Order $orderCode — ₹" . number_format((float) $order['grand_total'], 0),
    'order',
    ['order_id' => $orderId, 'screen' => 'order_detail']
);

respond_ok([
    'order' => format_order($db, $order),
    'order_code' => $orderCode,
], 201);
