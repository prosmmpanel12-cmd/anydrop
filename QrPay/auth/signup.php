<?php
/**
 * QrPay — POST /auth/signup.php
 * Body: { "name", "email", "mobile_number", "password", "confirm_password" }
 *
 * Creates the developer account, auto-generates the API key, and
 * auto-subscribes the account to the 'free' plan (10 credits/day,
 * 300 credits/month — see core/plan_limits.php, config/schema.sql).
 *
 * Email verification is ADMIN-controlled (admin_settings.email_verification_enabled),
 * system-wide — not a per-signup choice. When it's on, the account is
 * created with email_verified = 0 and a verification link is emailed;
 * the developer cannot log in until they click it. When it's off, the
 * developer is logged straight in.
 *
 * 2FA (developers.two_fa_enabled) is a PER-USER toggle that defaults to
 * off at signup — the developer turns it on later from their own panel
 * settings (Phase 6), it is never forced at signup time.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/session.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$name           = trim($input['name'] ?? '');
$email          = strtolower(trim($input['email'] ?? ''));
$mobileNumber   = trim($input['mobile_number'] ?? '');
$password       = (string) ($input['password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

// ---- Validate input ----
if (strlen($name) < 2) fail('Please enter your full name.', 422);
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email address is required.', 422);
if (!isValidMobileNumber($mobileNumber)) fail('Please enter a valid mobile number.', 422);
if (!isPasswordStrongEnough($password)) fail('Password must be at least 8 characters long.', 422);
if ($password !== $confirmPassword) fail('Password and confirm password do not match.', 422);

// ---- Check for existing account ----
$stmt = $pdo->prepare('SELECT id FROM developers WHERE email = ? OR mobile_number = ? LIMIT 1');
$stmt->execute([$email, $mobileNumber]);
if ($stmt->fetch()) {
    fail('An account with this email or mobile number already exists. Try logging in instead.', 409);
}

// ---- Look up the free plan (must exist — seeded in schema.sql) ----
$stmt = $pdo->prepare('SELECT id FROM plans WHERE plan_type = "free" AND is_active = 1 LIMIT 1');
$stmt->execute();
$freePlan = $stmt->fetch();
if (!$freePlan) {
    error_log('QrPay signup failed: no active free plan row found in plans table.');
    fail('Signup is temporarily unavailable. Please try again shortly.', 503);
}
$freePlanId = (int) $freePlan['id'];

$emailVerificationRequired = qrpay_email_verification_required($pdo);
$apiKey = bin2hex(random_bytes(24));
$passwordHash = hashPassword($password);

$pdo->beginTransaction();
try {
    // ---- Create the developer ----
    $stmt = $pdo->prepare(
        'INSERT INTO developers
            (name, email, mobile_number, password_hash, email_verified, two_fa_enabled, apikey, is_admin, status)
         VALUES (?, ?, ?, ?, ?, 0, ?, 0, "active")'
    );
    $stmt->execute([
        $name, $email, $mobileNumber, $passwordHash,
        $emailVerificationRequired ? 0 : 1,
        $apiKey,
    ]);
    $developerId = (int) $pdo->lastInsertId();

    // ---- Empty merchant settings row (developer fills in their own UPI ID later) ----
    $pdo->prepare('INSERT INTO user_settings (developer_id) VALUES (?)')
        ->execute([$developerId]);

    // ---- Auto-subscribe to the free plan — 100-year expiry so it's
    // effectively permanent and the daily expiry cron never touches it.
    $pdo->prepare(
        'INSERT INTO subscriptions (developer_id, plan_id, billing_cycle, starts_at, expires_at, status)
         VALUES (?, ?, "monthly", NOW(), DATE_ADD(NOW(), INTERVAL 100 YEAR), "active")'
    )->execute([$developerId, $freePlanId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('QrPay signup failed for ' . $email . ': ' . $e->getMessage());
    fail('Signup failed. Please try again.', 500);
}

// ---- Email verification branch: don't log in yet ----
if ($emailVerificationRequired) {
    $token = generateSecureToken();
    $tokenHash = hashSecureToken($token);
    $expiryMins = (int) qrpay_env('EMAIL_VERIFY_EXPIRY_MINUTES', '60');
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMins * 60);

    $pdo->prepare(
        'INSERT INTO email_verification_tokens (developer_id, token_hash, expires_at, consumed)
         VALUES (?, ?, ?, 0)'
    )->execute([$developerId, $tokenHash, $expiresAt]);

    $appUrl = rtrim((string) qrpay_env('APP_URL', ''), '/');
    $verifyLink = $appUrl . '/auth/verify_email.php?token=' . $token;

    $sent = send_verification_email($email, $verifyLink, $expiryMins);
    if (!$sent) {
        error_log('QrPay signup: verification email failed to send to ' . $email);
    }

    success([
        'developer_id'          => $developerId,
        'email_verification_required' => true,
    ], 'Account created. Please check your email to verify your account before logging in.');
}

// ---- No email verification required: log straight in ----
qrpay_session_start();
session_regenerate_id(true);
$_SESSION['developer_id']    = $developerId;
$_SESSION['developer_email'] = $email;
$_SESSION['is_admin']        = false;

success([
    'developer_id' => $developerId,
    'email'        => $email,
    'apikey'       => $apiKey,
    'email_verification_required' => false,
], 'Account created successfully.');
