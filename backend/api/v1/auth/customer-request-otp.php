<?php
/**
 * POST /api/v1/auth/customer/email/request-otp
 * Request:  { "email": "user@example.com" }
 * Response: { "message": "OTP sent" }
 *
 * NOTE: Actual SMTP sending is stubbed here (logs OTP instead of emailing)
 * until Phase 1 email delivery is wired up with real SMTP credentials.
 * This lets the full login flow be tested end-to-end immediately.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['email']);

$email = trim(strtolower($body['email']));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond_error('validation_error', 422, ['fields' => ['email']]);
}

$otpLength = (int) get_setting('otp_length', 6);
$expiryMinutes = (int) get_setting('otp_expiry_minutes', 10);

$otp = str_pad((string) random_int(0, (int) str_repeat('9', $otpLength)), $otpLength, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

$db = Database::get();
$stmt = $db->prepare(
    'INSERT INTO email_otps (email, otp_code, expires_at) VALUES (:e, :o, :x)'
);
$stmt->execute(['e' => $email, 'o' => $otp, 'x' => $expiresAt]);

// TODO Phase 1.5: send via real SMTP. For now, OTP is returned in the response
// ONLY so you can test the flow before email is configured. Remove `debug_otp`
// once SMTP is live — never ship OTP-in-response to production.
respond_ok([
    'message' => 'OTP sent',
    'debug_otp' => $otp,
]);
