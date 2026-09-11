<?php
/**
 * GET /api/v1/restaurant/orders/{id}
 * Auth: Restaurant token (must own the order)
 * Response: { "order": {...} } — same shape as the customer-facing detail endpoint.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$orderId = (int) ($_GET['id'] ?? 0);

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

$formatted = format_order($db, $order);

// Migration 83 — pickup OTP is restaurant-facing (the restaurant reads
// it out to whichever rider shows up to collect the order), so it's
// added here rather than in format_order() itself (shared by
// customer/admin/rider responses too, none of which should ever see
// this). Only surfaced once a rider is actually assigned — before
// that there's no one to hand it to yet, and after pickup it's no
// longer useful (pickup_otp_verified tells the app to stop showing it).
$formatted['pickup_otp'] = $order['status'] === 'rider_assigned' ? $order['pickup_otp'] : null;
$formatted['pickup_otp_verified'] = $order['pickup_otp_verified_at'] !== null;

respond_ok(['order' => $formatted]);
