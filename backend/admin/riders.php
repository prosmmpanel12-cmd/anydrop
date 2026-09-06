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
 * Migration 75 (deep-plan §22, Rider Documents) added a separate
 * Documents column/review block, gated by its own
 * `rider_documents_view`/`rider_documents_manage` pair rather than
 * riders_view/riders_approve — see that migration's own header for why
 * viewing a government ID photo is kept as a distinct permission from
 * the base account-lifecycle ones above. "View ID Doc"/"View Vehicle
 * Doc" link to documents-view.php (api/v1/rider/), which accepts this
 * page's own PHP session in addition to a rider Bearer token — see
 * that endpoint's kdoc for the two-path auth check.
 *
 * NOT tested end-to-end (no PHP/MySQL/network in the sandbox this was
 * written in) — per done.md, this is 🟡 IMPLEMENTED — TEST PENDING
 * until migration 69 has actually been run against a live DB with at
 * least one real pending rider row to approve/reject/suspend against.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
// Deep-plan §23 (Account category: Approved/Rejected/Suspended; also
// covers the document-verify/reject pair below, which the plan groups
// under Account rather than a separate category) — this file had zero
// create_notification() calls before this session despite being the
// only place any of these four transitions happen. Mirrors the
// "write the DB change, THEN notify, never inside the same try/catch
// as the real write" ordering every other call site in this codebase
// already follows (see lib/notifications.php's own header) — each
// call below sits right after its own write_audit_log() line, once
// the transition is already committed.
require_once __DIR__ . '/../lib/notifications.php';
// Deep-plan §25 (Admin Rider Command Center, "Rider list" columns:
// COD cash held, Earnings) — same rider_cod_settlement_limit() helper
// rider-settlements.php already uses, reused here rather than
// re-reading the app_settings row a second way.
require_once __DIR__ . '/../lib/rider_ledger.php';

$admin = admin_require_login();
admin_require_permission($admin, 'riders_view');
$canEdit = admin_has_permission($admin['id'], 'riders_edit');
$canApprove = admin_has_permission($admin['id'], 'riders_approve');
// Same permission key rider-settlements.php/rider-earnings.php already
// gate on — this list only *links* to those two existing screens per
// rider rather than duplicating their detail views, so it reuses their
// gate rather than introducing a third permission key for the same
// data.
$canViewPayouts = admin_has_permission($admin['id'], 'payouts_view');
// Migration 75 (deep-plan §22) — deliberately separate permission from
// the three above, same "viewing/actioning a government ID photo is
// its own blast radius" reasoning that migration's own header gives.
$canViewDocuments = admin_has_permission($admin['id'], 'rider_documents_view');
$canManageDocuments = admin_has_permission($admin['id'], 'rider_documents_manage');
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
            "SELECT id, name, status, documents_status FROM riders
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
                    $fromStatus = $rider['status'];
                    $upd = $db->prepare("UPDATE riders SET status = 'approved', rejection_reason = NULL WHERE id = :id");
                    $upd->execute(['id' => $riderId]);
                    write_audit_log('admin', $admin['id'], 'rider_approved', ['rider_id' => $riderId, 'from_status' => $fromStatus]);
                    // "Approved" and "Reactivated" are the same DB
                    // transition (approve, from any prior status) but
                    // deep-plan §23 lists them as two distinct events —
                    // the wording only differs so a rider who was never
                    // rejected/suspended doesn't get a confusing
                    // "reactivated" message on their very first approval.
                    create_notification(
                        'rider', $riderId,
                        $fromStatus === 'pending' ? 'Account approved' : 'Account reactivated',
                        $fromStatus === 'pending'
                            ? 'Your rider account has been approved. You can now go online and start accepting deliveries.'
                            : 'Your rider account has been reactivated. You can now go online and start accepting deliveries.',
                        'account', ['screen' => 'dashboard']
                    );
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
                        create_notification(
                            'rider', $riderId, 'Account rejected',
                            'Your rider application was rejected: ' . $reason,
                            'account', ['screen' => 'application_status']
                        );
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
                        create_notification(
                            'rider', $riderId, 'Account suspended',
                            'Your rider account has been suspended: ' . $reason,
                            'account', ['screen' => 'application_status']
                        );
                        $flash = admin_escape($rider['name']) . ' suspended.';
                    }
                }
            }
        } elseif ($formAction === 'verify_documents' || $formAction === 'reject_documents') {
            // Migration 75 — mirrors settlements.php's
            // verify_bank_details/reject_bank_details action pair
            // exactly (same form_action-value-picks-the-status trick,
            // same "remark/reason required only to reject" rule).
            if (!$canManageDocuments) {
                $flash = 'Your role doesn\'t have the rider_documents_manage permission.';
                $flashType = 'error';
            } elseif ($rider['documents_status'] !== 'pending') {
                $flash = 'Only documents currently under review (pending) can be verified or rejected — this rider\'s documents are ' . $rider['documents_status'] . '.';
                $flashType = 'error';
            } else {
                $newDocStatus = $formAction === 'verify_documents' ? 'verified' : 'rejected';
                $reason = trim($_POST['reason'] ?? '');

                if ($newDocStatus === 'rejected' && $reason === '') {
                    $flash = 'A reason is required when rejecting documents, so the rider knows what to fix.';
                    $flashType = 'error';
                } else {
                    $upd = $db->prepare(
                        'UPDATE riders
                         SET documents_status = :status, documents_reject_reason = :reason,
                             documents_verified_by_admin_id = :admin_id, documents_verified_at = NOW()
                         WHERE id = :id'
                    );
                    $upd->execute([
                        'status' => $newDocStatus,
                        'reason' => $newDocStatus === 'rejected' ? $reason : null,
                        'admin_id' => $admin['id'],
                        'id' => $riderId,
                    ]);
                    write_audit_log('admin', $admin['id'], 'rider_documents_' . $newDocStatus, ['rider_id' => $riderId, 'reason' => $reason ?: null]);
                    // Not in deep-plan §23's own Account list verbatim
                    // (that list predates migration 75), but it's the
                    // same "tell the rider their submission was
                    // actioned" need as Approved/Rejected/Suspended
                    // above, so it gets the same 'account' notification
                    // type rather than inventing a fifth one.
                    create_notification(
                        'rider', $riderId,
                        $newDocStatus === 'verified' ? 'Documents verified' : 'Documents rejected',
                        $newDocStatus === 'verified'
                            ? 'Your submitted documents have been verified.'
                            : 'Your documents were rejected: ' . $reason . '. Please re-submit.',
                        'account', ['screen' => 'submit_documents']
                    );
                    $flash = $newDocStatus === 'verified' ? 'Documents verified.' : 'Documents rejected.';
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

$where = ['r.deleted_at IS NULL', 'r.restaurant_id IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(r.name LIKE :q OR r.email LIKE :q OR r.mobile LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($statusFilter !== '') {
    $where[] = 'r.status = :status';
    $params['status'] = $statusFilter;
}
if ($areaFilter !== null) {
    $where[] = 'r.service_area_id = :area_id';
    $params['area_id'] = $areaFilter;
}
$whereSql = implode(' AND ', $where);

// Aliased "r" (rather than the unprefixed style every other WHERE
// clause in this file otherwise uses) only because of the new
// current-order LEFT JOIN below — orders has its own restaurant_id/
// status columns, so an unqualified WHERE would be ambiguous the
// moment that join is present. Applied to the count query too even
// though it has no join, just so $whereSql stays one shared string
// for both rather than two near-identical copies.
$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM riders r WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Deep-plan §25 (Admin Rider Command Center, "Rider list"): current
// order via a correlated subquery picking the single highest-id
// active order per rider (mirrors orders-current.php's own "ORDER BY
// o.id DESC LIMIT 1" convention for "the current one"), then LEFT
// JOIN orders on that specific id — deliberately not a plain
// `LEFT JOIN orders ON orders.rider_id = r.id AND status IN (...)`,
// which would silently duplicate a rider's row if the "no conflicting
// active order" assignment rule (deep-plan §4.1) is ever violated or
// relaxed for batching later. is_online/last_location_at/
// cod_cash_held/earnings_balance are the same columns location.php,
// rider_ledger.php and earnings-summary.php already read/write —
// nothing new is added to the riders table this session, only
// surfaced here.
$listStmt = $db->prepare(
    "SELECT r.id, r.name, r.email, r.mobile, r.status, r.service_area_id, r.vehicle_type,
            r.vehicle_number, r.rejection_reason, r.created_at,
            r.documents_status, r.documents_reject_reason, r.id_doc_url, r.vehicle_doc_url,
            r.profile_photo_url,
            r.is_online, r.last_location_at, r.cod_cash_held, r.earnings_balance,
            co.order_code AS current_order_code, co.status AS current_order_status
     FROM riders r
     LEFT JOIN orders co ON co.id = (
         SELECT o.id FROM orders o
         WHERE o.rider_id = r.id AND o.status IN ('rider_assigned', 'picked_up', 'out_for_delivery')
         ORDER BY o.id DESC LIMIT 1
     )
     WHERE {$whereSql}
     ORDER BY r.created_at ASC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$riders = $listStmt->fetchAll();

// Same setting/default dispatch.php enforces COD-assignment blocking
// against and rider-settlements.php already displays — reused here so
// this list's "at/over limit" flag can never disagree with either.
$settlementLimit = rider_cod_settlement_limit();
$currentOrderStatusLabels = [
    'rider_assigned' => 'Rider Assigned',
    'picked_up' => 'Picked Up',
    'out_for_delivery' => 'Out for Delivery',
];

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
                    <?php if ($canViewDocuments): ?><th>Documents</th><?php endif; ?>
                    <th>Area</th>
                    <th>Vehicle</th>
                    <th>Online</th>
                    <th>Current order</th>
                    <?php if ($canViewPayouts): ?><th>COD held</th><th>Earnings</th><?php endif; ?>
                    <th>Applied</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($riders as $r): ?>
                    <tr>
                        <td><strong><a href="rider-detail.php?rider_id=<?= (int) $r['id'] ?>"><?= admin_escape($r['name']) ?></a></strong></td>
                        <td class="muted"><?= admin_escape($r['mobile'] ?: '—') ?><br><?= admin_escape($r['email'] ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $r['status'] === 'approved' ? 'active' : ($r['status'] === 'pending' ? 'system' : 'inactive') ?>">
                                <?= ucfirst($r['status']) ?>
                            </span>
                        </td>
                        <?php if ($canViewDocuments): ?>
                        <td>
                            <span class="badge <?= $r['documents_status'] === 'verified' ? 'active' : ($r['documents_status'] === 'pending' ? 'system' : 'inactive') ?>">
                                <?= ucfirst(str_replace('_', ' ', $r['documents_status'])) ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td><?= $r['service_area_id'] && isset($areaNodeById[(int) $r['service_area_id']]) ? admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $r['service_area_id']], $areaNodeById)) : '<span class="muted">Unassigned</span>' ?></td>
                        <td class="muted"><?= admin_escape($r['vehicle_type'] ?: '—') ?><?= $r['vehicle_number'] ? ' · ' . admin_escape($r['vehicle_number']) : '' ?></td>
                        <td>
                            <span class="badge <?= ((int) $r['is_online']) === 1 ? 'active' : 'inactive' ?>"><?= ((int) $r['is_online']) === 1 ? 'Online' : 'Offline' ?></span>
                            <br><span class="muted" style="font-size:11px;">Seen <?= admin_escape(admin_time_ago($r['last_location_at'])) ?></span>
                        </td>
                        <td>
                            <?php if ($r['current_order_code']): ?>
                                <span class="badge system"><?= admin_escape($currentOrderStatusLabels[$r['current_order_status']] ?? $r['current_order_status']) ?></span>
                                <br><span class="muted" style="font-size:11px;">#<?= admin_escape($r['current_order_code']) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canViewPayouts): ?>
                        <td>
                            <?php $codHeld = (float) $r['cod_cash_held']; ?>
                            <?php if ($codHeld >= $settlementLimit && $codHeld > 0): ?>
                                <span class="badge inactive">⚠ ₹<?= admin_escape(number_format($codHeld, 2)) ?></span>
                            <?php else: ?>
                                ₹<?= admin_escape(number_format($codHeld, 2)) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $owed = (float) $r['earnings_balance']; ?>
                            <?php if ($owed > 0): ?>
                                <span class="badge system">₹<?= admin_escape(number_format($owed, 2)) ?></span>
                            <?php else: ?>
                                <span class="muted">₹0.00</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
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

                <div class="row-actions" style="margin-bottom:10px;">
                    <a class="btn btn-outline" href="rider-detail.php?rider_id=<?= (int) $r['id'] ?>">View full detail (orders, location, audit trail)</a>
                </div>

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

                <?php if ($canViewDocuments): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <p class="modal-text">
                        Documents: <strong><?= ucfirst(str_replace('_', ' ', $r['documents_status'])) ?></strong>
                        <?php if ($r['documents_reject_reason']): ?><br>Last reason: <?= admin_escape($r['documents_reject_reason']) ?><?php endif; ?>
                    </p>
                    <div class="row-actions" style="margin-bottom:10px;">
                        <?php if ($r['id_doc_url']): ?>
                            <a class="btn btn-outline" target="_blank" rel="noopener"
                               href="/api/v1/rider/documents-view.php?rider_id=<?= (int) $r['id'] ?>&doc=id">View ID Doc</a>
                        <?php else: ?>
                            <span class="muted">No ID doc submitted</span>
                        <?php endif; ?>
                        <?php if ($r['vehicle_doc_url']): ?>
                            <a class="btn btn-outline" target="_blank" rel="noopener"
                               href="/api/v1/rider/documents-view.php?rider_id=<?= (int) $r['id'] ?>&doc=vehicle">View Vehicle Doc</a>
                        <?php else: ?>
                            <span class="muted">No vehicle doc submitted</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($canManageDocuments && $r['documents_status'] === 'pending'): ?>
                        <form method="post" style="margin-bottom:10px;">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="verify_documents">
                            <button type="submit" class="btn btn-approve" style="width:100%;">Verify Documents</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="form_action" value="reject_documents">
                            <label class="field-label">Rejection reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;">Reject Documents</button>
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

                <?php if ($canViewPayouts): ?>
                    <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
                    <div class="row-actions" style="margin-bottom:4px;">
                        <a class="btn btn-outline" href="rider-settlements.php?rider_id=<?= (int) $r['id'] ?>">COD Settlement</a>
                        <a class="btn btn-outline" href="rider-earnings.php?rider_id=<?= (int) $r['id'] ?>">Earnings Ledger</a>
                    </div>
                <?php endif; ?>

                <div class="modal-actions" style="margin-top:14px;">
                    <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                </div>
            </div>
        </dialog>
    <?php endforeach; ?>
    <?php endif; ?>
<?php require __DIR__ . '/_layout_foot.php'; ?>
