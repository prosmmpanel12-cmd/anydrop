<?php
/**
 * QrPay — GET/POST /auth/verify_email.php?token=...
 *
 * Consumes the link sent by auth/signup.php (only when
 * admin_settings.email_verification_enabled = 1). Marks the account
 * email_verified = 1 so auth/login.php stops blocking it.
 *
 * Accepts the token via query string (so the emailed link is a plain
 * clickable URL) or POST body (so a front-end confirmation page can
 * submit it via fetch instead of a full page navigation).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token = trim($_GET['token'] ?? $input['token'] ?? '');

if (empty($token)) {
    fail('Missing verification token.', 422);
}

$tokenHash = hashSecureToken($token);

$stmt = $pdo->prepare(
    'SELECT id, developer_id, expires_at, consumed
     FROM email_verification_tokens
     WHERE token_hash = ?
     ORDER BY created_at DESC
     LIMIT 1'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) {
    fail('Invalid verification link.', 400);
}
if ((int) $row['consumed'] === 1) {
    fail('This verification link has already been used.', 400);
}
if (strtotime($row['expires_at']) < time()) {
    fail('This verification link has expired. Please request a new one.', 400);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE email_verification_tokens SET consumed = 1 WHERE id = ?')
        ->execute([$row['id']]);
    $pdo->prepare('UPDATE developers SET email_verified = 1 WHERE id = ?')
        ->execute([$row['developer_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('QrPay email verification failed for developer_id ' . $row['developer_id'] . ': ' . $e->getMessage());
    fail('Verification failed. Please try again.', 500);
}

success([], 'Email verified successfully. You can now log in.');
