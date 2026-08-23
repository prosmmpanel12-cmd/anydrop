<?php
/**
 * Anydrop — Admin Web UI: Platform Cash Flow
 *
 * Implements doc 19 §6b's "Admin screen — Platform Cash Flow" exactly:
 * Total Money In, Total Money Out, Net Balance Held, Total Platform
 * Revenue, the full chronological platform_ledger entry list, and the
 * reconciliation check against SUM(restaurants.current_due < 0).
 *
 * Read-only — nothing here writes to platform_ledger. All writes go
 * through lib/ledger.php (settlements.php's Pay Now calls
 * record_settlement(); the two order-triggered writers exist but are
 * not yet called anywhere — see that file's kdoc). Until those two
 * triggers are wired up (needs the still-unbuilt 'delivered' status
 * transition and payment-confirmation flow), this report will only
 * ever show whatever manual settlements admins have recorded — that
 * part is fully live today.
 *
 * Gated on payouts_view (reporting, same module as Settlements).
 *
 * STATUS: 🆕 BUILT 2026-08-22 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox). Needs migration 38 run live, then a
 * couple of Pay Now settlements from settlements.php and a check that
 * the totals/reconciliation numbers here move correctly with them.
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$db = Database::get();

// ---------- Filters ----------
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');
$restaurantFilter = isset($_GET['restaurant_id']) && $_GET['restaurant_id'] !== '' ? (int) $_GET['restaurant_id'] : null;

$where = [];
$params = [];
if ($fromDate !== '') {
    $where[] = 'created_at >= :from';
    $params['from'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $where[] = 'created_at <= :to';
    $params['to'] = $toDate . ' 23:59:59';
}
if ($restaurantFilter !== null) {
    $where[] = 'restaurant_id = :rid';
    $params['rid'] = $restaurantFilter;
}
$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// ---------- Totals (doc 19 §6b's exact four figures) ----------
$totalsStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN entry_type IN ('customer_payment_in','restaurant_settlement_in') THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN entry_type IN ('restaurant_payout_out','refund_out') THEN ABS(amount) ELSE 0 END), 0) AS total_out,
        COALESCE(SUM(CASE WHEN entry_type = 'platform_revenue' THEN amount ELSE 0 END), 0) AS total_revenue
     FROM platform_ledger $whereSql"
);
$totalsStmt->execute($params);
$totals = $totalsStmt->fetch();
$totalIn = (float) $totals['total_in'];
$totalOut = (float) $totals['total_out'];
$netBalanceHeld = round($totalIn - $totalOut, 2);
$totalRevenue = (float) $totals['total_revenue'];

// Reconciliation: Net Balance Held should equal -1 * SUM(current_due WHERE current_due < 0)
// — i.e. the total the admin owes out across every restaurant should
// match what's sitting unspent in the merchant account. This ignores
// the date filter above deliberately (current_due is always the
// live, whole-platform figure, not a date-ranged one).
$owedOutStmt = $db->query(
    "SELECT COALESCE(SUM(current_due), 0) AS negative_total FROM restaurants WHERE current_due < 0 AND deleted_at IS NULL"
);
$expectedHeld = round(-1 * (float) $owedOutStmt->fetch()['negative_total'], 2);
$reconciliationDiff = round($netBalanceHeld - $expectedHeld, 2);
// Small rounding drift (a few paise across many entries) isn't a real
// bug — only flag anything beyond that as worth investigating.
$reconciliationOk = abs($reconciliationDiff) < 0.5;

// ---------- Entry list ----------
$entriesStmt = $db->prepare(
    "SELECT pl.*, r.name AS restaurant_name
     FROM platform_ledger pl
     LEFT JOIN restaurants r ON r.id = pl.restaurant_id
     $whereSql
     ORDER BY pl.created_at DESC, pl.id DESC LIMIT 300"
);
$entriesStmt->execute($params);
$entries = $entriesStmt->fetchAll();

$restaurantOptions = $db->query('SELECT id, name FROM restaurants WHERE deleted_at IS NULL ORDER BY name')->fetchAll();

$pageTitle = 'Platform Cash Flow';
$activeNav = 'platform_ledger';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Platform Cash Flow</h2>
    <p class="muted">Total money in / out across the admin's own UPIPE merchant account — the whole-platform view, separate from any single restaurant's ledger (see <a href="settlements.php">Settlements</a> for that).</p>
</div>

<div class="grid">
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalIn, 2)) ?></div><div class="label">Total Money In</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalOut, 2)) ?></div><div class="label">Total Money Out</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($netBalanceHeld, 2)) ?></div><div class="label">Net Balance Held</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalRevenue, 2)) ?></div><div class="label">Total Platform Revenue</div></div>
</div>

<div class="card">
    <p>
        Reconciliation check:
        <?php if ($reconciliationOk): ?>
            <span class="badge active">OK — matches restaurants' negative current_due total (₹<?= admin_escape(number_format($expectedHeld, 2)) ?>)</span>
        <?php else: ?>
            <span class="badge inactive">Mismatch: ₹<?= admin_escape(number_format($reconciliationDiff, 2)) ?> off expected ₹<?= admin_escape(number_format($expectedHeld, 2)) ?> — worth investigating, not a rounding artifact</span>
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <label>From <input type="date" name="from" value="<?= admin_escape($fromDate) ?>"></label>
        <label>To <input type="date" name="to" value="<?= admin_escape($toDate) ?>"></label>
        <label>Restaurant
            <select name="restaurant_id">
                <option value="">— All restaurants —</option>
                <?php foreach ($restaurantOptions as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $restaurantFilter === (int) $r['id'] ? 'selected' : '' ?>><?= admin_escape($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-outline">Filter</button>
        <a href="platform-ledger.php" class="btn btn-outline">Clear</a>
    </form>
</div>

<div class="card">
    <h2>Entries</h2>
    <?php if (empty($entries)): ?>
        <p class="muted">No platform ledger entries yet — nothing has moved through Settlements' Pay Now yet, and the order-triggered writers aren't wired up until the delivery/payment-confirmation flows they depend on exist (see this page's kdoc).</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Date</th><th>Type</th><th>Restaurant</th><th>Order</th><th>Amount</th><th>Running Balance</th><th>By</th><th>Note</th></tr>
        <?php foreach ($entries as $e): ?>
        <tr>
            <td><?= admin_escape($e['created_at']) ?></td>
            <td><?= admin_escape(str_replace('_', ' ', $e['entry_type'])) ?></td>
            <td><?= $e['restaurant_name'] ? admin_escape($e['restaurant_name']) : '—' ?></td>
            <td><?= $e['order_id'] ? '#' . (int) $e['order_id'] : '—' ?></td>
            <td style="color:<?= (float) $e['amount'] >= 0 ? '#1b8a3c' : '#c0392b' ?>;">
                <?= (float) $e['amount'] >= 0 ? '+' : '' ?><?= admin_escape(number_format((float) $e['amount'], 2)) ?>
            </td>
            <td><?= admin_escape(number_format((float) $e['running_balance'], 2)) ?></td>
            <td><?= admin_escape($e['created_by']) ?></td>
            <td class="muted"><?= admin_escape($e['note'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
