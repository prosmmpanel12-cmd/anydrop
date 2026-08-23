<?php
/**
 * POST /api/v1/restaurant/orders/{id}/accept
 * Auth: Restaurant token (must own the order)
 * Request (optional): { "estimated_prep_minutes": 20 }
 * Only allowed from 'pending' status.
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

$owner = require_auth('restaurant');
$orderId = (int) ($_GET['id'] ?? 0);
$body = get_json_body();

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ((int) $order['restaurant_id'] !== (int) $owner['owner_id']) {
    respond_error('forbidden', 403);
}
if ($order['status'] !== 'pending') {
    respond_error('invalid_status_transition', 409);
}
// UPI FIX (2026-08-23): a 'upi' order sits in orders.status = 'pending'
// from the moment it's placed, same as any other order — but unlike
// cod/wallet, its payment_status can still be 'pending' too (payment
// not yet verified). Block accept until PaymentService::
// promoteOrderIfNeeded() has flipped it to 'paid' — never let a
// restaurant confirm/prep an order nobody has actually paid for.
if ($order['payment_method'] === 'upi' && $order['payment_status'] !== 'paid') {
    respond_error('payment_not_confirmed', 409);
}

$prepMinutes = isset($body['estimated_prep_minutes']) ? max(1, (int) $body['estimated_prep_minutes']) : 20;

$upd = $db->prepare(
    "UPDATE orders SET status = 'accepted', accepted_at = NOW(), estimated_prep_minutes = :p WHERE id = :id"
);
$upd->execute(['p' => $prepMinutes, 'id' => $orderId]);
insert_status_history($db, $orderId, 'accepted', 'restaurant', $owner['owner_id']);

create_notification(
    'customer',
    (int) $order['customer_id'],
    'Order accepted',
    "Your order {$order['order_code']} was accepted — ready in about $prepMinutes min",
    'order',
    ['order_id' => $orderId, 'screen' => 'order_status']
);

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
