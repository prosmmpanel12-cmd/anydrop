<?php
/**
 * GET /api/v1/restaurant/staff-list.php
 * Auth: Restaurant token, owner only (manage_staff permission)
 * Response: { "staff": [{ "id", "name", "username", "role",
 *                         "is_active", "created_at" }, ...] }
 *
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3). Password
 * hash never leaves the SELECT — same convention every other login
 * table in this project already follows.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_staff');
$restaurantId = $owner['owner_id'];

$db = Database::get();
$stmt = $db->prepare(
    'SELECT id, name, username, role, is_active, created_at
     FROM restaurant_staff
     WHERE restaurant_id = :rid AND deleted_at IS NULL
     ORDER BY created_at ASC'
);
$stmt->execute(['rid' => $restaurantId]);
$rows = $stmt->fetchAll();

$staff = array_map(static function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'username' => $row['username'],
        'role' => $row['role'],
        'is_active' => (bool) $row['is_active'],
        'created_at' => $row['created_at'],
    ];
}, $rows);

respond_ok(['staff' => $staff]);
