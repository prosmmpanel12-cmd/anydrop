<?php
/**
 * POST /orders/{id}/payment/upi/create
 * (Mapped to api/v1/orders/payment-upi-create.php?id=$1, same clean-URL
 * convention as create.php/detail.php/cancel.php in this folder.)
 * Auth: Customer token
 *
 * Native UPI-QR flow (docs/23_Native_UPI_Payment_Gateway_Architecture_
 * 2026-08-23.md). Order must already exist (created via POST /orders
 * with payment_method=upi) — this call only starts/resumes the
 * payment attempt for it. Idempotent: re-calling this for an
 * unresolved, unexpired transaction returns the SAME txn_ref/upi_link
 * rather than creating a new one (PaymentService::initiatePayment).
 *
 * Response `data` = the client_payload documented in doc 23 §2/§8:
 * { method, txn_ref, upi_link, upi_id, payee_name, amount,
 *   expires_in_sec, utr_required, utr_window_sec, poll_interval_sec,
 *   instructions[] }
 *
 * IMPORTANT: no `qr_url` / QR image is returned. The customer app
 * renders the actual scannable QR client-side (e.g. ZXing on
 * Android) from `upi_link` — see UpipeProvider.php's own doc-comment
 * for why a server-generated QR image was deliberately dropped.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment/PaymentService.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
if ($order['payment_method'] !== 'upi') {
    respond_error('order_not_upi', 422);
}
if ($order['payment_status'] === 'paid') {
    respond_ok(['already_paid' => true]);
}
if (in_array($order['status'], ['cancelled', 'rejected', 'expired'], true)) {
    respond_error('order_not_payable', 422, ['order_status' => $order['status']]);
}

$result = PaymentService::initiatePayment($db, $order);

if (!$result['ok']) {
    respond_error($result['error'] ?? 'payment_initiation_failed', 422, ['message' => $result['message'] ?? null]);
}

respond_ok($result['client_payload']);
