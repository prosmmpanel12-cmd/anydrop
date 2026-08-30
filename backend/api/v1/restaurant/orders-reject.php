<?php
/**
 * POST /api/v1/restaurant/orders/{id}/reject
 * Auth: Restaurant token (must own the order)
 * Request: { "reason": "..." }
 * Only allowed from 'pending' status (once accepted, use cancel/status flow instead).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';
require_once __DIR__ . '/../../../lib/refunds.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_orders');
$orderId = (int) ($_GET['id'] ?? 0);
$body = get_json_body();
require_fields($body, ['reason']);

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

$reason = trim((string) $body['reason']);

$db->beginTransaction();
$upd = $db->prepare(
    "UPDATE orders SET status = 'rejected', cancelled_at = NOW(), cancellation_reason = :r WHERE id = :id"
);
$upd->execute(['r' => $reason, 'id' => $orderId]);
insert_status_history($db, $orderId, 'rejected', 'restaurant', $owner['owner_id'], $reason);

// Same gap as orders/cancel.php: a restaurant can only reject a
// 'pending' order, but a customer can pay by UPI and have that
// payment confirmed before the restaurant acts — so a paid order CAN
// still reach here. Previously nothing checked payment_status at all.
if ($order['payment_status'] === 'paid') {
    create_refund_request($db, $order, 'Order rejected by restaurant: ' . $reason, 'restaurant');
}
$db->commit();

create_notification(
    'customer',
    (int) $order['customer_id'],
    'Order rejected',
    "Your order {$order['order_code']} was rejected: $reason",
    'order',
    ['order_id' => $orderId, 'screen' => 'order_status']
);

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
