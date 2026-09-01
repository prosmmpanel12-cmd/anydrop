<?php
/**
 * QrPay — POST /auth/request_otp.php
 * Body: { "email": "dev@example.com" }
 *
 * Resends the 2FA code for a login already in progress. Only usable
 * after auth/login.php has set $_SESSION['pending_2fa_email'] for this
 * exact email (i.e. password already verified) — this is a "resend",
 * not a way to trigger a 2FA code out of nowhere.
 *
 * Signup no longer uses OTP at all (see auth/signup.php — email+password
 * instead). OTP is exclusively the 2FA second login step now.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/session.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$email = strtolower(trim($input['email'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email address is required.', 422);
}

qrpay_session_start();
if (empty($_SESSION['pending_2fa_email']) || $_SESSION['pending_2fa_email'] !== $email) {
    fail('Please log in with your password first.', 401);
}

// ---- Rate limit: max N resends per email per hour ----
$maxPerHour = (int) qrpay_env('OTP_MAX_REQUESTS_PER_HOUR', '5');

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS cnt FROM otp_codes WHERE email = ? AND purpose = "2fa_login" AND created_at > (NOW() - INTERVAL 1 HOUR)'
);
$stmt->execute([$email]);
$recentCount = (int) $stmt->fetch()['cnt'];

if ($recentCount >= $maxPerHour) {
    fail('Too many code requests. Please try again later.', 429);
}

// ---- Confirm the account still exists, is active, and still has 2FA on ----
$stmt = $pdo->prepare('SELECT id, status, two_fa_enabled FROM developers WHERE email = ?');
$stmt->execute([$email]);
$developer = $stmt->fetch();

if (!$developer) fail('Account not found.', 404);
if ($developer['status'] === 'suspended') fail('This account is suspended. Contact support.', 403);
if (!(bool) $developer['two_fa_enabled']) fail('2FA is not enabled for this account.', 400);

// ---- Generate, hash, store ----
$otpLength   = (int) qrpay_env('OTP_LENGTH', '6');
$expiryMins  = (int) qrpay_env('OTP_EXPIRY_MINUTES', '5');

$otp     = generateOtp($otpLength);
$otpHash = hashOtp($otp);
$expiresAt = date('Y-m-d H:i:s', time() + $expiryMins * 60);

// Invalidate any still-active codes for this email first, so only the
// newest code can ever be consumed.
$pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE email = ? AND purpose = "2fa_login" AND consumed = 0')
    ->execute([$email]);

$stmt = $pdo->prepare(
    'INSERT INTO otp_codes (email, otp_hash, purpose, expires_at, attempts, consumed)
     VALUES (?, ?, "2fa_login", ?, 0, 0)'
);
$stmt->execute([$email, $otpHash, $expiresAt]);

// ---- Send email ----
$sent = send_otp_email($email, $otp, $expiryMins);

if (!$sent) {
    fail('Could not send the verification email. Please try again shortly.', 502);
}

success([
    'email'          => $email,
    'expires_in_sec' => $expiryMins * 60,
], 'A new 2FA code has been sent to your email.');
