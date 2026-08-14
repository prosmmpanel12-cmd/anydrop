<?php
/**
 * POST /api/v1/auth/restaurant/signup
 * Request:  { "name", "owner_name", "owner_mobile", "owner_email",
 *              "password", "address" (optional) }
 * Response: { "restaurant": {...}, "status": "pending" }
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
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/audit.php';

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

$stmt = $db->prepare(
    'INSERT INTO restaurants (name, owner_name, owner_mobile, owner_email, password_hash, address, status, operational_status)
     VALUES (:name, :owner_name, :owner_mobile, :owner_email, :password_hash, :address, \'pending\', \'closed\')'
);
$stmt->execute([
    'name' => $name,
    'owner_name' => $ownerName,
    'owner_mobile' => $ownerMobile,
    'owner_email' => $email,
    'password_hash' => $passwordHash,
    'address' => $address,
]);
$restaurantId = (int) $db->lastInsertId();

$stmt = $db->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();
unset($restaurant['password_hash']);

write_audit_log('restaurant', $restaurantId, 'signup_submitted', ['email' => $email]);

respond_ok(['restaurant' => $restaurant, 'status' => 'pending']);
