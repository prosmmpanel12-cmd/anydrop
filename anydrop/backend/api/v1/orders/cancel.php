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
// the order must not silently leave that unresolved. Migration 65:
// a customer cancelling their OWN order, inside the cancel window
// that was just checked above ("jab tak time ho"), is a policy-safe
// refund with no judgement call for an admin to make — so this
// credits the customer's Anydrop Wallet instantly instead of landing
// in admin/refunds.php's manual review queue. Restaurant-rejected and
// admin-force-cancelled orders are UNCHANGED by this — see
// orders-reject.php / admin/orders.php, both still call
// create_refund_request() and go through manual review, since those
// genuinely are judgement calls (the customer didn't choose to
// cancel).
if ($order['payment_status'] === 'paid') {
    auto_wallet_refund_on_cancel($db, $order, 'Order cancelled by customer: ' . $reason);
    // auto_wallet_refund_on_cancel() deliberately doesn't touch
    // orders.payment_status itself (see its own kdoc) — this endpoint
    // owns that flip since it's already the one writer touching this
    // order row in this transaction.
    $db->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = :id")
        ->execute(['id' => $orderId]);
}

$db->commit();

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
