<?php
/**
 * Anydrop — Admin Web UI: FCM / Push Notification Settings.
 *
 * WHY THIS PAGE EXISTS: lib/fcm.php originally required a physical
 * file, backend/config/firebase-service-account.json, to exist on the
 * server before any push notification could send. On a lot of hosts
 * this app actually runs on (shared hosting, a phone-based PHP server
 * — see the /storage/emulated/0/htdocs/ path in this project's own
 * error logs) there's no convenient way to drop an arbitrary file next
 * to the code, so the file was simply never there and every push
 * silently failed with "service account file missing" in the PHP
 * error log, with nothing visible in the admin panel to say so. This
 * page fixes both problems: paste the whole service-account JSON
 * here (saved to app_settings.fcm_service_account_json — the file
 * path still works as a fallback, see lib/fcm.php's own kdoc) and see
 * the last send attempt's outcome without having to find the server's
 * error log.
 *
 * SECURITY: the private key is never redisplayed after saving — only
 * project_id/client_email (both already visible on Firebase's own
 * console) are shown back as a "yes, this is the one you pasted"
 * confirmation, same masking spirit as email-providers.php's API keys.
 *
 * Gated on `settings_manage`, already seeded by migration 29 — no new
 * RBAC migration needed, same pattern payment-gateways.php and
 * email-providers.php already document for their own permission keys.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/fcm.php';

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

        if ($formAction === 'save_service_account') {
            $pasted = trim($_POST['service_account_json'] ?? '');
            if ($pasted === '') {
                $flash = 'Paste the service account JSON before saving.';
                $flashType = 'error';
            } else {
                $decoded = json_decode($pasted, true);
                if (!is_array($decoded) || empty($decoded['private_key']) || empty($decoded['client_email']) || empty($decoded['project_id'])) {
                    $flash = 'That doesn\'t look like a valid Firebase service-account JSON file — it must contain at least project_id, client_email and private_key.';
                    $flashType = 'error';
                } else {
                    set_setting('fcm_service_account_json', $pasted);
                    // A newly-pasted key invalidates any cached access
                    // token from the previous credentials — clear it so
                    // the very next send re-mints against the new key
                    // instead of reusing a token minted under the old one.
                    set_setting('fcm_access_token', '');
                    set_setting('fcm_access_token_expires_at', '0');
                    write_audit_log('admin', $admin['id'], 'fcm_service_account_updated', [
                        'project_id' => $decoded['project_id'],
                        'client_email' => $decoded['client_email'],
                    ]);
                    $flash = 'FCM service account saved for project "' . $decoded['project_id'] . '".';
                }
            }
        } elseif ($formAction === 'clear_service_account') {
            set_setting('fcm_service_account_json', '');
            set_setting('fcm_access_token', '');
            set_setting('fcm_access_token_expires_at', '0');
            write_audit_log('admin', $admin['id'], 'fcm_service_account_cleared', []);
            $flash = 'FCM service account cleared.';
        } elseif ($formAction === 'send_test') {
            $testToken = trim($_POST['test_token'] ?? '');
            if ($testToken === '') {
                $flash = 'Paste a device FCM token to send a test push to.';
                $flashType = 'error';
            } else {
                $ok = fcm_send_to_token($testToken, 'Anydrop test push', 'If you can see this, FCM is working.', ['type' => 'admin_test']);
                $flash = $ok ? 'Test push sent — check the device.' : 'Test push failed — see the status panel below for why.';
                $flashType = $ok ? 'success' : 'error';
                write_audit_log('admin', $admin['id'], 'fcm_test_push_sent', ['success' => $ok]);
            }
        }
    }
}

$currentJson = get_setting('fcm_service_account_json', '');
$currentDecoded = null;
if (is_string($currentJson) && trim($currentJson) !== '') {
    $decoded = json_decode($currentJson, true);
    if (is_array($decoded)) {
        $currentDecoded = $decoded;
    }
}
$usingFileFallback = $currentDecoded === null && file_exists(__DIR__ . '/../config/firebase-service-account.json');

$lastStatus = get_setting('fcm_last_status');
$lastMessage = get_setting('fcm_last_message');
$lastCheckedAt = get_setting('fcm_last_checked_at');

$csrf = admin_csrf_token();
$pageTitle = 'FCM Settings';
$activeNav = 'fcm_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>FCM / Push Notification Settings</h2>
    <p class="muted">
        Powers push notifications in the Customer, Restaurant, and
        Rider apps (new orders, order status changes, offers, admin
        broadcasts). Paste the Firebase project's service-account JSON
        below — download it from Firebase Console → Project Settings →
        Service accounts → Generate new private key. Nothing here is
        ever committed to the codebase or shown back in full once saved.
    </p>

    <?php if ($currentDecoded): ?>
        <p><span class="badge active">Configured</span>
            Project <strong><?= admin_escape($currentDecoded['project_id'] ?? '—') ?></strong>
            via <strong><?= admin_escape($currentDecoded['client_email'] ?? '—') ?></strong>
        </p>
    <?php elseif ($usingFileFallback): ?>
        <p><span class="badge active">Configured (file fallback)</span>
            Using config/firebase-service-account.json on disk — paste it below instead to move it into the database.</p>
    <?php else: ?>
        <p><span class="badge inactive">Not configured</span> Push notifications will silently fail until this is set.</p>
    <?php endif; ?>

    <?php if ($lastStatus !== null): ?>
    <div class="muted" style="margin:10px 0;padding:10px;border-radius:8px;background:var(--bg-subtle,rgba(128,128,128,0.08));">
        Last attempt: <span class="badge <?= $lastStatus === 'ok' ? 'active' : 'inactive' ?>"><?= $lastStatus === 'ok' ? 'OK' : 'Failed' ?></span>
        <?= admin_escape((string) $lastMessage) ?>
        <?php if ($lastCheckedAt): ?> — <?= admin_escape((string) $lastCheckedAt) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_service_account">
        <label class="field-label" for="sa-json">Service account JSON</label>
        <textarea id="sa-json" name="service_account_json" rows="8" placeholder='{ "type": "service_account", "project_id": "...", "private_key": "...", "client_email": "...", ... }'></textarea>
        <p class="muted" style="margin-top:6px">Paste the entire downloaded .json file's contents here, exactly as-is.</p>
        <button type="submit" class="btn btn-primary" style="margin-top:8px">Save</button>
        <?php if ($currentDecoded): ?>
        <button type="submit" form="clear-form" class="btn btn-outline danger" style="margin-top:8px">Clear saved key</button>
        <?php endif; ?>
    </form>
    <form id="clear-form" method="post" onsubmit="return confirm('Remove the saved FCM service account? Push notifications will stop working until a new one is saved.');" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="clear_service_account">
    </form>
</div>

<div class="card">
    <h2>Send a test push</h2>
    <p class="muted">Grab a real device's FCM token (visible in that app's own debug/log output, or from the customers/restaurants/riders table's fcm_token column) and send it a one-off test notification.</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="send_test">
        <label style="flex:1;min-width:260px">Device FCM token
            <input type="text" name="test_token" placeholder="paste token here" required>
        </label>
        <button type="submit" class="btn btn-outline">Send test</button>
    </form>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
