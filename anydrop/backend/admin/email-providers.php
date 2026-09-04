<?php
/**
 * Anydrop — Admin Web UI: Email Providers
 *
 * Implements AnyDrop_Email_OTP_MultiProvider_Plan.md §6/§24 Phase 6 +
 * docs/19 §7's "Admin screen (Email Providers)" ask: list with
 * priority, Enable/Disable, per-driver config form (API key never
 * shown back in full after saving — masked to last 4 chars), Test
 * button (real send to an admin-entered address), usage bars, and
 * recent failed deliveries.
 *
 * Same shape as admin/payment-gateways.php on purpose (single
 * server-rendered page, POST-to-self, CSRF token, audit log) — this
 * codebase's established admin-page pattern, not a separate JSON
 * admin/api/*.php surface (there isn't one for any other admin screen
 * either — see admin/_bootstrap.php's note on why).
 *
 * Gated on `email_providers_manage`, already seeded by migration 29 —
 * no new RBAC migration needed, same as payment-gateways.php's own
 * comment about payment_providers_manage.
 *
 * Requires migration 67 (backend/sql/67_migration_email_otp_providers.sql)
 * to have been run first.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/email_otp/SecretManager.php';
require_once __DIR__ . '/../lib/email_otp/ProviderRegistry.php';

$admin = admin_require_login();
admin_require_permission($admin, 'email_providers_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

/** Per-driver_key: which config fields to render, and whether each is a secret. */
function email_provider_field_schema(string $driverKey): array
{
    $common = [
        ['key' => 'sender_email', 'label' => 'Sender email', 'secret' => false, 'placeholder' => 'noreply@anydrop.in'],
        ['key' => 'sender_name', 'label' => 'Sender name', 'secret' => false, 'placeholder' => 'AnyDrop'],
    ];
    if ($driverKey === 'mailjet') {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'placeholder' => ''],
            ['key' => 'api_secret', 'label' => 'API Secret', 'secret' => true, 'placeholder' => ''],
            ...$common,
        ];
    }
    return [
        ['key' => 'api_key', 'label' => 'API Key', 'secret' => true, 'placeholder' => ''],
        ...$common,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_provider') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM email_otp_providers WHERE id = :id');
            $stmt->execute(['id' => $providerId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $existingConfig = json_decode($existing['config_json'] ?? '{}', true) ?: [];
                $schema = email_provider_field_schema($existing['driver_key']);

                $newConfig = [];
                foreach ($schema as $field) {
                    $posted = trim($_POST['cfg_' . $field['key']] ?? '');
                    if ($field['secret']) {
                        if ($posted !== '') {
                            // Only overwrite a saved key when the admin actually
                            // typed a new one — the field is always rendered
                            // blank (masked placeholder only), so blank means
                            // "keep the existing key", never "clear it".
                            $newConfig[$field['key']] = SecretManager::encrypt($posted);
                        } else {
                            $newConfig[$field['key']] = $existingConfig[$field['key']] ?? '';
                        }
                    } else {
                        $newConfig[$field['key']] = $posted;
                    }
                }

                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $priority = max(0, (int) ($_POST['priority'] ?? 0));
                $dailyQuota = trim($_POST['daily_quota'] ?? '') === '' ? null : max(0, (int) $_POST['daily_quota']);
                $monthlyQuota = trim($_POST['monthly_quota'] ?? '') === '' ? null : max(0, (int) $_POST['monthly_quota']);

                $upd = $db->prepare(
                    'UPDATE email_otp_providers
                     SET config_json = :cfg, is_active = :active, priority = :pr,
                         daily_quota = :dq, monthly_quota = :mq
                     WHERE id = :id'
                );
                $upd->execute([
                    'cfg' => json_encode($newConfig),
                    'active' => $isActive,
                    'pr' => $priority,
                    'dq' => $dailyQuota,
                    'mq' => $monthlyQuota,
                    'id' => $providerId,
                ]);

                write_audit_log('admin', $admin['id'], 'email_provider_updated', [
                    'provider_id' => $providerId,
                    'driver_key' => $existing['driver_key'],
                    'is_active' => $isActive,
                    'priority' => $priority,
                    // never log key material, only whether one was (re)typed
                    'key_updated' => trim($_POST['cfg_api_key'] ?? '') !== '',
                ]);
                $flash = $existing['name'] . ' updated.';
            }
        } elseif ($formAction === 'reset_usage') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $upd = $db->prepare(
                'UPDATE email_otp_providers SET daily_used = 0, monthly_used = 0, consecutive_failures = 0 WHERE id = :id'
            );
            $upd->execute(['id' => $providerId]);
            write_audit_log('admin', $admin['id'], 'email_provider_usage_reset', ['provider_id' => $providerId]);
            $flash = 'Usage counters reset.';
        } elseif ($formAction === 'test_send') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $testEmail = trim($_POST['test_email'] ?? '');

            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $flash = 'Enter a valid email address to send the test to.';
                $flashType = 'error';
            } else {
                $stmt = $db->prepare('SELECT * FROM email_otp_providers WHERE id = :id');
                $stmt->execute(['id' => $providerId]);
                $row = $stmt->fetch();

                $driverMap = [
                    'resend' => ResendProvider::class, 'brevo' => BrevoProvider::class,
                    'mailersend' => MailerSendProvider::class, 'sendix' => SendixProvider::class,
                    'maileroo' => MailerooProvider::class, 'mailjet' => MailjetProvider::class,
                ];

                if (!$row || !isset($driverMap[$row['driver_key']])) {
                    $flash = 'Unknown provider.';
                    $flashType = 'error';
                } else {
                    $config = json_decode($row['config_json'] ?? '{}', true) ?: [];
                    foreach (['api_key', 'api_secret'] as $f) {
                        if (!empty($config[$f])) {
                            $config[$f] = SecretManager::decrypt($config[$f]);
                        }
                    }
                    $driverClass = $driverMap[$row['driver_key']];
                    $driver = new $driverClass();
                    $result = $driver->send(
                        $testEmail,
                        'AnyDrop — Email Provider Test',
                        '<p>This is a test email from the AnyDrop Admin Panel confirming the <strong>' . htmlspecialchars($row['name']) . '</strong> provider is working.</p>',
                        'This is a test email from the AnyDrop Admin Panel confirming the ' . $row['name'] . ' provider is working.',
                        $config
                    );

                    // Test sends are logged the same as real OTP attempts
                    // (purpose = 'admin_test') so they show up in the
                    // usage/health picture, but deliberately do NOT
                    // increment daily/monthly quota counters — a test
                    // send shouldn't eat into a provider's real OTP quota.
                    $logStmt = $db->prepare(
                        'INSERT INTO email_otp_logs (provider_id, recipient_email, purpose, status, error_reason, provider_http_status, provider_message_id, attempt_number)
                         VALUES (:pid, :email, :purpose, :status, :reason, :http, :mid, 1)'
                    );
                    $logStmt->execute([
                        'pid' => $providerId,
                        'email' => $testEmail,
                        'purpose' => 'admin_test',
                        'status' => $result->success ? 'sent' : 'failed',
                        'reason' => $result->errorType,
                        'http' => $result->httpStatus,
                        'mid' => $result->providerMessageId,
                    ]);

                    if ($result->success) {
                        $db->prepare('UPDATE email_otp_providers SET last_success_at = NOW(), consecutive_failures = 0 WHERE id = :id')->execute(['id' => $providerId]);
                        $flash = 'Test email sent successfully via ' . $row['name'] . '.';
                        $flashType = 'success';
                    } else {
                        $db->prepare('UPDATE email_otp_providers SET last_failure_at = NOW(), consecutive_failures = consecutive_failures + 1 WHERE id = :id')->execute(['id' => $providerId]);
                        $flash = 'Test send failed via ' . $row['name'] . ': ' . $result->errorMessage;
                        $flashType = 'error';
                    }
                    write_audit_log('admin', $admin['id'], 'email_provider_tested', [
                        'provider_id' => $providerId, 'success' => $result->success, 'error_type' => $result->errorType,
                    ]);
                }
            }
        }
    }
}

$providers = $db->query('SELECT * FROM email_otp_providers ORDER BY priority ASC, id ASC')->fetchAll();

$recentFailures = $db->query(
    "SELECT l.*, p.name AS provider_name
     FROM email_otp_logs l
     LEFT JOIN email_otp_providers p ON p.id = l.provider_id
     WHERE l.status = 'failed'
     ORDER BY l.created_at DESC
     LIMIT 25"
)->fetchAll();

$statsRow = $db->query(
    "SELECT
        SUM(status = 'sent') AS sent_count,
        SUM(status = 'failed') AS failed_count
     FROM email_otp_logs
     WHERE created_at >= NOW() - INTERVAL 7 DAY"
)->fetch();
$sentCount = (int) ($statsRow['sent_count'] ?? 0);
$failedCount = (int) ($statsRow['failed_count'] ?? 0);
$successRate = ($sentCount + $failedCount) > 0 ? round($sentCount / ($sentCount + $failedCount) * 100, 1) : null;

$csrf = admin_csrf_token();
$pageTitle = 'Email Providers';
$activeNav = 'email_providers';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Email Providers</h2>
    <p class="muted">
        Customer and Restaurant OTP emails are sent through this priority
        list — first active, under-quota provider is tried first;
        anything retryable (rate limit, timeout, 5xx, auth failure)
        automatically falls through to the next one. Client apps never
        call any of these providers directly and never see API keys.
    </p>
    <p class="muted">
        7-day delivery: <strong><?= $sentCount ?></strong> sent,
        <strong><?= $failedCount ?></strong> failed
        <?php if ($successRate !== null): ?> — <strong><?= $successRate ?>%</strong> success rate<?php endif; ?>.
    </p>
</div>

<?php foreach ($providers as $p): ?>
<?php
$cfg = json_decode($p['config_json'] ?? '{}', true) ?: [];
$schema = email_provider_field_schema($p['driver_key']);
$dailyPct = $p['daily_quota'] ? min(100, round(((int) $p['daily_used'] / max(1, (int) $p['daily_quota'])) * 100)) : null;
$monthlyPct = $p['monthly_quota'] ? min(100, round(((int) $p['monthly_used'] / max(1, (int) $p['monthly_quota'])) * 100)) : null;
?>
<div class="card">
    <h2>
        <?= admin_escape($p['name']) ?>
        <span class="badge <?= $p['is_active'] ? 'active' : 'inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
        <span class="muted" style="font-weight:400;font-size:13px">priority <?= (int) $p['priority'] ?></span>
        <?php if ((int) $p['consecutive_failures'] >= 3): ?>
            <span class="badge inactive">⚠ <?= (int) $p['consecutive_failures'] ?> failures in a row</span>
        <?php endif; ?>
    </h2>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_provider">
        <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
        <div class="form-grid">
            <?php foreach ($schema as $field): ?>
                <?php if ($field['secret']): ?>
                <label><?= admin_escape($field['label']) ?>
                    <?php $existingVal = $cfg[$field['key']] ?? ''; ?>
                    <input type="password" name="cfg_<?= $field['key'] ?>" value=""
                        placeholder="<?= $existingVal !== '' ? admin_escape(SecretManager::mask(SecretManager::decrypt($existingVal))) . ' — leave blank to keep' : 'Not set' ?>"
                        autocomplete="new-password">
                </label>
                <?php else: ?>
                <label><?= admin_escape($field['label']) ?>
                    <input type="text" name="cfg_<?= $field['key'] ?>" value="<?= admin_escape($cfg[$field['key']] ?? '') ?>" placeholder="<?= admin_escape($field['placeholder']) ?>">
                </label>
                <?php endif; ?>
            <?php endforeach; ?>

            <label>Priority (lower = tried first)
                <input type="number" name="priority" min="0" value="<?= (int) $p['priority'] ?>">
            </label>
            <label>Daily quota (blank = unlimited)
                <input type="number" name="daily_quota" min="0" value="<?= $p['daily_quota'] !== null ? (int) $p['daily_quota'] : '' ?>">
            </label>
            <label>Monthly quota (blank = unlimited)
                <input type="number" name="monthly_quota" min="0" value="<?= $p['monthly_quota'] !== null ? (int) $p['monthly_quota'] : '' ?>">
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>>
                Active
            </label>
        </div>

        <?php if ($dailyPct !== null || $monthlyPct !== null): ?>
        <div class="muted" style="margin:10px 0">
            <?php if ($dailyPct !== null): ?>Daily usage: <?= (int) $p['daily_used'] ?>/<?= (int) $p['daily_quota'] ?> (<?= $dailyPct ?>%)<br><?php endif; ?>
            <?php if ($monthlyPct !== null): ?>Monthly usage: <?= (int) $p['monthly_used'] ?>/<?= (int) $p['monthly_quota'] ?> (<?= $monthlyPct ?>%)<?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>

    <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="test_send">
        <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
        <input type="email" name="test_email" placeholder="send test to..." required style="flex:1;min-width:200px">
        <button type="submit" class="btn btn-outline">Test</button>
    </form>

    <form method="post" style="margin-top:6px">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="reset_usage">
        <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
        <button type="submit" class="btn btn-outline" onclick="return confirm('Reset usage counters for <?= admin_escape($p['name']) ?>?')">Reset usage counters</button>
    </form>
</div>
<?php endforeach; ?>

<div class="card">
    <h2>Recent Delivery Failures</h2>
    <?php if (empty($recentFailures)): ?>
        <p class="muted">No failed deliveries logged yet.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>When</th><th>Provider</th><th>Recipient</th><th>Purpose</th><th>Reason</th><th>HTTP</th></tr></thead>
            <tbody>
            <?php foreach ($recentFailures as $log): ?>
                <tr>
                    <td><?= admin_escape($log['created_at']) ?></td>
                    <td><?= admin_escape($log['provider_name'] ?? '—') ?></td>
                    <td><?= admin_escape($log['recipient_email']) ?></td>
                    <td><?= admin_escape($log['purpose']) ?></td>
                    <td><?= admin_escape($log['error_reason'] ?? '') ?></td>
                    <td><?= $log['provider_http_status'] !== null ? (int) $log['provider_http_status'] : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
