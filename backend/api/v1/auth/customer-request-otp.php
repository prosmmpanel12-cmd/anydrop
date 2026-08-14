<?php
/**
 * POST /api/v1/auth/customer/email/request-otp
 * Request:  { "email": "user@example.com" }
 * Response: { "message": "OTP sent" }
 *
 * NOTE: Actual SMTP sending is stubbed here (logs OTP instead of emailing)
 * until Phase 1 email delivery is wired up with real SMTP credentials.
 * Until then, the OTP itself is only visible in the response when the
 * `debug_otp_enabled` app_settings row is explicitly set to '1' (defaults
 * to off — see bugs.md #2.2) — set it on a dev/staging DB to keep testing
 * the login flow end-to-end without SMTP configured.
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

// bugs.md #2.2 fix — debug_otp used to always be returned in the live
// response, meaning anyone could log in as any email with zero
// possession-of-inbox proof. Real SMTP still isn't wired up (see the
// file header TODO), so this can't just be deleted outright without
// breaking the ability to test login at all — instead it's now gated
// behind `debug_otp_enabled` in app_settings, defaulting to OFF ('0')
// whenever the row doesn't exist. Flip it to '1' only on a dev/staging
// DB; never set it on production.
$response = ['message' => 'OTP sent'];
if (get_setting('debug_otp_enabled', '0') === '1') {
    $response['debug_otp'] = $otp;
}
respond_ok($response);
