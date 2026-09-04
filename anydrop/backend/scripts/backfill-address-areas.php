<?php
/**
 * Anydrop — Backfill customer_addresses.area_id
 *
 * recall.md item 9 / item 3's remaining gap. The customer_addresses.
 * area_id column has existed since migration 30, and backend/api/v1/
 * customer/addresses.php now populates it going forward on every
 * add/edit — but every address saved BEFORE that change still has
 * area_id = NULL even though it has a usable latitude/longitude. This
 * one-off script fills those in.
 *
 * SAFE TO RUN REPEATEDLY (same convention as
 * auto-update-bestseller-discount.php, not the "refuses to run twice"
 * seed scripts): by default it only touches rows where area_id IS NULL,
 * so re-running after admins add more service_areas nodes will pick up
 * addresses that couldn't resolve to anything the first time, without
 * re-touching rows that already resolved. Pass --force to re-resolve
 * every row with lat/lng regardless of its current area_id (useful
 * after a service_areas restructure, e.g. the 2026-08-21 city/village
 * merge, where an address's correct area may have changed).
 *
 * Does NOT touch rows with no latitude/longitude at all — nothing to
 * resolve from — those stay NULL, same "don't hide behind unresolved
 * data" stance as every other resolve_service_area() consumer.
 *
 * Usage:
 *   php backend/scripts/backfill-address-areas.php           # fill NULLs only
 *   php backend/scripts/backfill-address-areas.php --force    # re-resolve all
 *
 * NOTE: not run in this sandbox (no PHP CLI available here, no live DB
 * access — same standing limitation as every other session's DB work).
 * Needs to be run once on the live DB, then spot-checked: pick a handful
 * of addresses with known real-world locations and confirm the area
 * breadcrumb shown against them (customers.php) is the correct one.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/geo.php';

$force = in_array('--force', $argv, true);

$db = Database::get();

$where = $force
    ? 'latitude IS NOT NULL AND longitude IS NOT NULL'
    : 'latitude IS NOT NULL AND longitude IS NOT NULL AND area_id IS NULL';

$rows = $db->query("SELECT id, latitude, longitude, area_id FROM customer_addresses WHERE {$where}")->fetchAll();

$update = $db->prepare('UPDATE customer_addresses SET area_id = :area WHERE id = :id');

$resolved = 0;
$unresolved = 0;
$unchanged = 0;

foreach ($rows as $row) {
    $matches = resolve_service_area($db, (float) $row['latitude'], (float) $row['longitude']);
    $newAreaId = $matches[0]['id'] ?? null;

    if ($newAreaId === ($row['area_id'] !== null ? (int) $row['area_id'] : null)) {
        $unchanged++;
        continue;
    }

    $update->execute(['area' => $newAreaId, 'id' => $row['id']]);

    if ($newAreaId !== null) {
        $resolved++;
    } else {
        $unresolved++;
    }
}

echo "Backfill complete (" . ($force ? 'forced re-resolve' : 'NULL rows only') . "):\n";
echo "  Candidates checked : " . count($rows) . "\n";
echo "  Newly/changed area : {$resolved}\n";
echo "  Still unresolved   : {$unresolved} (no service_areas node covers that lat/lng yet — expected for areas admin hasn't configured coordinates for)\n";
echo "  Already correct    : {$unchanged}\n";
