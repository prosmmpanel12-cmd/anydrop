<?php
/**
 * Anydrop — Admin Web UI: Review Moderation (migration 54).
 *
 * Closes PENDING.md item 8's remaining checklist: admin reported-review
 * queue, moderation, hide/remove workflow, audit log. (Customer
 * report-review action + report reason are api/v1/customer/report-
 * review.php; abuse protection is that endpoint's uq_review_report_once
 * DB constraint.)
 *
 * Two tabs:
 *   - Reported (default): reviews.is_reported = 1 AND moderation_status
 *     = 'visible' — needs an admin decision. Each row shows every report
 *     reason on file for it (review_reports, could be several
 *     customers).
 *   - Hidden: already actioned reviews, with a Restore (undo) option.
 *
 * Actions go through lib/reviews.php's hide_review()/restore_review()/
 * dismiss_review_report() — this file only calls them + writes the audit
 * log, same division of labour as rider-settlements.php uses for
 * lib/rider_ledger.php.
 *
 * Gated on reviews_moderate (new permission, migration 54).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/reviews.php';

$admin = admin_require_login();
admin_require_permission($admin, 'reviews_moderate');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $reviewId = (int) ($_POST['review_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        try {
            if ($action === 'hide') {
                $reason = trim((string) ($_POST['hide_reason'] ?? '')) ?: null;
                hide_review($db, $reviewId, (int) $admin['id'], $reason);
                write_audit_log('admin', $admin['id'], 'review_hidden', ['review_id' => $reviewId, 'reason' => $reason]);
                $flash = 'Review hidden — it no longer counts toward the restaurant\'s rating.';
            } elseif ($action === 'dismiss') {
                dismiss_review_report($db, $reviewId);
                write_audit_log('admin', $admin['id'], 'review_report_dismissed', ['review_id' => $reviewId]);
                $flash = 'Report dismissed — review stays visible.';
            } elseif ($action === 'restore') {
                restore_review($db, $reviewId, (int) $admin['id']);
                write_audit_log('admin', $admin['id'], 'review_restored', ['review_id' => $reviewId]);
                $flash = 'Review restored — visible again.';
            } else {
                $flash = 'Unknown action.';
                $flashType = 'error';
            }
        } catch (Throwable $e) {
            $flash = 'Could not complete that action.';
            $flashType = 'error';
        }
    }
}

$csrf = admin_csrf_token();
$activeNav = 'review_moderation';
$tab = ($_GET['tab'] ?? 'reported') === 'hidden' ? 'hidden' : 'reported';

if ($tab === 'hidden') {
    $rows = $db->query(
        "SELECT r.id, r.restaurant_id, rs.name AS restaurant_name, r.customer_id, c.name AS customer_name,
                r.restaurant_rating, r.food_rating, r.delivery_rating, r.comment,
                r.hidden_reason, r.moderated_at, r.created_at
         FROM reviews r
         JOIN restaurants rs ON rs.id = r.restaurant_id
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE r.moderation_status = 'hidden'
         ORDER BY r.moderated_at DESC
         LIMIT 200"
    )->fetchAll();
} else {
    $rows = $db->query(
        "SELECT r.id, r.restaurant_id, rs.name AS restaurant_name, r.customer_id, c.name AS customer_name,
                r.restaurant_rating, r.food_rating, r.delivery_rating, r.comment, r.created_at
         FROM reviews r
         JOIN restaurants rs ON rs.id = r.restaurant_id
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE r.is_reported = 1 AND r.moderation_status = 'visible'
         ORDER BY r.created_at DESC
         LIMIT 200"
    )->fetchAll();

    // Pull every report reason for the reviews on this page in one query
    // rather than one query per row. Migration 56: a report row now has
    // either customer_id or restaurant_id set (never both) — the reporter
    // label reflects whichever one is present, so a restaurant's
    // self-report on its own review reads "Restaurant (self-report)"
    // instead of a customer name.
    $reasonsByReview = [];
    if (!empty($rows)) {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reasonStmt = $db->prepare(
            "SELECT rr.review_id, rr.reason, rr.created_at, rr.restaurant_id, c.name AS reporter_customer_name
             FROM review_reports rr
             LEFT JOIN customers c ON c.id = rr.customer_id
             WHERE rr.review_id IN ($placeholders)
             ORDER BY rr.created_at"
        );
        $reasonStmt->execute($ids);
        foreach ($reasonStmt->fetchAll() as $r) {
            $reporterLabel = $r['restaurant_id'] !== null
                ? 'Restaurant (self-report)'
                : ($r['reporter_customer_name'] ?? 'Customer');
            $reasonsByReview[(int) $r['review_id']][] = $reporterLabel . ': ' . $r['reason'];
        }
    }
}

$pageTitle = 'Review Moderation';
require __DIR__ . '/_layout_head.php';
?>
<div class="section">
<div class="card">
    <h2>Review Moderation</h2>
    <p class="muted">Reviews customers have reported. Hiding a review removes it from the restaurant's public rating; dismissing a report just clears the flag and leaves the review as-is.</p>
    <div class="tabs">
        <a href="review-moderation.php?tab=reported" class="btn <?= $tab === 'reported' ? 'btn-primary' : 'btn-outline' ?>">Reported</a>
        <a href="review-moderation.php?tab=hidden" class="btn <?= $tab === 'hidden' ? 'btn-primary' : 'btn-outline' ?>">Hidden</a>
    </div>
</div>

<?php if (!empty($rows)): ?>
<?php foreach ($rows as $row): ?>
<div class="card">
    <p>
        <strong><?= admin_escape($row['restaurant_name']) ?></strong>
        &nbsp;·&nbsp; <span class="muted">by <?= admin_escape($row['customer_name'] ?? 'Customer #' . $row['customer_id']) ?></span>
        &nbsp;·&nbsp; <span class="muted"><?= admin_escape($row['created_at']) ?></span>
    </p>
    <p>
        ⭐ <?= admin_escape((string) ($row['restaurant_rating'] ?? '—')) ?>
        <?php if ($row['food_rating'] !== null): ?> · Food: <?= admin_escape((string) $row['food_rating']) ?><?php endif; ?>
        <?php if ($row['delivery_rating'] !== null): ?> · Delivery: <?= admin_escape((string) $row['delivery_rating']) ?><?php endif; ?>
    </p>
    <?php if (!empty($row['comment'])): ?>
        <p>"<?= admin_escape($row['comment']) ?>"</p>
    <?php endif; ?>

    <?php if ($tab === 'reported'): ?>
        <?php if (!empty($reasonsByReview[$row['id']])): ?>
            <p class="muted"><strong>Reported for:</strong> <?= admin_escape(implode(' · ', $reasonsByReview[$row['id']])) ?></p>
        <?php endif; ?>
        <form method="post" style="display:inline-block; margin-right:8px;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="review_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="action" value="dismiss">
            <button type="submit" class="btn btn-outline">Dismiss report</button>
        </form>
        <form method="post" style="display:inline-block;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="review_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="action" value="hide">
            <input type="text" name="hide_reason" placeholder="Reason (optional)" style="width:auto;">
            <button type="submit" class="btn btn-primary">Hide review</button>
        </form>
    <?php else: ?>
        <p class="muted"><strong>Hidden reason:</strong> <?= admin_escape($row['hidden_reason'] ?? '—') ?> (<?= admin_escape($row['moderated_at']) ?>)</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="review_id" value="<?= (int) $row['id'] ?>">
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn btn-outline">Restore</button>
        </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card">
    <p class="muted"><?= $tab === 'hidden' ? 'No hidden reviews.' : 'No reported reviews pending — queue is clear.' ?></p>
</div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
