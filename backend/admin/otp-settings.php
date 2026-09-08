<?php
/**
 * Anydrop — Admin Web UI: OTP Settings.
 *
 * WHY THIS PAGE EXISTS: docs/111 (§16 re-audit, 2026-09-06) flagged
 * `otp_max_attempts` as a real `app_settings` row that every OTP flow
 * in the codebase already reads consistently
 * (`api/v1/rider/orders-deliver.php`, `api/v1/auth/rider-verify-otp.php`,
 * `api/v1/auth/customer-verify-otp.php`, `api/v1/auth/restaurant-verify-otp.php`)
 * but that had no admin-panel field anywhere — changing it meant a
 * direct SQL update. Same "one small page for a platform-wide setting
 * with no single app/page owner" shape route-recalc-settings.php and
 * directions-settings.php already established, rather than forcing
 * this into app-settings.php's per-app-suffixed $fields array (this
 * key is shared across delivery OTP AND every login OTP flow — auth
 * for all three apps, not customer/restaurant/rider-specific, and not
 * delivery-specific either despite admin/orders.php being the page
 * that most recently grew OTP-adjacent UI).
 *
 * Deliberately just this one field, not a bundle with `otp_length` or
 * `otp_required_for_cod` — docs/111 only flagged `otp_max_attempts`
 * as the one with no owner-visible admin UI at all; the other two
 * remain their own separate (pre-existing, not new) gap if the owner
 * wants them added later.
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

// Sanity rails, not a guess at the "right" tuned value — a wrong OTP
// really can lock an order/login after this many tries (see
// orders-deliver.php's own kdoc), so 1 is allowed (immediate lock on
// any wrong guess is a legitimate strict setting) but 0 is not (that
// would lock every order/login on its very first attempt, including a
// correct one that just hasn't been checked yet — otp_attempts starts
// at 0 and the check is `>= max`). Upper bound is generous headroom,
// not a recommendation.
$defaultMaxAttempts = 3;
$minAttempts = 1;
$maxAttempts = 20;

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (($_POST['form_action'] ?? '') === 'save') {
        $raw = trim((string) ($_POST['otp_max_attempts'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            $flash = 'Max attempts must be a whole number.';
            $flashType = 'error';
        } elseif ((int) $raw < $minAttempts || (int) $raw > $maxAttempts) {
            $flash = "Max attempts must be between {$minAttempts} and {$maxAttempts}.";
            $flashType = 'error';
        } else {
            set_setting('otp_max_attempts', (string) (int) $raw);
            write_audit_log('admin', $admin['id'], 'otp_settings_updated', [
                'otp_max_attempts' => (int) $raw,
            ]);
            $flash = 'OTP settings saved. Takes effect on the next OTP check — no app redeploy needed.';
        }
    } elseif (($_POST['form_action'] ?? '') === 'reset_default') {
        set_setting('otp_max_attempts', (string) $defaultMaxAttempts);
        write_audit_log('admin', $admin['id'], 'otp_settings_reset', ['otp_max_attempts' => $defaultMaxAttempts]);
        $flash = 'OTP max attempts reset to the default (' . $defaultMaxAttempts . ').';
    }
}

$currentMaxAttempts = (int) get_setting('otp_max_attempts', $defaultMaxAttempts);

$csrf = admin_csrf_token();
$pageTitle = 'OTP Settings';
$activeNav = 'otp_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>OTP — Max wrong attempts</h2>
    <p class="muted">
        Shared across every OTP flow in the app — delivery OTP
        (<code>api/v1/rider/orders-deliver.php</code>) and login OTP
        for all three apps (<code>api/v1/auth/*-verify-otp.php</code>)
        all read this same setting; there's no separate delivery-only
        knob. Raising or lowering it here changes the lockout point
        for OTP checks going forward — it doesn't retroactively
        change anything already recorded on an order or login attempt
        already in progress.
    </p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save">

        <div style="margin-bottom:18px">
            <label class="field-label" for="otp_max_attempts">Max wrong attempts before lockout</label>
            <input
                type="number"
                id="otp_max_attempts"
                name="otp_max_attempts"
                value="<?= admin_escape((string) $currentMaxAttempts) ?>"
                min="<?= admin_escape((string) $minAttempts) ?>"
                max="<?= admin_escape((string) $maxAttempts) ?>"
                step="1"
                style="width:100%;max-width:220px"
            >
            <p class="muted" style="margin-top:4px;max-width:640px">
                Once an order's or login's wrong-attempt count reaches this
                number, further checks are rejected outright — a
                delivery order stays at "out for delivery" until an
                admin resolves it from Order Control (Reset attempts /
                Force-deliver), and a login OTP requires a fresh code
                to be requested.
                Default: <?= admin_escape((string) $defaultMaxAttempts) ?>.
                Allowed range: <?= admin_escape((string) $minAttempts) ?>–<?= admin_escape((string) $maxAttempts) ?>.
            </p>
        </div>

        <button type="submit" class="btn btn-primary">Save</button>
        <button type="submit" form="reset-form" class="btn btn-outline" style="margin-left:8px">Reset to default</button>
    </form>
    <form id="reset-form" method="post" onsubmit="return confirm('Reset OTP max attempts to the default (<?= admin_escape((string) $defaultMaxAttempts) ?>)?');" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="reset_default">
    </form>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
