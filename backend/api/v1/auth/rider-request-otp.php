<?php
/**
 * POST /api/v1/auth/rider/request-otp
 * Request:  { "email": "rider@example.com" }
 * Response: { "message": "OTP sent" }
 *
 * Step 1 of Rider Signup (mirrors restaurant-request-otp.php exactly —
 * same email_otps table, same EmailOtpService, same cooldown/debug_otp
 * settings). Also doubles as the OTP step for rider LOGIN (riders log
 * in passwordless, email-OTP only, same as customers — see
 * rider-verify-otp.php / rider-login.php in this same batch), so this
 * does NOT block on "email already registered" the way the restaurant
 * signup version does: an existing rider requesting a login OTP must
 * be allowed through. rider-signup.php is what blocks a duplicate
 * email at actual account-creation time.
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

$deliveryResult = (new EmailOtpService($db))->send($email, $otp, 'rider_auth', $expiryMinutes);

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
