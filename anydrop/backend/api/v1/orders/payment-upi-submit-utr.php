<?php
/**
 * POST /orders/{id}/payment/upi/utr   { "utr": "123456789012" }
 * (Mapped to api/v1/orders/payment-upi-submit-utr.php?id=$1)
 * Auth: Customer token
 *
 * Optional step in the native UPI flow (docs/23_Native_UPI_Payment_
 * Gateway_Architecture_2026-08-23.md §5 model B) — only meaningful
 * once GET .../status has reported `utr_available`. Submitting a UTR
 * does NOT confirm the payment by itself; it only queues the
 * transaction for admin review (admin/payment-pending.php). The
 * customer app should keep polling the verify endpoint after this
 * call the same way it already was.
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

$body = get_json_body();
require_fields($body, ['utr']);
$utr = trim((string) $body['utr']);

$db = Database::get();

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND customer_id = :cid LIMIT 1');
$orderStmt->execute(['id' => $orderId, 'cid' => $customerId]);
$order = $orderStmt->fetch();

if (!$order) {
    respond_error('order_not_found', 404);
}

$result = PaymentService::submitUtr($db, $order, $utr);

if (!$result['ok']) {
    respond_error($result['error'] ?? 'utr_submission_failed', 422);
}

respond_ok(['status' => $result['status']]);
