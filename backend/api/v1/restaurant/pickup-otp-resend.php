<?php
/**
 * POST /api/v1/restaurant/pickup-otp-resend.php?id={order_id}
 * Auth: Restaurant token (must own the order)
 * Request: {}
 * Response: { "message": "OTP resent" }
 *
 * App-owner ask, 2026-09-11: "resend option resend karne par wo app and
 * mail dono pe jayega" — this does NOT generate a new pickup_otp (the
 * code stays the same as migration 83's order-creation value; the
 * rider or restaurant may have already noted it down, and a resend
 * that silently changes the code would invalidate that). It just
 * re-delivers the existing code through two channels:
 *   1. An in-app notification (create_notification — surfaces via the
 *      restaurant app's existing notification bell/poll, same as
 *      every other notification type in this codebase).
 *   2. An email to the restaurant's own owner_email, reusing
 *      EmailOtpService's shared HTML template (same branded shell as
 *      login OTPs) with an order-specific heading.
 *
 * Only legal while the OTP is still relevant — same 'rider_assigned'
 * gate orders-detail.php already reveals the code under, since once
 * pickup is verified or a rider is unassigned there's nothing to
 * resend. Cooldown (30s) reuses the same "read the last-sent
 * timestamp off the row" shape payout-bank-details-request-otp.php
 * uses against a separate table, just against the order row itself
 * (migration 83's pickup_otp_last_sent_at) since there's no
 * separate OTP-request table for an already-existing code.
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

$owner = require_auth('restaurant');
$restaurantId = (int) $owner['owner_id'];
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
if ((int) $order['restaurant_id'] !== $restaurantId) {
    respond_error('forbidden', 403);
}
if ($order['status'] !== 'rider_assigned') {
    // Matches orders-detail.php's own reveal condition — nothing to
    // resend before a rider exists or after pickup is already done.
    respond_error('invalid_state', 409);
}

$cooldownSeconds = 30;
if ($order['pickup_otp_last_sent_at'] !== null) {
    $secondsSinceLast = time() - strtotime($order['pickup_otp_last_sent_at']);
    if ($secondsSinceLast < $cooldownSeconds) {
        respond_error('resend_cooldown', 429, [
            'retry_after_seconds' => $cooldownSeconds - $secondsSinceLast,
        ]);
    }
}

$restStmt = $db->prepare('SELECT owner_email, name FROM restaurants WHERE id = :id LIMIT 1');
$restStmt->execute(['id' => $restaurantId]);
$restaurant = $restStmt->fetch();

if (!$restaurant || empty($restaurant['owner_email'])) {
    respond_error('restaurant_email_missing', 500);
}

create_notification(
    'restaurant',
    $restaurantId,
    'Pickup OTP resent',
    "Pickup code for order {$order['order_code']}: {$order['pickup_otp']}",
    'order',
    ['order_id' => $orderId, 'screen' => 'order_detail']
);

$expiryMinutes = 0; // pickup_otp has no separate expiry — valid for the order's own life.
$deliveryResult = (new EmailOtpService($db))->send(
    $restaurant['owner_email'],
    $order['pickup_otp'],
    'rider_pickup_otp_resend',
    $expiryMinutes,
    "Pickup code for order {$order['order_code']}",
    'Your pickup code',
    "Share this code with the rider collecting order {$order['order_code']} from {$restaurant['name']} — they'll need it to confirm pickup."
);

$updStmt = $db->prepare('UPDATE orders SET pickup_otp_last_sent_at = NOW() WHERE id = :id');
$updStmt->execute(['id' => $orderId]);

// The in-app notification above already delivered the code even if the
// email provider is temporarily down — so this endpoint doesn't fail
// the whole request on email-only failure, unlike a pure email-OTP
// login flow which has no other channel to fall back on.
respond_ok([
    'message' => 'OTP resent',
    'email_sent' => $deliveryResult['success'],
]);
