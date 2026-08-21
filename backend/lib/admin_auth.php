<?php
/**
 * Anydrop — Admin RBAC permission checks
 *
 * Backs docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_
 * 2026-08-14.md §1's "Enforcement pattern": every admin action checks
 * the calling admin's role against a specific permission `key` (e.g.
 * `restaurants_approve`), rather than a single flat "is *some* admin
 * logged in" check. See backend/sql/29_migration_admin_rbac.sql for
 * the admin_roles / admin_permissions / admin_role_permissions schema
 * this reads from.
 *
 * This is the web-admin (session-based) side — see admin/_bootstrap.php
 * for why sessions instead of Bearer tokens. There is no JSON
 * `/admin/*` API today, so there is no separate "403 with missing
 * permission key in the JSON body" path yet (doc 19's note about that
 * applies once/if a JSON-driven admin surface exists) — the equivalent
 * here is admin_require_permission() rendering a plain 403 HTML page.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * All permission keys held by the given admin's role.
 * Cached per-request (a single page load may check several keys).
 */
function admin_permission_keys(int $adminId): array
{
    static $cache = [];
    if (array_key_exists($adminId, $cache)) {
        return $cache[$adminId];
    }

    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT p.`key`
         FROM admins a
         JOIN admin_role_permissions rp ON rp.role_id = a.role_id
         JOIN admin_permissions p ON p.id = rp.permission_id
         WHERE a.id = :id'
    );
    $stmt->execute(['id' => $adminId]);

    return $cache[$adminId] = array_column($stmt->fetchAll(), 'key');
}

function admin_has_permission(int $adminId, string $permissionKey): bool
{
    return in_array($permissionKey, admin_permission_keys($adminId), true);
}

/**
 * Call after admin_require_login(). Exits with a 403 page if the
 * signed-in admin's role doesn't have $permissionKey — every page that
 * gates a specific action (approve a restaurant, manage roles, ...)
 * should call this before doing anything sensitive.
 */
function admin_require_permission(array $admin, string $permissionKey): void
{
    if (admin_has_permission($admin['id'], $permissionKey)) {
        return;
    }

    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<title>403 — Not permitted</title>'
        . '<style>body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;'
        . 'background:#f4f5f7;display:flex;align-items:center;justify-content:center;'
        . 'min-height:100vh;margin:0;color:#1a1a1a;}'
        . '.card{background:#fff;padding:32px;border-radius:12px;'
        . 'box-shadow:0 2px 12px rgba(0,0,0,0.08);max-width:380px;text-align:center;}'
        . 'a{color:#e6521f;}</style></head><body>'
        . '<div class="card"><h2>403 — Not permitted</h2>'
        . '<p>Your role doesn\'t have the <code>' . htmlspecialchars($permissionKey, ENT_QUOTES, 'UTF-8')
        . '</code> permission needed for this page.</p>'
        . '<p><a href="index.php">Back to dashboard</a></p></div></body></html>';
    exit;
}
