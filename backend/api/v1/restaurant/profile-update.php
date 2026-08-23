<?php
/**
 * POST /api/v1/restaurant/profile-update.php
 * Auth: Restaurant token
 * Request: any subset of { name, address, cuisine_tags, opening_time,
 *                           closing_time, working_days, description,
 *                           logo_url, cover_url, latitude, longitude }
 * Response: { "restaurant": {...full row, minus password_hash} }
 *
 * docs/restorent/19 §7 (Account tab) / §10 item 5. Partial update, same
 * dynamic-SET pattern as menu-items-update.php / categories-update.php.
 *
 * Deliberately restricted to a restaurant-safe column subset — mirrors
 * status-update.php's own restraint. NOT settable here: status
 * (admin-only approval gate), operational_status (status-update.php's
 * job), current_due/commission_percent (platform ledger), and
 * owner_email/password (would need re-auth, not a plain profile field).
 *
 * latitude/longitude were excluded here (see prior revision of this kdoc)
 * pending a map-picker flow — added 2026-08-16 per app owner's real-device
 * feedback (docs/restorent/00_Status.md), reusing the Customer app's H6
 * pin-drop pattern client-side. Both optional/nullable, set together (a
 * lone lat with no lng, or vice versa, is rejected as malformed rather
 * than silently half-applied).
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
require_once __DIR__ . '/../../../lib/delivery_pricing.php';

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

// latitude/longitude — set together via the map-picker flow (see kdoc
// above). Both keys must be present and non-null if either is — a lone
// coordinate is ambiguous (was the other one meant to stay unchanged, or
// meant to be cleared?) so it's rejected outright rather than guessed at,
// same "don't silently half-apply" reasoning as logo-upload.php's split
// from this endpoint. DECIMAL(10,8)/DECIMAL(11,8) per 01_Database_Schema.md
// — a plain numeric-range sanity check (-90..90 / -180..180) here catches
// an obviously malformed payload before it hits the DB; MySQL's own
// column precision handles the rest.
$hasLat = array_key_exists('latitude', $body);
$hasLng = array_key_exists('longitude', $body);
if ($hasLat !== $hasLng) {
    respond_error('validation_error', 422, ['fields' => ['latitude', 'longitude']]);
}
if ($hasLat && $hasLng) {
    $lat = $body['latitude'];
    $lng = $body['longitude'];
    if ($lat !== null && $lng !== null) {
        if (!is_numeric($lat) || !is_numeric($lng) ||
            (float) $lat < -90 || (float) $lat > 90 ||
            (float) $lng < -180 || (float) $lng > 180) {
            respond_error('validation_error', 422, ['fields' => ['latitude', 'longitude']]);
        }
        $fields[] = 'latitude = :latitude';
        $fields[] = 'longitude = :longitude';
        $params['latitude'] = (float) $lat;
        $params['longitude'] = (float) $lng;
    } else {
        // Both explicitly null — clears a previously-set location.
        $fields[] = 'latitude = :latitude';
        $fields[] = 'longitude = :longitude';
        $params['latitude'] = null;
        $params['longitude'] = null;
    }
}

// min_order_amount — recall.md Phase B item 13 / migration 36. The
// restaurant sets its own value (this column already existed and
// price_cart() already reads it for the below_min_order_amount check —
// nothing changes there), but it can never be saved below the
// admin-set floor for whichever service area this restaurant is
// assigned to (restaurants.area_id, set by admin — recall.md item 2).
// "Floor, restaurant can go higher" per app owner's explicit answer —
// not "admin's number always wins" (that would make this field
// pointless for the restaurant to touch at all). A restaurant with no
// area_id yet (not assigned by admin) is floored by the platform-wide
// default only.
if (array_key_exists('min_order_amount', $body) && $body['min_order_amount'] !== null) {
    if (!is_numeric($body['min_order_amount']) || (float) $body['min_order_amount'] < 0) {
        respond_error('validation_error', 422, ['fields' => ['min_order_amount']]);
    }
    $requestedMinOrder = round((float) $body['min_order_amount'], 2);

    $dbForFloorCheck = Database::get();
    $areaStmt = $dbForFloorCheck->prepare('SELECT area_id FROM restaurants WHERE id = :id LIMIT 1');
    $areaStmt->execute(['id' => $restaurantId]);
    $currentAreaId = $areaStmt->fetchColumn();
    $currentAreaId = $currentAreaId !== false && $currentAreaId !== null ? (int) $currentAreaId : null;

    $floor = get_min_order_floor_for_area_id($dbForFloorCheck, $currentAreaId);
    if ($requestedMinOrder < $floor) {
        respond_error('min_order_below_area_floor', 422, [
            'fields' => ['min_order_amount'],
            'area_floor' => $floor,
        ]);
    }

    $fields[] = 'min_order_amount = :min_order_amount';
    $params['min_order_amount'] = $requestedMinOrder;
}

$db = Database::get();

// If logo_url is being changed, capture the previous value first so the
// old file on disk can be deleted after the UPDATE succeeds — otherwise
// every re-upload (Edit Profile > pick new logo > Save) leaves the prior
// file orphaned in uploads/restaurant_logos/ forever. Fetched before the
// UPDATE runs since afterward the old value is gone from the row.
$oldLogoUrl = null;
if (array_key_exists('logo_url', $body)) {
    $prevStmt = $db->prepare('SELECT logo_url FROM restaurants WHERE id = :id LIMIT 1');
    $prevStmt->execute(['id' => $restaurantId]);
    $oldLogoUrl = $prevStmt->fetchColumn() ?: null;
}

if (!empty($fields)) {
    $sql = 'UPDATE restaurants SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $upd = $db->prepare($sql);
    $upd->execute($params);
}

// Delete the old logo file now that the new logo_url (or null, if
// cleared) is committed. Guarded so this only ever unlinks a file inside
// uploads/restaurant_logos/ — never trusts the DB value as a raw path
// without confirming it resolves inside that directory first.
if ($oldLogoUrl !== null && $oldLogoUrl !== ($params['logo_url'] ?? null)) {
    $uploadsDir = realpath(__DIR__ . '/../../../uploads/restaurant_logos');
    $oldPath = realpath(__DIR__ . '/../../../' . $oldLogoUrl);
    if ($uploadsDir && $oldPath && strpos($oldPath, $uploadsDir) === 0) {
        @unlink($oldPath);
    }
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
