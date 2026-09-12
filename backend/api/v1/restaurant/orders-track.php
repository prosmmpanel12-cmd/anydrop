<?php
/**
 * GET /api/v1/restaurant/orders/{id}/track
 * Auth: Restaurant token (must own the order)
 * Response: { status, rider: { name, mobile, lat, lng } | null,
 *             restaurant: { lat, lng } | null,
 *             delivery: { lat, lng } | null, eta_minutes }
 *
 * Plan doc 127 §1 (2026-09-11) — near-direct port of orders/track.php,
 * restaurant-auth'd instead of customer-auth'd, so a restaurant can see
 * where their order's rider currently is, same as the customer app
 * already can. Ownership check mirrors orders-detail.php's existing one
 * exactly rather than reinventing it.
 *
 * Deliberately excludes, unlike the customer version:
 *   - `otp` — pickup OTP is orders-detail.php's concern already (see
 *     that endpoint's `pickup_otp` field); delivery OTP is
 *     customer-only and none of the restaurant's business.
 *   - payment info.
 *
 * `rider` is gated server-side to only populate while `status` is
 * rider_assigned/picked_up/out_for_delivery, mirroring the customer
 * endpoint's own shouldShowMap() gate — this way the Android side can
 * just check `rider != null` rather than separately reasoning about
 * which statuses are "trackable".
 *
 * Marker-only for v1, per doc 127 §1's recommendation — no
 * route.php-equivalent polyline here. Ship this first; add a route
 * line as a fast-follow only if the app owner asks for it.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

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
// Same ownership check as orders-detail.php — reused verbatim, not
// reinvented.
if ((int) $order['restaurant_id'] !== (int) $owner['owner_id']) {
    respond_error('forbidden', 403);
}

$trackableStatuses = ['rider_assigned', 'picked_up', 'out_for_delivery'];

$rider = null;
if ($order['rider_id'] && in_array($order['status'], $trackableStatuses, true)) {
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

// Restaurant's own fixed pin — mostly useful as a route origin, arguably
// skippable since the restaurant already knows where it is, but keeps
// the response shape consistent with the customer endpoint in case a
// shared Android map-drawing helper gets extracted later (doc 127 §1).
$restStmt = $db->prepare('SELECT latitude, longitude FROM restaurants WHERE id = :id LIMIT 1');
$restStmt->execute(['id' => $order['restaurant_id']]);
$rest = $restStmt->fetch();
$restaurant = $rest ? [
    'lat' => $rest['latitude'] !== null ? (float) $rest['latitude'] : null,
    'lng' => $rest['longitude'] !== null ? (float) $rest['longitude'] : null,
] : null;

// Destination pin — same customer_addresses join orders/track.php
// already does.
$delivery = null;
if ($order['delivery_address_id']) {
    $addrStmt = $db->prepare('SELECT latitude, longitude FROM customer_addresses WHERE id = :id LIMIT 1');
    $addrStmt->execute(['id' => $order['delivery_address_id']]);
    $addr = $addrStmt->fetch();
    if ($addr) {
        $delivery = [
            'lat' => $addr['latitude'] !== null ? (float) $addr['latitude'] : null,
            'lng' => $addr['longitude'] !== null ? (float) $addr['longitude'] : null,
        ];
    }
}

// Same placeholder-ETA logic as orders/track.php, until Phase 4 wires
// OSRM route-based ETA for both apps.
$etaMinutes = in_array($order['status'], ['pending', 'accepted', 'preparing'], true)
    ? $order['estimated_prep_minutes']
    : ($order['status'] === 'ready' || $order['status'] === 'rider_assigned' || $order['status'] === 'picked_up' || $order['status'] === 'out_for_delivery' ? 20 : null);

respond_ok([
    'status' => $order['status'],
    'rider' => $rider,
    'restaurant' => $restaurant,
    'delivery' => $delivery,
    'eta_minutes' => $etaMinutes !== null ? (int) $etaMinutes : null,
]);
