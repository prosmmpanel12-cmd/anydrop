<?php
/**
 * Anydrop — Admin Web UI: Directions (Live Tracking Route) Settings.
 *
 * WHY THIS PAGE EXISTS: api/v1/orders/route.php (customer-app live
 * tracking, deep-plan §14-15 / docs/rider/88) reads a single
 * platform-wide `google_directions_api_key` via get_setting() — see
 * that file's own kdoc. It was deliberately left with no admin field
 * at the time (app-settings.php's $fields array is per-app-suffixed,
 * which doesn't fit one shared key cleanly) and had to be set directly
 * via set_setting()/SQL. This page is that flagged fast-follow — same
 * "one platform-wide key, its own small page" shape fcm-settings.php
 * already established for fcm_service_account_json, rather than
 * forcing it into the per-app app-settings.php tabs.
 *
 * SECURITY: the key is partially masked once saved (first 4 / last 4
 * characters only) — same redaction spirit fcm-settings.php uses for
 * its service-account private key and email-providers.php uses for
 * its API keys, so a shoulder-surfed screenshot of this page doesn't
 * leak a live, billable Google credential.
 *
 * Gated on `settings_manage`, already seeded by migration 29 — no new
 * RBAC migration needed, same as fcm-settings.php.
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
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'save_key') {
            $pasted = trim($_POST['api_key'] ?? '');
            if ($pasted === '') {
                $flash = 'Paste a Directions API key before saving.';
                $flashType = 'error';
            } else {
                // Loose sanity check only — Google API keys don't have
                // one fixed public format Anydrop should hard-validate
                // against (would risk rejecting a legitimate key over
                // a guessed pattern); this just catches the obvious
                // paste mistakes (whitespace-only, a whole curl command
                // pasted by accident, etc.), same "soft validation,
                // still saves it" spirit app-settings.php's own
                // min/latest version check uses.
                if (strlen($pasted) < 20 || strpos($pasted, ' ') !== false) {
                    $flash = 'That doesn\'t look like a valid API key (too short, or contains spaces) — double-check what was pasted. Not saved.';
                    $flashType = 'error';
                } else {
                    set_setting('google_directions_api_key', $pasted);
                    write_audit_log('admin', $admin['id'], 'directions_api_key_updated', []);
                    $flash = 'Directions API key saved.';
                }
            }
        } elseif ($formAction === 'clear_key') {
            set_setting('google_directions_api_key', '');
            write_audit_log('admin', $admin['id'], 'directions_api_key_cleared', []);
            $flash = 'Directions API key cleared — customer live-tracking maps will show markers only, no route line, until a new key is saved.';
        }
    }
}

$currentKey = trim((string) get_setting('google_directions_api_key', ''));
$isConfigured = $currentKey !== '';
$maskedKey = null;
if ($isConfigured) {
    $maskedKey = strlen($currentKey) > 8
        ? substr($currentKey, 0, 4) . str_repeat('•', max(4, strlen($currentKey) - 8)) . substr($currentKey, -4)
        : str_repeat('•', strlen($currentKey));
}

$csrf = admin_csrf_token();
$pageTitle = 'Directions Settings';
$activeNav = 'directions_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Live Tracking — Directions API</h2>
    <p class="muted">
        Powers the route line drawn on the customer app's order-tracking
        map (rider → restaurant, then rider → delivery address) via
        Google's Directions API, called server-side from
        <code>api/v1/orders/route.php</code>. Get a key from
        <a href="https://console.cloud.google.com/google/maps-apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>
        with the Directions API enabled and billing set up. Without a
        key configured, the tracking map still shows rider/restaurant/
        delivery markers — it just can't draw the route line or show
        distance/ETA, degrading gracefully rather than breaking the
        screen.
    </p>

    <?php if ($isConfigured): ?>
        <p><span class="badge active">Configured</span>
            Key ending in <strong><?= admin_escape(substr($currentKey, -4)) ?></strong> — <code><?= admin_escape($maskedKey) ?></code>
        </p>
    <?php else: ?>
        <p><span class="badge inactive">Not configured</span> Route lines and ETA/distance are disabled on the customer tracking map until a key is saved.</p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_key">
        <label class="field-label" for="api-key"><?= $isConfigured ? 'Replace key' : 'Directions API key' ?></label>
        <input type="text" id="api-key" name="api_key" placeholder="AIzaSy..." style="width:100%;max-width:480px" autocomplete="off">
        <p class="muted" style="margin-top:6px">Never shown back in full once saved — only the last 4 characters, above.</p>
        <button type="submit" class="btn btn-primary" style="margin-top:8px">Save</button>
        <?php if ($isConfigured): ?>
        <button type="submit" form="clear-form" class="btn btn-outline danger" style="margin-top:8px">Clear saved key</button>
        <?php endif; ?>
    </form>
    <form id="clear-form" method="post" onsubmit="return confirm('Remove the saved Directions API key? The customer tracking map will stop showing route lines and ETA until a new one is saved.');" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="clear_key">
    </form>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
