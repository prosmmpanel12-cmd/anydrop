<?php
/**
 * Anydrop — ONE-TIME web wrapper for backend/scripts/backfill-address-areas.php
 *
 * InfinityFree (and most shared hosts) have no SSH/PHP-CLI access, so the
 * CLI-only backfill script (backend/scripts/backfill-address-areas.php)
 * can't be run the normal way. This page does the exact same work, gated
 * behind admin login so a random visitor can't trigger it, and prints the
 * result to the browser instead of stdout.
 *
 * HOW TO USE:
 *   1. Log into the admin panel as normal (Super Admin).
 *   2. Visit this page's URL directly in the browser
 *      (e.g. https://yourdomain/admin/run-address-backfill.php).
 *   3. Read the summary. Re-run any time — same NULL-only safety as the
 *      CLI original; pass ?force=1 in the URL to re-resolve every row
 *      (only needed after restructuring service_areas coordinates).
 *
 * DELETE THIS FILE after you've confirmed the numbers look right — it's
 * a one-off migration helper, not a permanent admin feature, and there's
 * no reason to leave an unlinked bulk-DB-write page sitting on the server
 * indefinitely.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/geo.php';

$admin = admin_require_login();
admin_require_permission($admin, 'reports_view'); // Super Admin already has this (migration 29)

$db = Database::get();
$force = isset($_GET['force']) && $_GET['force'] === '1';

$where = $force
    ? 'latitude IS NOT NULL AND longitude IS NOT NULL'
    : 'latitude IS NOT NULL AND longitude IS NOT NULL AND area_id IS NULL';

$rows = $db->query("SELECT id, latitude, longitude, area_id FROM customer_addresses WHERE {$where}")->fetchAll();

$update = $db->prepare('UPDATE customer_addresses SET area_id = :area WHERE id = :id');

$resolved = 0;
$unresolved = 0;
$unchanged = 0;
$details = [];

foreach ($rows as $row) {
    $matches = resolve_service_area($db, (float) $row['latitude'], (float) $row['longitude']);
    $newAreaId = $matches[0]['id'] ?? null;
    $oldAreaId = $row['area_id'] !== null ? (int) $row['area_id'] : null;

    if ($newAreaId === $oldAreaId) {
        $unchanged++;
        continue;
    }

    $update->execute(['area' => $newAreaId, 'id' => $row['id']]);
    $details[] = [
        'address_id' => $row['id'],
        'old_area_id' => $oldAreaId,
        'new_area_id' => $newAreaId,
    ];

    if ($newAreaId !== null) {
        $resolved++;
    } else {
        $unresolved++;
    }
}

$pageTitle = 'Address Area Backfill (one-time)';
$activeNav = 'analytics';
require __DIR__ . '/_layout_head.php';
?>
<div class="card">
    <h2>Backfill complete (<?= $force ? 'forced re-resolve' : 'NULL rows only' ?>)</h2>
    <table class="table">
        <tr><td>Candidates checked</td><td><b><?= count($rows) ?></b></td></tr>
        <tr><td>Newly / changed area</td><td><b style="color:green"><?= $resolved ?></b></td></tr>
        <tr><td>Still unresolved</td><td><b style="color:#b45309"><?= $unresolved ?></b> (no service_areas node covers that lat/lng — check center_lat/center_lng/radius_km on the Osian/Jodhpur node)</td></tr>
        <tr><td>Already correct</td><td><?= $unchanged ?></td></tr>
    </table>

    <?php if ($details): ?>
    <h3 style="margin-top:20px;">Changed rows</h3>
    <table class="table">
        <thead><tr><th>Address ID</th><th>Old area_id</th><th>New area_id</th></tr></thead>
        <tbody>
        <?php foreach ($details as $d): ?>
            <tr>
                <td><?= $d['address_id'] ?></td>
                <td><?= $d['old_area_id'] ?? '<i>NULL</i>' ?></td>
                <td><?= $d['new_area_id'] ?? '<i>still NULL</i>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p style="margin-top:16px;color:#666;">
        Re-run any time — safe. Add <code>?force=1</code> to the URL to
        re-resolve every row with lat/lng regardless of current area_id.
        <b>Delete this file once you've confirmed the numbers look right.</b>
    </p>
</div>
