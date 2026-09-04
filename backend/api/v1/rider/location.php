<?php
/**
 * POST /api/v1/rider/location.php
 * Auth: Rider token
 * Request: { "lat": <float>, "lng": <float>, "order_id": <int> (optional),
 *            "speed_kmh": <float> (optional) }
 * Response: { "ok": true }
 *
 * Phase 3 (R2.3, docs/rider/83_Plan_Phase3...) — writes
 * riders.last_lat/last_lng/last_location_at. Called by the app on
 * foreground + on a poll interval while the dashboard's online switch
 * is on (see that doc's "open question" re: interval — Android side
 * owns the actual timing, this endpoint just accepts whatever it's sent).
 *
 * Phase 3 R4 (deep-plan §12-13, this session) extends this same
 * endpoint rather than adding a second one: when the caller supplies
 * `order_id`, and that order both belongs to this rider AND is in an
 * active-delivery status, this endpoint ALSO inserts into
 * `rider_locations` (the audit/history table location.php's own kdoc
 * previously flagged as "a natural extension once R3 has an
 * active-delivery concept for it to attach to" — R3/R4 now do). Every
 * other call shape (no order_id, or an order_id that fails validation)
 * behaves exactly as before: only the `riders.last_lat/last_lng` hot
 * cache is touched, silently, no error surfaced for a stale/foreign/
 * completed order_id — the app may still be sending its last known
 * order_id for a brief window right after delivery completes, and
 * that's expected, not a client bug worth failing the ping over.
 *
 * order_id validation is deliberately silent-fail (not respond_error)
 * for exactly this reason: a location ping is best-effort telemetry,
 * never something the app needs to react to. The one thing this
 * endpoint must never do is let the client dictate an arbitrary
 * rider_id/order_id pair into rider_locations (deep-plan §13: "Never
 * trust the client to write arbitrary rider/order combinations") — so
 * order_id is only trusted after an explicit ownership+status check
 * against the DB, never taken at face value.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['lat', 'lng']);

if (!is_numeric($body['lat']) || !is_numeric($body['lng'])) {
    respond_error('validation_error', 422, ['fields' => ['lat', 'lng']]);
}

$lat = (float) $body['lat'];
$lng = (float) $body['lng'];

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    respond_error('validation_error', 422, ['fields' => ['lat', 'lng']]);
}

// speed_kmh is optional and purely informational (rider_locations.speed_kmh
// is nullable) — accept anything numeric, silently drop anything else
// rather than failing a best-effort telemetry ping over it.
$speedKmh = null;
if (isset($body['speed_kmh']) && is_numeric($body['speed_kmh'])) {
    $speedKmh = (float) $body['speed_kmh'];
}

$db = Database::get();

$upd = $db->prepare(
    'UPDATE riders SET last_lat = :lat, last_lng = :lng, last_location_at = NOW() WHERE id = :id'
);
$upd->execute(['lat' => $lat, 'lng' => $lng, 'id' => $riderId]);

// Active-delivery audit trail — only when order_id is supplied AND
// checks out as this rider's own order in a status where a customer
// could plausibly be watching a live position. Anything else (missing,
// foreign, wrong status) is a silent no-op on this half of the write —
// see kdoc above for why that's intentional rather than an error.
$orderId = isset($body['order_id']) ? (int) $body['order_id'] : 0;
if ($orderId > 0) {
    $activeStatuses = ['rider_assigned', 'picked_up', 'out_for_delivery'];
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $chk = $db->prepare(
        "SELECT id FROM orders WHERE id = ? AND rider_id = ? AND status IN ($placeholders) LIMIT 1"
    );
    $chk->execute(array_merge([$orderId, $riderId], $activeStatuses));
    if ($chk->fetch()) {
        $ins = $db->prepare(
            'INSERT INTO rider_locations (rider_id, order_id, latitude, longitude, speed_kmh, recorded_at)
             VALUES (:rider_id, :order_id, :lat, :lng, :speed_kmh, NOW())'
        );
        $ins->execute([
            'rider_id' => $riderId,
            'order_id' => $orderId,
            'lat' => $lat,
            'lng' => $lng,
            'speed_kmh' => $speedKmh,
        ]);
    }
}

respond_ok(['ok' => true]);
