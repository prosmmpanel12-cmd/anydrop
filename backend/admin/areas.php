<?php
/**
 * Anydrop — Admin Web UI: Service Area Management
 *
 * Implements recall.md item 2 / docs/19_Admin_Panel_Full_Spec_And_Payment_
 * Email_Architecture_2026-08-14.md §2 — the State → District → City →
 * Area hierarchy everything else (restaurant visibility, COD rules,
 * banner targeting, analytics) keys off. Reads/writes the schema from
 * backend/sql/30_migration_service_areas.sql.
 *
 * A new node's level is always exactly one below its chosen parent's
 * level (state has no parent; a child of a city is always an area) —
 * this keeps the hierarchy well-formed without needing a separate
 * re-parenting UI, matching doc 19 §2's "adjacency-list, variable depth
 * is fine" design. center_lat/center_lng/radius_km only do anything at
 * the 'area' level (recall.md item 3's resolution check) but the form
 * always shows them since the resulting level isn't known until the
 * parent is actually saved server-side.
 *
 * Delete is hard (not just deactivate) but only allowed when nothing
 * still points at the row (no child areas, no restaurants, no
 * customer_addresses) — otherwise it's silently converted to a
 * deactivate instead, so a delete click never leaves dangling FKs or
 * quietly breaks an assigned restaurant's area. Deactivate (is_active)
 * is the normal way to retire an area per recall.md item 2's own
 * wording ("can disable an area without deleting it").
 *
 * Gated on `areas_view` to see this page, `areas_edit` to
 * create/edit/toggle, `areas_delete` to hard-delete — same
 * per-permission-key pattern as roles.php / dashboard.php.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/geo.php';

$admin = admin_require_login();
admin_require_permission($admin, 'areas_view');
$canEdit = admin_has_permission($admin['id'], 'areas_edit');
$canDelete = admin_has_permission($admin['id'], 'areas_delete');
$db = Database::get();

$LEVELS = ['state', 'district', 'city', 'area'];
$LEVEL_LABEL = ['state' => 'State', 'district' => 'District', 'city' => 'City', 'area' => 'Area'];

function next_level(array $levels, ?string $parentLevel): ?string
{
    if ($parentLevel === null) {
        return $levels[0]; // 'state'
    }
    $idx = array_search($parentLevel, $levels, true);
    return $idx !== false && $idx + 1 < count($levels) ? $levels[$idx + 1] : null;
}

$flash = null;
$flashType = 'success';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'create_area') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to add areas.';
                $flashType = 'error';
            } else {
                $name = trim($_POST['name'] ?? '');
                $parentId = trim($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
                $centerLat = trim($_POST['center_lat'] ?? '') !== '' ? (float) $_POST['center_lat'] : null;
                $centerLng = trim($_POST['center_lng'] ?? '') !== '' ? (float) $_POST['center_lng'] : null;
                $radiusKm = trim($_POST['radius_km'] ?? '') !== '' ? (float) $_POST['radius_km'] : null;

                $parentLevel = null;
                $parentOk = true;
                if ($parentId !== null) {
                    $pStmt = $db->prepare('SELECT level FROM service_areas WHERE id = :id LIMIT 1');
                    $pStmt->execute(['id' => $parentId]);
                    $parentRow = $pStmt->fetch();
                    if (!$parentRow) {
                        $parentOk = false;
                    } else {
                        $parentLevel = $parentRow['level'];
                    }
                }

                $level = next_level($LEVELS, $parentLevel);

                if ($name === '') {
                    $flash = 'Name is required.';
                    $flashType = 'error';
                } elseif (!$parentOk) {
                    $flash = 'Selected parent not found.';
                    $flashType = 'error';
                } elseif ($level === null) {
                    $flash = 'This parent is already an Area (the deepest level) — it can\'t have children.';
                    $flashType = 'error';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO service_areas (parent_id, level, name, is_active, center_lat, center_lng, radius_km)
                         VALUES (:p, :l, :n, 1, :lat, :lng, :r)'
                    );
                    $ins->execute([
                        'p' => $parentId,
                        'l' => $level,
                        'n' => $name,
                        'lat' => $centerLat,
                        'lng' => $centerLng,
                        'r' => $radiusKm,
                    ]);
                    write_audit_log('admin', $admin['id'], 'service_area_created', [
                        'name' => $name, 'level' => $level, 'parent_id' => $parentId,
                    ]);
                    $flash = ucfirst($level) . ' "' . admin_escape($name) . '" created.';
                }
            }
        } elseif ($formAction === 'update_area') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit areas.';
                $flashType = 'error';
            } else {
                $areaId = (int) ($_POST['area_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $centerLat = trim($_POST['center_lat'] ?? '') !== '' ? (float) $_POST['center_lat'] : null;
                $centerLng = trim($_POST['center_lng'] ?? '') !== '' ? (float) $_POST['center_lng'] : null;
                $radiusKm = trim($_POST['radius_km'] ?? '') !== '' ? (float) $_POST['radius_km'] : null;

                if ($name === '') {
                    $flash = 'Name is required.';
                    $flashType = 'error';
                } else {
                    $upd = $db->prepare(
                        'UPDATE service_areas SET name = :n, center_lat = :lat, center_lng = :lng, radius_km = :r
                         WHERE id = :id'
                    );
                    $upd->execute([
                        'n' => $name, 'lat' => $centerLat, 'lng' => $centerLng, 'r' => $radiusKm, 'id' => $areaId,
                    ]);
                    write_audit_log('admin', $admin['id'], 'service_area_updated', ['area_id' => $areaId, 'name' => $name]);
                    $flash = 'Area updated.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit areas.';
                $flashType = 'error';
            } else {
                $areaId = (int) ($_POST['area_id'] ?? 0);
                $upd = $db->prepare('UPDATE service_areas SET is_active = NOT is_active WHERE id = :id');
                $upd->execute(['id' => $areaId]);
                write_audit_log('admin', $admin['id'], 'service_area_active_toggled', ['area_id' => $areaId]);
                $flash = 'Area status updated.';
            }
        } elseif ($formAction === 'delete_area') {
            if (!$canDelete) {
                $flash = 'You don\'t have permission to delete areas.';
                $flashType = 'error';
            } else {
                $areaId = (int) ($_POST['area_id'] ?? 0);

                $childStmt = $db->prepare('SELECT COUNT(*) AS c FROM service_areas WHERE parent_id = :id');
                $childStmt->execute(['id' => $areaId]);
                $childCount = (int) $childStmt->fetch()['c'];

                $restStmt = $db->prepare('SELECT COUNT(*) AS c FROM restaurants WHERE area_id = :id');
                $restStmt->execute(['id' => $areaId]);
                $restCount = (int) $restStmt->fetch()['c'];

                $addrStmt = $db->prepare('SELECT COUNT(*) AS c FROM customer_addresses WHERE area_id = :id');
                $addrStmt->execute(['id' => $areaId]);
                $addrCount = (int) $addrStmt->fetch()['c'];

                if ($childCount > 0 || $restCount > 0 || $addrCount > 0) {
                    $flash = 'Can\'t delete — this area still has ' .
                        ($childCount > 0 ? "{$childCount} sub-area(s)" : '') .
                        ($childCount > 0 && ($restCount > 0 || $addrCount > 0) ? ', ' : '') .
                        ($restCount > 0 ? "{$restCount} restaurant(s)" : '') .
                        ($restCount > 0 && $addrCount > 0 ? ', ' : '') .
                        ($addrCount > 0 ? "{$addrCount} saved address(es)" : '') .
                        ' attached. Deactivate it instead, or reassign those first.';
                    $flashType = 'error';
                } else {
                    $db->prepare('DELETE FROM service_areas WHERE id = :id')->execute(['id' => $areaId]);
                    write_audit_log('admin', $admin['id'], 'service_area_deleted', ['area_id' => $areaId]);
                    $flash = 'Area deleted.';
                }
            }
        }
    }
}

// ---------- Test coordinates → resolved area (GET, read-only) ----------
// Lets an admin sanity-check center/radius values against a real GPS pin
// before this same resolution logic gets wired into customer_addresses
// (recall.md item 3) — same "nearest area whose radius contains the
// point" rule, using the existing haversine_km() helper.
$testLat = isset($_GET['test_lat']) && $_GET['test_lat'] !== '' ? (float) $_GET['test_lat'] : null;
$testLng = isset($_GET['test_lng']) && $_GET['test_lng'] !== '' ? (float) $_GET['test_lng'] : null;
$testMatches = [];
if ($testLat !== null && $testLng !== null) {
    $areaRows = $db->query(
        "SELECT id, name, center_lat, center_lng, radius_km FROM service_areas
         WHERE level = 'area' AND is_active = 1
           AND center_lat IS NOT NULL AND center_lng IS NOT NULL AND radius_km IS NOT NULL"
    )->fetchAll();
    foreach ($areaRows as $ar) {
        $dist = haversine_km($testLat, $testLng, (float) $ar['center_lat'], (float) $ar['center_lng']);
        if ($dist <= (float) $ar['radius_km']) {
            $testMatches[] = ['name' => $ar['name'], 'distance' => $dist];
        }
    }
    usort($testMatches, fn($a, $b) => $a['distance'] <=> $b['distance']);
}

// ---------- Data for rendering ----------
$allAreas = $db->query('SELECT * FROM service_areas ORDER BY level, name')->fetchAll();

$byParent = []; // parent_id (or 0 for top) => [rows]
foreach ($allAreas as $a) {
    $key = $a['parent_id'] ?? 0;
    $byParent[$key][] = $a;
}

// Counts of restaurants/addresses per area, for the list view.
$restCounts = [];
foreach ($db->query('SELECT area_id, COUNT(*) AS c FROM restaurants WHERE area_id IS NOT NULL GROUP BY area_id')->fetchAll() as $r) {
    $restCounts[$r['area_id']] = (int) $r['c'];
}

$editingAreaId = isset($_GET['edit_area']) ? (int) $_GET['edit_area'] : null;
$editingArea = null;
if ($editingAreaId) {
    foreach ($allAreas as $a) {
        if ((int) $a['id'] === $editingAreaId) {
            $editingArea = $a;
            break;
        }
    }
}

// Parents eligible to receive a new child = any area not already at the deepest level.
$possibleParents = array_values(array_filter($allAreas, fn($a) => $a['level'] !== 'area'));

$csrf = admin_csrf_token();

/** Recursively renders one row of the tree + its children. */
function render_area_row(array $area, array $byParent, array $restCounts, int $depth, bool $canEdit, bool $canDelete, string $csrf, array $LEVEL_LABEL): void
{
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
    $restCount = $restCounts[$area['id']] ?? 0;
    ?>
    <tr>
        <td><?= $indent ?><?= admin_escape($area['name']) ?></td>
        <td><span class="badge level-<?= admin_escape($area['level']) ?>"><?= $LEVEL_LABEL[$area['level']] ?></span></td>
        <td>
            <?php if ($area['level'] === 'area'): ?>
                <?php if ($area['center_lat'] !== null && $area['radius_km'] !== null): ?>
                    <span class="muted"><?= (float) $area['center_lat'] ?>, <?= (float) $area['center_lng'] ?> · <?= (float) $area['radius_km'] ?> km</span>
                <?php else: ?>
                    <span class="muted">not set</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="muted">—</span>
            <?php endif; ?>
        </td>
        <td><?= $restCount > 0 ? $restCount : '<span class="muted">0</span>' ?></td>
        <td><span class="badge <?= $area['is_active'] ? 'active' : 'inactive' ?>"><?= $area['is_active'] ? 'Active' : 'Inactive' ?></span></td>
        <td class="row-actions">
            <?php if ($canEdit): ?>
                <a class="btn btn-outline" href="areas.php?edit_area=<?= (int) $area['id'] ?>">Edit</a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="toggle_active">
                    <input type="hidden" name="area_id" value="<?= (int) $area['id'] ?>">
                    <button type="submit" class="btn btn-outline"><?= $area['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="delete_area">
                    <input type="hidden" name="area_id" value="<?= (int) $area['id'] ?>">
                    <button type="submit" class="btn btn-outline danger"
                        data-confirm-title="Delete &quot;<?= admin_escape($area['name']) ?>&quot;?"
                        data-confirm-text="Only works if nothing (sub-areas, restaurants, addresses) is attached to it."
                        data-confirm-ok-label="Delete">Delete</button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    foreach ($byParent[$area['id']] ?? [] as $child) {
        render_area_row($child, $byParent, $restCounts, $depth + 1, $canEdit, $canDelete, $csrf, $LEVEL_LABEL);
    }
}
?>
<?php
$pageTitle = 'Service Areas';
$activeNav = 'areas';
require __DIR__ . '/_layout_head.php';
?>
    <?php if ($canEdit): ?>
    <div class="section">
    <div class="card">
        <h2>Add area</h2>
        <p class="hint">Pick a parent to create the next level down — leave "Parent" empty to add a new top-level State. Center/radius only matter for the deepest (Area) level; they're ignored otherwise.</p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="create_area">
            <div>
                <label class="field-label">Parent</label>
                <select name="parent_id">
                    <option value="">— top level (State) —</option>
                    <?php foreach ($possibleParents as $p): ?>
                        <option value="<?= (int) $p['id'] ?>">
                            <?= str_repeat('— ', array_search($p['level'], $LEVELS, true)) ?><?= admin_escape($p['name']) ?> (<?= $LEVEL_LABEL[$p['level']] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Name</label>
                <input type="text" name="name" required placeholder="e.g. Osian">
            </div>
            <div>
                <label class="field-label">Center latitude</label>
                <input type="number" step="0.00000001" name="center_lat" placeholder="area level only">
            </div>
            <div>
                <label class="field-label">Center longitude</label>
                <input type="number" step="0.00000001" name="center_lng" placeholder="area level only">
            </div>
            <div>
                <label class="field-label">Radius (km)</label>
                <input type="number" step="0.1" name="radius_km" placeholder="area level only">
            </div>
            <button type="submit" class="btn btn-primary">Add</button>
        </form>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($canEdit && $editingArea): ?>
    <div class="section">
    <div class="card">
        <h2>Edit — <?= admin_escape($editingArea['name']) ?> (<?= $LEVEL_LABEL[$editingArea['level']] ?>)</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="update_area">
            <input type="hidden" name="area_id" value="<?= (int) $editingArea['id'] ?>">
            <div>
                <label class="field-label">Name</label>
                <input type="text" name="name" required value="<?= admin_escape($editingArea['name']) ?>">
            </div>
            <?php if ($editingArea['level'] === 'area'): ?>
            <div>
                <label class="field-label">Center latitude</label>
                <input type="number" step="0.00000001" name="center_lat" value="<?= $editingArea['center_lat'] !== null ? (float) $editingArea['center_lat'] : '' ?>">
            </div>
            <div>
                <label class="field-label">Center longitude</label>
                <input type="number" step="0.00000001" name="center_lng" value="<?= $editingArea['center_lng'] !== null ? (float) $editingArea['center_lng'] : '' ?>">
            </div>
            <div>
                <label class="field-label">Radius (km)</label>
                <input type="number" step="0.1" name="radius_km" value="<?= $editingArea['radius_km'] !== null ? (float) $editingArea['radius_km'] : '' ?>">
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="areas.php" class="btn btn-outline">Cancel</a>
        </form>
    </div>
    </div>
    <?php endif; ?>

    <div class="section">
    <div class="card">
        <h2>Test coordinates</h2>
        <p class="hint">Check which Area(s) a GPS pin resolves into, using the same nearest-area-within-radius rule that'll be used for customer address resolution. Multiple matches mean overlapping radii — worth tightening.</p>
        <form method="get" class="form-grid">
            <div>
                <label class="field-label">Latitude</label>
                <input type="number" step="0.00000001" name="test_lat" value="<?= $testLat !== null ? admin_escape((string) $testLat) : '' ?>" required>
            </div>
            <div>
                <label class="field-label">Longitude</label>
                <input type="number" step="0.00000001" name="test_lng" value="<?= $testLng !== null ? admin_escape((string) $testLng) : '' ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Resolve</button>
        </form>
        <?php if ($testLat !== null && $testLng !== null): ?>
            <?php if (empty($testMatches)): ?>
                <p class="muted" style="margin-top:12px;">No active Area matches this point (outside every configured radius).</p>
            <?php else: ?>
                <p style="margin-top:12px; font-size:13px;">
                    Resolves to: <strong><?= admin_escape($testMatches[0]['name']) ?></strong>
                    (<?= number_format($testMatches[0]['distance'], 2) ?> km from center)
                    <?php if (count($testMatches) > 1): ?>
                        <br><span class="muted">Also within: <?= implode(', ', array_map(fn($m) => admin_escape($m['name']) . ' (' . number_format($m['distance'], 2) . ' km)', array_slice($testMatches, 1))) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </div>

    <div class="section">
    <div class="card">
        <h2>Hierarchy</h2>
        <?php if (empty($allAreas)): ?>
            <p class="muted">No areas yet — add a State above to get started.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table>
            <tr><th>Name</th><th>Level</th><th>Resolution (area only)</th><th>Restaurants</th><th>Status</th><th></th></tr>
            <?php foreach ($byParent[0] ?? [] as $top): ?>
                <?php render_area_row($top, $byParent, $restCounts, 0, $canEdit, $canDelete, $csrf, $LEVEL_LABEL); ?>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    </div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
