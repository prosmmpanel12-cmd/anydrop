<?php
/**
 * GET /api/v1/rider/orders-current.php
 * Auth: Rider token
 * Response: { "order": null | { id, order_code, status, restaurant_name,
 *             restaurant_address, delivery_address, delivery_lat,
 *             delivery_lng, payment_method, grand_total, item_count,
 *             delivery_otp_required, accepted_at } }
 *
 * Phase 3 R3 (doc 83/85). Backs RiderDashboardActivity's "current
 * delivery" card, replacing the static placeholder from R2 (doc 83) now
 * that accept.php can actually put a rider into rider_assigned/
 * picked_up/out_for_delivery. Pickup/drop-off flow itself (deep-plan
 * sections 9-16 — status advance buttons, OTP entry) is NOT built this
 * session; this endpoint only surfaces the current order's read state
 * so the dashboard has something real to show.
 *
 * delivery_otp is intentionally NOT returned here — that's the
 * customer's copy to read out to the rider at the door, not something
 * the rider's own app should display up front (deep-plan §16). Only
 * whether one exists (delivery_otp_required) is exposed.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$db = Database::get();
$stmt = $db->prepare(
    "SELECT o.id, o.order_code, o.status, o.payment_method, o.grand_total,
            o.delivery_instructions, o.delivery_otp, o.accepted_at,
            r.name AS restaurant_name, r.address AS restaurant_address,
            ca.full_address AS delivery_address, ca.latitude AS delivery_lat, ca.longitude AS delivery_lng
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     WHERE o.rider_id = :rider_id AND o.status IN ('rider_assigned','picked_up','out_for_delivery')
     ORDER BY o.id DESC
     LIMIT 1"
);
$stmt->execute(['rider_id' => $riderId]);
$row = $stmt->fetch();

if (!$row) {
    respond_ok(['order' => null]);
}

$itemCountStmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) AS n FROM order_items WHERE order_id = :id');
$itemCountStmt->execute(['id' => $row['id']]);

respond_ok(['order' => [
    'id' => (int) $row['id'],
    'order_code' => $row['order_code'],
    'status' => $row['status'],
    'restaurant_name' => $row['restaurant_name'],
    'restaurant_address' => $row['restaurant_address'],
    'delivery_address' => $row['delivery_address'],
    'delivery_lat' => $row['delivery_lat'] !== null ? (float) $row['delivery_lat'] : null,
    'delivery_lng' => $row['delivery_lng'] !== null ? (float) $row['delivery_lng'] : null,
    'delivery_instructions' => $row['delivery_instructions'],
    'payment_method' => $row['payment_method'],
    'grand_total' => (float) $row['grand_total'],
    'item_count' => (int) $itemCountStmt->fetch()['n'],
    'delivery_otp_required' => $row['delivery_otp'] !== null,
    'accepted_at' => $row['accepted_at'],
]]);
