<?php
/**
 * POST /api/v1/rider/status.php
 * Auth: Rider token
 * Request: { "online": true|false }
 * Response: { "is_online": true|false }
 *
 * Phase 3 (R2.3, docs/rider/83_Plan_Phase3...) — the dashboard's
 * online/offline switch. Deliberately narrow: this endpoint only ever
 * flips `riders.is_online`. It does NOT touch location (that's
 * location.php, called separately/more often) and does NOT do any
 * dispatch/assignment work (that's R3, not built yet).
 *
 * require_auth('rider') already 403s a suspended/deleted rider before
 * we get here, but it does NOT block pending/rejected (see that
 * function's own comment — they still need authenticated screens).
 * Going online is a stronger requirement than "has a valid session",
 * so we check status === 'approved' ourselves here, same pattern
 * every other status-gated action in this codebase uses rather than
 * pushing it into require_auth for every owner type.
 *
 * Going online additionally requires the rider already has a location
 * on file (riders.last_lat/last_lng, written by location.php). An
 * online rider with no known location is meaningless for dispatch —
 * rather than let the app go online first and silently be
 * un-dispatchable, we reject with a clear error the app can act on
 * (request location permission / send one location update first).
 * No freshness/staleness window is enforced here on purpose — that
 * belongs to R3's candidate-selection logic (see deep-plan section
 * 4.1 "recent location freshness"), which decides per-order whether a
 * given rider's last-known location is fresh enough to dispatch to,
 * not this toggle.
 *
 * Going offline has no such requirement and is always allowed for an
 * approved rider — a rider must always be able to take themselves out
 * of the pool.
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

if (($owner['status'] ?? null) !== 'approved') {
    respond_error('not_approved', 403);
}

$body = get_json_body();
if (!array_key_exists('online', $body) || !is_bool($body['online'])) {
    respond_error('validation_error', 422, ['fields' => ['online']]);
}

$goingOnline = $body['online'];

$db = Database::get();

if ($goingOnline) {
    $stmt = $db->prepare('SELECT last_lat, last_lng FROM riders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $riderId]);
    $row = $stmt->fetch();
    if (!$row || $row['last_lat'] === null || $row['last_lng'] === null) {
        respond_error('location_required', 422);
    }
}

$upd = $db->prepare('UPDATE riders SET is_online = :online WHERE id = :id');
$upd->execute(['online' => $goingOnline ? 1 : 0, 'id' => $riderId]);

respond_ok(['is_online' => $goingOnline]);
