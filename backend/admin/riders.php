<?php
/**
 * Anydrop — Admin Web UI: Rider Management (platform / self-signed-up riders)
 *
 * Built as the flagged next-session TODO from
 * docs/79_Handover_2026-09-01_Rider_App_Phase1_Backend_Signup_OTP.md
 * (§ "Known gaps / next-session TODO", item 4): rider-signup.php has
 * been producing `status = 'pending'` rider rows with no admin UI to
 * approve/reject them — same gap index.php once existed for
 * restaurants before restaurants.php was built. Deliberately mirrors
 * restaurants.php's shape (search/filter/pagination + per-row Manage
 * dialog with full status lifecycle) rather than index.php's simpler
 * pending-only queue, since a "Riders" screen an admin reaches for
 * daily should also let them look up an already-approved rider, not
 * just triage new applications.
 *
 * Scope: **platform riders only** (`restaurant_id IS NULL`) — the
 * self-signup model from migration 69. Restaurant-created riders
 * (`restaurant_id NOT NULL`, username/password, no self-service
 * status lifecycle) are a different, older product managed by the
 * restaurant itself; they're deliberately excluded from this list so
 * "pending" here always means "a platform rider is waiting on admin",
 * never a restaurant-internal row. rider-settlements.php's COD list is
 * the one existing admin screen that already touches the riders table
 * for both kinds of rider — unaffected by this page since it queries
 * on cod_cash_held, not status.
 *
 * Status lifecycle (riders.status enum, migration 69 — mirrors
 * restaurants.status exactly):
 *   pending            -> approve | reject (reason required)
 *   approved           -> suspend (reason required)
 *   rejected/suspended -> approve (== reactivate; also clears the
 *                                   stored reason)
 * Reuses `riders.rejection_reason` (added by migration 69) for either
 * a rejection or a suspension reason, same dual-purpose-column
 * convention `restaurants.rejection_reason` already established.
 *
 * Every transition writes to the same audit_logs trail every other
 * admin status change in this codebase uses.
 *
 * Gated: `riders_view` to see this page at all (permission key already
 * seeded by migration 29 — unused until now); `riders_edit` for area
 * reassignment; `riders_approve` for every status transition. No
 * riders_delete key was seeded, so there's no soft-delete action here
 * — suspend covers "stop this rider from working" without a separate
 * delete concept, same as how a restaurant is suspended rather than
 * deleted.
 *
 * NOT tested end-to-end (no PHP/MySQL/network in the sandbox this was
 * written in) — per done.md, this is 🟡 IMPLEMENTED — TEST PENDING
 * until migration 69 has actually been run against a live DB with at
 * least one real pending rider row to approve/reject/suspend against.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'riders_view');
$canEdit = admin_has_permission($admin['id'], 'riders_edit');
$canApprove = admin_has_permission($admin['id'], 'riders_approve');
$db = Database::get();

$flash = null;
$flashType = 'success';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $riderId = (int) ($_POST['rider_id'] ?? 0);

        $stmt = $db->prepare(
            "SELECT id, name, status FROM riders
             WHERE id = :id AND deleted_at IS NULL AND restaurant_id IS NULL LIMIT 1"
        );
        $stmt->execute(['id' => $riderId]);
        $rider = $stmt->fetch();

        if (!$rider) {
            $flash = 'Rider not found.';
            $flashType = 'error';
        } elseif ($formAction === 'change_status') {
            if (!$canApprove) {
                $flash = 'Your role doesn\'t have the riders_approve permission.';
                $flashType = 'error';
            } else {
                $action = $_POST['action'] ?? '';
                $reason = trim($_POST['reason'] ?? '');

                if ($action === 'approve') {
                    // Covers pending->approved AND reactivating a rejected/suspended rider.
                    $upd = $db->prepare("UPDATE riders SET status = 'approved', rejection_reason = NULL WHERE id = :id");
                    $upd->execute(['id' => $riderId]);
                    write_audit_log('admin', $admin['id'], 'rider_approved', ['rider_id' => $riderId, 'from_status' => $rider['status']]);
                    $flash = admin_escape($rider['name']) . ' is now approved.';
                } elseif ($action === 'reject') {
                    if ($rider['status'] !== 'pending') {
                        $flash = 'Only a pending rider can be rejected — this one is ' . $rider['status'] . '.';
                        $flashType = 'error';
                    } elseif ($reason === '') {
                        $flash = 'A rejection reason is required.';
                        $flashType = 'error';
                    } else {
                        $upd = $db->prepare("UPDATE riders SET status = 'rejected', rejection_reason = :r WHERE id = :id");
                        $upd->execute(['r' => $reason, 'id' => $riderId]);
                        write_audit_log('admin', $admin['id'], 'rider_rejected', ['rider_id' => $riderId, 'reason' => $reason]);
                        $flash = admin_escape($rider['name']) . ' rejected.';
                    }
                } elseif ($action === 'suspend') {
                    if ($rider['status'] !== 'approved') {
                        $flash = 'Only an approved rider can be suspended.';
                        $flashType = 'error';
                    } elseif ($reason === '') {
                        $flash = 'A suspension reason is required.';
                        $flashType = 'error';
                    } else {
                        $upd = $db->prepare("UPDATE riders SET status = 'suspended', rejection_reason = :r WHERE id = :id");
                        $upd->execute(['r' => $reason, 'id' => $riderId]);
                        write_audit_log('admin', $admin['id'], 'rider_suspended', ['rider_id' => $riderId, 'reason' => $reason]);
                        $flash = admin_escape($rider['name']) . ' suspended.';
                    }
                }
            }
        } elseif ($formAction === 'assign_area') {
            if (!$canEdit) {
                $flash = 'Your role doesn\'t have the riders_edit permission.';
                $flashType = 'error';
            } else {
                $areaId = trim($_POST['area_id'] ?? '') !== '' ? (int) $_POST['area_id'] : null;
                if ($areaId !== null) {
                    $chk = $db->prepare('SELECT id FROM service_areas WHERE id = :id LIMIT 1');
                    $chk->execute(['id' => $areaId]);
                    if (!$chk->fetch()) {
                        $areaId = null;
                        $flash = 'Selected area not found — clearing area instead.';
                        $flashType = 'error';
                    }
                }
                if ($flash === null) {
                    $upd = $db->prepare('UPDATE riders SET service_area_id = :a WHERE id = :id');
                    $upd->execute(['a' => $areaId, 'id' => $riderId]);
                    write_audit_log('admin', $admin['id'], 'rider_area_assigned', ['rider_id' => $riderId, 'area_id' => $areaId]);
                    $flash = 'Area updated for ' . admin_escape($rider['name']) . '.';
                }
            }
        }
    }
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$areaFilter = trim($_GET['area_id'] ?? '') !== '' ? (int) $_GET['area_id'] : null;
$validStatuses = ['pending', 'approved', 'rejected', 'suspended'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ['deleted_at IS NULL', 'restaurant_id IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR email LIKE :q OR mobile LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params['status'] = $statusFilter;
}
if ($areaFilter !== null) {
    $where[] = 'service_area_id = :area_id';
    $params['area_id'] = $areaFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM riders WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT id, name, email, mobile, status, service_area_id, vehicle_type,
            vehicle_number, rejection_reason, created_at
     FROM riders
     WHERE {$whereSql}
     ORDER BY created_at ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$riders = $listStmt->fetchAll();

// Area dropdown — every active node, since a rider (unlike a
// restaurant) is reasonably assigned at any level their signup
// resolved to; deepest-in-branch isn't enforced here the way
// restaurants.php enforces it for delivery-radius matching.
$areaOptions = $db->query(
    'SELECT id, name, level FROM service_areas WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}

$statusCounts = [];
foreach (
    $db->query(
        "SELECT status, COUNT(*) AS c FROM riders WHERE deleted_at IS NULL AND restaurant_id IS NULL GROUP BY status"
    )->fetchAll() as $row
) {
    $statusCounts[$row['status']] = (int) $row['c'];
}

$csrf = admin_csrf_token();
$pageTitle = 'Riders (' . $totalCount . ')';
$activeNav = 'riders';
require __DIR__ . '/_layout_head.php';
?>
    <div class="card" style="margin-bottom:16px;">
        <form method="get" class="form-grid">
            <div>
                <label class="field-label">Search</label>
                <input type="text" name="q" value="<?= admin_escape($q) ?>" placeholder="Name, email, mobile...">
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
                <select name="area_id">
                    <option value="">All areas</option>
                    <?php foreach ($areaOptions as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $areaFilter === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" data-no-loading>Filter</button>
                <?php if ($q !== '' || $statusFilter !== '' || $areaFilter !== null): ?>
                    <a href="riders.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($riders)): ?>
        <div class="empty">No riders match this filter.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th>Area</th>
                    <th>Vehicle</th>
                    <th>Applied</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($riders as $r): ?>
                    <tr>
                        <td><strong><?= admin_escape($r['name']) ?></strong></td>
                        <td class="muted"><?= admin_escape($r['mobile'] ?: '—') ?><br><?= admin_escape($r['email'] ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $r['status'] === 'approved' ? 'active' : ($r['status'] === 'pending' ? 'system' : 'inactive') ?>">
                                <?= ucfirst($r['status']) ?>
                            </span>
                        </td>
                        <td><?= $r['service_area_id'] && isset($areaNodeById[(int) $r['service_area_id']]) ? admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $r['service_area_id']], $areaNodeById)) : '<span class="muted">Unassigned</span>' ?></td>
                        <td class="muted"><?= admin_escape($r['vehicle_type'] ?: '—') ?><?= $r['vehicle_number'] ? ' · ' . admin_escape($r['vehicle_number']) : '' ?></td>
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

    <?php foreach ($riders as $r): ?>
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
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Approve</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="reject">
                            <label class="field-label">Rejection reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Reject</button>
                        </form>
                    <?php elseif ($r['status'] === 'approved'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="suspend">
                            <label class="field-label">Suspension reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Suspend</button>
                        </form>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="change_status">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Reactivate (set Approved)</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($canEdit): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="form_action" value="assign_area">
                        <div style="flex:1;">
                            <label class="field-label">Service area</label>
                            <select name="area_id" style="width:100%;">
                                <option value="">Unassigned</option>
                                <?php foreach ($areaOptions as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= (int) $r['service_area_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline">Save area</button>
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
