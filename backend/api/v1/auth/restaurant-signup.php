<?php
/**
 * POST /api/v1/auth/restaurant/signup
 * Request:  { "name", "owner_name", "owner_mobile", "owner_email",
 *              "password", "address" (optional),
 *              "latitude" (optional), "longitude" (optional) }
 * Response: { "restaurant": {...}, "status": "pending",
 *              "area_resolved": bool, "area": {...}|null }
 *
 * Step 3 of Restaurant Partner Signup. Requires a just-verified OTP for
 * owner_email (checked here, not just trusted from the client) — a used,
 * non-expired email_otps row for this email within the last
 * `otp_expiry_minutes` is treated as proof of Step 2 having happened.
 * No token is issued here: the new row is `status='pending'`
 * (restaurants.status default, doc 19 §3 Restaurant Approval), so the
 * app sends the owner to the "application submitted" screen, not the
 * Dashboard — restaurant-login.php already rejects pending accounts
 * with `pending_approval`, so this matches existing backend behaviour,
 * no new gate needed.
 *
 * 2026-08-28 — Service-area gap fix (app owner flagged: "new restaurant
 * signup karega to uska service area kaise decide hoga, uske paas koi
 * field nahi hai"). `restaurants.latitude`/`longitude` columns already
 * existed (01_schema.sql) but nothing ever wrote to them at signup, and
 * `area_id` (migration 30) was admin-assigned-only via a manual dropdown
 * — no auto-resolution was ever wired to onboarding, even though
 * `resolve_service_area()` (lib/geo.php) already exists and is already
 * used by customer addresses, banners, and restaurant listing.
 *
 * Fix: `latitude`/`longitude` are now accepted (optional — Android side
 * for this call isn't built yet, see PENDING.md/today.md; this is the
 * backend contract that side will call). If both are given and valid,
 * they're stored, and `resolve_service_area()` is run immediately:
 *   - Match found -> nearest area's id is auto-set on `area_id` at
 *     signup time. Restaurant still goes into the normal 'pending'
 *     approval queue — this does NOT auto-approve, it only saves the
 *     admin a manual area-lookup step during approval.
 *   - No match (village/city not in service_areas yet, or no
 *     coordinates given at all) -> `area_id` stays NULL, exactly like
 *     before. The response's `area_resolved: false` tells the app to
 *     show a "we don't cover your area yet, we'll notify you" message
 *     instead of silently succeeding. This case is expected and normal
 *     for a new launch city, not an error — the signup itself still
 *     succeeds so a legitimate new-area application isn't blocked.
 *   - No lat/lng sent at all (old client, or user skipped location) ->
 *     same as no-match: area_id stays NULL, admin resolves manually at
 *     approval time exactly as before this fix. Nothing breaks for a
 *     caller that doesn't send the new fields.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/geo.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['name', 'owner_name', 'owner_mobile', 'owner_email', 'password']);

$name = trim($body['name']);
$ownerName = trim($body['owner_name']);
$ownerMobile = trim($body['owner_mobile']);
$email = trim(strtolower($body['owner_email']));
$password = (string) $body['password'];
$address = isset($body['address']) ? trim($body['address']) : null;

// Optional — see 2026-08-28 header note. Only trusted if both are
// present and numeric-looking; a partial/garbage pair is treated the
// same as "not given" rather than erroring the whole signup out for a
// non-critical field.
$latitude = null;
$longitude = null;
if (isset($body['latitude']) && isset($body['longitude'])
    && is_numeric($body['latitude']) && is_numeric($body['longitude'])) {
    $latCandidate = (float) $body['latitude'];
    $lngCandidate = (float) $body['longitude'];
    if ($latCandidate >= -90 && $latCandidate <= 90 && $lngCandidate >= -180 && $lngCandidate <= 180) {
        $latitude = $latCandidate;
        $longitude = $lngCandidate;
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond_error('validation_error', 422, ['fields' => ['owner_email']]);
}
if (strlen($password) < 6) {
    respond_error('validation_error', 422, ['fields' => ['password'], 'reason' => 'min_length_6']);
}
if (!preg_match('/^[0-9]{10}$/', $ownerMobile)) {
    respond_error('validation_error', 422, ['fields' => ['owner_mobile'], 'reason' => 'expected_10_digits']);
}

$db = Database::get();

$existing = $db->prepare('SELECT id FROM restaurants WHERE owner_email = :e LIMIT 1');
$existing->execute(['e' => $email]);
if ($existing->fetch()) {
    respond_error('email_already_registered', 409);
}

$expiryMinutes = (int) get_setting('otp_expiry_minutes', 10);
$otpStmt = $db->prepare(
    'SELECT created_at FROM email_otps
     WHERE email = :e AND is_used = 1 AND created_at >= :since
     ORDER BY id DESC LIMIT 1'
);
$otpStmt->execute([
    'e' => $email,
    'since' => date('Y-m-d H:i:s', strtotime("-{$expiryMinutes} minutes")),
]);
if (!$otpStmt->fetch()) {
    respond_error('email_not_verified', 403);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Resolve area BEFORE the insert so area_id can be set in the same
// single write — same "no second UPDATE needed" reasoning every other
// insert-then-resolve path in this project follows.
$resolvedArea = null;
if ($latitude !== null && $longitude !== null) {
    $matches = resolve_service_area($db, $latitude, $longitude);
    if (!empty($matches)) {
        $resolvedArea = $matches[0]; // nearest, per resolve_service_area()'s own sort
    }
}
$areaId = $resolvedArea['id'] ?? null;

$stmt = $db->prepare(
    'INSERT INTO restaurants (name, owner_name, owner_mobile, owner_email, password_hash, address, latitude, longitude, area_id, status, operational_status)
     VALUES (:name, :owner_name, :owner_mobile, :owner_email, :password_hash, :address, :latitude, :longitude, :area_id, \'pending\', \'closed\')'
);
$stmt->execute([
    'name' => $name,
    'owner_name' => $ownerName,
    'owner_mobile' => $ownerMobile,
    'owner_email' => $email,
    'password_hash' => $passwordHash,
    'address' => $address,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'area_id' => $areaId,
]);
$restaurantId = (int) $db->lastInsertId();

$stmt = $db->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();
unset($restaurant['password_hash']);

write_audit_log('restaurant', $restaurantId, 'signup_submitted', [
    'email' => $email,
    'area_resolved' => $areaId !== null,
    'area_id' => $areaId,
]);

respond_ok([
    'restaurant' => $restaurant,
    'status' => 'pending',
    'area_resolved' => $areaId !== null,
    'area' => $resolvedArea !== null ? [
        'id' => $resolvedArea['id'],
        'name' => $resolvedArea['name'],
        'level' => $resolvedArea['level'],
    ] : null,
]);
