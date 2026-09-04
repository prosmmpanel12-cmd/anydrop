<?php
/**
 * Anydrop — Admin Web UI: Locality name → coordinates lookup.
 *
 * Companion to fetch-pincode.php, added 2026-08-22 to answer a gap
 * that endpoint can't cover: a pincode's geocoded centroid is one
 * point for the WHOLE pincode area, but a City/Village or (especially)
 * an Area node underneath it — e.g. Osian's pincode covers Osian
 * town, but "Neora" is a specific Area inside/near it with its own,
 * different, real location — needs its own coordinate, not Osian's
 * average center.
 *
 * This proxies OpenStreetMap's free Nominatim SEARCH endpoint
 * (https://nominatim.openstreetmap.org/search), which geocodes by
 * free-text place name rather than by postalcode, so it can resolve a
 * specific named locality if OSM has it mapped. Called with the full
 * breadcrumb context (locality name + City/Village + District + State)
 * as one query string, e.g. "Neora, Osian, Jodhpur, Rajasthan, India"
 * — giving Nominatim the surrounding context sharply narrows the
 * search versus the bare name alone, since "Neora" by itself could
 * plausibly match a same-named place elsewhere in India.
 *
 * NOT always able to find small hamlets/localities — OSM's rural India
 * coverage is inconsistent. A miss returns {ok:false} with a clear
 * message; this is expected sometimes, not a bug. When it misses, the
 * admin should fall back to manually finding the point (e.g. via
 * Google Maps → right-click → copy coordinates) and typing it in.
 *
 * Same "purely a suggestion, always editable, never authoritative" and
 * "never live-tested, this sandbox has no outbound network" caveats as
 * fetch-pincode.php — see that file's header for the fuller
 * explanation, applies identically here.
 *
 * Nominatim's usage policy: descriptive User-Agent required, and caps
 * free usage at ~1 request/second. This endpoint is only ever called
 * one admin-click-at-a-time from the Add Area form, never in a loop —
 * keep it that way if this is ever reused elsewhere.
 *
 * Request:  GET ?name=Neora&city_village=Osian&district=Jodhpur&state=Rajasthan
 *           (name is required; the rest are optional but strongly
 *           recommended — omitting them makes ambiguous name collisions
 *           with other places in India far more likely)
 * Response: ok:true  -> { ok:true, center_lat, center_lng, matched_label }
 *           ok:false -> { ok:false, error: "..." }
 *
 * matched_label is Nominatim's own display_name for what it actually
 * matched — shown back to the admin so they can sanity-check this is
 * really the place they meant before trusting the coordinates.
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');

$admin = admin_require_login();
admin_require_permission($admin, 'areas_edit');

$name = trim($_GET['name'] ?? '');
$cityVillage = trim($_GET['city_village'] ?? '');
$district = trim($_GET['district'] ?? '');
$state = trim($_GET['state'] ?? '');

if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A locality name is required.']);
    exit;
}

// Build the query with as much context as we have — most-specific
// first, same order a human would say the address in.
$queryParts = array_filter([$name, $cityVillage, $district, $state, 'India']);
$query = implode(', ', $queryParts);

$ch = curl_init(
    'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $query,
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'in',
    ])
);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
    // Required by Nominatim's usage policy — requests without a real
    // User-Agent can be blocked outright.
    CURLOPT_HTTPHEADER => ['User-Agent: AnydropAdminPanel/1.0 (area-management locality geocode)'],
]);
$raw = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    // Sandbox-verified failure mode only (no outbound network here at
    // all). On the real server this means the lookup service is
    // unreachable/timed out.
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the geocoding service.' . ($curlError ? " ({$curlError})" : '')]);
    exit;
}

$data = json_decode($raw, true);
$top = is_array($data) ? ($data[0] ?? null) : null;

if (!$top || !isset($top['lat'], $top['lon'])) {
    echo json_encode([
        'ok' => false,
        'error' => "Couldn't find \"{$name}\" — OSM may not have this locality mapped. Try searching it on Google Maps and enter the coordinates manually.",
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'center_lat' => (float) $top['lat'],
    'center_lng' => (float) $top['lon'],
    'matched_label' => $top['display_name'] ?? $query,
]);
