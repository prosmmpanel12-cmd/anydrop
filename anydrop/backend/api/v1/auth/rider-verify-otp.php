<?php
/**
 * POST /api/v1/auth/rider/verify-otp
 * Request:  { "email": "...", "otp": "123456" }
 * Response (new rider, no account yet):
 *   { "verified": true, "email": "...", "account_exists": false }
 * Response (existing rider — this IS their login):
 *   { "verified": true, "email": "...", "account_exists": true,
 *     "rider": {...}, "token": "...", "status": "approved" }
 *
 * Mirrors restaurant-verify-otp.php's OTP-checking logic exactly, but
 * does double duty as both Step 2 of signup AND the whole of login,
 * since riders (like customers) are email-OTP-only — no password.
 * If the email already belongs to a rider, this verify call itself
 * issues the auth token (same as customer-verify-otp.php's pattern) so
 * the app doesn't need a separate rider-login.php round trip. If it's
 * a brand-new email, no token yet — the app still needs to collect
 * name/mobile/service_area on the signup form before there's a row to
 * issue a token for (rider-signup.php does that, re-checking this same
 * is_used=1 OTP row exactly as restaurant-signup.php does).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/audit.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['email', 'otp']);

$email = trim(strtolower($body['email']));
$otp = trim($body['otp']);
$maxAttempts = (int) get_setting('otp_max_attempts', 3);

$db = Database::get();

$stmt = $db->prepare(
    'SELECT * FROM email_otps WHERE email = :e AND is_used = 0 ORDER BY id DESC LIMIT 1'
);
$stmt->execute(['e' => $email]);
$record = $stmt->fetch();

if (!$record) {
    respond_error('otp_not_found', 400);
}

if (strtotime($record['expires_at']) < time()) {
    respond_error('otp_expired', 400);
}

if ((int) $record['attempts'] >= $maxAttempts) {
    respond_error('otp_max_attempts_exceeded', 400);
}

if ($record['otp_code'] !== $otp) {
    $upd = $db->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id');
    $upd->execute(['id' => $record['id']]);
    respond_error('invalid_otp', 401, ['attempts_remaining' => max(0, $maxAttempts - (int) $record['attempts'] - 1)]);
}

$upd = $db->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id');
$upd->execute(['id' => $record['id']]);

// Is this email already a rider? If so, this verify call is their
// login — issue the token right here rather than making the app do a
// separate call, same reasoning as customer-verify-otp.php.
$riderStmt = $db->prepare('SELECT * FROM riders WHERE email = :e AND deleted_at IS NULL LIMIT 1');
$riderStmt->execute(['e' => $email]);
$rider = $riderStmt->fetch();

if (!$rider) {
    respond_ok(['verified' => true, 'email' => $email, 'account_exists' => false]);
}

if ($rider['status'] === 'suspended' || $rider['status'] === 'rejected') {
    write_audit_log('rider', (int) $rider['id'], 'login_blocked', ['status' => $rider['status']]);
    respond_error('account_suspended', 403, ['reason' => $rider['rejection_reason'] ?? null, 'status' => $rider['status']]);
}

$token = create_auth_token('rider', (int) $rider['id']);
write_audit_log('rider', (int) $rider['id'], 'login_success');

unset($rider['password_hash']);

respond_ok([
    'verified' => true,
    'email' => $email,
    'account_exists' => true,
    'rider' => $rider,
    'token' => $token,
    'status' => $rider['status'],
]);
