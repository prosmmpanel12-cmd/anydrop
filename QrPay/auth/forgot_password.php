<?php
/**
 * QrPay — POST /auth/forgot_password.php
 * Body: { "email": "dev@example.com" }
 *
 * Sends a password reset link. Response is ALWAYS the same generic
 * message regardless of whether the account exists — never reveal
 * account existence via this endpoint (standard practice for
 * password reset flows, unlike auth/request_otp.php's OTP path which
 * already requires a password to reach).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/mailer.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$email = strtolower(trim($input['email'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('A valid email address is required.', 422);
}

$genericMessage = 'If an account exists for this email, a password reset link has been sent.';

$stmt = $pdo->prepare('SELECT id, status FROM developers WHERE email = ?');
$stmt->execute([$email]);
$developer = $stmt->fetch();

if ($developer && $developer['status'] !== 'suspended') {
    // ---- Rate limit: max N reset requests per account per hour ----
    $maxPerHour = (int) qrpay_env('PASSWORD_RESET_MAX_REQUESTS_PER_HOUR', '5');

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM password_reset_tokens
         WHERE developer_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $stmt->execute([$developer['id']]);
    $recentCount = (int) $stmt->fetch()['cnt'];

    if ($recentCount < $maxPerHour) {
        $token = generateSecureToken();
        $tokenHash = hashSecureToken($token);
        $expiryMins = (int) qrpay_env('PASSWORD_RESET_EXPIRY_MINUTES', '30');
        $expiresAt = date('Y-m-d H:i:s', time() + $expiryMins * 60);

        // Invalidate previous unconsumed tokens so only the newest link works.
        $pdo->prepare('UPDATE password_reset_tokens SET consumed = 1 WHERE developer_id = ? AND consumed = 0')
            ->execute([$developer['id']]);

        $pdo->prepare(
            'INSERT INTO password_reset_tokens (developer_id, token_hash, expires_at, consumed)
             VALUES (?, ?, ?, 0)'
        )->execute([$developer['id'], $tokenHash, $expiresAt]);

        $appUrl = rtrim((string) qrpay_env('APP_URL', ''), '/');
        $resetLink = $appUrl . '/panel/reset_password.php?token=' . $token;

        send_password_reset_email($email, $resetLink, $expiryMins);
    }
    // Silently skip if rate-limited — response stays generic either way.
}

success([], $genericMessage);
