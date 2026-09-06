<?php
/**
 * Anydrop — Admin Web UI: Live Tracking Route Recalculation Settings.
 *
 * WHY THIS PAGE EXISTS: doc 91/92 (progress-trim route line +
 * deviation-based recalc, 2026-09-05) added three platform-wide
 * app_settings keys read by api/v1/orders/route.php's
 * with_route_config() on every response:
 *   - rider_route_deviation_threshold_m       (default 70)
 *   - rider_route_deviation_sustain_seconds   (default 60)
 *   - rider_route_max_recalc_interval_seconds (default 90)
 * Doc 92 explicitly flagged "no admin-panel UI added yet for these
 * three keys ... same fast-follow gap google_directions_api_key had"
 * — this page is that fast-follow, same "one small page for a
 * platform-wide numeric trio" shape directions-settings.php already
 * established rather than forcing these into app-settings.php's
 * per-app-suffixed $fields array (they're shared across all apps,
 * not customer/restaurant/rider-specific).
 *
 * Gated on `settings_manage`, already seeded by migration 29 — no new
 * RBAC migration needed, same as directions-settings.php.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'settings_manage');
$db = Database::get();

// Key => [label, unit, default, min, max, help]
// Bounds are sanity rails, not guesses at the "right" tuned value —
// doc 92 item 1 says the defaults themselves are TBD/to-be-tuned
// after watching a real rider's GPS drift, not by further guessing
// here. These bounds just stop an admin from saving something that
// would break the feature outright (e.g. 0s sustain firing on every
// GPS jitter sample, or a negative threshold).
$fieldsSpec = [
    'rider_route_deviation_threshold_m' => [
        'label' => 'Deviation threshold',
        'unit' => 'metres',
        'default' => 70,
        'min' => 5,
        'max' => 2000,
        'help' => 'How far off the drawn route the rider\'s real GPS position must be before it counts as "deviated". Too low and normal GPS jitter or a wide road will trigger recalcs; too high and a genuine wrong-turn won\'t be caught quickly.',
    ],
    'rider_route_deviation_sustain_seconds' => [
        'label' => 'Deviation sustain time',
        'unit' => 'seconds',
        'default' => 60,
        'min' => 5,
        'max' => 600,
        'help' => 'How long the rider must stay past the deviation threshold, continuously, before a recalculation is triggered. A momentary in-threshold GPS sample resets this timer — this is what stops a single noisy reading from firing an unnecessary recalc.',
    ],
    'rider_route_max_recalc_interval_seconds' => [
        'label' => 'Max recalc interval (fallback ceiling)',
        'unit' => 'seconds',
        'default' => 90,
        'min' => 15,
        'max' => 1800,
        'help' => 'Fallback: even with no deviation, the route is re-fetched at least this often, so a very stale ETA/polyline can\'t linger indefinitely. Deviation-triggered recalcs reset this timer too — this is a ceiling alongside deviation-triggering, not a replacement for it.',
    ],
];

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (($_POST['form_action'] ?? '') === 'save') {
        $errors = [];
        $toSave = [];

        foreach ($fieldsSpec as $key => $spec) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            if ($raw === '' || !is_numeric($raw)) {
                $errors[] = $spec['label'] . ' must be a number.';
                continue;
            }
            $num = $raw + 0; // int or float depending on input
            if ($num < $spec['min'] || $num > $spec['max']) {
                $errors[] = sprintf(
                    '%s must be between %s and %s %s.',
                    $spec['label'], $spec['min'], $spec['max'], $spec['unit']
                );
                continue;
            }
            $toSave[$key] = $num;
        }

        if ($errors) {
            $flash = implode(' ', $errors) . ' Nothing was saved.';
            $flashType = 'error';
        } else {
            foreach ($toSave as $key => $num) {
                set_setting($key, (string) $num);
            }
            write_audit_log('admin', $admin['id'], 'route_recalc_settings_updated', $toSave);
            $flash = 'Route recalculation settings saved. Takes effect on the next route fetch — no app redeploy needed.';
        }
    } elseif (($_POST['form_action'] ?? '') === 'reset_defaults') {
        foreach ($fieldsSpec as $key => $spec) {
            set_setting($key, (string) $spec['default']);
        }
        write_audit_log('admin', $admin['id'], 'route_recalc_settings_reset', []);
        $flash = 'Route recalculation settings reset to defaults.';
    }
}

$current = [];
foreach ($fieldsSpec as $key => $spec) {
    $current[$key] = get_setting($key, $spec['default']);
}

$csrf = admin_csrf_token();
$pageTitle = 'Route Recalculation Settings';
$activeNav = 'route_recalc_settings';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Live Tracking — Route Recalculation</h2>
    <p class="muted">
        Controls how the customer app's order-tracking map decides when
        to re-fetch the rider's route line, on top of the existing
        smooth progress-trim animation. Read by
        <code>api/v1/orders/route.php</code>'s <code>with_route_config()</code>
        on every response and picked up by the Android app on its next
        successful route fetch — no rebuild or redeploy needed to
        change these.
    </p>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save">

        <?php foreach ($fieldsSpec as $key => $spec): ?>
        <div style="margin-bottom:18px">
            <label class="field-label" for="<?= admin_escape($key) ?>">
                <?= admin_escape($spec['label']) ?> (<?= admin_escape($spec['unit']) ?>)
            </label>
            <input
                type="number"
                id="<?= admin_escape($key) ?>"
                name="<?= admin_escape($key) ?>"
                value="<?= admin_escape((string) $current[$key]) ?>"
                min="<?= admin_escape((string) $spec['min']) ?>"
                max="<?= admin_escape((string) $spec['max']) ?>"
                step="1"
                style="width:100%;max-width:220px"
            >
            <p class="muted" style="margin-top:4px;max-width:640px">
                <?= admin_escape($spec['help']) ?>
                Default: <?= admin_escape((string) $spec['default']) ?> <?= admin_escape($spec['unit']) ?>.
                Allowed range: <?= admin_escape((string) $spec['min']) ?>–<?= admin_escape((string) $spec['max']) ?>.
            </p>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="btn btn-primary">Save</button>
        <button type="submit" form="reset-form" class="btn btn-outline" style="margin-left:8px">Reset to defaults</button>
    </form>
    <form id="reset-form" method="post" onsubmit="return confirm('Reset all three route recalculation settings to their defaults (70m / 60s / 90s)?');" style="display:none">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="reset_defaults">
    </form>

    <p class="muted" style="margin-top:16px">
        These three numbers are intentionally left un-tuned defaults
        (per doc 91/92) — adjust after watching real rider GPS
        behaviour in the field, not by guessing further here.
    </p>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
