<?php
/**
 * Anydrop — Admin Web UI: All Settings (Full List).
 *
 * WHY THIS PAGE EXISTS: app-owner ask, 2026-09-12 — "sari default
 * values ke liye sabhi app ke setting table mai different colum bnao
 * taki dekh/check kar shke" (for all default values, make them
 * checkable in the settings table). Every setting this app reads via
 * get_setting($key, $default) only ever showed up in the database once
 * something actually saved a row for it — otherwise it silently used
 * its PHP-code fallback with nothing to look at anywhere in the admin
 * panel. Migration 85 (sql/85_migration_seed_all_setting_defaults.sql)
 * seeded a row for every such key using its own code default, so this
 * page has something to show for all of them, not just the ones a
 * topic-specific page (commission-rules.php, otp-settings.php, etc.)
 * happened to have already saved.
 *
 * This is a GENERIC key/value editor — every row in app_settings,
 * editable inline, no per-key validation beyond "not empty" (a topic
 * page like otp-settings.php or rider-earnings.php still exists for
 * anything that needs real validation/range-checking/business rules;
 * this page is the "see everything, quick-fix anything" catch-all, not
 * a replacement for those). Search box filters by key/description
 * client-side-free (simple server-side LIKE) since the full list can
 * run to 60+ rows.
 *
 * Gated on `settings_manage`, same as every other page in this
 * "Settings" nav group.
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
    } elseif (($_POST['form_action'] ?? '') === 'save_value') {
        $key = trim((string) ($_POST['key'] ?? ''));
        $value = (string) ($_POST['value'] ?? '');
        if ($key === '') {
            $flash = 'Missing key.';
            $flashType = 'error';
        } else {
            set_setting($key, $value);
            write_audit_log('admin', $admin['id'], 'setting_updated', [
                'key' => $key, 'via' => 'all_settings_page',
            ]);
            $flash = "Saved \"{$key}\".";
        }
    }
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT `key`, `value`, description FROM app_settings';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE `key` LIKE :q OR description LIKE :q';
    $params['q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY `key`';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$csrf = admin_csrf_token();
$pageTitle = 'All Settings';
$activeNav = 'all_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>All Settings — Full List</h2>
    <p class="muted">Every row in <code>app_settings</code>, in one place — the value shown is exactly what <code>get_setting()</code> returns to every part of the app right now. Saving here writes directly to that same table (same <code>set_setting()</code> every other settings page uses), so a change here takes effect immediately, no redeploy. For settings with dedicated pages elsewhere (commission, OTP, rider earnings, pricing rules, COD rules, etc.) prefer editing there — this page skips the extra validation/explanation those pages give, it's the quick catch-all view.</p>
    <form method="get" class="filter-row">
        <input type="text" name="q" placeholder="Search key or description" value="<?= admin_escape($search) ?>">
        <button type="submit" class="btn btn-outline">Search</button>
        <?php if ($search !== ''): ?>
            <a href="all-settings.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (empty($rows)): ?>
        <p class="muted">No settings found<?= $search !== '' ? ' for that search' : '' ?>.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th style="width:26%;">Key</th><th style="width:34%;">Value</th><th>Description</th><th style="width:80px;"></th></tr>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><code><?= admin_escape($row['key']) ?></code></td>
            <td>
                <form method="post" style="display:flex; gap:6px; align-items:flex-start;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="save_value">
                    <input type="hidden" name="key" value="<?= admin_escape($row['key']) ?>">
                    <?php if (strlen((string) $row['value']) > 60): ?>
                        <textarea name="value" rows="2" style="flex:1; min-width:160px;"><?= admin_escape($row['value']) ?></textarea>
                    <?php else: ?>
                        <input type="text" name="value" value="<?= admin_escape($row['value']) ?>" style="flex:1; min-width:120px;">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-outline">Save</button>
                </form>
            </td>
            <td class="muted"><?= $row['description'] ? admin_escape($row['description']) : '—' ?></td>
            <td class="muted">&nbsp;</td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p class="muted" style="margin-top:12px;margin-bottom:0;"><?= count($rows) ?> setting(s)<?= $search !== '' ? ' matching "' . admin_escape($search) . '"' : ' total' ?>.</p>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
