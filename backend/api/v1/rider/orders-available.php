<?php
/**
 * GET /api/v1/rider/orders-available.php
 * Auth: Rider token
 * Response: { "offer": null | { assignment_id, order_id, order_code,
 *             restaurant_name, restaurant_address, distance_km,
 *             payment_method, grand_total, item_count, expires_at,
 *             expires_in_seconds } }
 *
 * Phase 3 R3 (doc 83/85). Sequential single-offer model (see
 * lib/dispatch.php's header) — a rider only ever has at most one open
 * offer at a time, so this returns a single object, not a list. The
 * dashboard polls this while online with no active/accepted delivery
 * (RiderDashboardActivity, "explicitly out of scope" note in doc 83 is
 * now superseded for the offer/accept/reject piece specifically).
 *
 * expire_stale_offers() runs first so a poll never returns an offer
 * that's actually already timed out server-side, even if this
 * particular rider's own offer row hasn't been swept by anything else
 * yet — see that function's own kdoc for why this is safe to call on
 * every request instead of needing a cron/worker.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/dispatch.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$db = Database::get();
expire_stale_offers($db);

$stmt = $db->prepare(
    "SELECT a.id AS assignment_id, a.order_id, a.expires_at,
            o.order_code, o.payment_method, o.grand_total, o.restaurant_id,
            r.name AS restaurant_name, r.address AS restaurant_address,
            r.latitude AS restaurant_lat, r.longitude AS restaurant_lng
     FROM rider_order_assignments a
     JOIN orders o ON o.id = a.order_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE a.rider_id = :rider_id AND a.status = 'offered' AND a.expires_at >= NOW()
     ORDER BY a.id DESC
     LIMIT 1"
);
$stmt->execute(['rider_id' => $riderId]);
$row = $stmt->fetch();

if (!$row) {
    respond_ok(['offer' => null]);
}

$itemCountStmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) AS n FROM order_items WHERE order_id = :id');
$itemCountStmt->execute(['id' => $row['order_id']]);
$itemCount = (int) $itemCountStmt->fetch()['n'];

$riderLocStmt = $db->prepare('SELECT last_lat, last_lng FROM riders WHERE id = :id LIMIT 1');
$riderLocStmt->execute(['id' => $riderId]);
$riderLoc = $riderLocStmt->fetch();
$distanceKm = null;
if ($riderLoc && $riderLoc['last_lat'] !== null && $row['restaurant_lat'] !== null) {
    $distanceKm = round(haversine_km(
        (float) $riderLoc['last_lat'],
        (float) $riderLoc['last_lng'],
        (float) $row['restaurant_lat'],
        (float) $row['restaurant_lng']
    ), 1);
}

$expiresInSeconds = max(0, strtotime($row['expires_at']) - time());

respond_ok(['offer' => [
    'assignment_id' => (int) $row['assignment_id'],
    'order_id' => (int) $row['order_id'],
    'order_code' => $row['order_code'],
    'restaurant_name' => $row['restaurant_name'],
    'restaurant_address' => $row['restaurant_address'],
    'distance_km' => $distanceKm,
    'payment_method' => $row['payment_method'],
    'grand_total' => (float) $row['grand_total'],
    'item_count' => $itemCount,
    'expires_at' => $row['expires_at'],
    'expires_in_seconds' => $expiresInSeconds,
]]);
