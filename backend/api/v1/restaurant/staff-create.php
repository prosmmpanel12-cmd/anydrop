<?php
/**
 * POST /api/v1/restaurant/staff-create.php
 * Auth: Restaurant token, owner only (manage_staff permission)
 * Request: { "name": "...", "username": "...", "password": "...",
 *            "role": "manager" | "kitchen" | "cashier" }
 * Response: { "staff": {...} }
 *
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3). "owner" is
 * deliberately not an accepted role here — see migration 63's own
 * header for why the owner is never a `restaurant_staff` row.
 * `username` is globally unique (see the same header) — a duplicate
 * gets a plain `validation_error`, not a leak of which restaurant
 * already owns it.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_staff');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['name', 'username', 'password', 'role']);

$name = trim((string) $body['name']);
$username = trim((string) $body['username']);
$password = (string) $body['password'];
$role = (string) $body['role'];

if (!in_array($role, ['manager', 'kitchen', 'cashier'], true)) {
    respond_error('validation_error', 422, ['fields' => ['role']]);
}
if (mb_strlen($username) < 3) {
    respond_error('validation_error', 422, ['fields' => ['username']]);
}
if (mb_strlen($password) < 6) {
    respond_error('validation_error', 422, ['fields' => ['password']]);
}

$db = Database::get();

$existsStmt = $db->prepare('SELECT id FROM restaurant_staff WHERE username = :u AND deleted_at IS NULL LIMIT 1');
$existsStmt->execute(['u' => $username]);
if ($existsStmt->fetch()) {
    respond_error('validation_error', 422, ['fields' => ['username'], 'reason' => 'username_taken']);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$insert = $db->prepare(
    'INSERT INTO restaurant_staff (restaurant_id, name, username, password_hash, role, is_active)
     VALUES (:rid, :name, :username, :hash, :role, 1)'
);
$insert->execute([
    'rid' => $restaurantId,
    'name' => $name,
    'username' => $username,
    'hash' => $passwordHash,
    'role' => $role,
]);
$newId = (int) $db->lastInsertId();

write_staff_audit_log($owner, 'staff_created', $newId, ['name' => $name, 'username' => $username, 'role' => $role]);

respond_ok([
    'staff' => [
        'id' => $newId,
        'name' => $name,
        'username' => $username,
        'role' => $role,
        'is_active' => true,
    ],
], 201);
