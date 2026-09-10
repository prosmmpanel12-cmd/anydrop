<?php
/**
 * Anydrop — Admin Web UI: Base URL Settings.
 *
 * WHY THIS PAGE EXISTS (app owner, 2026-09-07): "pure admin panel ke
 * liye ek base url banao settings table mein, sari files is base url
 * se chale" — one platform-wide base URL, stored in app_settings, that
 * every admin/*.php page's links to uploaded files (banners,
 * settlement screenshots, support attachments) and private-document
 * endpoints (rider documents-view.php) are built from.
 *
 * Immediate trigger: admin/riders.php's "View ID Doc"/"View Vehicle
 * Doc" links used an absolute root path that 404'd, because this
 * backend is deployed under a subdirectory, not the domain root. A
 * relative `../` path (what every other page had separately guessed,
 * mostly correctly) is itself fragile the same way — it silently
 * depends on how deep the calling page happens to be. This setting
 * removes the guessing everywhere at once.
 *
 * Read via `admin_base_url()` (backend/admin/_bootstrap.php) — every
 * admin page already gets that helper for free, no extra require
 * needed. No seed migration: falls back to a sensible local-dev
 * default (`http://localhost:8080/anydrop`, matching the Customer/
 * Restaurant/Rider apps' own ApiClient.kt BASE_URL) until an admin
 * saves a real value here — same "in-code default until someone
 * saves" pattern google_directions_api_key/route_recalc_* already use
 * (directions-settings.php / route-recalc-settings.php).
 *
 * Gated on `settings_manage`, already seeded by migration 29 — no new
 * RBAC migration needed, same as every other page in this "Settings"
 * nav group.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'settings_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $pasted = trim($_POST['base_url'] ?? '');
        if ($pasted === '') {
            $flash = 'Enter a base URL before saving.';
            $flashType = 'error';
        } elseif (!preg_match('#^https?://#i', $pasted)) {
            $flash = 'Base URL must start with http:// or https:// — not saved.';
            $flashType = 'error';
        } else {
            // Always stored WITHOUT a trailing slash — admin_base_url()
            // relies on this so every call site's `admin_base_url() .
            // '/uploads/...'` never risks a doubled `//`.
            $normalized = rtrim($pasted, '/');
            set_setting('admin_base_url', $normalized);
            write_audit_log('admin', $admin['id'], 'admin_base_url_updated', ['new_value' => $normalized]);
            $flash = 'Base URL saved.';
        }
    }
}

$currentUrl = admin_base_url();
$csrf = admin_csrf_token();
$pageTitle = 'Base URL Settings';
$activeNav = 'base_url_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Admin Panel Base URL</h2>
    <p class="muted">
        The backend's own deployed root URL (no trailing slash, no
        <code>api/v1/</code> suffix) — e.g.
        <code>https://yourdomain.com/anydrop</code>. Every admin-panel
        link to an uploaded file (banner images, settlement
        screenshots, support attachments) or a private-document
        endpoint (rider ID/vehicle documents) is built by appending a
        path onto this value, instead of each page separately guessing
        a relative <code>../</code> path — which is exactly what broke
        for rider documents before this setting existed: an absolute
        root-relative link 404'd because this backend isn't hosted at
        the domain root.
    </p>

    <p><span class="badge active">Current value</span> <code><?= admin_escape($currentUrl) ?></code></p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <label class="field-label" for="base-url">Base URL</label>
        <input type="text" id="base-url" name="base_url" value="<?= admin_escape($currentUrl) ?>"
               placeholder="https://yourdomain.com/anydrop" style="width:100%;max-width:480px" autocomplete="off">
        <p class="muted" style="margin-top:6px">
            Must start with <code>http://</code> or <code>https://</code>.
            Any trailing slash is stripped automatically.
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:8px">Save</button>
    </form>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
