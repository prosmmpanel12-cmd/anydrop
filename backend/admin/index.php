<?php
/**
 * Anydrop — Admin Web UI: Pending Restaurant Approvals
 *
 * The screen this whole slice exists for: self-signup
 * (auth/restaurant-signup.php) has been producing `status = 'pending'`
 * restaurant rows since restaurant-app launch, with no way to approve or
 * reject one except a manual DB UPDATE — flagged as overdue in
 * docs/18/docs/restorent/00_Status.md across multiple sessions.
 *
 * Approve/reject write to the same `write_audit_log('admin', ...)` audit
 * trail every other sensitive action in this codebase uses
 * (lib/audit.php) — same actor_type as restaurant/customer logins, so
 * `audit_logs` stays one consistent table across the whole platform.
 *
 * Gated on `restaurants_view` (not `dashboard_view`) since this page's
 * actual content is the restaurant list/approval queue — `dashboard_
 * view` is reserved for a future general stats dashboard (recall.md
 * Phase A item 3), a different, not-yet-built page. A Restaurant
 * Manager role (restaurants_view/edit/approve only, no dashboard_view)
 * must be able to reach this page.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'restaurants_view');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_has_permission($admin['id'], 'restaurants_approve')) {
        http_response_code(403);
        $flash = 'Your role doesn\'t have the restaurants_approve permission.';
        $flashType = 'error';
    } elseif (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $restaurantId = (int) ($_POST['restaurant_id'] ?? 0);
        $action = $_POST['action'] ?? '';

        $stmt = $db->prepare("SELECT id, name, status FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $restaurantId]);
        $restaurant = $stmt->fetch();

        if (!$restaurant) {
            $flash = 'Restaurant not found.';
            $flashType = 'error';
        } elseif ($restaurant['status'] !== 'pending') {
            $flash = 'That restaurant is no longer pending (already ' . $restaurant['status'] . ').';
            $flashType = 'error';
        } elseif ($action === 'approve') {
            $upd = $db->prepare("UPDATE restaurants SET status = 'approved', rejection_reason = NULL WHERE id = :id");
            $upd->execute(['id' => $restaurantId]);
            write_audit_log('admin', $admin['id'], 'restaurant_approved', ['restaurant_id' => $restaurantId]);
            $flash = admin_escape($restaurant['name']) . ' approved.';
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                $flash = 'A rejection reason is required.';
                $flashType = 'error';
            } else {
                $upd = $db->prepare("UPDATE restaurants SET status = 'rejected', rejection_reason = :r WHERE id = :id");
                $upd->execute(['r' => $reason, 'id' => $restaurantId]);
                write_audit_log('admin', $admin['id'], 'restaurant_rejected', ['restaurant_id' => $restaurantId, 'reason' => $reason]);
                $flash = admin_escape($restaurant['name']) . ' rejected.';
            }
        }
    }
}

$pending = $db->query(
    "SELECT id, name, owner_name, owner_mobile, owner_email, address,
            cuisine_tags, gst_number, fssai_number, created_at
     FROM restaurants
     WHERE status = 'pending' AND deleted_at IS NULL
     ORDER BY created_at ASC"
)->fetchAll();

$csrf = admin_csrf_token();
$canApprove = admin_has_permission($admin['id'], 'restaurants_approve');

$pageTitle = 'Pending Restaurants (' . count($pending) . ')';
$activeNav = 'approvals';
require __DIR__ . '/_layout_head.php';
?>
    <?php if (empty($pending)): ?>
        <div class="empty">No restaurants waiting for approval right now.</div>
    <?php endif; ?>

    <?php foreach ($pending as $r): ?>
        <div class="card" style="margin-bottom:14px;">
            <h2><?= admin_escape($r['name']) ?></h2>
            <span class="muted">Applied <?= admin_escape($r['created_at']) ?></span>
            <div class="muted" style="font-size:13px; line-height:1.7; margin:10px 0; color:var(--text);">
                <strong>Owner:</strong> <?= admin_escape($r['owner_name'] ?: '—') ?><br>
                <strong>Mobile:</strong> <?= admin_escape($r['owner_mobile'] ?: '—') ?> &nbsp;·&nbsp;
                <strong>Email:</strong> <?= admin_escape($r['owner_email']) ?><br>
                <strong>Address:</strong> <?= admin_escape($r['address'] ?: '—') ?><br>
                <strong>Cuisine:</strong> <?= admin_escape($r['cuisine_tags'] ?: '—') ?><br>
                <strong>GST:</strong> <?= admin_escape($r['gst_number'] ?: '—') ?> &nbsp;·&nbsp;
                <strong>FSSAI:</strong> <?= admin_escape($r['fssai_number'] ?: '—') ?>
            </div>
            <?php if ($canApprove): ?>
            <div class="row-actions">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-approve"
                        data-confirm-title="Approve <?= admin_escape($r['name']) ?>?"
                        data-confirm-text="They'll be able to go live and start receiving orders."
                        data-confirm-ok-label="Approve">Approve</button>
                </form>
                <button type="button" class="btn btn-outline danger" data-open-dialog="reject-<?= (int) $r['id'] ?>">Reject</button>
            </div>
            <dialog class="modal" id="reject-<?= (int) $r['id'] ?>">
                <div class="modal-body">
                    <h3 class="modal-title">Reject <?= admin_escape($r['name']) ?></h3>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <label class="field-label">Reason (shown to the restaurant)</label>
                        <textarea name="reason" style="width:100%; min-height:80px;" required></textarea>
                        <div class="modal-actions" style="margin-top:14px;">
                            <button type="button" class="btn btn-outline" data-close-dialog>Cancel</button>
                            <button type="submit" class="btn btn-outline danger">Confirm Reject</button>
                        </div>
                    </form>
                </div>
            </dialog>
            <?php else: ?>
            <div class="muted">View only — your role doesn't include restaurant approval.</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php require __DIR__ . '/_layout_foot.php'; ?>
