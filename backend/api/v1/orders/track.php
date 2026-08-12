<?php
/**
 * GET /api/v1/orders/{id}/track
 * Auth: Customer token (must own the order)
 * Response: { status, rider: { name, mobile, lat, lng } | null, eta_minutes,
 *             otp: "1234" (only if status is rider_assigned/out_for_delivery and payment_method=upi) }
 *
 * Deliberately tiny/fast — meant to be polled every few seconds while an
 * order is active (Phase 4 will add the live map on top of this same call).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$orderId = (int) ($_GET['id'] ?? 0);

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

$rider = null;
if ($order['rider_id']) {
    $rStmt = $db->prepare('SELECT name, mobile, last_lat, last_lng FROM riders WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $order['rider_id']]);
    $r = $rStmt->fetch();
    if ($r) {
        $rider = [
            'name' => $r['name'],
            'mobile' => $r['mobile'],
            'lat' => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
            'lng' => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
        ];
    }
}

$otp = null;
if (in_array($order['status'], ['rider_assigned', 'out_for_delivery'], true) && $order['payment_method'] === 'upi') {
    $otp = $order['delivery_otp'];
}

// Simple placeholder ETA until Phase 4 wires OSRM route-based ETA.
$etaMinutes = in_array($order['status'], ['pending', 'accepted', 'preparing'], true)
    ? $order['estimated_prep_minutes']
    : ($order['status'] === 'ready' || $order['status'] === 'rider_assigned' || $order['status'] === 'picked_up' || $order['status'] === 'out_for_delivery' ? 20 : null);

respond_ok([
    'status' => $order['status'],
    'rider' => $rider,
    'eta_minutes' => $etaMinutes !== null ? (int) $etaMinutes : null,
    'otp' => $otp,
]);
