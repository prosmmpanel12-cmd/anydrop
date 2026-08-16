<?php
/**
 * POST /api/v1/restaurant/profile-update.php
 * Auth: Restaurant token
 * Request: any subset of { name, address, cuisine_tags, opening_time,
 *                           closing_time, working_days, description,
 *                           logo_url, cover_url }
 * Response: { "restaurant": {...full row, minus password_hash} }
 *
 * docs/restorent/19 §7 (Account tab) / §10 item 5. Partial update, same
 * dynamic-SET pattern as menu-items-update.php / categories-update.php.
 *
 * Deliberately restricted to a restaurant-safe column subset — mirrors
 * status-update.php's own restraint. NOT settable here: status
 * (admin-only approval gate), operational_status (status-update.php's
 * job), current_due/commission_percent (platform ledger), latitude/
 * longitude (needs its own map-picker flow, out of scope this pass —
 * see docs/restorent/00_Status.md for the flagged follow-up), and
 * owner_email/password (would need re-auth, not a plain profile field).
 *
 * logo_url/cover_url are plain string fields here, same pattern as H6's
 * address-photo.php + addresses.php split: logo-upload.php does the
 * actual file upload and returns a relative path, the app then sends
 * that path here as an ordinary string alongside the rest of the form
 * (so a logo change and a name/address change save in one request).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
$fields = [];
$params = ['id' => $restaurantId];

if (array_key_exists('name', $body) && $body['name'] !== null) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $fields[] = 'name = :name';
    $params['name'] = $name;
}

if (array_key_exists('address', $body)) {
    $address = trim((string) $body['address']);
    if ($address === '') {
        respond_error('validation_error', 422, ['fields' => ['address']]);
    }
    $fields[] = 'address = :address';
    $params['address'] = $address;
}

if (array_key_exists('cuisine_tags', $body)) {
    // Comma-separated per 01_Database_Schema.md — stored as-is, same as
    // seed-demo-catalog.php's rows. Empty string clears it (nullable
    // display everywhere it's read).
    $tags = trim((string) $body['cuisine_tags']);
    $fields[] = 'cuisine_tags = :cuisine_tags';
    $params['cuisine_tags'] = $tags !== '' ? $tags : null;
}

if (array_key_exists('description', $body)) {
    $description = trim((string) $body['description']);
    $fields[] = 'description = :description';
    $params['description'] = $description !== '' ? $description : null;
}

// opening_time / closing_time — TIME columns, compared as plain "H:i:s"
// strings elsewhere in the backend (lib/restaurant_status.php,
// lib/orders.php's accepting-orders guard) — validate the shape here so
// a malformed value can't silently break every one of those string
// comparisons. Accepts "HH:MM" or "HH:MM:SS" and normalizes to "HH:MM:SS".
if (array_key_exists('opening_time', $body) && $body['opening_time'] !== null) {
    $opening = normalize_time_field((string) $body['opening_time']);
    if ($opening === null) {
        respond_error('validation_error', 422, ['fields' => ['opening_time']]);
    }
    $fields[] = 'opening_time = :opening_time';
    $params['opening_time'] = $opening;
}

if (array_key_exists('closing_time', $body) && $body['closing_time'] !== null) {
    $closing = normalize_time_field((string) $body['closing_time']);
    if ($closing === null) {
        respond_error('validation_error', 422, ['fields' => ['closing_time']]);
    }
    $fields[] = 'closing_time = :closing_time';
    $params['closing_time'] = $closing;
}

// working_days — comma-separated day numbers, 1 (Monday) .. 7 (Sunday)
// per PHP's date('N') / lib/restaurant_status.php's $currentDow. Dedupe
// and re-sort so what's stored is always canonical, not whatever order
// the client's chip UI happened to send.
if (array_key_exists('working_days', $body) && $body['working_days'] !== null) {
    $raw = explode(',', (string) $body['working_days']);
    $days = [];
    foreach ($raw as $d) {
        $d = trim($d);
        if ($d === '' || !ctype_digit($d) || (int) $d < 1 || (int) $d > 7) {
            respond_error('validation_error', 422, ['fields' => ['working_days']]);
        }
        $days[(int) $d] = true;
    }
    if (empty($days)) {
        respond_error('validation_error', 422, ['fields' => ['working_days']]);
    }
    ksort($days);
    $fields[] = 'working_days = :working_days';
    $params['working_days'] = implode(',', array_keys($days));
}

if (array_key_exists('logo_url', $body)) {
    $fields[] = 'logo_url = :logo_url';
    $params['logo_url'] = $body['logo_url'] !== '' ? $body['logo_url'] : null;
}

if (array_key_exists('cover_url', $body)) {
    $fields[] = 'cover_url = :cover_url';
    $params['cover_url'] = $body['cover_url'] !== '' ? $body['cover_url'] : null;
}

$db = Database::get();

if (!empty($fields)) {
    $sql = 'UPDATE restaurants SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $upd = $db->prepare($sql);
    $upd->execute($params);
}

$fetch = $db->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $restaurantId]);
$row = $fetch->fetch();

if (!$row) {
    respond_error('not_found', 404);
}

unset($row['password_hash']);

respond_ok(['restaurant' => $row]);

/** "HH:MM" or "HH:MM:SS" (24h) -> normalized "HH:MM:SS", or null if invalid. */
function normalize_time_field(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $value, $m)) {
        return null;
    }
    $seconds = $m[4] ?? '00';
    return $m[1] . ':' . $m[2] . ':' . $seconds;
}
