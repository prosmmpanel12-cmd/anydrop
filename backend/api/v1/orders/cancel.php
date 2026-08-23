<?php
/**
 * POST /api/v1/orders/{id}/cancel
 * Auth: Customer token (must own the order)
 * Allowed only in 'pending'/'accepted' states, and only within
 * `order_cancel_window_minutes` of placement (both configurable via app_settings).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/refunds.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$orderId = (int) ($_GET['id'] ?? 0);
$body = get_json_body();

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ((int) $order['customer_id'] !== (int) $owner['owner_id']) {
    respond_error('forbidden', 403);
}
if (!in_array($order['status'], ['pending', 'accepted'], true)) {
    respond_error('order_not_cancellable', 409);
}

$windowMinutes = (int) get_setting('order_cancel_window_minutes', 5);
$placedAt = strtotime($order['created_at']);
if (time() - $placedAt > $windowMinutes * 60) {
    respond_error('cancel_window_expired', 409);
}

$reason = trim((string) ($body['reason'] ?? 'Cancelled by customer'));

$db->beginTransaction();
$upd = $db->prepare(
    "UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = :r WHERE id = :id"
);
$upd->execute(['r' => $reason, 'id' => $orderId]);
insert_status_history($db, $orderId, 'cancelled', 'customer', $owner['owner_id'], $reason);

// This order already has real money sitting with admin (a UPI payment
// that was confirmed BEFORE the cancel window closed) — cancelling
// the order must not silently leave that unresolved. Previously this
// endpoint didn't check payment_status at all: a paid order could be
// cancelled and the customer's money just... stayed with admin, with
// no record anywhere that anything was owed back. Auto-creates a
// 'requested' refund (recall.md item 25 / migration 42) so it lands
// in admin/refunds.php's queue instead of getting lost.
if ($order['payment_status'] === 'paid') {
    create_refund_request($db, $order, 'Order cancelled by customer: ' . $reason, 'customer');
}

$db->commit();

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
