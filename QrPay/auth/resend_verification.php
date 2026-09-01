<?php
/**
 * QrPay — POST /auth/resend_verification.php
 * Body: { "email": "dev@example.com" }
 *
 * Re-sends the email verification link for an account that hasn't
 * clicked it yet. Response is intentionally the SAME generic message
 * whether or not the account exists / is already verified — avoids
 * leaking account existence to a prober, same pattern as
 * auth/forgot_password.php.
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

$genericMessage = 'If an unverified account exists for this email, a new verification link has been sent.';

if (!qrpay_email_verification_required($pdo)) {
    // Feature is off system-wide — nothing to resend.
    success([], $genericMessage);
}

$stmt = $pdo->prepare('SELECT id, email_verified, status FROM developers WHERE email = ?');
$stmt->execute([$email]);
$developer = $stmt->fetch();

if ($developer && $developer['status'] !== 'suspended' && !(bool) $developer['email_verified']) {
    // ---- Rate limit: max N resends per email per hour ----
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM email_verification_tokens
         WHERE developer_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)'
    );
    $stmt->execute([$developer['id']]);
    $recentCount = (int) $stmt->fetch()['cnt'];

    if ($recentCount < 5) {
        $token = generateSecureToken();
        $tokenHash = hashSecureToken($token);
        $expiryMins = (int) qrpay_env('EMAIL_VERIFY_EXPIRY_MINUTES', '60');
        $expiresAt = date('Y-m-d H:i:s', time() + $expiryMins * 60);

        // Invalidate previous unconsumed tokens so only the newest link works.
        $pdo->prepare('UPDATE email_verification_tokens SET consumed = 1 WHERE developer_id = ? AND consumed = 0')
            ->execute([$developer['id']]);

        $pdo->prepare(
            'INSERT INTO email_verification_tokens (developer_id, token_hash, expires_at, consumed)
             VALUES (?, ?, ?, 0)'
        )->execute([$developer['id'], $tokenHash, $expiresAt]);

        $appUrl = rtrim((string) qrpay_env('APP_URL', ''), '/');
        $verifyLink = $appUrl . '/auth/verify_email.php?token=' . $token;

        send_verification_email($email, $verifyLink, $expiryMins);
    }
    // Silently skip if rate-limited too — response stays generic either way.
}

success([], $genericMessage);
