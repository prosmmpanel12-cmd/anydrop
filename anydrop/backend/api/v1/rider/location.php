<?php
/**
 * POST /api/v1/rider/location.php
 * Auth: Rider token
 * Request: { "lat": <float>, "lng": <float> }
 * Response: { "ok": true }
 *
 * Phase 3 (R2.3, docs/rider/83_Plan_Phase3...) — writes
 * riders.last_lat/last_lng/last_location_at. Called by the app on
 * foreground + on a poll interval while the dashboard's online switch
 * is on (see that doc's "open question" re: interval — Android side
 * owns the actual timing, this endpoint just accepts whatever it's sent).
 *
 * Deliberately NOT writing to `rider_locations` (the per-point audit/
 * trail table used for live order tracking — see 03_Live_Tracking.md).
 * That table is for tracking a rider during an active delivery a
 * customer is watching; this endpoint is "is this rider's online
 * status still backed by a real location", which only ever needs the
 * single latest point, not a history. Wiring this same lat/lng into
 * rider_locations as well is a natural extension once R3 has an
 * active-delivery concept for it to attach to — not needed for the
 * online-toggle slice this endpoint ships with.
 *
 * No approval-status gate here (unlike status.php) — a rider can be
 * sending location before they've gone online (e.g. right after
 * SignupActivity's GPS grab, or app-foreground before they've toggled
 * on), and there's no harm in an approved-but-offline rider's location
 * being fresh. status.php is what actually gates entry into the
 * online pool.
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

$db = Database::get();
$upd = $db->prepare(
    'UPDATE riders SET last_lat = :lat, last_lng = :lng, last_location_at = NOW() WHERE id = :id'
);
$upd->execute(['lat' => $lat, 'lng' => $lng, 'id' => $riderId]);

respond_ok(['ok' => true]);
