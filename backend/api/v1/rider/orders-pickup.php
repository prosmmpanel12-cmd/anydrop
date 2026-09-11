<?php
/**
 * POST /api/v1/rider/orders-pickup.php?id={order_id}
 * Auth: Rider token
 * Request: { "otp": "1234" } — required, checked against orders.pickup_otp
 * Response (success): { "order_id": ..., "status": "out_for_delivery" }
 * Response (wrong otp): 401 invalid_otp, { "attempts_remaining": N }
 * Response (locked):    400 otp_max_attempts_exceeded
 *
 * Phase 3 R4 (pickup/drop-off flow, deep-plan §10-11), built on top of
 * R3's accept flow (doc 85).
 *
 * Migration 83 (2026-09-11, app-owner ask): pickup used to be a single
 * unauthenticated tap — anyone holding the rider's phone could advance
 * the order with no proof they were actually the one standing at the
 * restaurant counter. Now mirrors orders-deliver.php's own OTP-check
 * shape exactly (same lockout via the shared otp_max_attempts setting,
 * same attempts-remaining response body), just against pickup_otp/
 * pickup_otp_attempts instead of delivery_otp/otp_attempts — kept as
 * fully separate columns so a wrong pickup attempt never eats into the
 * rider's delivery-OTP attempt budget later in the same order's life.
 * Unlike delivery_otp, pickup_otp is never null (see migration 83's own
 * comment), so — unlike orders-deliver.php — there is no "skip the
 * check" branch here; every order requires it.
 *
 * Deep-plan §11's V1 recommendation is followed exactly: pickup
 * confirmation immediately transitions the order to out_for_delivery
 * (skipping a separate "sitting at picked_up" resting state) rather than
 * making the rider tap twice — "this avoids an unnecessary extra tap".
 * orders/track.php already only surfaces the delivery OTP for
 * rider_assigned/out_for_delivery (not picked_up), which independently
 * confirms this was always the intended shape even before this endpoint
 * existed.
 *
 * picked_up_at is still recorded, and BOTH transitions
 * (rider_assigned -> picked_up -> out_for_delivery) get their own
 * order_status_history row, so the audit trail reads correctly even
 * though the rider only made one tap and one API call.
 *
 * Same conditional-UPDATE race-safety convention as orders-accept.php —
 * WHERE encodes the only state this is legal from (rider_assigned,
 * owned by this rider). No FOR UPDATE locking, matches the rest of this
 * codebase's style.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
if (($owner['status'] ?? null) !== 'approved') {
    respond_error('not_approved', 403);
}
$riderId = (int) $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$body = get_json_body();
$enteredOtp = isset($body['otp']) ? trim((string) $body['otp']) : '';

$db = Database::get();

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND rider_id = :rider_id LIMIT 1');
$orderStmt->execute(['id' => $orderId, 'rider_id' => $riderId]);
$order = $orderStmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ($order['status'] !== 'rider_assigned') {
    respond_error('invalid_state', 409);
}

$maxAttempts = (int) get_setting('otp_max_attempts', 3);

if ((int) $order['pickup_otp_attempts'] >= $maxAttempts) {
    respond_error('otp_max_attempts_exceeded', 400);
}

if ($enteredOtp === '' || $enteredOtp !== $order['pickup_otp']) {
    $incStmt = $db->prepare(
        "UPDATE orders SET pickup_otp_attempts = pickup_otp_attempts + 1
         WHERE id = :id AND rider_id = :rider_id AND status = 'rider_assigned'"
    );
    $incStmt->execute(['id' => $orderId, 'rider_id' => $riderId]);
    respond_error('invalid_otp', 401, [
        'attempts_remaining' => max(0, $maxAttempts - (int) $order['pickup_otp_attempts'] - 1),
    ]);
}

$db->beginTransaction();

$upd = $db->prepare(
    "UPDATE orders SET status = 'out_for_delivery', picked_up_at = NOW(), pickup_otp_verified_at = NOW()
     WHERE id = :id AND rider_id = :rider_id AND status = 'rider_assigned'"
);
$upd->execute(['id' => $orderId, 'rider_id' => $riderId]);

if ($upd->rowCount() !== 1) {
    // Either not this rider's order, already advanced past
    // rider_assigned, or doesn't exist — the app's job on seeing this
    // is just to re-poll orders-current and re-render, not retry blindly.
    $db->rollBack();
    respond_error('invalid_state', 409);
}

insert_status_history($db, $orderId, 'picked_up', 'rider', $riderId);
insert_status_history($db, $orderId, 'out_for_delivery', 'rider', $riderId, 'auto-advanced after pickup confirmation');
$db->commit();

$orderStmt = $db->prepare('SELECT customer_id, order_code FROM orders WHERE id = :id LIMIT 1');
$orderStmt->execute(['id' => $orderId]);
$order = $orderStmt->fetch();
if ($order) {
    create_notification(
        'customer',
        (int) $order['customer_id'],
        'Order picked up',
        "Order {$order['order_code']} is on its way to you",
        'order',
        ['order_id' => $orderId, 'screen' => 'order_status']
    );
}

respond_ok(['order_id' => $orderId, 'status' => 'out_for_delivery']);
