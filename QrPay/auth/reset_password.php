<?php
/**
 * QrPay — POST /auth/reset_password.php
 * Body: { "token", "password", "confirm_password" }
 *
 * Completes the flow started by auth/forgot_password.php. The token
 * itself IS the proof of identity here (it was only ever sent to the
 * account's own inbox) — no email field needed in the request body.
 *
 * On success: updates the password, burns the token, and invalidates
 * every OTHER unconsumed reset token for that developer (defensive —
 * closes out any other reset links that might still be sitting in an
 * inbox from an earlier request).
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$token            = trim($input['token'] ?? '');
$password         = (string) ($input['password'] ?? '');
$confirmPassword  = (string) ($input['confirm_password'] ?? '');

if (empty($token)) fail('Missing reset token.', 422);
if (!isPasswordStrongEnough($password)) fail('Password must be at least 8 characters long.', 422);
if ($password !== $confirmPassword) fail('Password and confirm password do not match.', 422);

$tokenHash = hashSecureToken($token);

$stmt = $pdo->prepare(
    'SELECT id, developer_id, expires_at, consumed
     FROM password_reset_tokens
     WHERE token_hash = ?
     ORDER BY created_at DESC
     LIMIT 1'
);
$stmt->execute([$tokenHash]);
$row = $stmt->fetch();

if (!$row) fail('Invalid or expired reset link.', 400);
if ((int) $row['consumed'] === 1) fail('This reset link has already been used.', 400);
if (strtotime($row['expires_at']) < time()) fail('This reset link has expired. Please request a new one.', 400);

$developerId = (int) $row['developer_id'];
$newHash = hashPassword($password);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE developers SET password_hash = ? WHERE id = ?')
        ->execute([$newHash, $developerId]);

    // Burn every unconsumed reset token for this developer, not just
    // the one used — closes out any other still-live links at once.
    $pdo->prepare('UPDATE password_reset_tokens SET consumed = 1 WHERE developer_id = ? AND consumed = 0')
        ->execute([$developerId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('QrPay password reset failed for developer_id ' . $developerId . ': ' . $e->getMessage());
    fail('Password reset failed. Please try again.', 500);
}

success([], 'Password reset successfully. You can now log in with your new password.');
