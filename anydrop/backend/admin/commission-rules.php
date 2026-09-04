<?php
/**
 * Anydrop — Admin Web UI: Commission Rules (category + area override)
 *
 * Implements recall.md Phase C items 20-23 / migration 38's
 * commission_rules table. Manages ONLY the rules themselves — the
 * resolution logic lives in backend/lib/commission.php's
 * get_effective_commission_rate(), shared by price_cart() so this page
 * and actual order pricing can never drift apart.
 *
 * A rule can be scoped to a category, an area, or both (at least one
 * required — migration 38's chk_commission_rule_scoped). Both-scoped
 * rules are the most specific and always win over a category-only or
 * area-only rule for the same category/area. Restaurants with no
 * matching rule keep using their existing flat restaurants.commission_percent
 * (or the platform default) exactly as before this page existed —
 * nothing here is required for the platform to keep working.
 *
 * Gated on payouts_view/payouts_manage — commission is a financial
 * setting, same module as the Settlements/Payouts pages.
 *
 * STATUS: 🆕 BUILT 2026-08-22 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox). Needs migration 38 run live, then a
 * click-through: add a category-only rule, add an area-only rule, add
 * a rule scoped to both for the same category (confirm it wins over
 * the category-only one for a restaurant in that area), place a test
 * order against a menu item tagged with that category and confirm
 * order_items.commission_percent/commission_amount reflect the rule.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission($admin['id'], 'payouts_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to edit commission rules.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'create_rule' || $formAction === 'update_rule') {
            $categoryId = trim((string) ($_POST['food_category_id'] ?? '')) !== '' ? (int) $_POST['food_category_id'] : null;
            $areaId = trim((string) ($_POST['area_id'] ?? '')) !== '' ? (int) $_POST['area_id'] : null;
            $percent = trim((string) ($_POST['commission_percent'] ?? ''));

            if ($categoryId === null && $areaId === null) {
                $flash = 'Choose a category, an area, or both — a rule needs at least one to apply to.';
                $flashType = 'error';
            } elseif ($percent === '' || !is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
                $flash = 'Commission % must be a number between 0 and 100.';
                $flashType = 'error';
            } else {
                $percent = round((float) $percent, 2);

                if ($formAction === 'create_rule') {
                    // Same category+area combination twice would leave two
                    // rules both matching the same lookup with no defined
                    // winner — reuse the existing row instead, same
                    // pattern as cod-rules.php/payment-restrictions.php.
                    $existing = $db->prepare(
                        'SELECT id FROM commission_rules
                         WHERE (food_category_id <=> :cat) AND (area_id <=> :area) LIMIT 1'
                    );
                    $existing->execute(['cat' => $categoryId, 'area' => $areaId]);
                    $existingRow = $existing->fetch();

                    if ($existingRow) {
                        $db->prepare('UPDATE commission_rules SET commission_percent = :p, is_active = 1 WHERE id = :id')
                            ->execute(['p' => $percent, 'id' => $existingRow['id']]);
                        write_audit_log('admin', $admin['id'], 'commission_rule_updated', ['rule_id' => $existingRow['id']]);
                        $flash = 'A rule for that combination already existed — updated it instead of creating a duplicate.';
                    } else {
                        $db->prepare(
                            'INSERT INTO commission_rules (food_category_id, area_id, commission_percent, is_active)
                             VALUES (:cat, :area, :p, 1)'
                        )->execute(['cat' => $categoryId, 'area' => $areaId, 'p' => $percent]);
                        write_audit_log('admin', $admin['id'], 'commission_rule_created', ['food_category_id' => $categoryId, 'area_id' => $areaId]);
                        $flash = 'Commission rule added.';
                    }
                } else {
                    $ruleId = (int) ($_POST['rule_id'] ?? 0);
                    $db->prepare('UPDATE commission_rules SET commission_percent = :p WHERE id = :id')
                        ->execute(['p' => $percent, 'id' => $ruleId]);
                    write_audit_log('admin', $admin['id'], 'commission_rule_updated', ['rule_id' => $ruleId]);
                    $flash = 'Commission rule updated.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('UPDATE commission_rules SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'commission_rule_toggled', ['rule_id' => $ruleId]);
            $flash = 'Rule status updated.';
        } elseif ($formAction === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('DELETE FROM commission_rules WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'commission_rule_deleted', ['rule_id' => $ruleId]);
            $flash = 'Rule deleted.';
        }
    }
}

// ---------- Data for rendering ----------

$allRules = $db->query(
    "SELECT r.*, fc.name AS category_name, sa.name AS area_name, sa.level AS area_level
     FROM commission_rules r
     LEFT JOIN food_categories fc ON fc.id = r.food_category_id
     LEFT JOIN service_areas sa ON sa.id = r.area_id
     ORDER BY (r.food_category_id IS NOT NULL AND r.area_id IS NOT NULL) DESC, fc.name, sa.name"
)->fetchAll();

$categoryOptions = $db->query(
    "SELECT id, name FROM food_categories WHERE is_active = 1 ORDER BY name"
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

$platformDefault = (float) get_setting('commission_default_percent', 15);

$csrf = admin_csrf_token();
$pageTitle = 'Commission Rules';
$activeNav = 'commission_rules';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>How commission is resolved</h2>
    <p class="muted">
        For each order line item, the most specific matching rule wins:
        <strong>category + area</strong> &rarr; <strong>category only</strong> &rarr; <strong>area only</strong> &rarr;
        the restaurant's own flat override (<a href="restaurants.php">Restaurants</a>) &rarr;
        the platform default (currently <strong><?= admin_escape(number_format($platformDefault, 2)) ?>%</strong> —
        no dedicated Settings page exists yet for this key; it lives in <code>app_settings.commission_default_percent</code>).
        A menu item can carry more than one category — if more than one rule matches at the same
        specificity, the <strong>higher</strong> rate is used.
    </p>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <h2>Add Rule</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="create_rule">
        <div class="form-grid">
            <label>Category <span class="muted">(optional)</span>
                <select name="food_category_id">
                    <option value="">— Any category —</option>
                    <?php foreach ($categoryOptions as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= admin_escape($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Area <span class="muted">(optional — restaurant's own area)</span>
                <select name="area_id">
                    <option value="">— Any area —</option>
                    <?php foreach ($areaOptions as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= admin_escape($areaBreadcrumb[$a['id']]) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Commission %
                <input type="number" name="commission_percent" step="0.01" min="0" max="100" required placeholder="e.g. 8.00">
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Add Rule</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Rules</h2>
    <?php if (empty($allRules)): ?>
        <p class="muted">No commission rules yet — every restaurant uses its own flat commission_percent, or the platform default.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Category</th><th>Area</th><th>Commission %</th><th>Status</th><th></th></tr>
        <?php foreach ($allRules as $r): ?>
        <tr>
            <td><?= $r['category_name'] ? admin_escape($r['category_name']) : '<span class="muted">Any category</span>' ?></td>
            <td><?= $r['area_id'] ? admin_escape($areaBreadcrumb[$r['area_id']] ?? $r['area_name']) : '<span class="muted">Any area</span>' ?></td>
            <td><?= admin_escape(number_format((float) $r['commission_percent'], 2)) ?>%</td>
            <td><span class="badge <?= $r['is_active'] ? 'active' : 'inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="row-actions">
                <?php if ($canEdit): ?>
                    <form method="post" style="display:inline-flex; gap:4px; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="update_rule">
                        <input type="hidden" name="rule_id" value="<?= (int) $r['id'] ?>">
                        <input type="number" name="commission_percent" step="0.01" min="0" max="100" value="<?= admin_escape((string) $r['commission_percent']) ?>" style="width:80px;">
                        <button type="submit" class="btn btn-outline">Save</button>
                    </form>
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
                            data-confirm-text="Affected orders will fall back to the next rule in priority, or the restaurant's flat commission."
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
