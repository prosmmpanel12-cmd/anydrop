<?php
/**
 * POST /api/v1/restaurant/staff-delete.php?id={staff_id}
 * Auth: Restaurant token, owner only (manage_staff permission)
 * Response: { "deleted": true }
 *
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3). Hard soft-
 * delete (`deleted_at`), removing them from the roster entirely and
 * immediately invalidating any of their existing tokens (require_auth()
 * re-checks `deleted_at IS NULL` on every request — see lib/auth.php).
 * For a temporary disable instead (staff on leave, expected back), use
 * staff-update.php's `is_active` toggle — that keeps the row (and its
 * order/action history attribution) intact.
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
    'SELECT id, name, username, role FROM restaurant_staff WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute(['id' => $staffId, 'rid' => $restaurantId]);
$staff = $stmt->fetch();
if (!$staff) {
    respond_error('not_found', 404);
}

$db->prepare('UPDATE restaurant_staff SET deleted_at = NOW() WHERE id = :id')
    ->execute(['id' => $staffId]);

// Staff Audit Trail (migration 64) — logged after the soft-delete
// succeeds, capturing the row's last-known name/username/role since
// a later audit-list read can no longer join back to the (now
// deleted_at-marked) restaurant_staff row for display.
write_staff_audit_log($owner, 'staff_deleted', $staffId, [
    'name' => $staff['name'],
    'username' => $staff['username'],
    'role' => $staff['role'],
]);

respond_ok(['deleted' => true]);
