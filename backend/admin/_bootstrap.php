<?php
/**
 * Anydrop — Admin Web UI bootstrap (session helpers, shared by every
 * page in this folder).
 *
 * Deliberately session-based, not Bearer-token — this matches
 * docs/02_API_Contract.md §6's own heading verbatim: "Admin Panel (web,
 * session-auth instead of Bearer token since it's server-rendered)".
 * The Bearer-token `auth_tokens` system (lib/auth.php) is what the
 * Customer/Restaurant *native apps* authenticate with; a plain
 * server-rendered, multi-page-reload HTML admin UI (no JS build step,
 * nothing to install in a sandbox with no network access, works by just
 * pointing a browser at it) calls for PHP native sessions instead, same
 * as any classic PHP admin panel.
 *
 * This page queries the DB directly rather than calling a JSON API over
 * HTTP (calling your own server over the network from itself is a
 * needless extra hop for same-process code, and this environment can't
 * self-test outbound HTTP anyway). If a future JSON-driven admin
 * SPA/native app is ever built, it would need its own Bearer-token
 * endpoints added at that point — none exist yet, intentionally, since
 * nothing consumes them today and doc 02 didn't ask for them.
 *
 * RBAC (doc 19 §1 — admin_roles/admin_permissions/named roles,
 * backend/sql/29_migration_admin_rbac.sql, backend/lib/admin_auth.php)
 * is implemented as of 2026-08-21: admin_require_login() below checks
 * "is *some* active admin logged in" (same scope as the JSON endpoints'
 * require_auth('admin') would have); pages that gate a specific action
 * additionally call admin_require_permission() from admin_auth.php.
 */

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/admin_auth.php';
require_once __DIR__ . '/../lib/settings.php';

/**
 * Anydrop's backend root URL — e.g. "http://localhost:8080/anydrop" in
 * this project's local/sandbox setup, matching the same host+path the
 * Customer/Restaurant/Rider apps' own ApiClient.kt BASE_URL constants
 * point at (minus the trailing `api/v1/`, since admin pages need to
 * reach `uploads/`, `rider_documents/`-adjacent private endpoints,
 * etc. too — not just the API).
 *
 * 2026-09-07 (app owner: "pure admin panel ke liye ek base url banao
 * settings table mein, sari files is base url se chale"): every
 * admin/*.php link to an uploaded file or a private-document endpoint
 * should be built from THIS, not a hand-rolled relative `../` guess.
 * The immediate trigger was admin/riders.php's document-view links
 * using an absolute root path (`/api/v1/...`) that 404'd because this
 * backend isn't deployed at the domain root — but a relative path is
 * itself fragile the same way (it silently depends on how many
 * folders deep the *calling* page happens to be, which every one of
 * banners.php/settlements.php/support.php/riders.php had to separately
 * guess right). One admin-configured absolute value removes the
 * guessing for every page, forever, in one place.
 *
 * Stored in app_settings under 'admin_base_url', edited via
 * base-url-settings.php — no seed migration needed, same "falls back
 * to this in-code default until an admin saves a real value" pattern
 * google_directions_api_key/route_recalc_*/fcm_service_account_json
 * already use (get_setting()'s own $default parameter). Always
 * returned WITHOUT a trailing slash, so every call site can safely do
 * `admin_base_url() . '/uploads/...'` without ever risking a doubled
 * `//`.
 */
function admin_base_url(): string
{
    $url = trim((string) get_setting('admin_base_url', 'http://localhost:8080/anydrop'));
    return rtrim($url, '/');
}

/**
 * Redirects to login.php unless an admin is currently signed in AND
 * still active. Re-checks is_active from the DB on every call (not
 * just at login time) so deactivating an admin takes effect on their
 * very next page load, not just their next login.
 */
function admin_require_login(): array
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }

    $db = Database::get();
    $stmt = $db->prepare('SELECT id, username, role_id, is_active FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !$admin['is_active']) {
        session_destroy();
        header('Location: login.php?deactivated=1');
        exit;
    }

    return [
        'id' => (int) $admin['id'],
        'username' => (string) $admin['username'],
        'role_id' => (int) $admin['role_id'],
    ];
}

/** One CSRF token per session, reused across this session's forms. */
function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_verify_csrf(string $submitted): bool
{
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

function admin_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Builds "Neora, Osian, Jodhpur, Rajasthan" style breadcrumb — most
 * specific first, comma-separated — for one service_areas node.
 *
 * 2026-08-21 (app owner request): used anywhere an admin picks a
 * service_areas node from a dropdown (restaurants.php's area-assign,
 * banners.php's area targeting) so two nodes with the same name (e.g.
 * two "Osian" — see recall.md item 2's merge-tool note on how that
 * happens) are actually distinguishable, and so the full location is
 * obvious at a glance without hovering or clicking through.
 *
 * $areaById must be a full [id => row] map of every service_areas row
 * (id, name, parent_id) — callers already have $allAreas/$areaOptions
 * loaded, so this only ever walks parent_id pointers already in memory,
 * no extra queries per row.
 *
 * NOTE: areas.php's own Hierarchy/Merge UI uses a *different* helper,
 * area_breadcrumb() (biggest-first, "State > District > ..." arrows) —
 * that ordering reads better for a top-down tree page. This function is
 * for compact dropdown options elsewhere, deliberately smallest-first
 * to match how the app owner actually asked for it ("Neora, Osian,
 * Jodhpur, Rajasthan").
 */
function admin_area_breadcrumb_compact(array $area, array $areaById): string
{
    $parts = [$area['name']];
    $cursor = $area;
    while (!empty($cursor['parent_id']) && isset($areaById[(int) $cursor['parent_id']])) {
        $cursor = $areaById[(int) $cursor['parent_id']];
        $parts[] = $cursor['name'];
    }
    return implode(', ', $parts);
}

/**
 * Deep-plan §25 (Admin Rider Command Center, "Rider list" — last seen).
 * First caller is riders.php's `is_online`/`last_location_at` column;
 * kept here rather than local to that file since "how old is this
 * timestamp, in words" is a generic admin-list need with no existing
 * helper anywhere in this codebase (grepped for one before adding this)
 * and the next screen that wants a relative-time cell (e.g. a future
 * live rider map's "stale location" flag, deep-plan §25's own "Live
 * map" sub-section) shouldn't have to re-invent it.
 *
 * Deliberately coarse (minute/hour/day buckets only, no i18n plural
 * rules) — an admin ops screen, not customer-facing copy.
 */
function admin_time_ago(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return 'Never';
    }
    $then = strtotime($timestamp);
    if ($then === false) {
        return 'Never';
    }
    $diff = time() - $then;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return $m . ' min ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hr' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = (int) floor($diff / 86400);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}
