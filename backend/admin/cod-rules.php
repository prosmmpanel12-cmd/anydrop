<?php
/**
 * Anydrop — Admin Web UI: Area-wise COD Rules
 *
 * Implements recall.md item 4 — admin-controlled COD eligibility rules
 * per service area, never hardcoded in the Customer App. Schema:
 * backend/sql/35_migration_area_cod_rules.sql. Enforcement:
 * backend/lib/cod_rules.php (shared by orders/create.php and
 * customer/cod-eligibility.php) — this page only manages the
 * area_cod_rules table and the platform-wide app_settings defaults;
 * it never evaluates the rule itself.
 *
 * One rule row per service_areas node (city_village or area level,
 * same "both levels assignable" pattern as banners.php's area
 * targeting) — a node with no row here falls back to the platform-wide
 * defaults set in the "Platform Defaults" card at the top of this page.
 * Deactivating a rule (rather than deleting it) is the same "disable
 * without discarding config" pattern areas.php/banners.php already use.
 *
 * Gated on `areas_view`/`areas_edit` — same keys as Service Areas
 * (areas.php), since this is fundamentally area-scoped configuration
 * and doc 19's permission seed (migration 29) has no dedicated
 * cod_rules_* key of its own. Reusing areas_edit here avoids a new
 * migration just for RBAC seeding for what's a closely related concern
 * to Service Area Management.
 *
 * STATUS: 🟡 IMPLEMENTED 2026-08-22 — NOT build/device-verified (no
 * PHP CLI in the sandbox, same standing limitation as every other
 * session). Needs migration 35 run on the live DB, then a live
 * click-through: set platform defaults, add an area rule, place a COD
 * order that should be blocked by each rule type in turn, confirm
 * orders/create.php's 422 + cod-eligibility.php's pre-check agree.
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

/**
 * Reads a numeric app_settings value back into the form-friendly shape:
 * empty string in the DB (our "no cap" convention, see migration 35)
 * becomes '' here too, not '0' or null, so the form field renders
 * genuinely blank rather than a misleading zero.
 */
function cod_setting_or_blank(string $key): string
{
    $v = get_setting($key, '');
    return $v === null ? '' : (string) $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to edit COD rules.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_defaults') {
            $codEnabled = isset($_POST['cod_enabled']) ? '1' : '0';
            $minPrepaid = trim($_POST['min_prepaid_orders'] ?? '') !== '' ? (string) max(0, (int) $_POST['min_prepaid_orders']) : '0';
            $maxAmount = trim($_POST['max_cod_order_amount'] ?? '') !== '' ? (string) max(0, (float) $_POST['max_cod_order_amount']) : '';
            $maxPerDay = trim($_POST['max_cod_orders_per_day'] ?? '') !== '' ? (string) max(0, (int) $_POST['max_cod_orders_per_day']) : '';
            $newCustomerBlocked = isset($_POST['new_customer_cod_blocked']) ? '1' : '0';

            $upd = $db->prepare('UPDATE app_settings SET `value` = :v WHERE `key` = :k');
            foreach ([
                'default_cod_enabled' => $codEnabled,
                'default_cod_min_prepaid_orders' => $minPrepaid,
                'default_cod_max_order_amount' => $maxAmount,
                'default_cod_max_orders_per_day' => $maxPerDay,
                'default_cod_new_customer_blocked' => $newCustomerBlocked,
            ] as $k => $v) {
                $upd->execute(['k' => $k, 'v' => $v]);
            }
            write_audit_log('admin', $admin['id'], 'cod_platform_defaults_updated', [
                'cod_enabled' => $codEnabled, 'min_prepaid_orders' => $minPrepaid,
                'max_cod_order_amount' => $maxAmount, 'max_cod_orders_per_day' => $maxPerDay,
                'new_customer_cod_blocked' => $newCustomerBlocked,
            ]);
            $flash = 'Platform-wide COD defaults updated.';
        } elseif ($formAction === 'create_rule' || $formAction === 'update_rule') {
            $areaId = (int) ($_POST['area_id'] ?? 0);
            $codEnabled = isset($_POST['cod_enabled']) ? 1 : 0;
            $minPrepaid = max(0, (int) ($_POST['min_prepaid_orders'] ?? 0));
            $maxAmountRaw = trim($_POST['max_cod_order_amount'] ?? '');
            $maxAmount = $maxAmountRaw !== '' ? max(0, (float) $maxAmountRaw) : null;
            $maxPerDayRaw = trim($_POST['max_cod_orders_per_day'] ?? '');
            $maxPerDay = $maxPerDayRaw !== '' ? max(0, (int) $maxPerDayRaw) : null;
            $newCustomerBlocked = isset($_POST['new_customer_cod_blocked']) ? 1 : 0;

            if ($areaId <= 0) {
                $flash = 'Choose an area.';
                $flashType = 'error';
            } else {
                // One rule row per area (area_id UNIQUE) — an existing
                // row for this area is updated in place rather than
                // rejected, so re-submitting the Add form for an area
                // that already has a rule just edits it (matches
                // find_or_create_area_node()'s "reuse, don't duplicate"
                // philosophy elsewhere in this admin panel).
                $existing = $db->prepare('SELECT id FROM area_cod_rules WHERE area_id = :a LIMIT 1');
                $existing->execute(['a' => $areaId]);
                $existingRow = $existing->fetch();

                if ($existingRow) {
                    $upd = $db->prepare(
                        'UPDATE area_cod_rules SET cod_enabled = :ce, min_prepaid_orders = :mp,
                         max_cod_order_amount = :ma, max_cod_orders_per_day = :md,
                         new_customer_cod_blocked = :nc, is_active = 1 WHERE area_id = :a'
                    );
                    $upd->execute([
                        'ce' => $codEnabled, 'mp' => $minPrepaid, 'ma' => $maxAmount,
                        'md' => $maxPerDay, 'nc' => $newCustomerBlocked, 'a' => $areaId,
                    ]);
                    write_audit_log('admin', $admin['id'], 'area_cod_rule_updated', ['area_id' => $areaId]);
                    $flash = 'COD rule updated.';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO area_cod_rules
                         (area_id, cod_enabled, min_prepaid_orders, max_cod_order_amount, max_cod_orders_per_day, new_customer_cod_blocked, is_active)
                         VALUES (:a, :ce, :mp, :ma, :md, :nc, 1)'
                    );
                    $ins->execute([
                        'a' => $areaId, 'ce' => $codEnabled, 'mp' => $minPrepaid, 'ma' => $maxAmount,
                        'md' => $maxPerDay, 'nc' => $newCustomerBlocked,
                    ]);
                    write_audit_log('admin', $admin['id'], 'area_cod_rule_created', ['area_id' => $areaId]);
                    $flash = 'COD rule added.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('UPDATE area_cod_rules SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_cod_rule_toggled', ['rule_id' => $ruleId]);
            $flash = 'Rule status updated.';
        } elseif ($formAction === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('DELETE FROM area_cod_rules WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_cod_rule_deleted', ['rule_id' => $ruleId]);
            $flash = 'Rule deleted.';
        }
    }
}

// ---------- Data for rendering ----------

$allRules = $db->query(
    'SELECT r.*, sa.name AS area_name, sa.level AS area_level
     FROM area_cod_rules r INNER JOIN service_areas sa ON sa.id = r.area_id
     ORDER BY sa.name'
)->fetchAll();

// Area dropdown — same "city_village or area, whichever is meaningful
// to scope to" reasoning as banners.php's area targeting.
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
// Areas that already have a rule — excluded from the Add form's dropdown
// (edit the existing row via its own form instead of creating a
// second one for the same area; area_id is UNIQUE anyway, but hiding
// them up front avoids the "reused instead of created" surprise banners.php's
// own comment above already flags).
$areasWithRules = array_column($allRules, 'area_id');

$defaults = [
    'cod_enabled' => (bool) ((int) get_setting('default_cod_enabled', 1)),
    'min_prepaid_orders' => cod_setting_or_blank('default_cod_min_prepaid_orders'),
    'max_cod_order_amount' => cod_setting_or_blank('default_cod_max_order_amount'),
    'max_cod_orders_per_day' => cod_setting_or_blank('default_cod_max_orders_per_day'),
    'new_customer_cod_blocked' => (bool) ((int) get_setting('default_cod_new_customer_blocked', 0)),
];

$csrf = admin_csrf_token();
$pageTitle = 'COD Rules';
$activeNav = 'cod_rules';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Platform-wide COD Defaults</h2>
    <p class="muted">Applied to any customer whose resolved area has no rule of its own below, and to any customer whose location can't be resolved to an area at all.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_defaults">
        <div class="form-grid">
            <label class="checkbox-row">
                <input type="checkbox" name="cod_enabled" <?= $defaults['cod_enabled'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                COD enabled by default
            </label>
            <label>Minimum completed prepaid orders before COD unlocks
                <input type="number" name="min_prepaid_orders" min="0" value="<?= admin_escape($defaults['min_prepaid_orders']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
            <label>Max COD order amount (₹, blank = no cap)
                <input type="number" name="max_cod_order_amount" min="0" step="0.01" value="<?= admin_escape($defaults['max_cod_order_amount']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
            <label>Max COD orders per customer per day (blank = no cap)
                <input type="number" name="max_cod_orders_per_day" min="0" value="<?= admin_escape($defaults['max_cod_orders_per_day']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="new_customer_cod_blocked" <?= $defaults['new_customer_cod_blocked'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                Block COD for a customer's first order
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
    <?php
        $addableAreas = array_filter($areaOptions, fn($a) => !in_array((int) $a['id'], $areasWithRules, true));
    ?>
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
            <label class="checkbox-row"><input type="checkbox" name="cod_enabled" checked> COD enabled in this area</label>
            <label>Minimum completed prepaid orders before COD unlocks
                <input type="number" name="min_prepaid_orders" min="0" value="0">
            </label>
            <label>Max COD order amount (₹, blank = no cap)
                <input type="number" name="max_cod_order_amount" min="0" step="0.01" placeholder="No cap">
            </label>
            <label>Max COD orders per customer per day (blank = no cap)
                <input type="number" name="max_cod_orders_per_day" min="0" placeholder="No cap">
            </label>
            <label class="checkbox-row"><input type="checkbox" name="new_customer_cod_blocked"> Block COD for a customer's first order</label>
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
        <tr><th>Area</th><th>COD</th><th>Min Prepaid Orders</th><th>Max Amount</th><th>Max/Day</th><th>New Customer</th><th>Status</th><th></th></tr>
        <?php foreach ($allRules as $r): ?>
        <tr>
            <td><?= admin_escape($areaBreadcrumb[$r['area_id']] ?? $r['area_name']) ?></td>
            <td><span class="badge <?= $r['cod_enabled'] ? 'active' : 'inactive' ?>"><?= $r['cod_enabled'] ? 'Enabled' : 'Disabled' ?></span></td>
            <td><?= (int) $r['min_prepaid_orders'] ?></td>
            <td><?= $r['max_cod_order_amount'] !== null ? '₹' . admin_escape((string) $r['max_cod_order_amount']) : '<span class="muted">No cap</span>' ?></td>
            <td><?= $r['max_cod_orders_per_day'] !== null ? (int) $r['max_cod_orders_per_day'] : '<span class="muted">No cap</span>' ?></td>
            <td><?= $r['new_customer_cod_blocked'] ? 'Blocked' : '<span class="muted">Allowed</span>' ?></td>
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
