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
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anydrop Admin — Pending Restaurants</title>
<style>
    :root { color-scheme: light; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #f4f5f7; margin: 0; color: #1a1a1a; }
    header { background: #fff; border-bottom: 1px solid #eaeaea; padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; }
    header h1 { font-size: 17px; margin: 0; }
    header a { color: #888; font-size: 13px; text-decoration: none; }
    header a:hover { text-decoration: underline; }
    main { max-width: 900px; margin: 24px auto; padding: 0 16px; }
    .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
    .flash.success { background: #e6f4ea; color: #1e7e34; }
    .flash.error { background: #fdecea; color: #b3261e; }
    .empty { background: #fff; border-radius: 10px; padding: 40px 24px; text-align: center; color: #888; }
    .card { background: #fff; border-radius: 10px; padding: 18px 20px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
    .card h2 { font-size: 16px; margin: 0 0 4px; }
    .meta { font-size: 13px; color: #666; line-height: 1.6; margin: 10px 0; }
    .meta strong { color: #333; }
    .actions { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; align-items: center; }
    .btn { padding: 8px 16px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; }
    .btn-approve { background: #1e7e34; color: #fff; }
    .btn-approve:hover { background: #17672a; }
    .btn-reject { background: #fff; color: #b3261e; border: 1px solid #b3261e; }
    .btn-reject:hover { background: #fdecea; }
    .reject-box { display: none; margin-top: 10px; }
    .reject-box.open { display: block; }
    .reject-box textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ddd; border-radius: 7px; font-size: 13px; font-family: inherit; min-height: 60px; resize: vertical; }
    .reject-box .btn { margin-top: 8px; }
    .waiting { font-size: 12px; color: #999; }
</style>
</head>
<body>
<header>
    <h1>Anydrop Admin — Pending Restaurants (<?= count($pending) ?>)</h1>
    <div>
        <span style="font-size:13px; color:#888; margin-right:14px;"><?= admin_escape($admin['username']) ?></span>
        <a href="logout.php">Log out</a>
    </div>
</header>
<main>
    <?php if ($flash): ?>
        <div class="flash <?= $flashType ?>"><?= $flash ?></div>
    <?php endif; ?>

    <?php if (empty($pending)): ?>
        <div class="empty">No restaurants waiting for approval right now.</div>
    <?php endif; ?>

    <?php foreach ($pending as $r): ?>
        <div class="card">
            <h2><?= admin_escape($r['name']) ?></h2>
            <span class="waiting">Applied <?= admin_escape($r['created_at']) ?></span>
            <div class="meta">
                <strong>Owner:</strong> <?= admin_escape($r['owner_name'] ?: '—') ?><br>
                <strong>Mobile:</strong> <?= admin_escape($r['owner_mobile'] ?: '—') ?> &nbsp;·&nbsp;
                <strong>Email:</strong> <?= admin_escape($r['owner_email']) ?><br>
                <strong>Address:</strong> <?= admin_escape($r['address'] ?: '—') ?><br>
                <strong>Cuisine:</strong> <?= admin_escape($r['cuisine_tags'] ?: '—') ?><br>
                <strong>GST:</strong> <?= admin_escape($r['gst_number'] ?: '—') ?> &nbsp;·&nbsp;
                <strong>FSSAI:</strong> <?= admin_escape($r['fssai_number'] ?: '—') ?>
            </div>
            <div class="actions">
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-approve" onclick="return confirm('Approve <?= admin_escape(addslashes($r['name'])) ?>?');">Approve</button>
                </form>
                <button type="button" class="btn btn-reject" onclick="document.getElementById('reject-<?= (int) $r['id'] ?>').classList.toggle('open');">Reject</button>
            </div>
            <div class="reject-box" id="reject-<?= (int) $r['id'] ?>">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="restaurant_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <textarea name="reason" placeholder="Reason for rejecting (shown to the restaurant)" required></textarea>
                    <button type="submit" class="btn btn-reject">Confirm Reject</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</main>
</body>
</html>
