<?php
/**
 * GET /api/v1/orders/{id}/track
 * Auth: Customer token (must own the order)
 * Response: { status, rider: { name, mobile, lat, lng } | null,
 *             restaurant: { name, lat, lng } | null,
 *             delivery: { lat, lng } | null, eta_minutes,
 *             otp: "1234" (only if status is rider_assigned/out_for_delivery
 *             AND an OTP was actually generated for this order) }
 *
 * bugs.md #1.2 fix — this used to gate the OTP purely on
 * `payment_method === 'upi'`, independently of `orders/create.php`'s own
 * generation condition (`payment_method === 'upi' || otp_required_for_cod`).
 * If an admin ever flips `otp_required_for_cod` on, a COD order would get
 * a real `delivery_otp` written to the DB that this endpoint would then
 * never return — the customer could never see a code to hand the rider.
 * Now checks `delivery_otp !== null` directly (i.e. "was one actually
 * generated for this order"), which stays correct regardless of which
 * condition governs generation in the future.
 *
 * Deliberately tiny/fast — meant to be polled every few seconds while an
 * order is active.
 *
 * `restaurant`/`delivery` added this session (deep-plan §14-15, live
 * tracking map) — both are static per order (a restaurant's location and
 * a delivery address's pin don't move mid-order), so the Android side
 * only needs to read them off the *first* successful poll rather than
 * re-reading every 5s cycle; they're included on every response anyway
 * since this endpoint has no per-field caching and the extra two rows'
 * worth of already-joined columns cost nothing meaningful here. `rider`
 * stays the only field that actually changes poll-to-poll.
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
if (in_array($order['status'], ['rider_assigned', 'out_for_delivery'], true) && $order['delivery_otp'] !== null) {
    $otp = $order['delivery_otp'];
}

// Restaurant pin — same coordinates the restaurant's own listing/menu
// pages use (restaurants.latitude/longitude), not something specific to
// this order. Included for the map's restaurant marker + as a route
// origin/destination candidate; null lat/lng falls back to name-only
// display on the Android side rather than a missing marker crashing
// anything (a restaurant without coordinates set is a pre-existing data
// gap this endpoint doesn't need to solve).
$restStmt = $db->prepare('SELECT name, latitude, longitude FROM restaurants WHERE id = :id LIMIT 1');
$restStmt->execute(['id' => $order['restaurant_id']]);
$rest = $restStmt->fetch();
$restaurant = $rest ? [
    'name' => $rest['name'],
    'lat' => $rest['latitude'] !== null ? (float) $rest['latitude'] : null,
    'lng' => $rest['longitude'] !== null ? (float) $rest['longitude'] : null,
] : null;

// Delivery pin — the saved address this order is actually going to
// (orders.delivery_address_id), not the customer's current live
// location (which this endpoint has no way to know and isn't asked
// for). Null when the order predates delivery_address_id being
// required, or the address was later deleted — same tolerant-null
// pattern as $restaurant above.
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

// Simple placeholder ETA until Phase 4 wires OSRM route-based ETA.
$etaMinutes = in_array($order['status'], ['pending', 'accepted', 'preparing'], true)
    ? $order['estimated_prep_minutes']
    : ($order['status'] === 'ready' || $order['status'] === 'rider_assigned' || $order['status'] === 'picked_up' || $order['status'] === 'out_for_delivery' ? 20 : null);

respond_ok([
    'status' => $order['status'],
    'rider' => $rider,
    'restaurant' => $restaurant,
    'delivery' => $delivery,
    'eta_minutes' => $etaMinutes !== null ? (int) $etaMinutes : null,
    'otp' => $otp,
]);
