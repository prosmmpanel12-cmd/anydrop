<?php
/**
 * POST /api/v1/auth/rider/signup
 * Request:  { "name", "email", "mobile",
 *              "service_area_id" (optional — dropdown selection),
 *              "latitude" (optional), "longitude" (optional),
 *              "vehicle_type" (optional), "vehicle_number" (optional) }
 * Response: { "rider": {...}, "status": "pending",
 *              "area_resolved": bool, "area": {...}|null,
 *              "token": "..." }
 *
 * Step 3 of Rider Signup. Requires a just-verified OTP for `email`
 * (same is_used=1-within-expiry check restaurant-signup.php uses) —
 * proof Step 2 (rider-verify-otp.php) actually happened for this email.
 *
 * Service area resolution (app owner: "live location bhi fetch kare
 * and drop-down service area ka" — both, not either/or):
 *   - If `latitude`/`longitude` are given, resolve_service_area()
 *     (lib/geo.php — same helper restaurant-signup.php already uses)
 *     runs first and its nearest match is used as the default.
 *   - If the rider then also picked a specific `service_area_id` from
 *     the dropdown (e.g. GPS matched the wrong node, or they want a
 *     neighbouring area instead), that explicit choice WINS over the
 *     GPS auto-match — the dropdown is presented as an editable
 *     confirmation of the GPS guess, not just a fallback for when GPS
 *     is unavailable.
 *   - If neither resolves to anything, `service_area_id` stays NULL
 *     and `area_resolved: false` — same "not an error, admin sorts it
 *     at approval" behaviour as the restaurant path for an
 *     out-of-coverage signup.
 *
 * New rider row is created with status='pending' (riders.status,
 * migration 69) — same admin-approval gate restaurants.status uses.
 * Unlike restaurant-signup.php, a token IS issued immediately here
 * (rider-verify-otp.php already established riders are
 * email-OTP-only/passwordless, and issues tokens itself on login) so
 * the newly-signed-up rider can be taken straight to an
 * "application submitted, pending approval" screen inside the
 * authenticated app shell rather than back to a public landing page.
 * require_auth('rider') (lib/auth.php, this same batch) still blocks
 * every actual order/earnings endpoint until status flips to
 * 'approved' — this token only unlocks the pending-status screens.
 *
 * Rate-limited per-IP (migration 70 / lib/rate_limit.php) — the
 * `otp_request_cooldown` on rider-request-otp.php only throttles
 * repeat requests for the *same* email; nothing previously stopped
 * one IP from cycling through many different emails to spam this
 * endpoint (each a real OTP-email send-cost, not just a fake-account
 * concern). Default 5 signup attempts per IP per 60 minutes,
 * configurable via app_settings `signup_rate_limit_max_attempts` /
 * `signup_rate_limit_window_minutes`. Flagged in doc 79 as a known
 * gap shared with restaurant-signup.php — this endpoint is fixed
 * first per app-owner request; restaurant-signup.php still has the
 * same gap, unchanged by this session.
 *
 * REQUIRES migration 71: `riders.username`/`password_hash` were made
 * nullable there (confirmed no restaurant-side code reads them
 * expecting non-null — see that migration's own comment for the
 * audit trail) so this endpoint could stop generating random
 * placeholder values for two columns a platform rider never uses.
 * Deploying this file without first running migration 71 will fail
 * every signup with a NOT NULL constraint violation.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/geo.php';
require_once __DIR__ . '/../../../lib/rate_limit.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

// Checked before any other work — a request that's already over the
// per-IP threshold shouldn't even get as far as body parsing/logging.
rate_limit_check_signup('rider_signup');

$body = get_json_body();
require_fields($body, ['name', 'email', 'mobile']);

$name = trim($body['name']);
$email = trim(strtolower($body['email']));
$mobile = trim($body['mobile']);
$vehicleType = isset($body['vehicle_type']) ? trim($body['vehicle_type']) : null;
$vehicleNumber = isset($body['vehicle_number']) ? trim($body['vehicle_number']) : null;

// Explicit dropdown choice, if any — validated against service_areas below.
$explicitAreaId = isset($body['service_area_id']) && is_numeric($body['service_area_id'])
    ? (int) $body['service_area_id']
    : null;

// Optional GPS pair — same "partial/garbage pair = treated as not given"
// tolerance as restaurant-signup.php, not a hard error for a non-critical field.
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
    rate_limit_log_signup('rider_signup', $email, false);
    respond_error('validation_error', 422, ['fields' => ['email']]);
}
if (!preg_match('/^[0-9]{10}$/', $mobile)) {
    rate_limit_log_signup('rider_signup', $email, false);
    respond_error('validation_error', 422, ['fields' => ['mobile'], 'reason' => 'expected_10_digits']);
}
if (strlen($name) < 2) {
    rate_limit_log_signup('rider_signup', $email, false);
    respond_error('validation_error', 422, ['fields' => ['name']]);
}

$db = Database::get();

$existing = $db->prepare('SELECT id FROM riders WHERE email = :e AND deleted_at IS NULL LIMIT 1');
$existing->execute(['e' => $email]);
if ($existing->fetch()) {
    rate_limit_log_signup('rider_signup', $email, false);
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
    rate_limit_log_signup('rider_signup', $email, false);
    respond_error('email_not_verified', 403);
}

// Resolve area BEFORE insert — GPS guess first, explicit dropdown choice overrides it.
$resolvedArea = null;
if ($latitude !== null && $longitude !== null) {
    $matches = resolve_service_area($db, $latitude, $longitude);
    if (!empty($matches)) {
        $resolvedArea = $matches[0];
    }
}
$areaId = $resolvedArea['id'] ?? null;

if ($explicitAreaId !== null) {
    $areaStmt = $db->prepare('SELECT id, parent_id, name, level FROM service_areas WHERE id = :id AND is_active = 1 LIMIT 1');
    $areaStmt->execute(['id' => $explicitAreaId]);
    $explicitArea = $areaStmt->fetch();
    if ($explicitArea) {
        $areaId = (int) $explicitArea['id'];
        $resolvedArea = $explicitArea; // dropdown choice takes over the response's "area" too
    }
}

$stmt = $db->prepare(
    'INSERT INTO riders (restaurant_id, name, username, password_hash, email, mobile, vehicle_type, vehicle_number, service_area_id, latitude, longitude, status, is_online, is_active)
     VALUES (NULL, :name, NULL, NULL, :email, :mobile, :vehicle_type, :vehicle_number, :area_id, :latitude, :longitude, \'pending\', 0, 1)'
);
// username/password_hash: legacy columns from the restaurant-created-rider
// path — made nullable by migration 71 once it was confirmed nothing
// restaurant-side still reads them expecting a non-null value. A
// platform rider authenticates by email-OTP only and never has a
// username/password at all, so these are simply left NULL now instead
// of the random-placeholder workaround migration 69 originally needed.

$stmt->execute([
    'name' => $name,
    'email' => $email,
    'mobile' => $mobile,
    'vehicle_type' => $vehicleType,
    'vehicle_number' => $vehicleNumber,
    'area_id' => $areaId,
    'latitude' => $latitude,
    'longitude' => $longitude,
]);
$riderId = (int) $db->lastInsertId();

$stmt = $db->prepare('SELECT * FROM riders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $riderId]);
$rider = $stmt->fetch();
unset($rider['password_hash']);

write_audit_log('rider', $riderId, 'signup_submitted', [
    'email' => $email,
    'area_resolved' => $areaId !== null,
    'area_id' => $areaId,
    'area_source' => $explicitAreaId !== null ? 'dropdown' : ($resolvedArea !== null ? 'gps' : 'none'),
]);

$token = create_auth_token('rider', $riderId);

rate_limit_log_signup('rider_signup', $email, true);

respond_ok([
    'rider' => $rider,
    'status' => 'pending',
    'area_resolved' => $areaId !== null,
    'area' => $resolvedArea !== null ? [
        'id' => $resolvedArea['id'],
        'name' => $resolvedArea['name'],
        'level' => $resolvedArea['level'],
    ] : null,
    'token' => $token,
]);
