<?php
/**
 * GET /api/v1/orders/{id}/route.php
 * Auth: Customer token (must own the order)
 * Response: { polyline: "<encoded Google polyline>" | null,
 *             distance_km: <float> | null, duration_minutes: <int> | null,
 *             leg: "to_restaurant" | "to_customer" | null }
 *
 * Phase 3 R5 follow-up (deep-plan §14-15) — separate from track.php on
 * purpose. track.php is polled every ~5s for the rider dot to move;
 * this is the "recalculate the drawn route" call, meant to be polled
 * far less often (deep-plan §15: "roughly 30-45 seconds or on
 * significant route deviation" — Android owns that cadence, same
 * division of responsibility rider/location.php's kdoc already
 * documents for its own poll interval).
 *
 * `leg` picks the destination based on where the rider actually is in
 * the delivery, same active-delivery framing rider/location.php uses:
 *   - status = rider_assigned            → leg to_restaurant (rider is
 *     still headed to pick up the order)
 *   - status = picked_up/out_for_delivery → leg to_customer (rider has
 *     the order, headed to the delivery address)
 *   - anything else (no rider yet, or order already delivered/
 *     cancelled) → no route to draw; responds with everything null,
 *     not an error — same "absence is a normal state, not a failure"
 *     convention track.php's own `rider: null` already uses.
 *
 * GOOGLE DIRECTIONS API KEY: read from app_settings key
 * `google_directions_api_key` (get_setting(), same DB-backed-config
 * convention lib/fcm.php uses for its service-account JSON — no
 * hardcoded config.php define, no vendor/Composer dependency, hand-
 * rolled curl call exactly like PaytmStatusClient.php). No admin-panel
 * field wired up for this key yet (fast follow — app-settings.php's
 * $fields array is per-app-suffixed, which doesn't fit a single
 * shared platform-wide key cleanly; for now set it directly via
 * set_setting('google_directions_api_key', '<key>') or an INSERT into
 * app_settings). Until a real key with Directions API enabled +
 * billing is configured, this endpoint responds with polyline: null
 * rather than an error — same "structurally complete, blank until a
 * real key exists" state Maps SDK tiles are already documented to be
 * in (see customer app's MapPinDropActivity kdoc) — so the map screen
 * degrades to markers-only, not a broken screen.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settings.php';

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

function respond_no_route(): void
{
    respond_ok(['polyline' => null, 'distance_km' => null, 'duration_minutes' => null, 'leg' => null]);
}

if (!$order['rider_id']) {
    respond_no_route();
}

$rStmt = $db->prepare('SELECT last_lat, last_lng FROM riders WHERE id = :id LIMIT 1');
$rStmt->execute(['id' => $order['rider_id']]);
$rider = $rStmt->fetch();
if (!$rider || $rider['last_lat'] === null || $rider['last_lng'] === null) {
    respond_no_route();
}
$originLat = (float) $rider['last_lat'];
$originLng = (float) $rider['last_lng'];

$leg = null;
$destLat = null;
$destLng = null;

if ($order['status'] === 'rider_assigned') {
    $leg = 'to_restaurant';
    $restStmt = $db->prepare('SELECT latitude, longitude FROM restaurants WHERE id = :id LIMIT 1');
    $restStmt->execute(['id' => $order['restaurant_id']]);
    $rest = $restStmt->fetch();
    if ($rest && $rest['latitude'] !== null && $rest['longitude'] !== null) {
        $destLat = (float) $rest['latitude'];
        $destLng = (float) $rest['longitude'];
    }
} elseif (in_array($order['status'], ['picked_up', 'out_for_delivery'], true)) {
    $leg = 'to_customer';
    if ($order['delivery_address_id']) {
        $addrStmt = $db->prepare('SELECT latitude, longitude FROM customer_addresses WHERE id = :id LIMIT 1');
        $addrStmt->execute(['id' => $order['delivery_address_id']]);
        $addr = $addrStmt->fetch();
        if ($addr && $addr['latitude'] !== null && $addr['longitude'] !== null) {
            $destLat = (float) $addr['latitude'];
            $destLng = (float) $addr['longitude'];
        }
    }
}

if ($destLat === null || $destLng === null) {
    respond_no_route();
}

$apiKey = trim((string) get_setting('google_directions_api_key', ''));
if ($apiKey === '') {
    // No key configured — degrade to "no route" rather than erroring,
    // per this file's kdoc. Still tell the Android side which leg it
    // would have been, so the map can at least draw a straight line
    // between origin/destination as a low-fidelity fallback if it
    // chooses to (Android's own decision, not this endpoint's).
    respond_ok(['polyline' => null, 'distance_km' => null, 'duration_minutes' => null, 'leg' => $leg]);
}

$url = 'https://maps.googleapis.com/maps/api/directions/json'
    . '?origin=' . urlencode($originLat . ',' . $originLng)
    . '&destination=' . urlencode($destLat . ',' . $destLng)
    . '&mode=driving'
    . '&key=' . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
curl_close($ch);

if ($response === false || $response === '') {
    // Network hiccup / Directions API unreachable — same tolerant
    // degrade as no-key-configured above; a route recalc failing is
    // never worth surfacing as an error to the customer's tracking
    // screen, the previously-drawn route (if any) just stays on screen
    // until the next successful recalc.
    respond_ok(['polyline' => null, 'distance_km' => null, 'duration_minutes' => null, 'leg' => $leg]);
}

$decoded = json_decode($response, true);
$route = is_array($decoded) ? ($decoded['routes'][0] ?? null) : null;
$leg0 = is_array($route) ? ($route['legs'][0] ?? null) : null;

if (!$route || !$leg0) {
    // Covers ZERO_RESULTS, REQUEST_DENIED (bad/restricted key),
    // OVER_QUERY_LIMIT, etc. — all "no usable route right now", same
    // graceful null response rather than distinguishing every Google
    // status string for the Android side to handle.
    respond_ok(['polyline' => null, 'distance_km' => null, 'duration_minutes' => null, 'leg' => $leg]);
}

$polyline = $route['overview_polyline']['points'] ?? null;
$distanceKm = isset($leg0['distance']['value']) ? round($leg0['distance']['value'] / 1000, 1) : null;
$durationMinutes = isset($leg0['duration']['value']) ? (int) round($leg0['duration']['value'] / 60) : null;

respond_ok([
    'polyline' => $polyline,
    'distance_km' => $distanceKm,
    'duration_minutes' => $durationMinutes,
    'leg' => $leg,
]);
