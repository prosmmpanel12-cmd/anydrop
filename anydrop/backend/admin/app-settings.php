<?php
/**
 * Anydrop — Admin Web UI: App Settings (per-app Update + Maintenance).
 *
 * One page, three tabs (?app=customer|restaurant|rider), instead of
 * three near-identical files — same "one file, a query param picks
 * the variant" shape refunds.php/orders.php use for their own
 * per-row dialogs. Each tab manages that app's own app_settings rows:
 *
 *   Update check (already read by api/v1/system/app-version.php,
 *   which existed with no admin UI to edit these before this page):
 *     latest_app_version_{app}, latest_app_version_name_{app},
 *     min_app_version_{app}, update_message_{app}, update_url_{app}
 *
 *   Maintenance mode (new keys this page introduces — the old global
 *   `maintenance_mode` key from 01_schema.sql was seeded but never
 *   read by any endpoint; these per-app keys replace that dead
 *   setting with one that's actually wired into the same
 *   app-version.php response every app already polls at startup):
 *     maintenance_mode_{app}, maintenance_message_{app}
 *
 * "Other things" (the person's own words for what belongs here) has
 * room to grow — add a new field to APP_SETTINGS_FIELDS below and it
 * renders/saves automatically, no other change needed.
 *
 * Gated on `app_version_manage`, already seeded by migration 29 — no
 * new RBAC migration needed.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'app_version_manage');
$db = Database::get();

$apps = [
    'customer' => 'Customer App',
    'restaurant' => 'Restaurant App',
    'rider' => 'Rider App',
];
$app = $_GET['app'] ?? 'customer';
if (!is_string($app) || !array_key_exists($app, $apps)) {
    $app = 'customer';
}

// Field schema shared by all three apps — every key is suffixed with
// _{app} in app_settings, same convention app-version.php already
// documents.
$fields = [
    ['key' => 'latest_app_version', 'label' => 'Latest version code', 'type' => 'number', 'help' => 'Newest available version code (integer, matches versionCode in build.gradle).'],
    ['key' => 'latest_app_version_name', 'label' => 'Latest version name', 'type' => 'text', 'help' => 'Display name shown to users, e.g. "1.4".'],
    ['key' => 'min_app_version', 'label' => 'Minimum supported version code', 'type' => 'number', 'help' => 'Versions below this are force-updated (can\'t skip the update popup).'],
    ['key' => 'update_message', 'label' => 'Update popup message', 'type' => 'textarea', 'help' => 'Shown in the in-app update popup.'],
    ['key' => 'update_url', 'label' => 'Update URL', 'type' => 'text', 'help' => 'Direct APK link or Play Store URL.'],
];

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'app_version_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $postedApp = $_POST['app'] ?? '';
        $formAction = $_POST['form_action'] ?? '';
        if (!is_string($postedApp) || !array_key_exists($postedApp, $apps)) {
            $flash = 'Unknown app.';
            $flashType = 'error';
        } elseif ($formAction === 'save_update_settings') {
            foreach ($fields as $f) {
                $value = trim($_POST[$f['key']] ?? '');
                if (in_array($f['type'], ['number'], true)) {
                    $value = (string) max(0, (int) $value);
                }
                set_setting($f['key'] . '_' . $postedApp, $value);
            }
            write_audit_log('admin', $admin['id'], 'app_settings_updated', ['app' => $postedApp]);
            $flash = ucfirst($postedApp) . ' app update settings saved.';
            $app = $postedApp;

            // Soft validation, after save — still saves whatever was
            // entered (an admin mid-rollout may intentionally pass
            // through an odd intermediate state), but flags the one
            // combination that's never correct: min_version above
            // latest_version would force-update every installed build
            // to a "latest" that's actually older than the floor being
            // enforced, which is always a typo, not a real intent.
            $minAfterSave = (int) get_setting('min_app_version_' . $postedApp, 0);
            $latestAfterSave = (int) get_setting('latest_app_version_' . $postedApp, 0);
            if ($minAfterSave > $latestAfterSave) {
                $flash .= ' Warning: minimum supported version (' . $minAfterSave
                    . ') is higher than the latest version (' . $latestAfterSave
                    . ') — every install will be forced to update against a target that\'s behind the floor. Double-check these numbers.';
                $flashType = 'error';
            }
        } elseif ($formAction === 'save_maintenance') {
            $enabled = isset($_POST['maintenance_enabled']) ? '1' : '0';
            $message = trim($_POST['maintenance_message'] ?? '');
            set_setting('maintenance_mode_' . $postedApp, $enabled);
            set_setting('maintenance_message_' . $postedApp, $message);
            write_audit_log('admin', $admin['id'], 'app_maintenance_updated', ['app' => $postedApp, 'enabled' => $enabled]);
            $flash = $enabled === '1'
                ? ucfirst($postedApp) . ' app put into maintenance mode.'
                : ucfirst($postedApp) . ' app taken out of maintenance mode.';
            $app = $postedApp;
        }
    }
}

$values = [];
foreach ($fields as $f) {
    $values[$f['key']] = get_setting($f['key'] . '_' . $app, '');
}
$maintenanceEnabled = get_setting('maintenance_mode_' . $app, '0') === '1';
$maintenanceMessage = get_setting('maintenance_message_' . $app, 'We\'re currently doing scheduled maintenance. Please check back shortly.');

$csrf = admin_csrf_token();
$pageTitle = 'App Settings';
$activeNav = 'app_settings_' . $app;
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>App Settings</h2>
    <p class="muted">
        Update-check and maintenance-mode settings, one tab per app.
        These feed <code>/api/v1/system/app-version.php</code>, which
        every app already calls at startup — no new endpoint needed for
        maintenance mode to reach the apps, only the Android side
        reading the new <code>maintenance_mode</code>/
        <code>maintenance_message</code> fields in that response is a
        separate, later change.
    </p>
    <div class="row-actions" style="margin-bottom:4px">
        <?php foreach ($apps as $key => $label): ?>
            <a class="btn <?= $app === $key ? 'btn-primary' : 'btn-outline' ?>" href="app-settings.php?app=<?= urlencode($key) ?>"><?= admin_escape($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h2>Update Check — <?= admin_escape($apps[$app]) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_update_settings">
        <input type="hidden" name="app" value="<?= admin_escape($app) ?>">
        <div class="form-grid">
            <?php foreach ($fields as $f): ?>
                <label style="flex:1;min-width:220px">
                    <?= admin_escape($f['label']) ?>
                    <?php if ($f['type'] === 'textarea'): ?>
                        <textarea name="<?= $f['key'] ?>" rows="2"><?= admin_escape((string) $values[$f['key']]) ?></textarea>
                    <?php else: ?>
                        <input type="<?= $f['type'] === 'number' ? 'number' : 'text' ?>" name="<?= $f['key'] ?>" value="<?= admin_escape((string) $values[$f['key']]) ?>" <?= $f['type'] === 'number' ? 'min="0"' : '' ?>>
                    <?php endif; ?>
                    <span class="muted" style="display:block;font-size:12px;font-weight:400;margin-top:3px"><?= admin_escape($f['help']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:12px">Save</button>
    </form>
</div>

<div class="card">
    <h2>Maintenance Mode — <?= admin_escape($apps[$app]) ?></h2>
    <p class="muted">Applies only to the <?= admin_escape($apps[$app]) ?>, not the other two.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_maintenance">
        <input type="hidden" name="app" value="<?= admin_escape($app) ?>">
        <label class="checkbox-row">
            <input type="checkbox" name="maintenance_enabled" <?= $maintenanceEnabled ? 'checked' : '' ?>>
            Put <?= admin_escape($apps[$app]) ?> into maintenance mode
        </label>
        <label style="display:block;margin-top:10px">Message shown to users while in maintenance
            <textarea name="maintenance_message" rows="2"><?= admin_escape((string) $maintenanceMessage) ?></textarea>
        </label>
        <button type="submit" class="btn <?= $maintenanceEnabled ? 'btn-outline danger' : 'btn-primary' ?>" style="margin-top:10px">Save</button>
    </form>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
