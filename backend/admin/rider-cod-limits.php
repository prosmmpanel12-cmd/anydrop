<?php
/**
 * Anydrop — Admin Web UI: Area-wise Rider COD Cash-Hold Limit
 *
 * Deep Plan Phase 4 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_
 * CashFlow_2026-09-09.md §4). Schema: backend/sql/81_migration_
 * area_rider_cod_limits.sql. Enforcement: backend/lib/rider_cod_limit.php
 * (shared by lib/dispatch.php's find_eligible_riders() and
 * rider/me.php) — this page only manages the area_rider_cod_limits
 * table and the platform-wide `rider_cod_settlement_limit` setting; it
 * never evaluates the limit itself.
 *
 * Distinct from `cod-rules.php` on purpose: that page controls whether
 * a CUSTOMER may pay by COD at all (area_cod_rules, migration 35).
 * This page controls how much COD cash a RIDER may hold before being
 * excluded from further COD-order dispatch (area_rider_cod_limits,
 * migration 81) — a different table, a different money-flow concern,
 * same "one row per service_areas node, no row = platform default"
 * shape reused because it already fits this need too.
 *
 * One rule row per service_areas node (city_village or area level,
 * same "both levels assignable" pattern cod-rules.php/banners.php
 * already use) — a node with no row here falls back to the
 * platform-wide `rider_cod_settlement_limit` setting (migration 53),
 * editable in the "Platform Default" card at the top of this page —
 * there was previously no admin UI for that setting at all, only the
 * hardcoded `get_setting(..., 2000)` fallback every call site used.
 *
 * Gated on `riders_view`/`riders_edit` (not `areas_*` like cod-rules.php)
 * since this is fundamentally about rider cash-handling policy, not
 * area/service-area configuration in general.
 *
 * STATUS: 🟡 IMPLEMENTED 2026-09-09 — NOT build/device-verified (no PHP
 * CLI in the sandbox, same standing limitation as every other session).
 * Needs migration 81 run on the live DB, then a live click-through: set
 * an area limit lower than a rider's current cod_cash_held, confirm
 * that rider stops appearing in find_eligible_riders() for a COD order
 * in that area, confirm the Rider App's Home banner appears.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/rider_ledger.php';

$admin = admin_require_login();
admin_require_permission($admin, 'riders_view');
$canEdit = admin_has_permission($admin['id'], 'riders_edit');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to edit rider COD limits.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_default') {
            $limitRaw = trim($_POST['rider_cod_settlement_limit'] ?? '');
            $limit = $limitRaw !== '' ? max(0, (float) $limitRaw) : 2000;
            set_setting('rider_cod_settlement_limit', (string) $limit);
            write_audit_log('admin', $admin['id'], 'rider_cod_platform_default_updated', ['limit' => $limit]);
            $flash = 'Platform-wide COD cash-hold limit updated.';
        } elseif ($formAction === 'create_rule' || $formAction === 'update_rule') {
            $areaId = (int) ($_POST['area_id'] ?? 0);
            $limitRaw = trim($_POST['cod_hold_limit'] ?? '');
            $limit = $limitRaw !== '' ? max(0, (float) $limitRaw) : null;

            if ($areaId <= 0) {
                $flash = 'Choose an area.';
                $flashType = 'error';
            } elseif ($limit === null) {
                $flash = 'Enter a cash-hold limit.';
                $flashType = 'error';
            } else {
                // One row per area (area_id UNIQUE) — same "reuse,
                // don't duplicate" shape cod-rules.php's own create/
                // update handler already uses.
                $existing = $db->prepare('SELECT id FROM area_rider_cod_limits WHERE area_id = :a LIMIT 1');
                $existing->execute(['a' => $areaId]);
                $existingRow = $existing->fetch();

                if ($existingRow) {
                    $upd = $db->prepare(
                        'UPDATE area_rider_cod_limits SET cod_hold_limit = :l, is_active = 1 WHERE area_id = :a'
                    );
                    $upd->execute(['l' => $limit, 'a' => $areaId]);
                    write_audit_log('admin', $admin['id'], 'area_rider_cod_limit_updated', ['area_id' => $areaId, 'limit' => $limit]);
                    $flash = 'Area COD cash-hold limit updated.';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO area_rider_cod_limits (area_id, cod_hold_limit, is_active) VALUES (:a, :l, 1)'
                    );
                    $ins->execute(['a' => $areaId, 'l' => $limit]);
                    write_audit_log('admin', $admin['id'], 'area_rider_cod_limit_created', ['area_id' => $areaId, 'limit' => $limit]);
                    $flash = 'Area COD cash-hold limit added.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('UPDATE area_rider_cod_limits SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_rider_cod_limit_toggled', ['rule_id' => $ruleId]);
            $flash = 'Limit status updated.';
        } elseif ($formAction === 'delete_rule') {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $db->prepare('DELETE FROM area_rider_cod_limits WHERE id = :id')->execute(['id' => $ruleId]);
            write_audit_log('admin', $admin['id'], 'area_rider_cod_limit_deleted', ['rule_id' => $ruleId]);
            $flash = 'Limit deleted.';
        }
    }
}

// ---------- Data for rendering ----------

$allRules = $db->query(
    'SELECT r.*, sa.name AS area_name, sa.level AS area_level
     FROM area_rider_cod_limits r INNER JOIN service_areas sa ON sa.id = r.area_id
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

$platformDefault = rider_cod_settlement_limit();

$csrf = admin_csrf_token();
$pageTitle = 'Rider COD Limits';
$activeNav = 'rider_cod_limits';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Platform-wide COD Cash-Hold Limit</h2>
    <p class="muted">Applied to any rider whose service area has no override below, and to any rider with no service area assigned. Used by the assignment engine (a rider at or over this amount is excluded from COD-order dispatch) and shown to the rider as a "deposit your cash" banner.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_default">
        <div class="form-grid">
            <label>Cash-hold limit (₹)
                <input type="number" name="rider_cod_settlement_limit" min="0" step="0.01" value="<?= admin_escape((string) $platformDefault) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </label>
        </div>
        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn-primary">Save Default</button>
        <?php endif; ?>
    </form>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <h2>Add Area Override</h2>
    <?php
        $addableAreas = array_filter($areaOptions, fn($a) => !in_array((int) $a['id'], $areasWithRules, true));
    ?>
    <?php if (empty($addableAreas)): ?>
        <p class="muted">Every area with coordinates already has an override — edit one below, or <a href="areas.php">add more areas</a> first.</p>
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
            <label>Cash-hold limit (₹)
                <input type="number" name="cod_hold_limit" min="0" step="0.01" required>
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Add Override</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Area Overrides</h2>
    <?php if (empty($allRules)): ?>
        <p class="muted">No area-specific overrides yet — every rider follows the platform default above.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Area</th><th>Cash-Hold Limit</th><th>Status</th><th></th></tr>
        <?php foreach ($allRules as $r): ?>
        <tr>
            <td><?= admin_escape($areaBreadcrumb[$r['area_id']] ?? $r['area_name']) ?></td>
            <td>₹<?= admin_escape((string) $r['cod_hold_limit']) ?></td>
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
                            data-confirm-title="Delete this override?"
                            data-confirm-text="This area will fall back to the platform-wide default."
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
