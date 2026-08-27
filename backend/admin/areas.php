<?php
/**
 * Anydrop — Admin Web UI: Service Area Management
 *
 * Implements recall.md item 2 / docs/19_Admin_Panel_Full_Spec_And_Payment_
 * Email_Architecture_2026-08-14.md §2 — the State → District → City/
 * Village → Area hierarchy everything else (restaurant visibility, COD
 * rules, banner targeting, analytics) keys off. Reads/writes the schema
 * from backend/sql/30_migration_service_areas.sql.
 *
 * A new node's level is always exactly one below its chosen parent's
 * level (state has no parent; a child of a district is always a
 * city/village) — this keeps the hierarchy well-formed without needing
 * a separate re-parenting UI, matching doc 19 §2's "adjacency-list,
 * variable depth is fine" design.
 *
 * 2026-08-21 (app owner request, later revision): the Add form no
 * longer works "pick one parent, add one child at a time". It now takes
 * State + District + City/Village + Area (optional) together in one
 * submit; find_or_create_area_node() reuses whichever of those already
 * exist (case-insensitive name match, correctly scoped by parent so a
 * same-named District under a different State never collides) and only
 * creates what's actually new. This is what makes picking an existing
 * State/District from the datalist suggestions just reuse it instead of
 * creating a duplicate branch. The Edit form still edits one existing
 * node's own name/coordinates at a time, unchanged.
 *
 * 2026-08-21 (app owner request): the last two levels were restructured.
 * 'city' and the same-day-earlier 'village' rename are now merged into
 * one level, City/Village (one node, whichever label fits — urban or
 * rural). Below that sits a new, genuinely OPTIONAL level, Area — a
 * City/Village does NOT need an Area child; most won't have one unless
 * a specific sub-locality needs its own rules (see the chat explanation
 * this session on when to actually add one). Because Area is optional,
 * whichever node is the deepest one actually created in a given branch
 * is the one that matters for resolution — so center_lat/center_lng/
 * radius_km can now be set on EITHER City/Village or Area (not
 * restricted to a single fixed level like before); the test-coordinates
 * tool below matches on "has coordinates set", not on level name.
 *
 * "Fetch by Pincode" (new this session) is a pure auto-fill convenience
 * — see backend/admin/api/fetch-pincode.php's own header for exactly
 * what it does and its important untested-in-this-sandbox caveat. It
 * never submits anything by itself; it only fills the Name field below,
 * which the admin can still edit or ignore entirely.
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

$LEVELS = ['state', 'district', 'city_village', 'area'];
$LEVEL_LABEL = ['state' => 'State', 'district' => 'District', 'city_village' => 'City/Village', 'area' => 'Area'];

/**
 * Find an existing node at $level (scoped correctly: 'state' has no
 * parent; anything else is scoped to $parentId so a same-named District
 * under a different State never collides) by case-insensitive name
 * match, or create it if none exists.
 *
 * 2026-08-21 (app owner request): the Add form below no longer works by
 * "pick one parent, add one child" — it takes State/District/City-
 * Village/Area (optional) all at once and creates whichever of those
 * don't already exist, reusing whichever do. This is what makes typing
 * an existing State name (e.g. via the Pincode datalist suggestions)
 * just reuse that State instead of creating a duplicate.
 *
 * Coordinates (center_lat/center_lng/radius_km) are only ever set when
 * ACTUALLY CREATING a node here — if a node already exists, this
 * intentionally leaves its coordinates untouched (use the Edit form to
 * change coordinates on an existing node); Add is not a silent-overwrite
 * path.
 */
function find_or_create_area_node(
    PDO $db,
    string $level,
    string $name,
    ?int $parentId,
    ?float $centerLat = null,
    ?float $centerLng = null,
    ?float $radiusKm = null
): array {
    if ($parentId === null) {
        $find = $db->prepare(
            'SELECT id FROM service_areas WHERE level = :l AND parent_id IS NULL AND LOWER(name) = LOWER(:n) LIMIT 1'
        );
        $find->execute(['l' => $level, 'n' => $name]);
    } else {
        $find = $db->prepare(
            'SELECT id FROM service_areas WHERE level = :l AND parent_id = :p AND LOWER(name) = LOWER(:n) LIMIT 1'
        );
        $find->execute(['l' => $level, 'p' => $parentId, 'n' => $name]);
    }
    $existing = $find->fetch();
    if ($existing) {
        return ['id' => (int) $existing['id'], 'created' => false];
    }

    $ins = $db->prepare(
        'INSERT INTO service_areas (parent_id, level, name, is_active, center_lat, center_lng, radius_km)
         VALUES (:p, :l, :n, 1, :lat, :lng, :r)'
    );
    $ins->execute([
        'p' => $parentId, 'l' => $level, 'n' => $name,
        'lat' => $centerLat, 'lng' => $centerLng, 'r' => $radiusKm,
    ]);
    return ['id' => (int) $db->lastInsertId(), 'created' => true];
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

        if ($formAction === 'merge_area') {
            if (!$canDelete) {
                // Merging destroys the duplicate node (and reassigns
                // everything pointing at it), so it needs delete
                // permission, not just edit — same reasoning as any
                // other permanent removal in this file.
                $flash = 'You don\'t have permission to merge areas.';
                $flashType = 'error';
            } else {
                $keepId = (int) ($_POST['keep_id'] ?? 0);
                $dupId = (int) ($_POST['duplicate_id'] ?? 0);

                $stmt = $db->prepare('SELECT * FROM service_areas WHERE id IN (:k, :d)');
                $stmt->execute(['k' => $keepId, 'd' => $dupId]);
                $rows = $stmt->fetchAll();
                $rowsById = [];
                foreach ($rows as $r) {
                    $rowsById[(int) $r['id']] = $r;
                }

                if ($keepId === $dupId || !isset($rowsById[$keepId]) || !isset($rowsById[$dupId])) {
                    $flash = 'Pick two different, existing nodes.';
                    $flashType = 'error';
                } elseif ($rowsById[$keepId]['level'] !== $rowsById[$dupId]['level']) {
                    $flash = 'Both nodes must be the same level (e.g. two City/Village nodes) — merging across levels would corrupt the hierarchy.';
                    $flashType = 'error';
                } else {
                    // Everything that pointed at the duplicate now points
                    // at the node being kept, then the duplicate itself
                    // is removed. All in one transaction — a duplicate
                    // that's half-merged (e.g. children moved but the
                    // row itself still there) is worse than not merging
                    // at all.
                    $db->beginTransaction();
                    try {
                        $db->prepare('UPDATE service_areas SET parent_id = :k WHERE parent_id = :d')
                            ->execute(['k' => $keepId, 'd' => $dupId]);
                        $db->prepare('UPDATE restaurants SET area_id = :k WHERE area_id = :d')
                            ->execute(['k' => $keepId, 'd' => $dupId]);
                        $db->prepare('UPDATE customer_addresses SET area_id = :k WHERE area_id = :d')
                            ->execute(['k' => $keepId, 'd' => $dupId]);
                        $db->prepare('UPDATE banners SET area_id = :k WHERE area_id = :d')
                            ->execute(['k' => $keepId, 'd' => $dupId]);
                        $db->prepare('DELETE FROM service_areas WHERE id = :d')->execute(['d' => $dupId]);
                        $db->commit();
                        write_audit_log('admin', $admin['id'], 'service_areas_merged', [
                            'kept_id' => $keepId, 'removed_id' => $dupId,
                            'kept_name' => $rowsById[$keepId]['name'], 'removed_name' => $rowsById[$dupId]['name'],
                        ]);
                        $flash = '"' . admin_escape($rowsById[$dupId]['name']) . '" merged into "' . admin_escape($rowsById[$keepId]['name']) . '" — its children, restaurants, addresses, and banners were all moved over first.';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $flash = 'Merge failed, nothing was changed: ' . $e->getMessage();
                        $flashType = 'error';
                    }
                }
            }
        } elseif ($formAction === 'create_area') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to add areas.';
                $flashType = 'error';
            } else {
                $stateName = trim($_POST['state_name'] ?? '');
                $districtName = trim($_POST['district_name'] ?? '');
                $cityVillageName = trim($_POST['city_village_name'] ?? '');
                $areaName = trim($_POST['area_name'] ?? ''); // optional
                $centerLat = trim($_POST['center_lat'] ?? '') !== '' ? (float) $_POST['center_lat'] : null;
                $centerLng = trim($_POST['center_lng'] ?? '') !== '' ? (float) $_POST['center_lng'] : null;
                $radiusKm = trim($_POST['radius_km'] ?? '') !== '' ? (float) $_POST['radius_km'] : null;

                if ($stateName === '' || $districtName === '' || $cityVillageName === '') {
                    $flash = 'State, District, and City/Village are all required (Area is optional).';
                    $flashType = 'error';
                } else {
                    // Coordinates attach to whichever node is actually the
                    // deepest one CREATED this submit: Area if given, else
                    // City/Village. If that node already existed, coordinates
                    // are simply not applied here (see find_or_create_area_node
                    // doc comment) — edit the existing node instead.
                    $stateNode = find_or_create_area_node($db, 'state', $stateName, null);
                    $districtNode = find_or_create_area_node($db, 'district', $districtName, $stateNode['id']);
                    $cvCenterLat = $areaName === '' ? $centerLat : null;
                    $cvCenterLng = $areaName === '' ? $centerLng : null;
                    $cvRadiusKm = $areaName === '' ? $radiusKm : null;
                    $cityVillageNode = find_or_create_area_node(
                        $db, 'city_village', $cityVillageName, $districtNode['id'], $cvCenterLat, $cvCenterLng, $cvRadiusKm
                    );

                    $createdParts = [];
                    if ($stateNode['created']) $createdParts[] = 'State';
                    if ($districtNode['created']) $createdParts[] = 'District';
                    if ($cityVillageNode['created']) $createdParts[] = 'City/Village';

                    if ($areaName !== '') {
                        $areaNode = find_or_create_area_node(
                            $db, 'area', $areaName, $cityVillageNode['id'], $centerLat, $centerLng, $radiusKm
                        );
                        if ($areaNode['created']) $createdParts[] = 'Area';
                    }

                    write_audit_log('admin', $admin['id'], 'service_area_created', [
                        'state' => $stateName, 'district' => $districtName,
                        'city_village' => $cityVillageName, 'area' => $areaName ?: null,
                    ]);

                    $flash = $createdParts
                        ? implode(', ', $createdParts) . ' created (existing levels above were reused, not duplicated).'
                        : 'Nothing new to create — that exact State/District/City-Village' . ($areaName !== '' ? '/Area' : '') . ' chain already exists.';
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
// point" rule, now shared via lib/geo.php's resolve_service_area() (also
// used by home/promo-banners.php's area-targeted banners, recall.md
// item 17) instead of living only here.
$testLat = isset($_GET['test_lat']) && $_GET['test_lat'] !== '' ? (float) $_GET['test_lat'] : null;
$testLng = isset($_GET['test_lng']) && $_GET['test_lng'] !== '' ? (float) $_GET['test_lng'] : null;
$testMatches = ($testLat !== null && $testLng !== null) ? resolve_service_area($db, $testLat, $testLng) : [];

// ---------- Data for rendering ----------
$allAreas = $db->query('SELECT * FROM service_areas ORDER BY level, name')->fetchAll();

$areaById = [];
foreach ($allAreas as $a) {
    $areaById[(int) $a['id']] = $a;
}

/**
 * Builds "State > District > City/Village > Area" style breadcrumb for
 * one node. This is what makes two same-named nodes under different
 * parents (e.g. two "Osian" rows because a District got typed/fetched
 * slightly differently the second time — see the merge tool's own
 * comment below) visually obvious instead of looking like an unlabeled
 * duplicate in a flat name list.
 */
function area_breadcrumb(array $area, array $areaById): string
{
    $parts = [$area['name']];
    $cursor = $area;
    while (!empty($cursor['parent_id']) && isset($areaById[(int) $cursor['parent_id']])) {
        $cursor = $areaById[(int) $cursor['parent_id']];
        array_unshift($parts, $cursor['name']);
    }
    return implode(' > ', $parts);
}

/**
 * Walks a node's parent_id chain and returns its ancestor names keyed
 * by level (state/district/city_village) — used to feed the Edit
 * form's "Get coordinates" button the same District/State/City-Village
 * context the Add form's geocode button gets from its separate text
 * inputs, since the Edit form only has one Name field for whichever
 * level is being edited.
 */
function area_ancestor_names_by_level(array $area, array $areaById): array
{
    $out = ['state' => '', 'district' => '', 'city_village' => ''];
    $cursor = $area;
    while (!empty($cursor['parent_id']) && isset($areaById[(int) $cursor['parent_id']])) {
        $cursor = $areaById[(int) $cursor['parent_id']];
        if (isset($out[$cursor['level']])) {
            $out[$cursor['level']] = $cursor['name'];
        }
    }
    return $out;
}

// Duplicate detection — same level + same name (case-insensitive),
// regardless of parent. A same-name pair under the SAME parent is a
// straightforward accidental double-entry. A same-name pair under
// DIFFERENT parents (recall.md item 2's logged case: "Osian" created
// twice because the second District typed/fetched slightly differently,
// e.g. "Jodhpur" vs "Jodhpur Rural") looks identical in a flat list but
// isn't necessarily wrong — it just needs the admin to actually look and
// decide. Either way, the merge tool below is the fix for both.
$duplicateGroups = [];
$byLevelName = [];
foreach ($allAreas as $a) {
    $key = $a['level'] . '|' . mb_strtolower(trim($a['name']));
    $byLevelName[$key][] = $a;
}
foreach ($byLevelName as $group) {
    if (count($group) > 1) {
        $duplicateGroups[] = $group;
    }
}

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

// Distinct existing names per level, for the Add form's datalist
// suggestions (autocomplete-and-reuse, not a strict dropdown — admin can
// still type a brand-new name).
$stateNames = array_values(array_unique(array_map(fn($a) => $a['name'], array_filter($allAreas, fn($a) => $a['level'] === 'state'))));
$districtNames = array_values(array_unique(array_map(fn($a) => $a['name'], array_filter($allAreas, fn($a) => $a['level'] === 'district'))));
$cityVillageNames = array_values(array_unique(array_map(fn($a) => $a['name'], array_filter($allAreas, fn($a) => $a['level'] === 'city_village'))));
$areaNodeNames = array_values(array_unique(array_map(fn($a) => $a['name'], array_filter($allAreas, fn($a) => $a['level'] === 'area'))));

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
            <?php if ($area['center_lat'] !== null && $area['radius_km'] !== null): ?>
                <span class="muted"><?= (float) $area['center_lat'] ?>, <?= (float) $area['center_lng'] ?> · <?= (float) $area['radius_km'] ?> km</span>
            <?php else: ?>
                <span class="muted">not set</span>
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
        <h2>Add area <span class="info-hint"><button type="button" class="info-hint-btn" aria-label="More info">!</button><span class="info-hint-body">State, District, and City/Village are required — Area is optional. Any of these that already exist (matched by name, case-insensitive) are reused, not duplicated; only what's actually new gets created. Center/radius apply to Area if you fill it in, otherwise to City/Village.</span></span></h2>

        <div class="card" style="background:var(--bg-subtle,#f7f7f8); margin-bottom:16px;">
            <label class="field-label">Fetch by Pincode (optional)</label>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" id="pincodeInput" inputmode="numeric" maxlength="6" placeholder="e.g. 342001" style="max-width:140px;">
                <button type="button" id="pincodeFetchBtn" class="btn btn-outline">Fetch</button>
                <span id="pincodeStatus" class="muted" style="font-size:13px;"></span>
            </div>
            <div id="pincodeResult" style="margin-top:8px; font-size:13px;"></div>
        </div>

        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="create_area">
            <div>
                <label class="field-label">State</label>
                <input type="text" id="stateInput" name="state_name" required placeholder="e.g. Rajasthan" list="stateDatalist">
                <datalist id="stateDatalist">
                    <?php foreach ($stateNames as $n): ?><option value="<?= admin_escape($n) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="field-label">District</label>
                <input type="text" id="districtInput" name="district_name" required placeholder="e.g. Jodhpur" list="districtDatalist">
                <datalist id="districtDatalist">
                    <?php foreach ($districtNames as $n): ?><option value="<?= admin_escape($n) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="field-label">City/Village</label>
                <input type="text" id="cityVillageInput" name="city_village_name" required placeholder="e.g. Osian" list="cityVillageDatalist">
                <datalist id="cityVillageDatalist">
                    <?php foreach ($cityVillageNames as $n): ?><option value="<?= admin_escape($n) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="field-label">Area (optional)</label>
                <input type="text" id="areaInput" name="area_name" placeholder="e.g. Teliya Mohalla" list="areaDatalist">
                <datalist id="areaDatalist">
                    <?php foreach ($areaNodeNames as $n): ?><option value="<?= admin_escape($n) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div style="grid-column: 1 / -1;">
                <button type="button" id="geocodeLocalityBtn" class="btn btn-outline">📍 Get coordinates for this Area/City-Village name</button>
                <button type="button" id="chooseOnMapBtn" class="btn btn-outline" data-lat-target="centerLatInput" data-lng-target="centerLngInput">🗺️ Choose on map</button>
                <span id="geocodeLocalityStatus" class="muted" style="margin-left:8px; font-size:12px;"></span>
                <span class="info-hint" style="margin-left:6px;">
                    <button type="button" class="info-hint-btn" aria-label="More info">!</button>
                    <span class="info-hint-body">
                        Looks up whichever is filled in — Area if you typed one (e.g. "Neora"), otherwise City/Village
                        (e.g. "Osian") — by name, using the District/State above for context. This is different from
                        "Fetch by Pincode": a pincode's coordinates are one average point for the whole pincode, which
                        isn't accurate for a specific Area inside it. Not always able to find small localities — if OSM
                        doesn't have it mapped, use "Choose on map" instead: it opens a map you can click on directly at
                        this area's center to get the coordinates.
                    </span>
                </span>
            </div>
            <div>
                <label class="field-label">Center latitude</label>
                <input type="number" step="0.00000001" name="center_lat" id="centerLatInput" placeholder="optional — or use a Get coordinates button above">
            </div>
            <div>
                <label class="field-label">Center longitude</label>
                <input type="number" step="0.00000001" name="center_lng" id="centerLngInput" placeholder="optional — or use a Get coordinates button above">
            </div>
            <div>
                <label class="field-label">Radius (km)</label>
                <input type="number" step="0.1" name="radius_km" placeholder="optional">
            </div>
            <button type="submit" class="btn btn-primary">Add</button>
        </form>
    </div>
    </div>
    <script>
    (function () {
        'use strict';
        var btn = document.getElementById('pincodeFetchBtn');
        var input = document.getElementById('pincodeInput');
        var status = document.getElementById('pincodeStatus');
        var result = document.getElementById('pincodeResult');
        var stateInput = document.getElementById('stateInput');
        var districtInput = document.getElementById('districtInput');
        var cityVillageInput = document.getElementById('cityVillageInput');
        var areaInput = document.getElementById('areaInput');
        var centerLatInput = document.getElementById('centerLatInput');
        var centerLngInput = document.getElementById('centerLngInput');
        if (!btn) return;

        function toTitleCase(s) {
            return (s || '').toLowerCase().replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        }

        btn.addEventListener('click', function () {
            var pin = (input.value || '').trim();
            result.innerHTML = '';
            if (!/^\d{6}$/.test(pin)) {
                status.textContent = 'Enter a valid 6-digit pincode.';
                return;
            }
            status.textContent = 'Looking up…';
            fetch('api/fetch-pincode.php?pincode=' + encodeURIComponent(pin))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        status.textContent = data.error || 'Lookup failed.';
                        return;
                    }
                    status.textContent = '';
                    // Auto-fill State + District straight away — these are
                    // reliable at the pincode level. City/Village and Area
                    // are offered as suggestions instead of auto-filled,
                    // since one pincode's post offices can be either.
                    if (stateInput) stateInput.value = toTitleCase(data.state);
                    if (districtInput) districtInput.value = toTitleCase(data.district);

                    // Coordinates are the PINCODE's centroid, not the
                    // true center of whatever City/Village or Area gets
                    // created below — pre-filled as a starting point
                    // only, both fields stay editable. Use "test
                    // coordinates" further down to sanity-check before
                    // relying on this for area resolution.
                    var coordNote = '';
                    if (data.center_lat != null && data.center_lng != null) {
                        if (centerLatInput) centerLatInput.value = data.center_lat;
                        if (centerLngInput) centerLngInput.value = data.center_lng;
                        coordNote = '<br><span class="muted">Center lat/lng pre-filled from the pincode\'s approximate centroid — this is a starting point, not exact; adjust if needed, and use "test coordinates" below to check it.</span>';
                    } else {
                        coordNote = '<br><span class="muted">Could not auto-fill coordinates for this pincode — enter them manually if needed.</span>';
                    }

                    var html = '<span class="muted">Suggested City/Village or Area names — click one, then pick which field it fills (not a boundary; edit if it doesn\'t match):</span>' + coordNote + '<br>';
                    (data.suggestions || []).forEach(function (s) {
                        html += '<span style="display:inline-flex; align-items:center; gap:2px; margin:4px 6px 0 0;">';
                        html += '<strong style="font-size:12px;">' + s.name + '</strong>';
                        html += '<button type="button" class="btn btn-outline pincode-fill-cv" data-name="' + s.name + '" style="padding:2px 6px; font-size:11px;">→ City/Village</button>';
                        html += '<button type="button" class="btn btn-outline pincode-fill-area" data-name="' + s.name + '" style="padding:2px 6px; font-size:11px;">→ Area</button>';
                        html += '</span>';
                    });
                    result.innerHTML = html;
                    result.querySelectorAll('.pincode-fill-cv').forEach(function (b) {
                        b.addEventListener('click', function () { if (cityVillageInput) cityVillageInput.value = b.dataset.name; });
                    });
                    result.querySelectorAll('.pincode-fill-area').forEach(function (b) {
                        b.addEventListener('click', function () { if (areaInput) areaInput.value = b.dataset.name; });
                    });
                })
                .catch(function () {
                    status.textContent = 'Could not reach the lookup service.';
                });
        });
    })();
    </script>
    <script>
    (function () {
        'use strict';
        var geoBtn = document.getElementById('geocodeLocalityBtn');
        var geoStatus = document.getElementById('geocodeLocalityStatus');
        var stateInput = document.getElementById('stateInput');
        var districtInput = document.getElementById('districtInput');
        var cityVillageInput = document.getElementById('cityVillageInput');
        var areaInput = document.getElementById('areaInput');
        var centerLatInput = document.getElementById('centerLatInput');
        var centerLngInput = document.getElementById('centerLngInput');
        if (!geoBtn) return;

        geoBtn.addEventListener('click', function () {
            // Prefer Area (more specific) if filled in, else fall back
            // to City/Village — whichever is the deepest node actually
            // being created is the one that needs its own coordinate.
            var name = (areaInput && areaInput.value.trim()) || (cityVillageInput && cityVillageInput.value.trim()) || '';
            if (!name) {
                geoStatus.textContent = 'Type an Area or City/Village name first.';
                return;
            }
            geoStatus.textContent = 'Looking up "' + name + '"…';
            var params = new URLSearchParams({
                name: name,
                city_village: (cityVillageInput && cityVillageInput.value.trim()) || '',
                district: (districtInput && districtInput.value.trim()) || '',
                state: (stateInput && stateInput.value.trim()) || ''
            });
            fetch('api/geocode-locality.php?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        geoStatus.textContent = (data.error || 'Lookup failed.') + ' Try "Choose on map" instead.';
                        return;
                    }
                    if (centerLatInput) centerLatInput.value = data.center_lat;
                    if (centerLngInput) centerLngInput.value = data.center_lng;
                    geoStatus.textContent = 'Matched: ' + (data.matched_label || name) + ' — check it looks right, then adjust if needed.';
                })
                .catch(function () {
                    geoStatus.textContent = 'Could not reach the geocoding service. Try "Choose on map" instead.';
                });
        });
    })();
    </script>
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
            <?php if (in_array($editingArea['level'], ['city_village', 'area'], true)):
                $editAncestors = area_ancestor_names_by_level($editingArea, $areaById);
            ?>
            <div style="grid-column: 1 / -1;">
                <button type="button" id="editGeocodeBtn" class="btn btn-outline"
                        data-name="<?= admin_escape($editingArea['name']) ?>"
                        data-city-village="<?= admin_escape($editingArea['level'] === 'city_village' ? $editingArea['name'] : $editAncestors['city_village']) ?>"
                        data-district="<?= admin_escape($editAncestors['district']) ?>"
                        data-state="<?= admin_escape($editAncestors['state']) ?>">📍 Re-fetch coordinates by name</button>
                <button type="button" id="editChooseOnMapBtn" class="btn btn-outline" data-lat-target="editCenterLatInput" data-lng-target="editCenterLngInput">🗺️ Choose on map</button>
                <span id="editGeocodeStatus" class="muted" style="margin-left:8px; font-size:12px;"></span>
                <span class="info-hint" style="margin-left:6px;">
                    <button type="button" class="info-hint-btn" aria-label="More info">!</button>
                    <span class="info-hint-body">Looks up "<?= admin_escape(area_breadcrumb($editingArea, $areaById)) ?>" and refills the fields below — still edited/saved manually, this just overwrites what's currently in the boxes, not the saved record until you hit Save. If OSM can't find it, use "Choose on map" to click the point directly instead.</span>
                </span>
            </div>
            <div>
                <label class="field-label">Center latitude</label>
                <input type="number" step="0.00000001" name="center_lat" id="editCenterLatInput" value="<?= $editingArea['center_lat'] !== null ? (float) $editingArea['center_lat'] : '' ?>">
            </div>
            <div>
                <label class="field-label">Center longitude</label>
                <input type="number" step="0.00000001" name="center_lng" id="editCenterLngInput" value="<?= $editingArea['center_lng'] !== null ? (float) $editingArea['center_lng'] : '' ?>">
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
    <script>
    (function () {
        'use strict';
        var btn = document.getElementById('editGeocodeBtn');
        var status = document.getElementById('editGeocodeStatus');
        var latInput = document.getElementById('editCenterLatInput');
        var lngInput = document.getElementById('editCenterLngInput');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var params = new URLSearchParams({
                name: btn.dataset.name || '',
                city_village: btn.dataset.cityVillage || '',
                district: btn.dataset.district || '',
                state: btn.dataset.state || ''
            });
            status.textContent = 'Looking up…';
            fetch('api/geocode-locality.php?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        status.textContent = (data.error || 'Lookup failed.') + ' Try "Choose on map" instead.';
                        return;
                    }
                    latInput.value = data.center_lat;
                    lngInput.value = data.center_lng;
                    status.textContent = 'Matched: ' + (data.matched_label || btn.dataset.name) + ' — check it looks right before saving.';
                })
                .catch(function () {
                    status.textContent = 'Could not reach the geocoding service. Try "Choose on map" instead.';
                });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <!-- Map picker (fallback when the name lookup above can't find a locality,
         e.g. small hamlets OSM's Nominatim search doesn't have mapped — see
         geocode-locality.php's kdoc). Click straight on the area's actual
         center on the map instead of hunting for coordinates elsewhere.
         Uses Leaflet + OSM raster tiles (no API key needed, unlike the
         customer app's Google Maps pin-drop) since this is a low-traffic
         internal admin tool. Shared by both the Add form's and the Edit
         form's "Choose on map" buttons — data-lat-target/data-lng-target on
         whichever button was clicked tells it which pair of inputs to fill. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <dialog class="modal modal-map" id="mapPickerDialog">
        <div class="modal-body">
            <h3 class="modal-title">Choose on map</h3>
            <p class="modal-text">Pan/zoom to this area, then click (or tap) its center point. Drag the pin afterwards to fine-tune.</p>
            <div id="mapPickerCanvas" style="height:360px; border-radius:8px; overflow:hidden;"></div>
            <p class="muted" id="mapPickerCoords" style="margin:10px 0 0; font-size:13px;">No point selected yet.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" id="mapPickerCancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="mapPickerUse" disabled>Use this point</button>
            </div>
        </div>
    </dialog>
    <script>
    (function () {
        'use strict';
        var dlg = document.getElementById('mapPickerDialog');
        if (!dlg) return;

        // Osian, Jodhpur — same regional fallback center the customer app's
        // map pin-drop uses when nothing more specific is known yet.
        var DEFAULT_CENTER = [26.7213, 72.9166];
        var DEFAULT_ZOOM = 13;

        var map = null;
        var marker = null;
        var activeLatInput = null;
        var activeLngInput = null;
        var coordsText = document.getElementById('mapPickerCoords');
        var useBtn = document.getElementById('mapPickerUse');

        function ensureMap() {
            if (map) return map;
            map = L.map('mapPickerCanvas');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);
            map.on('click', function (e) { placeMarker(e.latlng.lat, e.latlng.lng); });
            return map;
        }

        function placeMarker(lat, lng) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('dragend', function () {
                    var p = marker.getLatLng();
                    updateCoords(p.lat, p.lng);
                });
            }
            updateCoords(lat, lng);
        }

        function updateCoords(lat, lng) {
            // Inputs use step="0.00000001" (8 decimals) — Leaflet's click/
            // drag coordinates come back with 14-15 decimal places of raw
            // float precision, which browsers reject as "not a valid step
            // value" for a number input. Round to 8 decimals here, once,
            // so both the preview text and what actually lands in the
            // field always match what the input will accept.
            var latRounded = lat.toFixed(8);
            var lngRounded = lng.toFixed(8);
            coordsText.textContent = 'Selected: ' + latRounded + ', ' + lngRounded;
            useBtn.disabled = false;
            useBtn.dataset.lat = latRounded;
            useBtn.dataset.lng = lngRounded;
        }

        document.addEventListener('click', function (e) {
            var opener = e.target.closest('#chooseOnMapBtn, #editChooseOnMapBtn');
            if (!opener) return;

            activeLatInput = document.getElementById(opener.dataset.latTarget);
            activeLngInput = document.getElementById(opener.dataset.lngTarget);

            useBtn.disabled = true;
            coordsText.textContent = 'No point selected yet.';
            if (marker) { marker.remove(); marker = null; }

            dlg.showModal();

            // Leaflet sizes itself off the container's dimensions at init
            // time — the <dialog> is still display:none at that instant on
            // first open, so the map would render 0x0. invalidateSize()
            // after the dialog is actually visible (next tick) fixes it;
            // needed on every open, not just the first, since a hidden
            // dialog closing/reopening can also leave stale sizing.
            setTimeout(function () {
                ensureMap();
                map.invalidateSize();

                // Start from whatever's already in the target fields (e.g.
                // a previous manual entry, or a pincode-centroid pre-fill)
                // if present, so re-opening the picker doesn't lose that —
                // otherwise fall back to the regional default center.
                var existingLat = activeLatInput && parseFloat(activeLatInput.value);
                var existingLng = activeLngInput && parseFloat(activeLngInput.value);
                if (!isNaN(existingLat) && !isNaN(existingLng)) {
                    map.setView([existingLat, existingLng], DEFAULT_ZOOM);
                    placeMarker(existingLat, existingLng);
                } else {
                    map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                }
            }, 50);
        });

        document.getElementById('mapPickerCancel').addEventListener('click', function () {
            dlg.close();
        });

        useBtn.addEventListener('click', function () {
            if (activeLatInput) activeLatInput.value = useBtn.dataset.lat;
            if (activeLngInput) activeLngInput.value = useBtn.dataset.lng;
            dlg.close();
        });
    })();
    </script>
    <?php endif; ?>

    <div class="section">
    <div class="card">
        <h2>Test coordinates <span class="info-hint"><button type="button" class="info-hint-btn" aria-label="More info">!</button><span class="info-hint-body">Check which node(s) a GPS pin resolves into — City/Village or Area, whichever has coordinates set — using the same nearest-within-radius rule that'll be used for customer address resolution. Multiple matches mean overlapping radii — worth tightening.</span></span></h2>
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
                <p class="muted" style="margin-top:12px;">No active node matches this point (outside every configured radius).</p>
            <?php else: ?>
                <p style="margin-top:12px; font-size:13px;">
                    Resolves to: <strong><?= admin_escape($testMatches[0]['name']) ?></strong>
                    (<?= $LEVEL_LABEL[$testMatches[0]['level']] ?>, <?= number_format($testMatches[0]['distance'], 2) ?> km from center)
                    <?php if (count($testMatches) > 1): ?>
                        <br><span class="muted">Also within: <?= implode(', ', array_map(fn($m) => admin_escape($m['name']) . ' (' . $LEVEL_LABEL[$m['level']] . ', ' . number_format($m['distance'], 2) . ' km)', array_slice($testMatches, 1))) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    </div>

    <div class="section">
    <div class="card">
        <h2>Hierarchy</h2>
        <?php if (!empty($duplicateGroups)): ?>
            <div class="card" style="background:var(--warn-soft,#fff8e6); margin-bottom:16px;">
                <strong>Possible duplicates found</strong> — same name, same level, but check whether they're under the same parent (an accidental double-add) or different parents (e.g. a District that got typed/fetched slightly differently the second time). Either way, the merge tool below fixes it.
                <ul style="margin:8px 0 0 18px;">
                    <?php foreach ($duplicateGroups as $group): ?>
                        <li><?= $LEVEL_LABEL[$group[0]['level']] ?>: <?= implode(' — ', array_map(fn($a) => admin_escape(area_breadcrumb($a, $areaById)), $group)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
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

    <?php if ($canDelete): ?>
    <div class="section">
    <div class="card">
        <h2>Merge duplicate nodes <span class="info-hint"><button type="button" class="info-hint-btn" aria-label="More info">!</button><span class="info-hint-body">Pick the node to remove ("Duplicate") and the one to keep. Everything on the duplicate — its children, any restaurants/customer addresses/banners assigned to it — gets moved onto "Keep" first, then the duplicate is deleted. Both must be the same level. This can't be undone.</span></span></h2>
        <form method="post" class="form-grid" onsubmit="return confirm('Merge and delete the duplicate node? This can\'t be undone.');">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="merge_area">
            <div>
                <label class="field-label">Duplicate (will be removed)</label>
                <select name="duplicate_id" required>
                    <option value="">— select —</option>
                    <?php foreach ($allAreas as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= admin_escape(area_breadcrumb($a, $areaById)) ?> (<?= $LEVEL_LABEL[$a['level']] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Keep</label>
                <select name="keep_id" required>
                    <option value="">— select —</option>
                    <?php foreach ($allAreas as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"><?= admin_escape(area_breadcrumb($a, $areaById)) ?> (<?= $LEVEL_LABEL[$a['level']] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline danger">Merge</button>
        </form>
    </div>
    </div>
    <?php endif; ?>
<?php require __DIR__ . '/_layout_foot.php'; ?>
