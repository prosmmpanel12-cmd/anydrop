<?php
/**
 * POST /api/v1/auth/restaurant/login
 * Request:  { "email": "...", "password": "..." }
 * Response: { "restaurant": {...}, "token": "..." }
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/audit.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['email', 'password']);

$email = trim(strtolower($body['email']));
$password = $body['password'];

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM restaurants WHERE owner_email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$restaurant = $stmt->fetch();

if (!$restaurant || !password_verify($password, $restaurant['password_hash'])) {
    write_audit_log('restaurant', $restaurant['id'] ?? null, 'login_failed', ['email' => $email]);
    respond_error('invalid_credentials', 401);
}

if ($restaurant['status'] === 'suspended') {
    respond_error('account_suspended', 403, ['reason' => $restaurant['rejection_reason'] ?? null]);
}

if ($restaurant['status'] === 'pending') {
    respond_error('pending_approval', 403);
}

if ($restaurant['status'] === 'rejected') {
    respond_error('account_suspended', 403, ['reason' => $restaurant['rejection_reason'] ?? null]);
}

$token = create_auth_token('restaurant', (int) $restaurant['id']);
write_audit_log('restaurant', (int) $restaurant['id'], 'login_success');

unset($restaurant['password_hash']);

respond_ok([
    'restaurant' => $restaurant,
    'token' => $token,
]);
