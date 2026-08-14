<?php
/**
 * POST /api/v1/auth/restaurant/verify-otp
 * Request:  { "email": "...", "otp": "123456" }
 * Response: { "verified": true, "email": "..." }
 *
 * Step 2 of Restaurant Partner Signup. Deliberately does NOT create any
 * row here (unlike customer-verify-otp.php, which creates the customer
 * on first OTP success) — a restaurant signup needs name/owner/mobile/
 * password collected on the form before there's enough to insert, so
 * account creation happens in restaurant-signup.php instead, which
 * re-checks this same `is_used=1` OTP row so the signup endpoint can't
 * be called with a made-up email that was never actually verified.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';

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

respond_ok(['verified' => true, 'email' => $email]);
