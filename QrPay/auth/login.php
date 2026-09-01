<?php
/**
 * QrPay — POST /auth/login.php
 * Body: { "email", "password" }
 *
 * Step 1 of login. Verifies email+password.
 *   - If admin_settings.email_verification_enabled = 1 and this account
 *     hasn't verified yet -> blocked, told to check their inbox.
 *   - If developers.two_fa_enabled = 1 for this account -> a 2FA code is
 *     emailed and a PENDING (not-yet-authenticated) session marker is
 *     set; the dashboard session itself is only granted after
 *     auth/verify_otp.php confirms the code.
 *   - Otherwise -> full dashboard session granted immediately.
 *
 * Deliberately vague on failure ("Invalid email or password.") so a
 * failed attempt never reveals whether the email exists at all.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/session.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$email    = strtolower(trim($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email address is required.', 422);
if (empty($password)) fail('Password is required.', 422);

$stmt = $pdo->prepare(
    'SELECT id, name, email, password_hash, email_verified, two_fa_enabled, apikey, is_admin, status
     FROM developers WHERE email = ?'
);
$stmt->execute([$email]);
$developer = $stmt->fetch();

if (!$developer || !verifyPassword($password, $developer['password_hash'])) {
    fail('Invalid email or password.', 401);
}

if ($developer['status'] === 'suspended') {
    fail('This account is suspended. Contact support.', 403);
}

// ---- Admin-controlled email verification gate ----
if (qrpay_email_verification_required($pdo) && !(bool) $developer['email_verified']) {
    fail(
        'Please verify your email before logging in. Check your inbox for the verification link.',
        403,
        ['email_verification_required' => true]
    );
}

$developerId = (int) $developer['id'];

// ---- Admin allow-list check (Phase 7 reuses this same login flow) ----
$adminAllowlist = array_filter(array_map('trim', explode(',', qrpay_env('ADMIN_EMAIL_ALLOWLIST', ''))));
$isAdminByAllowlist = in_array($email, $adminAllowlist, true);

if ($isAdminByAllowlist && !$developer['is_admin']) {
    $pdo->prepare('UPDATE developers SET is_admin = 1 WHERE id = ?')->execute([$developerId]);
    $developer['is_admin'] = 1;
}

// ---- Per-user 2FA gate ----
if ((bool) $developer['two_fa_enabled']) {
    $otpLength  = (int) qrpay_env('OTP_LENGTH', '6');
    $expiryMins = (int) qrpay_env('OTP_EXPIRY_MINUTES', '5');

    $otp = generateOtp($otpLength);
    $otpHash = hashOtp($otp);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMins * 60);

    // Invalidate any still-active 2FA codes for this email first.
    $pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE email = ? AND consumed = 0')
        ->execute([$email]);

    $pdo->prepare(
        'INSERT INTO otp_codes (email, otp_hash, purpose, expires_at, attempts, consumed)
         VALUES (?, ?, "2fa_login", ?, 0, 0)'
    )->execute([$email, $otpHash, $expiresAt]);

    $sent = send_otp_email($email, $otp, $expiryMins);
    if (!$sent) {
        fail('Could not send the 2FA code. Please try again shortly.', 502);
    }

    // Lightweight pending marker — NOT a full session. verify_otp.php
    // checks this matches before granting the real dashboard session,
    // so a leaked/guessed OTP alone (without having passed step 1,
    // password) can't complete a login.
    qrpay_session_start();
    session_regenerate_id(true);
    $_SESSION['pending_2fa_email'] = $email;
    unset($_SESSION['developer_id']); // never partially-authenticated

    success([
        'two_fa_required' => true,
        'email'           => $email,
        'expires_in_sec'  => $expiryMins * 60,
    ], 'Password correct. Enter the 2FA code sent to your email.');
}

// ---- No 2FA — full session granted now ----
qrpay_session_start();
session_regenerate_id(true);
unset($_SESSION['pending_2fa_email']);
$_SESSION['developer_id']    = $developerId;
$_SESSION['developer_email'] = $email;
$_SESSION['is_admin']        = (bool) $developer['is_admin'];

success([
    'two_fa_required' => false,
    'developer_id'    => $developerId,
    'name'            => $developer['name'],
    'email'           => $email,
    'apikey'          => $developer['apikey'],
    'is_admin'        => (bool) $developer['is_admin'],
], 'Logged in successfully.');
