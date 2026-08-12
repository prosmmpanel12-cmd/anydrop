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
require_once __DIR__ . '/../../../lib/orders.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
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

$upd = $db->prepare(
    "UPDATE orders SET status = 'rejected', cancelled_at = NOW(), cancellation_reason = :r WHERE id = :id"
);
$upd->execute(['r' => $reason, 'id' => $orderId]);
insert_status_history($db, $orderId, 'rejected', 'restaurant', $owner['owner_id'], $reason);

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
