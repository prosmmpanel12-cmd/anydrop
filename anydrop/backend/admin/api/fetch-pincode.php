<?php
/**
 * Anydrop — Admin Web UI: Pincode → State/District/locality lookup.
 *
 * Feeds the "Fetch by Pincode" helper on areas.php's Add Area form
 * (recall.md item 2 follow-up, 2026-08-21). Purely an auto-fill
 * convenience — every field it suggests stays editable, and the Add
 * Area form works exactly as before if this is never used or the
 * lookup fails. Nothing here is authoritative; the admin always has
 * the final say on what actually gets saved to service_areas.
 *
 * Proxies India Post's free public pincode API
 * (https://api.postalpincode.in/pincode/{pincode}) server-side rather
 * than calling it from the browser, so no CORS dependency and no
 * third-party endpoint exposed directly to the admin's browser.
 *
 * IMPORTANT — this has NEVER been live-tested. This dev sandbox has no
 * outbound network access at all (see _bootstrap.php's own note above
 * admin_require_login()), so the cURL call below has only been
 * reviewed by reading, not executed. Confirm on the real server before
 * relying on it — check for a genuine JSON response, not just a
 * non-error PHP return, since a firewalled outbound connection would
 * silently return curl_exec() === false rather than throwing.
 *
 * Response shape:
 *   ok:true  -> { ok:true, state, district, suggestions: [ {name}, ... ],
 *                 center_lat, center_lng (both null if geocoding failed
 *                 or was skipped — never blocks the rest of the response) }
 *   ok:false -> { ok:false, error: "..." }
 *
 * "suggestions" are the pincode's Post Office names — often the same
 * as, or close to, the local village/mohalla name, but not a precise
 * village-boundary source (see the chat explanation this session: one
 * pincode commonly spans several villages under one post office). The
 * admin picks/edits from these, they're never auto-submitted untouched.
 *
 * center_lat/center_lng — 2026-08-22 addition, so the admin doesn't
 * have to hand-type coordinates for every area. Geocoded from the
 * pincode via OpenStreetMap's free Nominatim API
 * (https://nominatim.openstreetmap.org/search?postalcode=...), server-
 * side for the same CORS/key-hiding reasons as the India Post call
 * above. This is a PINCODE-CENTROID coordinate, not the true center of
 * whichever City/Village or Area the admin ends up creating — a large
 * pincode can easily span several villages. It is offered as a
 * pre-fill suggestion only (the form fields stay editable, and the
 * admin should use the existing "test coordinates" tool on areas.php
 * to sanity-check it against a real GPS point before trusting it for
 * area resolution). Nominatim's usage policy requires a descriptive
 * User-Agent and caps free usage at ~1 request/second — fine for an
 * admin manually adding areas one at a time, NOT fine to ever call in
 * a loop/bulk-import without adding a delay between calls.
 * Geocoding failure (timeout, no match, service down) never fails the
 * whole lookup — state/district/suggestions from India Post still come
 * back, just with center_lat/center_lng as null.
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

$admin = admin_require_login();
admin_require_permission($admin, 'areas_edit');

$pincode = trim($_GET['pincode'] ?? '');

if (!preg_match('/^\d{6}$/', $pincode)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Enter a valid 6-digit pincode.']);
    exit;
}

$ch = curl_init("https://api.postalpincode.in/pincode/{$pincode}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$raw = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    // Sandbox-verified failure mode only (no outbound network here at all —
    // see file header). On the real server this means the lookup service
    // is unreachable/timed out, not that the pincode is wrong.
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the pincode lookup service.' . ($curlError ? " ({$curlError})" : '')]);
    exit;
}

$data = json_decode($raw, true);
$top = is_array($data) ? ($data[0] ?? null) : null;

if (!$top || ($top['Status'] ?? '') !== 'Success' || empty($top['PostOffice'])) {
    echo json_encode(['ok' => false, 'error' => 'No post offices found for that pincode.']);
    exit;
}

$offices = $top['PostOffice'];
$state = $offices[0]['State'] ?? '';
$district = $offices[0]['District'] ?? '';

$suggestions = [];
$seen = [];
foreach ($offices as $o) {
    $name = trim($o['Name'] ?? '');
    if ($name === '' || isset($seen[$name])) {
        continue;
    }
    $seen[$name] = true;
    $suggestions[] = ['name' => $name];
}

// ---- Geocode the pincode centroid via Nominatim (best-effort, never
// fails the response — see file header for why this is only a
// suggestion, not authoritative). ----
$centerLat = null;
$centerLng = null;

$geoCh = curl_init(
    'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'postalcode' => $pincode,
        'country' => 'India',
        'format' => 'json',
        'limit' => 1,
    ])
);
curl_setopt_array($geoCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
    // Nominatim's usage policy requires a real, descriptive User-Agent
    // identifying the application — requests without one can be
    // blocked outright.
    CURLOPT_HTTPHEADER => ['User-Agent: AnydropAdminPanel/1.0 (area-management pincode geocode)'],
]);
$geoRaw = curl_exec($geoCh);
curl_close($geoCh);

if ($geoRaw !== false) {
    $geoData = json_decode($geoRaw, true);
    $geoTop = is_array($geoData) ? ($geoData[0] ?? null) : null;
    if ($geoTop && isset($geoTop['lat'], $geoTop['lon'])) {
        $centerLat = (float) $geoTop['lat'];
        $centerLng = (float) $geoTop['lon'];
    }
}

echo json_encode([
    'ok' => true,
    'state' => $state,
    'district' => $district,
    'suggestions' => $suggestions,
    'center_lat' => $centerLat,
    'center_lng' => $centerLng,
]);
