<?php
/**
 * POST /api/v1/restaurant/staff-update.php?id={staff_id}
 * Auth: Restaurant token, owner only (manage_staff permission)
 * Request: any of { "name", "role", "is_active", "password" }
 *          (all optional — only provided fields are changed, same
 *          partial-update convention as profile-update.php)
 * Response: { "staff": {...} }
 *
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3). `is_active`
 * is the owner's quick on/off switch (e.g. staff on leave) — see
 * migration 63's own column comment; use staff-delete.php instead to
 * remove someone from the roster entirely. `username` is intentionally
 * NOT editable here — same "identity field, not a profile field" call
 * this project already makes for `restaurants.owner_email` (also never
 * exposed via profile-update.php).
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
$staffId = (int) ($_GET['id'] ?? 0);

$db = Database::get();
$stmt = $db->prepare(
    'SELECT * FROM restaurant_staff WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute(['id' => $staffId, 'rid' => $restaurantId]);
$staff = $stmt->fetch();
if (!$staff) {
    respond_error('not_found', 404);
}

$body = get_json_body();
$updates = [];
$params = ['id' => $staffId];

if (isset($body['name'])) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $updates[] = 'name = :name';
    $params['name'] = $name;
}

if (isset($body['role'])) {
    $role = (string) $body['role'];
    if (!in_array($role, ['manager', 'kitchen', 'cashier'], true)) {
        respond_error('validation_error', 422, ['fields' => ['role']]);
    }
    $updates[] = 'role = :role';
    $params['role'] = $role;
}

if (isset($body['is_active'])) {
    $updates[] = 'is_active = :is_active';
    $params['is_active'] = $body['is_active'] ? 1 : 0;
}

if (isset($body['password'])) {
    $password = (string) $body['password'];
    if (mb_strlen($password) < 6) {
        respond_error('validation_error', 422, ['fields' => ['password']]);
    }
    $updates[] = 'password_hash = :hash';
    $params['hash'] = password_hash($password, PASSWORD_DEFAULT);
}

if (empty($updates)) {
    respond_error('validation_error', 422, ['reason' => 'no_fields_to_update']);
}

$db->prepare('UPDATE restaurant_staff SET ' . implode(', ', $updates) . ' WHERE id = :id')
    ->execute($params);

$refreshed = $db->prepare('SELECT id, name, username, role, is_active, created_at FROM restaurant_staff WHERE id = :id');
$refreshed->execute(['id' => $staffId]);
$row = $refreshed->fetch();

// Staff Audit Trail (migration 64). One row per meaningful change
// rather than one generic "staff_updated" for everything — a role
// change and an activate/deactivate toggle are different enough
// events that an owner scanning the trail later shouldn't have to
// open details_json just to tell them apart. A request can trigger
// more than one of these (e.g. role + is_active in the same call) —
// each gets its own row, same "one action, one log line" convention
// write_audit_log() already follows elsewhere in this codebase.
if (isset($body['is_active']) && (bool) $body['is_active'] !== (bool) $staff['is_active']) {
    write_staff_audit_log(
        $owner,
        $body['is_active'] ? 'staff_activated' : 'staff_deactivated',
        $staffId,
        ['name' => $staff['name'], 'username' => $staff['username']]
    );
}
if (isset($body['role']) && $body['role'] !== $staff['role']) {
    write_staff_audit_log($owner, 'staff_role_changed', $staffId, [
        'name' => $staff['name'],
        'username' => $staff['username'],
        'old_role' => $staff['role'],
        'new_role' => $body['role'],
    ]);
}
if (isset($body['name']) || isset($body['password'])) {
    write_staff_audit_log($owner, 'staff_updated', $staffId, [
        'name' => $row['name'],
        'username' => $row['username'],
        'fields_changed' => array_values(array_intersect(array_keys($body), ['name', 'password'])),
    ]);
}

respond_ok([
    'staff' => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'username' => $row['username'],
        'role' => $row['role'],
        'is_active' => (bool) $row['is_active'],
        'created_at' => $row['created_at'],
    ],
]);
