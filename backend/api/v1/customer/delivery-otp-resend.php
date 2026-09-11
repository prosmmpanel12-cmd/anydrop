<?php
/**
 * POST /api/v1/customer/delivery-otp-resend.php?id={order_id}
 * Auth: Customer token (must own the order)
 * Request: {}
 * Response: { "message": "OTP resent" }
 *
 * App-owner ask, 2026-09-11 — customer-side counterpart to
 * restaurant/pickup-otp-resend.php. Same shape exactly: does NOT
 * generate a new delivery_otp (re-delivers the existing one, same code
 * orders/track.php already shows in the app), just re-sends it via
 * in-app notification + branded HTML email using
 * delivery_otp_last_sent_at (migration 83) for a 30s cooldown.
 *
 * Only legal while delivery_otp actually exists (some orders never get
 * one — see orders/create.php's $otpRequired condition) AND the order
 * is still at a status where it matters (rider_assigned/
 * out_for_delivery), matching orders/track.php's own reveal condition.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/notifications.php';
require_once __DIR__ . '/../../../lib/email_otp/EmailOtpService.php';

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

$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ((int) $order['customer_id'] !== $customerId) {
    respond_error('forbidden', 403);
}
if ($order['delivery_otp'] === null) {
    respond_error('otp_not_applicable', 409);
}
if (!in_array($order['status'], ['rider_assigned', 'out_for_delivery'], true)) {
    // Matches orders/track.php's own reveal condition — nothing to
    // resend outside this window.
    respond_error('invalid_state', 409);
}

$cooldownSeconds = 30;
if ($order['delivery_otp_last_sent_at'] !== null) {
    $secondsSinceLast = time() - strtotime($order['delivery_otp_last_sent_at']);
    if ($secondsSinceLast < $cooldownSeconds) {
        respond_error('resend_cooldown', 429, [
            'retry_after_seconds' => $cooldownSeconds - $secondsSinceLast,
        ]);
    }
}

$custStmt = $db->prepare('SELECT email FROM customers WHERE id = :id LIMIT 1');
$custStmt->execute(['id' => $customerId]);
$email = $custStmt->fetchColumn();

if (!$email) {
    respond_error('customer_email_missing', 500);
}

create_notification(
    'customer',
    $customerId,
    'Delivery OTP resent',
    "Delivery code for order {$order['order_code']}: {$order['delivery_otp']}",
    'order',
    ['order_id' => $orderId, 'screen' => 'order_status']
);

$deliveryResult = (new EmailOtpService($db))->send(
    $email,
    $order['delivery_otp'],
    'customer_delivery_otp_resend',
    0,
    "Delivery code for order {$order['order_code']}",
    'Your delivery code',
    "Share this code with your rider when order {$order['order_code']} arrives — they'll need it to confirm delivery."
);

$updStmt = $db->prepare('UPDATE orders SET delivery_otp_last_sent_at = NOW() WHERE id = :id');
$updStmt->execute(['id' => $orderId]);

respond_ok([
    'message' => 'OTP resent',
    'email_sent' => $deliveryResult['success'],
]);
