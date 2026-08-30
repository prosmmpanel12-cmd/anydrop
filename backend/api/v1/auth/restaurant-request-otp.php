<?php
/**
 * POST /api/v1/auth/restaurant/request-otp
 * Request:  { "email": "owner@restaurant.com" }
 * Response: { "message": "OTP sent" }
 *
 * Step 1 of Restaurant Partner Signup. Verifies the owner actually
 * controls the email before a `restaurants` row (status='pending') is
 * ever created — mirrors customer-request-otp.php's shape/cooldown/
 * debug_otp pattern exactly, just scoped to restaurant signup instead
 * of customer login. Reuses the same `email_otps` table (keyed only by
 * email, purpose-agnostic) rather than a parallel restaurant_otps table.
 *
 * NOTE: same as customer-request-otp.php — delivery now goes through
 * EmailOtpService (docs/19 §7, AnyDrop_Email_OTP_MultiProvider_Plan.md),
 * 6-provider failover managed from the Admin Panel. OTP is only echoed
 * back in the response when `debug_otp_enabled` app_setting is '1'
 * (dev/staging) — otherwise a real delivery failure is a real error.
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

$db = Database::get();

// Block sending a signup OTP to an email that's already a restaurant
// account — send them to Login instead of down a dead-end signup path.
$existing = $db->prepare('SELECT id FROM restaurants WHERE owner_email = :e LIMIT 1');
$existing->execute(['e' => $email]);
if ($existing->fetch()) {
    respond_error('email_already_registered', 409);
}

$otpLength = (int) get_setting('otp_length', 6);
$expiryMinutes = (int) get_setting('otp_expiry_minutes', 10);
$cooldownSeconds = (int) get_setting('otp_request_cooldown_seconds', 60);

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

$deliveryResult = (new EmailOtpService($db))->send($email, $otp, 'restaurant_signup', $expiryMinutes);

if (!$deliveryResult['success'] && !$debugOtpEnabled) {
    respond_error('email_delivery_unavailable', 503, [
        'message' => 'Unable to send confirmation code right now. Please try again later.',
    ]);
}

$response = ['message' => 'OTP sent'];
if ($debugOtpEnabled) {
    $response['debug_otp'] = $otp;
}
respond_ok($response);
