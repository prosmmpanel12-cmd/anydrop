<?php
/**
 * Anydrop — Admin Web UI: Area-wise Payment Method Restrictions (general)
 *
 * Implements recall.md Phase B item 15 — admin-controlled, coarse
 * "which payment methods are allowed in this area at all" gate.
 * Schema: backend/sql/37_migration_area_payment_restrictions.sql.
 * Enforcement: backend/lib/payment_restrictions.php (shared by
 * orders/create.php and customer/payment-methods.php) — this page
 * only manages the area_payment_restrictions table and the
 * platform-wide app_settings defaults; it never evaluates the rule
 * itself.
 *
 * DELIBERATELY SEPARATE from cod-rules.php (recall.md item 4/migration
 * 35): that page's area_cod_rules table is fine-grained COD-specific
 * eligibility (min prepaid orders, max amount, daily cap, new-customer
 * block) for a customer who's already allowed to see COD at all. THIS
 * page is the coarser on/off gate per method — e.g. a newly-launched
 * area kept prepaid-only until enough delivered-order trust exists, or
 * a cash-risk area where COD shouldn't be offered to anyone regardless
 * of their own order history. The two compose: a payment method must
 * clear this general gate first, and if it's COD, then also clear
 * area_cod_rules' finer checks.
 *
 * One rule row per service_areas node (city_village or area level,
 * same "both levels assignable" pattern as cod-rules.php/pricing-rules.php).
 * Gated on `areas_view`/`areas_edit` — same reasoning as those two
 * pages: fundamentally area-scoped config, no dedicated permission key
 * exists for it in migration 29's seed.
 *
 * Both methods can never be turned off together for one rule — an area
 * with no way to ever place an order is a misconfiguration, not a
 * valid state (same reasoning as migration 37's own DB-level CHECK
 * constraint; this form-level guard gives the admin an immediate
 * message instead of a raw SQL constraint-violation error).
 *
 * STATUS: 🆕 BUILT 2026-08-22 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox, same standing limitation as every other
 * session). Needs migration 37 run on the live DB, then a live
 * click-through: set platform defaults, add an area rule with COD
 * off, attempt a COD order from that area (confirm orders/create.php's
 * 422 + payment-methods.php's pre-check agree), attempt to save a rule
 * with both methods off (confirm it's rejected client-side with a
 * clear message, not a raw DB error).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'areas_view');
$canEdit = admin_has_permission($admin['id'], 'areas_edit');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to edit payment restrictions.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_defaults') {
            $upiAllowed = isset($_POST['default_upi_allowed']) ? '1' : '0';
            $codAllowed = isset($_POST['default_cod_allowed']) ? '1' : '0';

            if ($upiAllowed === '0' && $codAllowed === '0') {
                $flash = 'At least one payment method must stay allowed by default — the platform can\'t take orders otherwise.';
                $flashType = 'error';
            } else {
                $upd = $db->prepare('UPDATE app_settings SET `value` = :v WHERE `key` = :k');
                foreach ([
                    'default_upi_allowed' => $upiAllowed,
                    'default_cod_allowed' => $codAllowed,
                ] as $k => $v) {
                    $upd->execute(['k' => $k, 'v' => $v]);
                }
                write_audit_log('admin', $admin['id'], 'payment_restriction_defaults_updated', [
                    'default_upi_allowed' => $upiAllowed, 'default_cod_allowed' => $codAllowed,
                ]);
                $flash = 'Platform-wide payment method defaults updated.';
            }
        } elseif ($formAction === 'create_rule' || $formAction === 'update_rule') {
            $areaId = (int) ($_POST['area_id'] ?? 0);
            $upiAllowed = isset($_POST['upi_allowed']) ? 1 : 0;
            $codAllowed = isset($_POST['cod_allowed']) ? 1 : 0;

            if ($areaId <= 0) {
                $flash = 'Choose an area.';
                $flashType = 'error';
            } elseif ($upiAllowed === 0 && $codAllowed === 0) {
                $flash = 'At least one payment method must stay allowed — this area would otherwise have no way to place an order.';
                $flashType = 'error';
            } else {
                // One rule row per area (area_id UNIQUE) — same
                // "reuse an existing row rather than reject" pattern as
                // cod-rules.php/pricing-rules.php.
                $existing = $db->prepare('SELECT id FROM area_payment_restrictions WHERE area_id = :a LIMIT 1');
                $existing->execute(['a' => $areaId]);
                $existingRow = $existing->fetch();

                if ($existingRow) {
                    $upd = $db->prepare(
                        'UPDATE area_payment_restrictions SET upi_allowed = :ua, cod_allowed = :ca, is_active = 1 WHERE area_id = :a'
                    );
                    $upd->execute(['ua' => $upiAllowed, 'ca' => $codAllowed, 'a' => $areaId]);
                    write_audit_log('admin', $admin['id'], 'area_payment_restriction_updated', ['area_id' => $areaId]);
                    $flash = 'Payment restriction updated.';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO area_payment_restrictions (area_id, upi_allowed, cod_allowed, is_active)
                         VALUES (:a, :ua, :ca, 1)'
                    );
                    $ins->execute(['a' => $areaId, 'ua' => $upiAllowed, 'ca' => $codAllowed]);
                    write_audit_log('admin', $admin['id'], 'area_payment_restriction_created', ['area_id' => $areaId]);
                    $flash = 'Payment restriction added.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('UPDATE area_payment_restrictions SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_payment_restriction_toggled', ['rule_id' => $ruleId]);
            $flash = 'Rule status updated.';
        } elseif ($formAction === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('DELETE FROM area_payment_restrictions WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_payment_restriction_deleted', ['rule_id' => $ruleId]);
            $flash = 'Rule deleted.';
        }
    }
}

// ---------- Data for rendering ----------

$allRules = $db->query(
    'SELECT r.*, sa.name AS area_name, sa.level AS area_level
     FROM area_payment_restrictions r INNER JOIN service_areas sa ON sa.id = r.area_id
     ORDER BY sa.name'
)->fetchAll();

$areaOptions = $db->query(
    "SELECT id, name, level FROM service_areas WHERE level IN ('city_village','area') AND is_active = 1 ORDER BY name"
)->fetchAll();
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}
$areaBreadcrumb = [];
foreach ($areaOptions as $a) {
    $areaBreadcrumb[$a['id']] = admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)
        . ' (' . ($a['level'] === 'area' ? 'Area' : 'City/Village') . ')';
}
$areasWithRules = array_column($allRules, 'area_id');

$defaults = [
    'upi_allowed' => (bool) ((int) get_setting('default_upi_allowed', 1)),
    'cod_allowed' => (bool) ((int) get_setting('default_cod_allowed', 1)),
];

$csrf = admin_csrf_token();
$pageTitle = 'Payment Restrictions';
$activeNav = 'payment_restrictions';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Platform-wide Payment Method Defaults</h2>
    <p class="muted">Applied to any area with no rule of its own below, and to any customer whose location doesn't resolve to an area at all. This is a general on/off gate per method — for COD-specific rules (minimum prepaid orders, max amount, daily limit, new-customer block), see <a href="cod-rules.php">COD Rules</a> instead.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_defaults">
        <div class="form-grid">
            <label class="checkbox-row">
                <input type="checkbox" name="default_upi_allowed" <?= $defaults['upi_allowed'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                UPI (online) allowed by default
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="default_cod_allowed" <?= $defaults['cod_allowed'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                COD allowed by default
            </label>
        </div>
        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn-primary">Save Defaults</button>
        <?php endif; ?>
    </form>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <h2>Add Area Rule</h2>
    <?php $addableAreas = array_filter($areaOptions, fn($a) => !in_array((int) $a['id'], $areasWithRules, true)); ?>
    <?php if (empty($addableAreas)): ?>
        <p class="muted">Every area with coordinates already has a rule — edit one below, or <a href="areas.php">add more areas</a> first.</p>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="create_rule">
        <div class="form-grid">
            <label>Area
                <select name="area_id" required>
                    <option value="">— Choose area —</option>
                    <?php foreach ($addableAreas as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= admin_escape($areaBreadcrumb[$a['id']]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="checkbox-row"><input type="checkbox" name="upi_allowed" checked> UPI (online) allowed in this area</label>
            <label class="checkbox-row"><input type="checkbox" name="cod_allowed" checked> COD allowed in this area</label>
        </div>
        <button type="submit" class="btn btn-primary">Add Rule</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Area Rules</h2>
    <?php if (empty($allRules)): ?>
        <p class="muted">No area-specific rules yet — every area follows the platform defaults above.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Area</th><th>UPI</th><th>COD</th><th>Status</th><th></th></tr>
        <?php foreach ($allRules as $r): ?>
        <tr>
            <td><?= admin_escape($areaBreadcrumb[$r['area_id']] ?? $r['area_name']) ?></td>
            <td><span class="badge <?= $r['upi_allowed'] ? 'active' : 'inactive' ?>"><?= $r['upi_allowed'] ? 'Allowed' : 'Blocked' ?></span></td>
            <td><span class="badge <?= $r['cod_allowed'] ? 'active' : 'inactive' ?>"><?= $r['cod_allowed'] ? 'Allowed' : 'Blocked' ?></span></td>
            <td><span class="badge <?= $r['is_active'] ? 'active' : 'inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="row-actions">
                <?php if ($canEdit): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="toggle_active">
                        <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn btn-outline"><?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn btn-outline danger"
                            data-confirm-title="Delete this rule?"
                            data-confirm-text="This area will fall back to the platform-wide defaults."
                            data-confirm-ok-label="Delete">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
