<?php
/**
 * Anydrop — Admin Web UI: Customer Feedback (migration 55).
 *
 * Read-only view of the `feedback` table (migration 06, Phase 3.6 §2.7 —
 * Profile > Feedback in the customer app, api/v1/customer/feedback.php).
 * That endpoint has always been capture-and-store only ("Reviewable
 * directly in the `feedback` table (or a future Admin Panel screen,
 * Phase 5)" per its own kdoc) — this is that screen.
 *
 * Optional star-rating filter (feedback.rating is nullable — a customer
 * can submit a message with no rating at all) plus a simple text search
 * over the message body. No mutation here on purpose: there's no
 * workflow/status column on `feedback` yet (unlike support tickets or
 * reported reviews), so this page only ever reads.
 *
 * Gated on feedback_view (migration 55).
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'feedback_view');
$db = Database::get();

$ratingFilter = $_GET['rating'] ?? '';
$ratingFilter = ctype_digit((string) $ratingFilter) && (int) $ratingFilter >= 1 && (int) $ratingFilter <= 5
    ? (int) $ratingFilter
    : null;

$search = trim((string) ($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($ratingFilter !== null) {
    $where[] = 'f.rating = :rating';
    $params['rating'] = $ratingFilter;
}

if ($search !== '') {
    $where[] = 'f.message LIKE :search';
    $params['search'] = '%' . $search . '%';
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    "SELECT f.id, f.customer_id, f.message, f.rating, f.created_at,
            c.name AS customer_name, c.email AS customer_email, c.mobile AS customer_mobile
     FROM feedback f
     LEFT JOIN customers c ON c.id = f.customer_id
     $whereSql
     ORDER BY f.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Quick counts for the filter chips — total + one per star value, same
// LIMIT-free query since `feedback` is small (capture-and-store, no
// pruning) and this is just a handful of COUNT(*)s.
$countsStmt = $db->query('SELECT rating, COUNT(*) AS n FROM feedback GROUP BY rating');
$countsByRating = [];
$totalCount = 0;
foreach ($countsStmt->fetchAll() as $r) {
    $totalCount += (int) $r['n'];
    if ($r['rating'] !== null) {
        $countsByRating[(int) $r['rating']] = (int) $r['n'];
    }
}

$pageTitle = 'Customer Feedback';
$activeNav = 'customer_feedback';
require __DIR__ . '/_layout_head.php';
?>
<div class="section">
<div class="card">
    <h2>Customer Feedback</h2>
    <p class="muted">Feedback submitted from the customer app's Profile &gt; Feedback screen. Read-only — capture-and-store, no status/workflow on these yet.</p>

    <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px;">
        <input type="text" name="q" placeholder="Search message..." value="<?= admin_escape($search) ?>" style="width:auto; min-width:220px;">
        <button type="submit" class="btn btn-outline">Search</button>
        <a href="customer-feedback.php" class="btn <?= $ratingFilter === null && $search === '' ? 'btn-primary' : 'btn-outline' ?>">
            All (<?= (int) $totalCount ?>)
        </a>
        <?php for ($star = 5; $star >= 1; $star--): ?>
            <a href="customer-feedback.php?rating=<?= $star ?>" class="btn <?= $ratingFilter === $star ? 'btn-primary' : 'btn-outline' ?>">
                <?= str_repeat('⭐', $star) ?> (<?= (int) ($countsByRating[$star] ?? 0) ?>)
            </a>
        <?php endfor; ?>
    </form>
</div>

<?php if (!empty($rows)): ?>
<?php foreach ($rows as $row): ?>
<div class="card">
    <p>
        <strong><?= admin_escape($row['customer_name'] ?? ('Customer #' . $row['customer_id'])) ?></strong>
        <?php if (!empty($row['customer_email'])): ?>
            &nbsp;·&nbsp; <span class="muted"><?= admin_escape($row['customer_email']) ?></span>
        <?php endif; ?>
        <?php if (!empty($row['customer_mobile'])): ?>
            &nbsp;·&nbsp; <span class="muted"><?= admin_escape($row['customer_mobile']) ?></span>
        <?php endif; ?>
        &nbsp;·&nbsp; <span class="muted"><?= admin_escape($row['created_at']) ?></span>
    </p>
    <?php if ($row['rating'] !== null): ?>
        <p><?= str_repeat('⭐', (int) $row['rating']) ?></p>
    <?php endif; ?>
    <p>"<?= nl2br(admin_escape($row['message'])) ?>"</p>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card">
    <p class="muted">No feedback found<?= ($ratingFilter !== null || $search !== '') ? ' for this filter.' : ' yet.' ?></p>
</div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
