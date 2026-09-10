<?php
/**
 * Anydrop — Admin Web UI: Restaurant Management
 *
 * Implements recall.md Phase A item 5 (Restaurant side) / doc 19's Super
 * Admin "Restaurants" module. index.php already handles the pending-
 * approval queue (kept as-is, it's a focused/fast triage screen); this
 * page is the broader control surface over the *entire* restaurants
 * table — search, filter, full status lifecycle (approve / reject /
 * suspend / reactivate), area assignment, and commission override.
 *
 * Status lifecycle used here (restaurants.status enum: pending, approved,
 * rejected, suspended):
 *   pending           -> approve | reject (reason required)
 *   approved          -> suspend (reason required)
 *   rejected/suspended -> approve  (== reactivate; also clears the
 *                                    stored reason)
 * Reuses the existing `rejection_reason` TEXT column (migration 25) to
 * store *either* a rejection or a suspension reason — no new column
 * needed, the UI just labels it contextually ("Reason shown to
 * restaurant"). Every transition writes to the same audit_logs trail
 * index.php already uses.
 *
 * Area assignment writes restaurants.area_id (migration 30) — this is
 * the "restaurant onboarding UI to actually assign area_id" piece
 * flagged as NOT done in done.md's 2026-08-21 Area Management session;
 * this page is what closes that gap. Only nodes with coordinates set
 * (level 'city_village' or 'area' — whichever is the deepest actually
 * created in a branch, per the 2026-08-21 restructure) are offered —
 * assigning a restaurant to a State/District would be meaningless for
 * the area-match check recall.md item 3 will use.
 *
 * 2026-09-07 — Auto-detect + bulk assign (app owner: "script mat do,
 * admin panel mai button de assign area ka, aur saath mai filter bhi
 * jese ki Osian mein aane wale restaurant ka area auto assign ho
 * jaye"). The Area filter dropdown gained an "Unassigned" option; when
 * selected, every matching restaurant with a lat/lng gets its likely
 * area computed live via resolve_service_area() (same function the
 * signup-time auto-resolve and the customer-address backfill already
 * use) and shown as a "Detected area" column, with a per-row "Assign"
 * button — and a further "Detected area" dropdown lets the admin
 * narrow down to exactly the restaurants that would resolve to one
 * specific area (e.g. Osian), then "Auto-assign area to all shown"
 * assigns all of them in one click. Nothing here bypasses admin
 * review — this only ever runs when an admin is looking at this page
 * and explicitly clicks a button, same "admin stays in the loop"
 * reasoning cod_rules.php/etc.'s combined-area-rules change documented
 * for why restaurant/profile-update.php still never auto-reassigns
 * area_id on its own.
 *
 * Gated: `restaurants_view` to see this page at all; `restaurants_edit`
 * for area/commission changes; `restaurants_approve` for every status
 * transition (approve/reject/suspend/reactivate); `restaurants_delete`
 * for the soft-delete action. Same per-permission-key pattern as every
 * other admin/*.php page.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/geo.php';

$admin = admin_require_login();
admin_require_permission($admin, 'restaurants_view');
$canEdit = admin_has_permission($admin['id'], 'restaurants_edit');
$canApprove = admin_has_permission($admin['id'], 'restaurants_approve');
$canDelete = admin_has_permission($admin['id'], 'restaurants_delete');
$db = Database::get();

// Full node map + assignable area options — moved up (was previously
// built further down, right before the display section) so the new
// bulk-auto-assign POST handler and the unassigned-restaurants lookup
// function below can both use them too, not just the table render.
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}
$areaOptions = $db->query(
    "SELECT id, name, level FROM service_areas WHERE level IN ('city_village','area') AND is_active = 1 ORDER BY name"
)->fetchAll();

if (!function_exists('admin_unassigned_restaurants_with_detected_area')) {
    /**
     * Every non-deleted restaurant with area_id IS NULL matching the
     * given search/status filters, each with a 'detected_area_id'
     * (and null 'detected_area_id' when there's no lat/lng to resolve
     * from, or nothing covers that point) computed live via
     * resolve_service_area() — the SAME resolution function
     * restaurant-signup.php's auto-assign-at-signup and the customer-
     * address backfill already use, just run on demand here instead of
     * once at signup. Read-only: never writes anything itself, callers
     * (the bulk-assign POST handler, the display section) decide what
     * to do with the result.
     */
    function admin_unassigned_restaurants_with_detected_area(PDO $db, string $q, string $statusFilter): array
    {
        $where = ['deleted_at IS NULL', 'area_id IS NULL'];
        $params = [];
        if ($q !== '') {
            $where[] = '(name LIKE :q OR owner_name LIKE :q OR owner_email LIKE :q OR owner_mobile LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($statusFilter !== '') {
            $where[] = 'status = :status';
            $params['status'] = $statusFilter;
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $db->prepare(
            "SELECT id, name, owner_name, owner_mobile, owner_email, status, operational_status,
                    area_id, current_due, commission_percent, rating_avg, created_at, rejection_reason,
                    latitude, longitude, address, pending_address, pending_latitude, pending_longitude,
                    address_review_status, address_review_remarks
             FROM restaurants
             WHERE {$whereSql}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['detected_area_id'] = null;
            if ($row['latitude'] !== null && $row['longitude'] !== null) {
                $matches = resolve_service_area($db, (float) $row['latitude'], (float) $row['longitude']);
                if (!empty($matches)) {
                    $row['detected_area_id'] = (int) $matches[0]['id'];
                }
            }
        }
        unset($row);

        return $rows;
    }
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

        if ($formAction === 'bulk_auto_assign') {
            if (!$canEdit) {
                $flash = 'Your role doesn\'t have the restaurants_edit permission.';
                $flashType = 'error';
            } else {
                // Re-derives the exact same filtered set from $_GET
                // (the admin's current filter — a plain <form
                // method="post"> with no action= attribute posts back
                // to the same URL, so the query string, and therefore
                // $_GET, is unchanged) rather than trusting any list of
                // ids the client might have sent, so this can never act
                // on a stale/tampered set.
                $bulkQ = trim($_GET['q'] ?? '');
                $bulkStatus = $_GET['status'] ?? '';
                if (!in_array($bulkStatus, ['pending', 'approved', 'rejected', 'suspended'], true)) {
                    $bulkStatus = '';
                }
                $bulkDetectedFilter = trim($_GET['detected_area_id'] ?? '') !== ''
                    ? (int) $_GET['detected_area_id'] : null;

                $candidates = admin_unassigned_restaurants_with_detected_area($db, $bulkQ, $bulkStatus);
                $updStmt = $db->prepare('UPDATE restaurants SET area_id = :a WHERE id = :id');
                $assigned = 0;
                $skipped = 0;

                foreach ($candidates as $c) {
                    if ($bulkDetectedFilter !== null && $c['detected_area_id'] !== $bulkDetectedFilter) {
                        continue; // outside the narrowed-down set the admin was actually looking at
                    }
                    if ($c['detected_area_id'] === null) {
                        $skipped++;
                        continue; // no coordinates, or nothing covers that point yet
                    }
                    $updStmt->execute(['a' => $c['detected_area_id'], 'id' => $c['id']]);
                    write_audit_log('admin', $admin['id'], 'restaurant_area_auto_assigned', [
                        'restaurant_id' => $c['id'],
                        'area_id' => $c['detected_area_id'],
                    ]);
                    $assigned++;
                }

                $flash = "Auto-assigned area to {$assigned} restaurant(s).";
                if ($skipped > 0) {
                    $flash .= " {$skipped} skipped — no coordinates set, or no service area covers that point yet.";
                }
            }
        } else {

        $restaurantId = (int) ($_POST['restaurant_id'] ?? 0);

        $stmt = $db->prepare('SELECT id, name, status, address_review_status, pending_address, pending_latitude, pending_longitude FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $restaurantId]);
        $restaurant = $stmt->fetch();

        if (!$restaurant) {
            $flash = 'Restaurant not found.';
            $flashType = 'error';
        } elseif ($formAction === 'change_status') {
            if (!$canApprove) {
                $flash = 'Your role doesn\'t have the restaurants_approve permission.';
                $flashType = 'error';
            } else {
                $action = $_POST['action'] ?? '';
                $reason = trim($_POST['reason'] ?? '');

                if ($action === 'approve') {
                    // Covers pending->approved AND reactivating a rejected/suspended restaurant.
                    $upd = $db->prepare("UPDATE restaurants SET status = 'approved', rejection_reason = NULL WHERE id = :id");
                    $upd->execute(['id' => $restaurantId]);
                    write_audit_log('admin', $admin['id'], 'restaurant_approved', ['restaurant_id' => $restaurantId, 'from_status' => $restaurant['status']]);
                    $flash = admin_escape($restaurant['name']) . ' is now approved.';
                } elseif ($action === 'reject') {
                    if ($restaurant['status'] !== 'pending') {
                        $flash = 'Only a pending restaurant can be rejected — this one is ' . $restaurant['status'] . '.';
                        $flashType = 'error';
                    } elseif ($reason === '') {
                        $flash = 'A rejection reason is required.';
                        $flashType = 'error';
                    } else {
                        $upd = $db->prepare("UPDATE restaurants SET status = 'rejected', rejection_reason = :r WHERE id = :id");
                        $upd->execute(['r' => $reason, 'id' => $restaurantId]);
                        write_audit_log('admin', $admin['id'], 'restaurant_rejected', ['restaurant_id' => $restaurantId, 'reason' => $reason]);
                        $flash = admin_escape($restaurant['name']) . ' rejected.';
                    }
                } elseif ($action === 'suspend') {
                    if ($restaurant['status'] !== 'approved') {
                        $flash = 'Only an approved restaurant can be suspended.';
                        $flashType = 'error';
                    } elseif ($reason === '') {
                        $flash = 'A suspension reason is required.';
                        $flashType = 'error';
                    } else {
                        $upd = $db->prepare("UPDATE restaurants SET status = 'suspended', rejection_reason = :r WHERE id = :id");
                        $upd->execute(['r' => $reason, 'id' => $restaurantId]);
                        write_audit_log('admin', $admin['id'], 'restaurant_suspended', ['restaurant_id' => $restaurantId, 'reason' => $reason]);
                        $flash = admin_escape($restaurant['name']) . ' suspended.';
                    }
                }
            }
        } elseif ($formAction === 'review_address') {
            // Migration 79 — approve/reject a restaurant-submitted address
            // change. Gated by restaurants_approve (not restaurants_edit)
            // since this is the same kind of trust decision as
            // approve/reject/suspend above, not a plain data edit an
            // admin might make on the restaurant's behalf.
            if (!$canApprove) {
                $flash = 'Your role doesn\'t have the restaurants_approve permission.';
                $flashType = 'error';
            } elseif ($restaurant['address_review_status'] !== 'pending') {
                $flash = 'No pending address change for this restaurant.';
                $flashType = 'error';
            } else {
                $addressAction = $_POST['address_action'] ?? '';
                $addressReason = trim($_POST['address_reason'] ?? '');

                if ($addressAction === 'approve') {
                    // Copy pending_* into the live columns and clear the
                    // pending state — same "copy over, then clear" shape
                    // as settlements.php's verify_bank_details action.
                    $upd = $db->prepare(
                        "UPDATE restaurants
                            SET address = pending_address,
                                latitude = COALESCE(pending_latitude, latitude),
                                longitude = COALESCE(pending_longitude, longitude),
                                pending_address = NULL, pending_latitude = NULL, pending_longitude = NULL,
                                address_review_status = 'none', address_review_remarks = NULL,
                                address_reviewed_by_admin_id = :admin_id, address_reviewed_at = NOW()
                            WHERE id = :id"
                    );
                    $upd->execute(['admin_id' => $admin['id'], 'id' => $restaurantId]);
                    write_audit_log('admin', $admin['id'], 'restaurant_address_approved', [
                        'restaurant_id' => $restaurantId,
                        'address' => $restaurant['pending_address'],
                    ]);
                    $flash = 'Address change approved for ' . admin_escape($restaurant['name']) . '.';
                } elseif ($addressAction === 'reject') {
                    if ($addressReason === '') {
                        $flash = 'A reason is required to reject an address change.';
                        $flashType = 'error';
                    } else {
                        // Live address is untouched — only the pending
                        // draft + status change, so the restaurant sees
                        // exactly why their submitted address didn't
                        // take effect (address_review_status = 'rejected'),
                        // same "keep the record so the reason is visible"
                        // reasoning as verification_status = 'rejected'
                        // on restaurant_bank_details.
                        $upd = $db->prepare(
                            "UPDATE restaurants
                                SET address_review_status = 'rejected', address_review_remarks = :reason,
                                    address_reviewed_by_admin_id = :admin_id, address_reviewed_at = NOW()
                                WHERE id = :id"
                        );
                        $upd->execute(['reason' => $addressReason, 'admin_id' => $admin['id'], 'id' => $restaurantId]);
                        write_audit_log('admin', $admin['id'], 'restaurant_address_rejected', [
                            'restaurant_id' => $restaurantId,
                            'reason' => $addressReason,
                        ]);
                        $flash = 'Address change rejected for ' . admin_escape($restaurant['name']) . '.';
                    }
                }
            }
        } elseif ($formAction === 'assign_area') {
            if (!$canEdit) {
                $flash = 'Your role doesn\'t have the restaurants_edit permission.';
                $flashType = 'error';
            } else {
                $areaId = trim($_POST['area_id'] ?? '') !== '' ? (int) $_POST['area_id'] : null;
                if ($areaId !== null) {
                    $chk = $db->prepare("SELECT id FROM service_areas WHERE id = :id AND level IN ('city_village','area') LIMIT 1");
                    $chk->execute(['id' => $areaId]);
                    if (!$chk->fetch()) {
                        $areaId = null;
                        $flash = 'Selected area not found — clearing area instead.';
                        $flashType = 'error';
                    }
                }
                if ($flash === null) {
                    $upd = $db->prepare('UPDATE restaurants SET area_id = :a WHERE id = :id');
                    $upd->execute(['a' => $areaId, 'id' => $restaurantId]);
                    write_audit_log('admin', $admin['id'], 'restaurant_area_assigned', ['restaurant_id' => $restaurantId, 'area_id' => $areaId]);
                    $flash = 'Area updated for ' . admin_escape($restaurant['name']) . '.';
                }
            }
        } elseif ($formAction === 'update_commission') {
            if (!$canEdit) {
                $flash = 'Your role doesn\'t have the restaurants_edit permission.';
                $flashType = 'error';
            } else {
                $commission = (float) ($_POST['commission_percent'] ?? -1);
                if ($commission < 0 || $commission > 100) {
                    $flash = 'Commission must be between 0 and 100.';
                    $flashType = 'error';
                } else {
                    $upd = $db->prepare('UPDATE restaurants SET commission_percent = :c WHERE id = :id');
                    $upd->execute(['c' => $commission, 'id' => $restaurantId]);
                    write_audit_log('admin', $admin['id'], 'restaurant_commission_updated', ['restaurant_id' => $restaurantId, 'commission_percent' => $commission]);
                    $flash = 'Commission updated for ' . admin_escape($restaurant['name']) . '.';
                }
            }
        } elseif ($formAction === 'soft_delete') {
            if (!$canDelete) {
                $flash = 'Your role doesn\'t have the restaurants_delete permission.';
                $flashType = 'error';
            } else {
                $db->prepare('UPDATE restaurants SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $restaurantId]);
                write_audit_log('admin', $admin['id'], 'restaurant_deleted', ['restaurant_id' => $restaurantId]);
                $flash = admin_escape($restaurant['name']) . ' removed.';
            }
        }

        }
    }
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$areaFilterRaw = $_GET['area_id'] ?? '';
$isUnassignedFilter = ($areaFilterRaw === 'unassigned');
$areaFilter = (!$isUnassignedFilter && trim($areaFilterRaw) !== '') ? (int) $areaFilterRaw : null;
// Only meaningful alongside $isUnassignedFilter — narrows "show
// unassigned restaurants" down to "show unassigned restaurants that
// would resolve to THIS specific area" (2026-09-07, the "Osian mein
// aane wale restaurant" case from the app owner's own example).
$detectedAreaFilter = ($isUnassignedFilter && trim($_GET['detected_area_id'] ?? '') !== '')
    ? (int) $_GET['detected_area_id'] : null;
$validStatuses = ['pending', 'approved', 'rejected', 'suspended'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// detectedAreaOptions — populated below only in the unassigned branch,
// used to render the "Detected area" narrowing dropdown; stays empty
// (and hidden) for the normal filter branch.
$detectedAreaOptions = [];

if ($isUnassignedFilter) {
    // Not a plain SQL WHERE — "detected area" is computed per-row via
    // resolve_service_area(), not a column we can filter/paginate in
    // SQL. Restaurant volumes on this platform are small enough that
    // fetching every currently-unassigned match and doing the
    // resolution + pagination in PHP is simpler and fast enough,
    // rather than adding a cached/denormalized column for this.
    $allUnassigned = admin_unassigned_restaurants_with_detected_area($db, $q, $statusFilter);

    // Build the narrowing dropdown from what's actually present among
    // ALL currently-unassigned matches (before applying
    // $detectedAreaFilter itself) — so an admin picking from it always
    // sees real, non-empty options with counts.
    $detectedCounts = [];
    foreach ($allUnassigned as $row) {
        if ($row['detected_area_id'] !== null) {
            $detectedCounts[$row['detected_area_id']] = ($detectedCounts[$row['detected_area_id']] ?? 0) + 1;
        }
    }
    foreach ($detectedCounts as $id => $count) {
        $detectedAreaOptions[] = ['id' => $id, 'count' => $count];
    }

    $filteredUnassigned = $detectedAreaFilter === null
        ? $allUnassigned
        : array_values(array_filter($allUnassigned, fn($row) => $row['detected_area_id'] === $detectedAreaFilter));

    $totalCount = count($filteredUnassigned);
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $restaurants = array_slice($filteredUnassigned, $offset, $perPage);
} else {
    $where = ['deleted_at IS NULL'];
    $params = [];
    if ($q !== '') {
        $where[] = '(name LIKE :q OR owner_name LIKE :q OR owner_email LIKE :q OR owner_mobile LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    if ($statusFilter !== '') {
        $where[] = 'status = :status';
        $params['status'] = $statusFilter;
    }
    if ($areaFilter !== null) {
        $where[] = 'area_id = :area_id';
        $params['area_id'] = $areaFilter;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM restaurants WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetch()['c'];
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $db->prepare(
        "SELECT r.id, r.name, r.owner_name, r.owner_mobile, r.owner_email, r.status,
                r.operational_status, r.area_id, r.current_due, r.commission_percent,
                r.rating_avg, r.created_at, r.rejection_reason,
                r.address, r.pending_address, r.pending_latitude, r.pending_longitude,
                r.address_review_status, r.address_review_remarks
         FROM restaurants r
         WHERE {$whereSql}
         ORDER BY r.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $listStmt->execute($params);
    $restaurants = $listStmt->fetchAll();
}

$statusCounts = [];
foreach ($db->query("SELECT status, COUNT(*) AS c FROM restaurants WHERE deleted_at IS NULL GROUP BY status")->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['c'];
}

$csrf = admin_csrf_token();
$pageTitle = 'Restaurants (' . $totalCount . ')';
$activeNav = 'restaurants';
require __DIR__ . '/_layout_head.php';
?>
    <div class="card" style="margin-bottom:16px;">
        <form method="get" class="form-grid">
            <div>
                <label class="field-label">Search</label>
                <input type="text" name="q" value="<?= admin_escape($q) ?>" placeholder="Name, owner, email, mobile...">
            </div>
            <div>
                <label class="field-label">Status</label>
                <select name="status">
                    <option value="">All (<?= $totalCount ?>)</option>
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                            <?= ucfirst($s) ?> (<?= $statusCounts[$s] ?? 0 ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Area</label>
                <select name="area_id" id="areaFilterSelect" onchange="document.getElementById('detectedAreaRow').style.display = this.value === 'unassigned' ? '' : 'none';">
                    <option value="">All areas</option>
                    <option value="unassigned" <?= $isUnassignedFilter ? 'selected' : '' ?>>Unassigned</option>
                    <?php foreach ($areaOptions as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $areaFilter === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?> (<?= $a['level'] === 'area' ? 'Area' : 'City/Village' ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="detectedAreaRow" style="<?= $isUnassignedFilter ? '' : 'display:none;' ?>">
                <label class="field-label">Detected area</label>
                <select name="detected_area_id">
                    <option value="">Any (<?= array_sum(array_column($detectedAreaOptions, 'count')) ?>)</option>
                    <?php foreach ($detectedAreaOptions as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $detectedAreaFilter === $d['id'] ? 'selected' : '' ?>>
                            <?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[$d['id']] ?? ['name' => '#' . $d['id']], $areaNodeById)) ?> (<?= $d['count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" data-no-loading>Filter</button>
                <?php if ($q !== '' || $statusFilter !== '' || $areaFilter !== null || $isUnassignedFilter): ?>
                    <a href="restaurants.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($isUnassignedFilter && $canEdit && $totalCount > 0): ?>
            <form method="post" style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border);">
                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                <input type="hidden" name="form_action" value="bulk_auto_assign">
                <button type="submit" class="btn btn-approve"
                    data-confirm-title="Auto-assign area to <?= $totalCount ?> restaurant(s)?"
                    data-confirm-text="This acts on all <?= $totalCount ?> restaurant(s) matching the current filter, not just this page. Each one's likely area is detected live from its own lat/lng (resolve_service_area — same function used everywhere else). Restaurants with no coordinates, or no service area covering their point yet, are left unassigned and skipped."
                    data-confirm-ok-label="Auto-assign">
                    Auto-assign area — all <?= $totalCount ?> matching this filter
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (empty($restaurants)): ?>
        <div class="empty">No restaurants match this filter.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Restaurant</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th><?= $isUnassignedFilter ? 'Detected area' : 'Area' ?></th>
                    <th>Due</th>
                    <th>Commission</th>
                    <th>Rating</th>
                    <th>Applied</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($restaurants as $r): ?>
                    <tr>
                        <td><strong><?= admin_escape($r['name']) ?></strong><br>
                            <span class="muted"><?= admin_escape($r['owner_name'] ?: '—') ?></span></td>
                        <td class="muted"><?= admin_escape($r['owner_mobile'] ?: '—') ?><br><?= admin_escape($r['owner_email']) ?></td>
                        <td>
                            <span class="badge <?= $r['status'] === 'approved' ? 'active' : ($r['status'] === 'pending' ? 'system' : 'inactive') ?>">
                                <?= ucfirst($r['status']) ?>
                            </span>
                            <div class="muted" style="margin-top:3px;"><?= ucfirst(str_replace('_', ' ', $r['operational_status'])) ?></div>
                            <?php if (($r['address_review_status'] ?? 'none') === 'pending'): ?>
                                <div class="badge system" style="margin-top:3px;">Address update pending review</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isUnassignedFilter): ?>
                                <?php if ($r['detected_area_id'] !== null && isset($areaNodeById[$r['detected_area_id']])): ?>
                                    <?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[$r['detected_area_id']], $areaNodeById)) ?>
                                    <?php if ($canEdit): ?>
                                        <form method="post" style="margin-top:4px;">
                                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="form_action" value="assign_area">
                                            <input type="hidden" name="area_id" value="<?= $r['detected_area_id'] ?>">
                                            <button type="submit" class="btn btn-outline" style="padding:2px 8px; font-size:12px;">Assign</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($r['latitude'] === null || $r['longitude'] === null): ?>
                                    <span class="muted">No coordinates set</span>
                                <?php else: ?>
                                    <span class="muted">No service area covers this point</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= $r['area_id'] && isset($areaNodeById[(int) $r['area_id']]) ? admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $r['area_id']], $areaNodeById)) : '<span class="muted">Unassigned</span>' ?>
                            <?php endif; ?>
                        </td>
                        <td>₹<?= number_format((float) $r['current_due'], 2) ?></td>
                        <td><?= admin_escape((string) $r['commission_percent']) ?>%</td>
                        <td><?= $r['rating_avg'] > 0 ? number_format((float) $r['rating_avg'], 1) . ' ★' : '—' ?></td>
                        <td class="muted"><?= admin_escape(substr($r['created_at'], 0, 10)) ?></td>
                        <td>
                            <button type="button" class="btn btn-outline" data-open-dialog="manage-<?= (int) $r['id'] ?>">Manage</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="row-actions" style="margin-top:14px; justify-content:center;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn btn-outline <?= $p === $page ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($restaurants as $r): ?>
        <dialog class="modal" id="manage-<?= (int) $r['id'] ?>">
            <div class="modal-body">
                <h3 class="modal-title"><?= admin_escape($r['name']) ?></h3>
                <p class="modal-text">
                    Status: <strong><?= ucfirst($r['status']) ?></strong>
                    <?php if ($r['rejection_reason']): ?><br>Last reason: <?= admin_escape($r['rejection_reason']) ?><?php endif; ?>
                </p>

                <?php if ($canApprove): ?>
                    <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" style="margin-bottom:10px;">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Approve</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="reject">
                            <label class="field-label">Rejection reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Reject</button>
                        </form>
                    <?php elseif ($r['status'] === 'approved'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="suspend">
                            <label class="field-label">Suspension reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Suspend</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Reactivate (set Approved)</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (($r['address_review_status'] ?? 'none') === 'pending'): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <p class="modal-text"><strong>Address update pending review</strong></p>
                    <p class="modal-text">
                        Current: <?= admin_escape($r['address'] ?: '—') ?><br>
                        Requested: <?= admin_escape($r['pending_address'] ?: '—') ?>
                        <?php if ($r['pending_latitude'] !== null && $r['pending_longitude'] !== null): ?>
                            <br><span class="muted">Pin: <?= admin_escape((string) $r['pending_latitude']) ?>, <?= admin_escape((string) $r['pending_longitude']) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($canApprove): ?>
                        <form method="post" style="margin-bottom:10px;">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="review_address">
                            <input type="hidden" name="address_action" value="approve">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Approve address</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="review_address">
                            <input type="hidden" name="address_action" value="reject">
                            <label class="field-label">Rejection reason</label>
                            <textarea name="address_reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Reject address</button>
                        </form>
                    <?php endif; ?>
                <?php elseif (($r['address_review_status'] ?? 'none') === 'rejected'): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <p class="modal-text">
                        <strong>Last address change was rejected</strong><br>
                        Reason: <?= admin_escape($r['address_review_remarks'] ?: '—') ?>
                    </p>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <form method="post" class="form-grid" style="margin-bottom:10px;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="form_action" value="assign_area">
                        <div style="flex:1;">
                            <label class="field-label">Service area</label>
                            <select name="area_id" style="width:100%;">
                                <option value="">Unassigned</option>
                                <?php foreach ($areaOptions as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= (int) $r['area_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?> (<?= $a['level'] === 'area' ? 'Area' : 'City/Village' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline">Save area</button>
                    </form>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="form_action" value="update_commission">
                        <div>
                            <label class="field-label">Commission %</label>
                            <input type="number" step="0.01" min="0" max="100" name="commission_percent" value="<?= admin_escape((string) $r['commission_percent']) ?>" style="width:100px;">
                        </div>
                        <button type="submit" class="btn btn-outline">Save commission</button>
                    </form>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="form_action" value="soft_delete">
                        <button type="submit" class="btn btn-outline danger" style="width:100%;"
                            data-confirm-title="Remove <?= admin_escape($r['name']) ?>?"
                            data-confirm-text="This hides the restaurant everywhere. It stays in the database and can only be restored via direct DB access."
                            data-confirm-ok-label="Remove">Remove restaurant</button>
                    </form>
                <?php endif; ?>

                <div class="modal-actions" style="margin-top:14px;">
                    <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                </div>
            </div>
        </dialog>
    <?php endforeach; ?>
    <?php endif; ?>
<?php require __DIR__ . '/_layout_foot.php'; ?>
