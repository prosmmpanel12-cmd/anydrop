<?php
/**
 * QrPay — POST /auth/verify_otp.php
 * Body: { "email": "dev@example.com", "otp": "123456" }
 *
 * Step 2 of login — ONLY reached for developers.two_fa_enabled = 1
 * accounts, after auth/login.php has already verified the password and
 * set $_SESSION['pending_2fa_email']. This endpoint does not create
 * accounts (see auth/signup.php) and does not log anyone in on its own
 * — it requires the pending marker from step 1, so a correct OTP alone
 * (without having passed the password check first) is not enough.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/session.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$email = strtolower(trim($input['email'] ?? ''));
$otp   = trim($input['otp'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email address is required.', 422);
}
if (empty($otp) || !preg_match('/^\d{4,8}$/', $otp)) {
    fail('A valid verification code is required.', 422);
}

// ---- Must have passed step 1 (password) for THIS email first ----
qrpay_session_start();
if (empty($_SESSION['pending_2fa_email']) || $_SESSION['pending_2fa_email'] !== $email) {
    fail('Please log in with your password first.', 401);
}

// ---- Fetch the most recent unconsumed 2FA code for this email ----
$stmt = $pdo->prepare(
    'SELECT id, otp_hash, expires_at, attempts, consumed
     FROM otp_codes
     WHERE email = ? AND purpose = "2fa_login" AND consumed = 0
     ORDER BY created_at DESC
     LIMIT 1'
);
$stmt->execute([$email]);
$otpRow = $stmt->fetch();

if (!$otpRow) {
    fail('No active verification code for this email. Please log in again to get a new one.', 400);
}

if (strtotime($otpRow['expires_at']) < time()) {
    fail('This code has expired. Please log in again to get a new one.', 400);
}

// Cap wrong attempts per OTP so it can't be brute-forced within its
// own expiry window (6 digits, 5 min window — this is belt-and-braces).
$maxAttempts = 5;
if ((int) $otpRow['attempts'] >= $maxAttempts) {
    // Burn the OTP so it can't be tried again even if within expiry.
    $pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE id = ?')->execute([$otpRow['id']]);
    fail('Too many incorrect attempts. Please log in again to get a new code.', 429);
}

if (!verifyOtpHash($otp, $otpRow['otp_hash'])) {
    $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$otpRow['id']]);
    fail('Incorrect verification code.', 401);
}

// ---- Correct code: consume it ----
$pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE id = ?')->execute([$otpRow['id']]);

// ---- Load the developer (already fully validated in login.php step 1) ----
$stmt = $pdo->prepare('SELECT id, name, email, apikey, is_admin, status FROM developers WHERE email = ?');
$stmt->execute([$email]);
$developer = $stmt->fetch();

if (!$developer) {
    fail('Account not found.', 404);
}
if ($developer['status'] === 'suspended') {
    fail('This account is suspended. Contact support.', 403);
}

// ---- Issue the real dashboard session ----
session_regenerate_id(true); // prevent session fixation across the login boundary
unset($_SESSION['pending_2fa_email']);

$_SESSION['developer_id']    = (int) $developer['id'];
$_SESSION['developer_email'] = $developer['email'];
$_SESSION['is_admin']        = (bool) $developer['is_admin'];

success([
    'developer_id' => (int) $developer['id'],
    'name'         => $developer['name'],
    'email'        => $developer['email'],
    'apikey'       => $developer['apikey'],
    'is_admin'     => (bool) $developer['is_admin'],
], 'Logged in successfully.');
