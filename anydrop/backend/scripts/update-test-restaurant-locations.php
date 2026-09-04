<?php
/**
 * Anydrop — Test Restaurant Location Updater (Osian, Jodhpur)
 *
 * WHY THIS EXISTS
 * ----------------
 * Every test restaurant currently has null/placeholder lat-lng, so the
 * features.md §6 distance/ETA row on RestaurantDetailActivity never has
 * anything real to show ("2.7 km · Sardarpura" style line stays hidden
 * since distanceKm comes back null from menu.php). This script gives
 * every restaurant a real, named location inside Osian, Jodhpur so that
 * row (and Home's existing distance sort/display) has real data to
 * render and test against.
 *
 * WHAT IT DOES
 * ------------
 * Assigns each restaurant one landmark from a fixed pool of real Osian
 * places (bus stand, hospital, temples, station, etc.), round-robin, in
 * id order — so re-running this script is deterministic (restaurant #1
 * always lands on the same landmark), not random like the
 * bestseller/discount script's demo data.
 *
 * `address` is written as "Near <Landmark>, Osian, Jodhpur" — the
 * "Near <Landmark>" part before the first comma is deliberately what
 * RestaurantDetailActivity.kt's `address?.substringBefore(",")` picks up
 * as the locality shown next to the distance ("2.7 km · Near New Bus
 * Stand"), so this script's address format and that Kotlin's parsing
 * logic are meant to match — flagged as a judgment call in Status.md
 * part 6, this is the real data that judgment call will be tested against.
 *
 * COORDINATES ARE APPROXIMATE, NOT SURVEYED — pulled from general-purpose
 * map lookups for each landmark's rough position within Osian town, good
 * enough to produce believable varying distances/ETAs for UI testing, not
 * accurate enough for real dispatch/routing.
 *
 * Safe to re-run any time (full recompute, same as the other scripts in
 * this folder) — every run reassigns every restaurant's address/lat/lng
 * from scratch off the same deterministic landmark pool.
 *
 * USAGE (same convention as the other scripts/*.php):
 *   http://localhost:8080/anydrop/scripts/update-test-restaurant-locations.php?key=SEED_ME
 *
 * Optional param:
 *   &jitter_m=80   small random offset in meters applied on top of each
 *                  landmark's base point (default 80), so restaurants
 *                  sharing a landmark don't sit on the exact same pixel.
 *                  Set to 0 for exact landmark coordinates.
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script.';
    exit;
}

$jitterMeters = max(0, (float) ($_GET['jitter_m'] ?? 80));

// Landmark pool — real places in/around Osian, Jodhpur district,
// Rajasthan. Base coordinates are approximate (general map lookup),
// spread across the town so distance/ETA values vary meaningfully
// between restaurants instead of clustering on one point.
$landmarks = [
    ['name' => 'New Bus Stand',        'lat' => 26.7231, 'lng' => 72.9061],
    ['name' => 'Government Hospital',  'lat' => 26.7268, 'lng' => 72.9033],
    ['name' => 'Sachiya Mata Mandir',  'lat' => 26.7205, 'lng' => 72.9098],
    ['name' => 'Railway Station',      'lat' => 26.7223, 'lng' => 72.9017],
    ['name' => 'Osian Fort',           'lat' => 26.7256, 'lng' => 72.9061],
    ['name' => 'Mahavir Circle',       'lat' => 26.7248, 'lng' => 72.9042],
    ['name' => 'Kali Devi Mandir',     'lat' => 26.7261, 'lng' => 72.9075],
    ['name' => 'Osian Bypass Road',    'lat' => 26.7285, 'lng' => 72.9010],
    ['name' => 'Government School',   'lat' => 26.7238, 'lng' => 72.9088],
    ['name' => 'Surya Mandir',         'lat' => 26.7215, 'lng' => 72.9055],
    ['name' => 'Police Station',       'lat' => 26.7252, 'lng' => 72.9028],
    ['name' => 'Sand Dunes Point',     'lat' => 26.7300, 'lng' => 72.9120],
];

$db = Database::get();

header('Content-Type: text/plain');

$restaurants = $db->query("SELECT id, name FROM restaurants WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll();
if (!$restaurants) {
    echo "No restaurants found. Nothing to do.\n";
    exit;
}

$updateStmt = $db->prepare(
    "UPDATE restaurants SET address = ?, latitude = ?, longitude = ? WHERE id = ?"
);

$updated = 0;
$landmarkCount = count($landmarks);

foreach ($restaurants as $i => $restaurant) {
    $landmark = $landmarks[$i % $landmarkCount];

    // Small deterministic-ish jitter (seeded off restaurant id, not
    // mt_rand) so re-running produces the same result every time, in
    // keeping with the rest of this script being fully deterministic.
    mt_srand((int) $restaurant['id']);
    $jitterLat = $jitterMeters > 0 ? (mt_rand(-1000, 1000) / 1000) * ($jitterMeters / 111000) : 0;
    $jitterLng = $jitterMeters > 0 ? (mt_rand(-1000, 1000) / 1000) * ($jitterMeters / 111000) : 0;

    $lat = round($landmark['lat'] + $jitterLat, 8);
    $lng = round($landmark['lng'] + $jitterLng, 8);
    $address = "Near {$landmark['name']}, Osian, Jodhpur";

    $updateStmt->execute([$address, $lat, $lng, $restaurant['id']]);
    $updated++;

    echo "Restaurant #{$restaurant['id']} ({$restaurant['name']}): {$address} [{$lat}, {$lng}]\n";
}

echo "\nDone. {$updated} restaurant(s) relocated to Osian, Jodhpur.\n";
