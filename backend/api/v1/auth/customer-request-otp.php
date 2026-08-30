<?php
/**
 * POST /api/v1/auth/customer/email/request-otp
 * Request:  { "email": "user@example.com" }
 * Response: { "message": "OTP sent" }
 *
 * Email delivery goes through EmailOtpService (AnyDrop_Email_OTP_
 * MultiProvider_Plan.md) — 6-provider failover managed entirely from
 * the Admin Panel's Email Providers screen. If every active provider
 * fails, this returns a real `email_delivery_unavailable` error rather
 * than pretending the OTP was sent (plan §3).
 *
 * `debug_otp_enabled` (app_settings, defaults '0'/off — bugs.md #2.2)
 * still gates whether the OTP is echoed back in the response, for
 * testing the login flow without a real inbox. On a dev/staging DB
 * with no provider configured yet, delivery will fail every time —
 * that's expected; debug_otp is what lets you keep testing anyway.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/email_otp/EmailOtpService.php';

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
$cooldownSeconds = (int) get_setting('otp_request_cooldown_seconds', 60);

$db = Database::get();

// bugs.md #2.1 fix — this endpoint used to have no rate limit at all,
// so any caller could POST an email repeatedly with no cooldown/throttle.
// Harmless while debug_otp/no-SMTP made it a no-op, but a real
// email-bombing vector the moment SMTP goes live, plus unbounded
// email_otps row growth either way. Simple per-email cooldown, same
// pattern most OTP systems use — checked against the same `idx_otp_email`
// index the table already had, so this adds no new index.
if ($cooldownSeconds > 0) {
    $cooldownStmt = $db->prepare(
        'SELECT created_at FROM email_otps WHERE email = :e ORDER BY created_at DESC LIMIT 1'
    );
    $cooldownStmt->execute(['e' => $email]);
    $lastRow = $cooldownStmt->fetch();
    if ($lastRow) {
        $secondsSinceLast = time() - strtotime($lastRow['created_at']);
        if ($secondsSinceLast < $cooldownSeconds) {
            respond_error('otp_request_cooldown', 429, [
                'retry_after_seconds' => $cooldownSeconds - $secondsSinceLast,
            ]);
        }
    }
}

$otp = str_pad((string) random_int(0, (int) str_repeat('9', $otpLength)), $otpLength, '0', STR_PAD_LEFT);
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

$stmt = $db->prepare(
    'INSERT INTO email_otps (email, otp_code, expires_at) VALUES (:e, :o, :x)'
);
$stmt->execute(['e' => $email, 'o' => $otp, 'x' => $expiresAt]);

$debugOtpEnabled = get_setting('debug_otp_enabled', '0') === '1';

$deliveryResult = (new EmailOtpService($db))->send($email, $otp, 'customer_login', $expiryMinutes);

if (!$deliveryResult['success'] && !$debugOtpEnabled) {
    // plan §3 — never claim "OTP sent" when nothing actually accepted
    // it. On production (debug_otp off) a real delivery failure is a
    // real error, full stop.
    respond_error('email_delivery_unavailable', 503, [
        'message' => 'Unable to send confirmation code right now. Please try again later.',
    ]);
}

// bugs.md #2.2 fix — debug_otp used to always be returned in the live
// response, meaning anyone could log in as any email with zero
// possession-of-inbox proof. Now gated behind `debug_otp_enabled` in
// app_settings, defaulting to OFF ('0') whenever the row doesn't
// exist. Flip it to '1' only on a dev/staging DB; never set it on
// production. With it on, login stays testable even if no provider is
// configured yet / a delivery attempt above failed.
$response = ['message' => 'OTP sent'];
if ($debugOtpEnabled) {
    $response['debug_otp'] = $otp;
}
respond_ok($response);
