<?php
/**
 * POST /api/v1/auth/customer/email/verify-otp
 * Request:  { "email": "...", "otp": "123456" }
 * Response: { "customer": {...}, "token": "..." }
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/audit.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['email', 'otp']);

$email = trim(strtolower($body['email']));
$otp = trim($body['otp']);
$maxAttempts = (int) get_setting('otp_max_attempts', 3);

$db = Database::get();

$stmt = $db->prepare(
    'SELECT * FROM email_otps WHERE email = :e AND is_used = 0 ORDER BY id DESC LIMIT 1'
);
$stmt->execute(['e' => $email]);
$record = $stmt->fetch();

if (!$record) {
    respond_error('otp_not_found', 400);
}

if (strtotime($record['expires_at']) < time()) {
    respond_error('otp_expired', 400);
}

if ((int) $record['attempts'] >= $maxAttempts) {
    respond_error('otp_max_attempts_exceeded', 400);
}

if ($record['otp_code'] !== $otp) {
    $upd = $db->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id');
    $upd->execute(['id' => $record['id']]);
    respond_error('invalid_otp', 401, ['attempts_remaining' => max(0, $maxAttempts - (int) $record['attempts'] - 1)]);
}

// Mark OTP used
$upd = $db->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id');
$upd->execute(['id' => $record['id']]);

// Find or create customer
$stmt = $db->prepare('SELECT * FROM customers WHERE email = :e LIMIT 1');
$stmt->execute(['e' => $email]);
$customer = $stmt->fetch();

if (!$customer) {
    $ins = $db->prepare(
        "INSERT INTO customers (email, login_type, is_active) VALUES (:e, 'email', 1)"
    );
    $ins->execute(['e' => $email]);
    $customerId = (int) $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $customerId]);
    $customer = $stmt->fetch();
} else {
    if (!$customer['is_active']) {
        respond_error('account_suspended', 403);
    }
}

$token = create_auth_token('customer', (int) $customer['id']);
write_audit_log('customer', (int) $customer['id'], 'login_success', ['method' => 'email_otp']);

respond_ok([
    'customer' => $customer,
    'token' => $token,
]);
