<?php
/**
 * POST /api/v1/rider/payout-bank-details-request-otp.php
 * Auth: Rider token
 * Request: {} (no body needed — OTP goes to the rider's own registered
 *           email, same one their login OTPs already go to)
 * Response: { "message": "OTP sent" } (+ "debug_otp" when
 *           debug_otp_enabled, same convention as rider-request-otp.php)
 *
 * App-owner ask, 2026-09-09: confirm-before-save on bank/UPI changes,
 * rider-style (OTP login) — request this OTP first, then include it as
 * "otp" in the actual payout-bank-details-save.php call. Reuses the
 * exact same email_otps table / cooldown / debug_otp settings /
 * EmailOtpService as rider-request-otp.php (the login flow), just keyed
 * off the already-authenticated rider's own email instead of one typed
 * into a login form, and logged under a distinct purpose
 * ('rider_payout_confirm') so email_otp_logs can tell the two apart.
 * This is intentionally a separate table row from any login OTP the
 * rider might have in flight — email_otps has no concept of "purpose"
 * on the row itself (only in the log), so a rider mid-login-OTP and
 * mid-payout-confirm at the same time would share the cooldown window,
 * which is an acceptable tradeoff for reusing the existing
 * infrastructure rather than adding a purpose column to email_otps.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/email_otp/EmailOtpService.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$db = Database::get();

$riderStmt = $db->prepare('SELECT email FROM riders WHERE id = :id LIMIT 1');
$riderStmt->execute(['id' => $riderId]);
$email = $riderStmt->fetchColumn();

if ($email === false || $email === null || $email === '') {
    // Shouldn't happen for an authenticated rider (email is required at
    // signup) — fail closed rather than silently sending nowhere.
    respond_error('rider_email_missing', 500);
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

$deliveryResult = (new EmailOtpService($db))->send($email, $otp, 'rider_payout_confirm', $expiryMinutes);

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
