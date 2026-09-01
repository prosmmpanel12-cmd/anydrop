<?php
/**
 * POST /api/v1/auth/restaurant-staff-login
 * Request:  { "username": "...", "password": "..." }
 * Response: { "restaurant": {...}, "staff": {...}, "token": "..." }
 *
 * Migration 63 (Restaurant Staff/RBAC, PENDING.md item 3) — sibling of
 * `restaurant-login.php` for a named staff account rather than the
 * restaurant owner. Deliberately a SEPARATE endpoint rather than
 * teaching `restaurant-login.php` to also accept a username: keeps the
 * owner's own login path (email + password against `restaurants`)
 * completely untouched, and the Android client can route a "Staff
 * Login" entry point straight here without threading an extra
 * "which kind of login is this" flag through the existing screen.
 *
 * `restaurant_staff.username` is globally unique (see migration 63's
 * header for why), so a plain lookup by username is enough to find the
 * right restaurant without any restaurant-scoping in this request body.
 *
 * Same suspension/pending/rejected checks as restaurant-login.php —
 * a staff member of a restaurant that's since been suspended (or never
 * got past pending/rejected — shouldn't be reachable in practice since
 * staff accounts are only ever created by an already-approved,
 * logged-in owner, but checked anyway rather than assumed) is blocked
 * the same way the owner themself would be.
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
require_fields($body, ['username', 'password']);

$username = trim($body['username']);
$password = $body['password'];

$db = Database::get();
$stmt = $db->prepare(
    'SELECT * FROM restaurant_staff WHERE username = :u AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute(['u' => $username]);
$staff = $stmt->fetch();

if (!$staff || !password_verify($password, $staff['password_hash'])) {
    // Bug fix: audit_logs.actor_type is an ENUM('customer','restaurant',
    // 'rider','admin','system') — 'restaurant_staff' isn't a valid
    // value. Under MySQL's default strict mode that INSERT throws,
    // which meant *every* staff login attempt (right password or
    // wrong) 500'd before a response could ever be sent — this is why
    // staff login looked completely broken rather than just showing a
    // wrong-password message. Matches write_staff_audit_log()'s own
    // already-correct convention (permissions.php): actor_type stays
    // 'restaurant' with actor_id = the restaurant's id, and who
    // actually acted (which staff account, if any) goes inside
    // details_json instead.
    write_audit_log('restaurant', $staff['restaurant_id'] ?? null, 'staff_login_failed', [
        'username' => $username,
        'staff_id' => $staff['id'] ?? null,
    ]);
    respond_error('invalid_credentials', 401);
}

if (!$staff['is_active']) {
    respond_error('staff_disabled', 403);
}

$restaurantStmt = $db->prepare(
    'SELECT * FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1'
);
$restaurantStmt->execute(['id' => $staff['restaurant_id']]);
$restaurant = $restaurantStmt->fetch();

// Defensive only — a restaurant_staff row's FK guarantees the
// restaurant existed at staff-creation time, but doesn't stop a later
// soft-delete. Same "no row = can't use this account" outcome
// require_auth() itself falls back to.
if (!$restaurant) {
    respond_error('account_suspended', 403);
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

$token = create_auth_token('restaurant', (int) $restaurant['id'], (int) $staff['id']);
// Same ENUM bug as the failure branch above — 'restaurant_staff' isn't
// a valid audit_logs.actor_type, so this would have crashed the
// request even for a completely correct username/password.
write_audit_log('restaurant', (int) $restaurant['id'], 'staff_login_success', [
    'staff_id' => (int) $staff['id'],
    'role' => $staff['role'],
]);

unset($restaurant['password_hash']);
unset($staff['password_hash']);

respond_ok([
    'restaurant' => $restaurant,
    'staff' => $staff,
    'token' => $token,
]);
