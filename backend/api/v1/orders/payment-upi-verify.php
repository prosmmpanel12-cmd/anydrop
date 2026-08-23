<?php
/**
 * GET /orders/{id}/payment/upi/status
 * (Mapped to api/v1/orders/payment-upi-verify.php?id=$1)
 * Auth: Customer token
 *
 * Polled every 10 seconds by the customer app's payment screen
 * (docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md §4)
 * while a UPI payment is in flight. Read-mostly — the only writes
 * that can happen here are (a) a lazy expiry flip and (b) the
 * one-time "success" side effects (orders.payment_status -> paid,
 * ledger entries, notification) via PaymentService::getClientStatus(),
 * both idempotent. This endpoint NEVER accepts a client-asserted
 * "I paid" claim — status always comes from `payment_transactions`,
 * per PaymentProviderInterface's own rule.
 *
 * Response `data.status` — one of:
 *   not_started | initiated | utr_pending_window | utr_available |
 *   utr_submitted | success | failed | expired
 * plus `utr_allowed_in_sec` (when utr_pending_window) or
 * `reject_reason` (when failed and an admin rejected with one).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment/PaymentService.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND customer_id = :cid LIMIT 1');
$orderStmt->execute(['id' => $orderId, 'cid' => $customerId]);
$order = $orderStmt->fetch();

if (!$order) {
    respond_error('order_not_found', 404);
}

$status = PaymentService::getClientStatus($db, $order);
respond_ok($status);
