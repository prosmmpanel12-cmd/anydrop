<?php
/**
 * Anydrop — Admin Web UI: Area-wise Pricing Rules (Min Order Floor +
 * Delivery Fee)
 *
 * Implements recall.md Phase B items 13/14 — explicitly flagged
 * pending in both migration 35's own comment and cod-rules.php's kdoc
 * ("delivery fee / min order are separate, still-pending items").
 * Schema: backend/sql/36_migration_area_pricing_rules.sql. Enforcement:
 * backend/lib/delivery_pricing.php (shared by lib/orders.php's
 * price_cart() for the delivery fee, and restaurant/profile-update.php
 * for the min-order floor check) — this page only manages the
 * area_pricing_rules table and the platform-wide app_settings
 * defaults; it never evaluates either rule itself.
 *
 * TWO INDEPENDENT SETTINGS PER AREA, deliberately not conflated:
 *   - Min order FLOOR — a restaurant in this area cannot set its own
 *     min_order_amount below this. The restaurant's own value (set
 *     from the Restaurant App) remains the actual effective minimum
 *     used at checkout — this is only a lower bound, never the value
 *     itself. Blank = platform default is the floor.
 *   - Delivery fee rate/base — real distance (haversine, restaurant to
 *     delivery address) × ₹/km + a flat base, rounded UP to the
 *     nearest ₹5 (lib/delivery_pricing.php's ceil_to_nearest_5() —
 *     app owner's explicit "never round down" rule). Blank = platform
 *     default rate/base is used for this area.
 *
 * One rule row per service_areas node (city_village or area level,
 * same "both levels assignable" pattern as banners.php/cod-rules.php).
 * Gated on `areas_view`/`areas_edit` — same reasoning as cod-rules.php:
 * fundamentally area-scoped config, no dedicated permission key exists
 * for it in migration 29's seed.
 *
 * STATUS: 🟡 IMPLEMENTED 2026-08-22 — NOT build/device-verified (no
 * PHP CLI or live DB in this sandbox, same standing limitation as
 * every other session). Needs migration 36 run on the live DB, then a
 * live click-through: set platform defaults, add an area rule, save a
 * restaurant's own min_order_amount both above and below an area
 * floor (confirm the below case is rejected with the floor value in
 * the error), place/preview an order with a real restaurant+delivery
 * lat/lng pair and confirm the delivery_charge matches
 * base+distance×rate rounded up to the nearest ₹5 by hand.
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

/** Same "blank stays blank, not 0" form-rendering convention as cod-rules.php. */
function pricing_setting_or_blank(string $key): string
{
    $v = get_setting($key, '');
    return $v === null ? '' : (string) $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to edit pricing rules.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_defaults') {
            $minOrder = trim($_POST['default_min_order_amount'] ?? '') !== '' ? (string) max(0, (float) $_POST['default_min_order_amount']) : '0';
            $ratePerKm = trim($_POST['default_delivery_rate_per_km'] ?? '') !== '' ? (string) max(0, (float) $_POST['default_delivery_rate_per_km']) : '0';
            $baseFee = trim($_POST['default_delivery_base_fee'] ?? '') !== '' ? (string) max(0, (float) $_POST['default_delivery_base_fee']) : '0';

            $upd = $db->prepare('UPDATE app_settings SET `value` = :v WHERE `key` = :k');
            foreach ([
                'default_min_order_amount' => $minOrder,
                'default_delivery_rate_per_km' => $ratePerKm,
                'default_delivery_base_fee' => $baseFee,
            ] as $k => $v) {
                $upd->execute(['k' => $k, 'v' => $v]);
            }
            write_audit_log('admin', $admin['id'], 'pricing_platform_defaults_updated', [
                'default_min_order_amount' => $minOrder,
                'default_delivery_rate_per_km' => $ratePerKm,
                'default_delivery_base_fee' => $baseFee,
            ]);
            $flash = 'Platform-wide pricing defaults updated.';
        } elseif ($formAction === 'create_rule' || $formAction === 'update_rule') {
            $areaId = (int) ($_POST['area_id'] ?? 0);
            $minOrderRaw = trim($_POST['min_order_amount'] ?? '');
            $minOrder = $minOrderRaw !== '' ? max(0, (float) $minOrderRaw) : null;
            $rateRaw = trim($_POST['delivery_rate_per_km'] ?? '');
            $rate = $rateRaw !== '' ? max(0, (float) $rateRaw) : null;
            $baseRaw = trim($_POST['delivery_base_fee'] ?? '');
            $base = $baseRaw !== '' ? max(0, (float) $baseRaw) : null;

            if ($areaId <= 0) {
                $flash = 'Choose an area.';
                $flashType = 'error';
            } else {
                // One rule row per area (area_id UNIQUE) — same
                // "reuse an existing row rather than reject" pattern as
                // cod-rules.php.
                $existing = $db->prepare('SELECT id FROM area_pricing_rules WHERE area_id = :a LIMIT 1');
                $existing->execute(['a' => $areaId]);
                $existingRow = $existing->fetch();

                if ($existingRow) {
                    $upd = $db->prepare(
                        'UPDATE area_pricing_rules SET min_order_amount = :mo,
                         delivery_rate_per_km = :rt, delivery_base_fee = :bf, is_active = 1
                         WHERE area_id = :a'
                    );
                    $upd->execute(['mo' => $minOrder, 'rt' => $rate, 'bf' => $base, 'a' => $areaId]);
                    write_audit_log('admin', $admin['id'], 'area_pricing_rule_updated', ['area_id' => $areaId]);
                    $flash = 'Pricing rule updated.';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO area_pricing_rules (area_id, min_order_amount, delivery_rate_per_km, delivery_base_fee, is_active)
                         VALUES (:a, :mo, :rt, :bf, 1)'
                    );
                    $ins->execute(['a' => $areaId, 'mo' => $minOrder, 'rt' => $rate, 'bf' => $base]);
                    write_audit_log('admin', $admin['id'], 'area_pricing_rule_created', ['area_id' => $areaId]);
                    $flash = 'Pricing rule added.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('UPDATE area_pricing_rules SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_pricing_rule_toggled', ['rule_id' => $ruleId]);
            $flash = 'Rule status updated.';
        } elseif ($formAction === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('DELETE FROM area_pricing_rules WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_pricing_rule_deleted', ['rule_id' => $ruleId]);
            $flash = 'Rule deleted.';
        }
    }
}

// ---------- Data for rendering ----------

$allRules = $db->query(
    'SELECT r.*, sa.name AS area_name, sa.level AS area_level
     FROM area_pricing_rules r INNER JOIN service_areas sa ON sa.id = r.area_id
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
    'min_order_amount' => pricing_setting_or_blank('default_min_order_amount'),
    'delivery_rate_per_km' => pricing_setting_or_blank('default_delivery_rate_per_km'),
    'delivery_base_fee' => pricing_setting_or_blank('default_delivery_base_fee'),
];

$csrf = admin_csrf_token();
$pageTitle = 'Pricing Rules';
$activeNav = 'pricing_rules';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Platform-wide Pricing Defaults</h2>
    <p class="muted">Applied to any restaurant/area with no rule of its own below. Delivery fee = base fee + (distance in km × rate per km), always rounded UP to the nearest ₹5 — never down.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_defaults">
        <div class="form-grid">
            <label>Minimum order floor (₹)
                <input type="number" name="default_min_order_amount" min="0" step="0.01" value="<?= admin_escape($defaults['min_order_amount']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
            <label>Delivery rate per km (₹)
                <input type="number" name="default_delivery_rate_per_km" min="0" step="0.01" value="<?= admin_escape($defaults['delivery_rate_per_km']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
            <label>Delivery base fee (₹, flat, added before per-km)
                <input type="number" name="default_delivery_base_fee" min="0" step="0.01" value="<?= admin_escape($defaults['delivery_base_fee']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
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
            <label>Minimum order floor (₹, blank = platform default)
                <input type="number" name="min_order_amount" min="0" step="0.01" placeholder="Platform default">
            </label>
            <label>Delivery rate per km (₹, blank = platform default)
                <input type="number" name="delivery_rate_per_km" min="0" step="0.01" placeholder="Platform default">
            </label>
            <label>Delivery base fee (₹, blank = platform default)
                <input type="number" name="delivery_base_fee" min="0" step="0.01" placeholder="Platform default">
            </label>
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
        <tr><th>Area</th><th>Min Order Floor</th><th>Rate/km</th><th>Base Fee</th><th>Status</th><th></th></tr>
        <?php foreach ($allRules as $r): ?>
        <tr>
            <td><?= admin_escape($areaBreadcrumb[$r['area_id']] ?? $r['area_name']) ?></td>
            <td><?= $r['min_order_amount'] !== null ? '₹' . admin_escape((string) $r['min_order_amount']) : '<span class="muted">Platform default</span>' ?></td>
            <td><?= $r['delivery_rate_per_km'] !== null ? '₹' . admin_escape((string) $r['delivery_rate_per_km']) . '/km' : '<span class="muted">Platform default</span>' ?></td>
            <td><?= $r['delivery_base_fee'] !== null ? '₹' . admin_escape((string) $r['delivery_base_fee']) : '<span class="muted">Platform default</span>' ?></td>
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
