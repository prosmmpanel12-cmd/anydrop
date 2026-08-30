<?php
/**
 * GET /api/v1/restaurant/staff-audit-list.php
 * Auth: Restaurant token, owner only (manage_staff permission — same
 *       gate as the staff CRUD endpoints themselves; seeing who
 *       changed staff accounts is exactly as sensitive as changing
 *       them).
 * Response: { "entries": [{ "id", "action", "target_staff_id",
 *                           "acting_role", "acting_staff_id",
 *                           "details", "created_at" }, ...] }
 *
 * Migration 64 (Restaurant Staff/RBAC audit trail, PENDING.md §7's
 * last remaining checkbox). Reads the generic `audit_logs` table
 * (01_schema.sql) rather than a dedicated staff_audit table — see
 * migration 64's own header for why. Filters to this restaurant's
 * rows (actor_type='restaurant', actor_id=$restaurantId) AND to just
 * the staff-management actions written by write_staff_audit_log()
 * (lib/permissions.php) — a restaurant's audit_logs rows could in
 * principle include other actor_type='restaurant' actions in future
 * (signup, login, bank details save all already write here), so the
 * action-name whitelist below is what keeps this screen scoped to
 * "staff account changes" specifically rather than becoming a
 * general activity feed by accident.
 *
 * No pagination — same "an owner's own action history is realistically
 * small" reasoning StaffManagementActivity's own list already uses,
 * capped at 200 most-recent rows as a sane upper bound rather than
 * genuinely unbounded.
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

const STAFF_AUDIT_ACTIONS = [
    'staff_created', 'staff_updated', 'staff_role_changed',
    'staff_activated', 'staff_deactivated', 'staff_deleted',
];

$db = Database::get();
$placeholders = implode(',', array_fill(0, count(STAFF_AUDIT_ACTIONS), '?'));
$stmt = $db->prepare(
    "SELECT id, action, details_json, created_at
     FROM audit_logs
     WHERE actor_type = 'restaurant' AND actor_id = ? AND action IN ($placeholders)
     ORDER BY created_at DESC
     LIMIT 200"
);
$stmt->execute(array_merge([$restaurantId], STAFF_AUDIT_ACTIONS));
$rows = $stmt->fetchAll();

$entries = array_map(static function (array $row): array {
    $details = json_decode($row['details_json'] ?? '', true) ?: [];
    return [
        'id' => (int) $row['id'],
        'action' => $row['action'],
        'target_staff_id' => isset($details['target_staff_id']) ? (int) $details['target_staff_id'] : null,
        'acting_role' => $details['acting_role'] ?? 'owner',
        'acting_staff_id' => isset($details['acting_staff_id']) ? (int) $details['acting_staff_id'] : null,
        'details' => $details,
        'created_at' => $row['created_at'],
    ];
}, $rows);

respond_ok(['entries' => $entries]);
