<?php
/**
 * Anydrop — Admin Web UI: Cash Flow Dashboard
 * (Deep Plan Phase 7, docs/00_Deep_Plan_Statement_CODLimit_QRPay_
 * CashFlow_2026-09-09.md §7 — built last on purpose, since it only
 * aggregates what Phases 1-6 already write.)
 *
 * Two clearly-separated sections, per the deep plan's own "never merge
 * into one number" instruction (§0's Key Model Clarification):
 *
 *   1. Rider COD Cash — money that flows Customer -> Rider -> Admin.
 *      Per-rider: cash currently held, total ever collected, total
 *      deposited to admin, last deposit date, over-limit status.
 *      Reads rider_cod_ledger + riders.cod_cash_held — the exact same
 *      tables rider-settlements.php's Record Settlement button and
 *      PayCodDepositActivity's self-service UPI deposit (Phase 5) both
 *      already write to. Nothing new is written here.
 *
 *   2. Restaurant Settlement Summary — a separate money flow entirely
 *      (commission owed / payout owed), reusing restaurants.current_due
 *      (already the live, ledger-derived signed balance settlements.php
 *      itself sorts its list by) rather than re-summing entry_type rows
 *      from scratch, so this can never drift from what Settlements
 *      already shows. "Paid this cycle" reads restaurant_payments
 *      directly, filtered by the optional date range below — reuses
 *      admin/settlements.php's Pay Now write path (record_settlement()
 *      in lib/ledger.php), so a "Pay Now" or "Record Settlement" click
 *      anywhere in the admin panel is reflected here automatically on
 *      next page load, no separate bookkeeping step (deep plan §7's
 *      core ask) — this page reads, it never writes.
 *
 *      UPDATE 2026-09-11 (app-owner ask): added a second, period-scoped
 *      row under this section — Order Revenue / Commission Taken / Net
 *      to Restaurants, summed straight from `orders` (delivered only,
 *      same $fromDate/$toDate range as "Paid"/"Received" above). This
 *      answers a genuinely different question from
 *      total_payable_to_restaurants/total_due_from_restaurants (which
 *      are "what's owed right now, all-time" — current_due) — it's
 *      "how much business happened in this window", the same
 *      delivered-orders definition restaurant/statement.php already
 *      uses per-restaurant, just summed across all restaurants here.
 *      The two rows are deliberately not reconciled against each other
 *      on this page — current_due carries forward across periods
 *      (it's a running balance) while the revenue row resets to
 *      whatever the date filter covers, so they answer different
 *      questions by design, same as this page's own Section
 *      1/2/3 split already does.
 *
 * Gated on payouts_view (view) / payouts_manage (the quick-action
 * forms below) — same modules as Settlements/Rider Settlements.
 *
 * UPDATE 2026-09-12 (app-owner ask): this was purely a read/aggregation
 * page — "record the settlement" always meant leaving this page for
 * rider-settlements.php or settlements.php. Added inline quick-action
 * forms (rider "Settle", restaurant "Pay Now") that POST back to this
 * same file and call the exact same lib/rider_ledger.php
 * record_rider_settlement() / lib/ledger.php record_settlement()
 * functions those detail pages already use — no new write logic, just
 * a shorter path to it. Since this page always recomputes every number
 * fresh from the ledger tables on load, acting here "auto syncs" the
 * page the moment it reloads, same as acting on the detail pages
 * always did. The detail pages are still linked next to every quick
 * action for the full form (UTR/screenshot/remarks) when that's
 * needed — this is a fast path for the common no-proof-needed case.
 *
 * UPDATE 2026-09-10 (same day, owner asked to merge rather than have
 * two separate "cash flow" pages): what used to be the standalone
 * `platform-ledger.php` page (admin's own UPIPE merchant-account
 * balance — customer payments in, refunds/payouts out) is now
 * Section 3 below, not a separate page. `platform-ledger.php` itself
 * now just redirects here (see that file) so no existing bookmark/
 * link breaks. Nothing about that section's logic changed — same
 * totals query, same reconciliation check, same entries list, just
 * relocated + given its own filter block instead of a whole page.
 *
 * STATUS: 🆕 BUILT 2026-09-10 — NOT build/device-verified, same
 * standing sandbox limitation as every phase before it (no php/mysql
 * CLI available here). Needs a real DB with a mix of rider deposits
 * (manual + auto-verified + admin-approved-via-UTR), restaurant Pay
 * Now settlements, and platform_ledger activity (refunds/payouts/
 * wallet withdrawals) to confirm every section's numbers match what
 * rider-settlements.php / settlements.php / the old platform-ledger.php
 * used to show, exactly.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/rider_ledger.php';
require_once __DIR__ . '/../lib/ledger.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission((int) $admin['id'], 'payouts_manage');
$db = Database::get();

$settlementLimit = rider_cod_settlement_limit();
$csrf = admin_csrf_token();
$flash = null;
$flashType = 'success';

// ============================================================
// Quick-action POST handling — 2026-09-12 app-owner ask: let admin
// record a rider's COD settlement or a restaurant's Pay Now directly
// from this page, instead of having to click through to
// rider-settlements.php / settlements.php first. Both write through
// the exact same functions those detail pages already use
// (record_rider_settlement() / record_settlement()) — no new ledger
// logic, just a shorter path to the same write path. Since every
// number below is read fresh from the ledger tables on every load
// (same as this page always did), the numbers on this page "auto
// sync" the moment the form posts back here — no separate refresh
// step, no caching to invalidate.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to record settlements.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        if ($formAction === 'rider_settle') {
            $postRiderId = (int) ($_POST['rider_id'] ?? 0);
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;
            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid settlement amount.';
                $flashType = 'error';
            } else {
                try {
                    record_rider_settlement($db, $postRiderId, (float) $amount, (int) $admin['id'], $remarks);
                    write_audit_log('admin', $admin['id'], 'rider_settlement_recorded', [
                        'rider_id' => $postRiderId, 'amount' => $amount, 'via' => 'cash_flow_quick_action',
                    ]);
                    $flash = 'Settlement recorded — rider\'s cash-held balance updated.';
                } catch (Throwable $e) {
                    $flash = 'Could not record settlement — nothing was saved.';
                    $flashType = 'error';
                }
            }
        } elseif ($formAction === 'restaurant_pay') {
            $postRestaurantId = (int) ($_POST['restaurant_id'] ?? 0);
            $direction = $_POST['direction'] ?? '';
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;
            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid settlement amount.';
                $flashType = 'error';
            } elseif (!in_array($direction, ['admin_to_restaurant', 'restaurant_to_admin'], true)) {
                $flash = 'Invalid settlement direction.';
                $flashType = 'error';
            } else {
                try {
                    // No UTR/screenshot field on this quick form on purpose —
                    // it's a fast path for the common case; anything needing
                    // proof attached still goes through settlements.php's
                    // full Pay Now form (linked right next to this button).
                    record_settlement(
                        $db, $postRestaurantId, $direction, (float) $amount, (int) $admin['id'],
                        null, null, $remarks, date('Y-m-d')
                    );
                    write_audit_log('admin', $admin['id'], 'settlement_recorded', [
                        'restaurant_id' => $postRestaurantId, 'direction' => $direction, 'amount' => $amount,
                        'via' => 'cash_flow_quick_action',
                    ]);
                    $flash = 'Settlement recorded and ledger updated.';
                } catch (Throwable $e) {
                    $flash = 'Could not record settlement — nothing was saved.';
                    $flashType = 'error';
                }
            }
        }
    }
}

// ---------- Filters (restaurant "paid this cycle" only — rider section
// is always all-time, since cod_cash_held itself is a live running
// balance, not something a date range makes sense to slice) ----------
$fromDate = trim($_GET['from'] ?? '');
$toDate = trim($_GET['to'] ?? '');
$riderSearch = trim($_GET['rider_q'] ?? '');
// Platform ledger (Section 3) gets its own restaurant filter, same as
// the old platform-ledger.php did — kept separate from rider_q above
// since these filter two unrelated tables.
$plRestaurantFilter = isset($_GET['pl_restaurant_id']) && $_GET['pl_restaurant_id'] !== '' ? (int) $_GET['pl_restaurant_id'] : null;

$payWhere = [];
$payParams = [];
if ($fromDate !== '') {
    $payWhere[] = 'created_at >= :from';
    $payParams['from'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $payWhere[] = 'created_at <= :to';
    $payParams['to'] = $toDate . ' 23:59:59';
}
$payWhereSql = empty($payWhere) ? '' : ('AND ' . implode(' AND ', $payWhere));

// ============================================================
// Section 1 — Rider COD Cash
// ============================================================

$riderSql = "SELECT id, name, mobile, cod_cash_held FROM riders WHERE deleted_at IS NULL";
$riderParams = [];
if ($riderSearch !== '') {
    $riderSql .= " AND name LIKE :q";
    $riderParams['q'] = '%' . $riderSearch . '%';
}
$riderSql .= " ORDER BY cod_cash_held DESC, name";
$riderStmt = $db->prepare($riderSql);
$riderStmt->execute($riderParams);
$riders = $riderStmt->fetchAll();

// Per-rider aggregates from rider_cod_ledger, in one pass rather than
// N+1 queries per rider — this table is small (200-row cap already
// used elsewhere for a single rider's statement) so a full GROUP BY
// scan here is cheap.
$aggStmt = $db->query(
    "SELECT
        rider_id,
        COALESCE(SUM(CASE WHEN entry_type = 'cod_collected' THEN amount ELSE 0 END), 0) AS total_collected,
        COALESCE(SUM(CASE WHEN entry_type = 'settlement_to_admin' THEN ABS(amount) ELSE 0 END), 0) AS total_deposited,
        MAX(CASE WHEN entry_type = 'settlement_to_admin' THEN created_at ELSE NULL END) AS last_deposit_at
     FROM rider_cod_ledger
     GROUP BY rider_id"
);
$riderAggByRiderId = [];
foreach ($aggStmt->fetchAll() as $row) {
    $riderAggByRiderId[(int) $row['rider_id']] = $row;
}

$totalCashHeld = 0.0;
$totalCollectedAll = 0.0;
$totalDepositedAll = 0.0;
$overLimitCount = 0;

foreach ($riders as &$r) {
    $agg = $riderAggByRiderId[(int) $r['id']] ?? ['total_collected' => 0, 'total_deposited' => 0, 'last_deposit_at' => null];
    $r['total_collected'] = (float) $agg['total_collected'];
    $r['total_deposited'] = (float) $agg['total_deposited'];
    $r['last_deposit_at'] = $agg['last_deposit_at'];
    $r['over_limit'] = (float) $r['cod_cash_held'] >= $settlementLimit;

    $totalCashHeld += (float) $r['cod_cash_held'];
    $totalCollectedAll += $r['total_collected'];
    $totalDepositedAll += $r['total_deposited'];
    if ($r['over_limit']) {
        $overLimitCount++;
    }
}
unset($r);

// ============================================================
// Section 2 — Restaurant Settlement Summary
// ============================================================

// current_due is already the live, ledger-maintained signed balance
// (lib/ledger.php's write_due_ledger_entry() keeps it in sync with
// every restaurant_due_ledger row) — summing it directly here can
// never disagree with what settlements.php's own list mode shows,
// unlike re-deriving from individual entry_type rows a second time.
$dueTotalsStmt = $db->query(
    "SELECT
        COALESCE(SUM(CASE WHEN current_due < 0 THEN -current_due ELSE 0 END), 0) AS total_payable_to_restaurants,
        COALESCE(SUM(CASE WHEN current_due > 0 THEN current_due ELSE 0 END), 0) AS total_due_from_restaurants
     FROM restaurants WHERE deleted_at IS NULL"
);
$dueTotals = $dueTotalsStmt->fetch();
$totalPayableToRestaurants = (float) $dueTotals['total_payable_to_restaurants'];
$totalDueFromRestaurants = (float) $dueTotals['total_due_from_restaurants'];

// Per-restaurant outstanding balances — same list settlements.php's own
// list mode shows (current_due != 0, biggest first), added here so the
// quick Pay Now action below has a rider-list-style table to act on
// without leaving this page. current_due sign convention (doc 19 §6):
// positive = restaurant owes admin (COD commission piled up), negative
// = admin owes restaurant (online-order payouts not yet paid out).
$restaurantsWithDueStmt = $db->query(
    "SELECT id, name, current_due FROM restaurants
     WHERE deleted_at IS NULL AND current_due <> 0
     ORDER BY ABS(current_due) DESC, name LIMIT 200"
);
$restaurantsWithDue = $restaurantsWithDueStmt->fetchAll();

$paidStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN direction = 'admin_to_restaurant' THEN amount ELSE 0 END), 0) AS paid_to_restaurants,
        COALESCE(SUM(CASE WHEN direction = 'restaurant_to_admin' THEN amount ELSE 0 END), 0) AS received_from_restaurants
     FROM restaurant_payments
     WHERE status = 'verified' $payWhereSql"
);
$paidStmt->execute($payParams);
$paidTotals = $paidStmt->fetch();
$paidToRestaurants = (float) $paidTotals['paid_to_restaurants'];
$receivedFromRestaurants = (float) $paidTotals['received_from_restaurants'];

// App-owner ask, 2026-09-11 — "kitna payment aaya, commission kitna
// tha, uske paas kitna aayega": a period-scoped view (how much order
// revenue came in, how much commission was taken, what restaurants net
// after it) sitting alongside the running-balance figures above. This
// is deliberately a DIFFERENT question from total_payable_to_restaurants/
// total_due_from_restaurants above (those are "what's owed right now,
// all-time", i.e. current_due) — this is "how much business happened
// in this period", read straight from orders like
// restaurant/statement.php's own per-restaurant version does, just
// summed across every restaurant instead of scoped to one. Same
// $payWhereSql date range as the "Paid"/"Received" figures directly
// above, reusing the exact same delivered-only revenue definition
// admin/settlements.php's $payoutNonRevenueStatuses exclusion and
// restaurant/statement.php both already use, so this figure agrees
// with what a restaurant sees on their own Statement screen for the
// same period.
$revenueWhere = [];
$revenueParams = [];
if ($fromDate !== '') {
    $revenueWhere[] = 'created_at >= :rev_from';
    $revenueParams['rev_from'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $revenueWhere[] = 'created_at <= :rev_to';
    $revenueParams['rev_to'] = $toDate . ' 23:59:59';
}
$revenueWhereSql = empty($revenueWhere) ? '' : ('AND ' . implode(' AND ', $revenueWhere));
$revenueStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(grand_total), 0) AS total_order_revenue,
        COALESCE(SUM(commission_amount), 0) AS total_commission_taken,
        COALESCE(SUM(grand_total - commission_amount), 0) AS total_net_to_restaurants
     FROM orders
     WHERE status = 'delivered' $revenueWhereSql"
);
$revenueStmt->execute($revenueParams);
$revenueTotals = $revenueStmt->fetch();
$totalOrderRevenue = (float) $revenueTotals['total_order_revenue'];
$totalCommissionTaken = (float) $revenueTotals['total_commission_taken'];
$totalNetToRestaurants = (float) $revenueTotals['total_net_to_restaurants'];

// ============================================================
// Section 3 — Platform Cash Flow (admin's own UPIPE merchant account)
// Merged in from the former standalone platform-ledger.php — same
// queries, same reconciliation logic, unchanged. Reuses the same
// $fromDate/$toDate as Section 2's "paid this cycle" filter (one date
// range for the whole page, rather than two separate filter blocks
// asking the same question in different words).
// ============================================================

$plWhere = [];
$plParams = [];
if ($fromDate !== '') {
    $plWhere[] = 'pl.created_at >= :pl_from';
    $plParams['pl_from'] = $fromDate . ' 00:00:00';
}
if ($toDate !== '') {
    $plWhere[] = 'pl.created_at <= :pl_to';
    $plParams['pl_to'] = $toDate . ' 23:59:59';
}
if ($plRestaurantFilter !== null) {
    $plWhere[] = 'pl.restaurant_id = :pl_rid';
    $plParams['pl_rid'] = $plRestaurantFilter;
}
$plWhereSql = empty($plWhere) ? '' : ('WHERE ' . implode(' AND ', $plWhere));

$plTotalsStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN entry_type IN ('customer_payment_in','restaurant_settlement_in') THEN amount ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN entry_type IN ('restaurant_payout_out','refund_out') THEN ABS(amount) ELSE 0 END), 0) AS total_out,
        COALESCE(SUM(CASE WHEN entry_type = 'platform_revenue' THEN amount ELSE 0 END), 0) AS total_revenue
     FROM platform_ledger pl $plWhereSql"
);
$plTotalsStmt->execute($plParams);
$plTotals = $plTotalsStmt->fetch();
$plTotalIn = (float) $plTotals['total_in'];
$plTotalOut = (float) $plTotals['total_out'];
$plNetBalanceHeld = round($plTotalIn - $plTotalOut, 2);
$plTotalRevenue = (float) $plTotals['total_revenue'];

// Reconciliation: unchanged from platform-ledger.php — always
// whole-platform/live, ignores the date filter above on purpose.
$plOwedOutStmt = $db->query(
    "SELECT COALESCE(SUM(current_due), 0) AS negative_total FROM restaurants WHERE current_due < 0 AND deleted_at IS NULL"
);
$plExpectedHeld = round(-1 * (float) $plOwedOutStmt->fetch()['negative_total'], 2);
$plReconciliationDiff = round($plNetBalanceHeld - $plExpectedHeld, 2);
$plReconciliationOk = abs($plReconciliationDiff) < 0.5;

$plEntriesStmt = $db->prepare(
    "SELECT pl.*, r.name AS restaurant_name
     FROM platform_ledger pl
     LEFT JOIN restaurants r ON r.id = pl.restaurant_id
     $plWhereSql
     ORDER BY pl.created_at DESC, pl.id DESC LIMIT 300"
);
$plEntriesStmt->execute($plParams);
$plEntries = $plEntriesStmt->fetchAll();

$plRestaurantOptions = $db->query('SELECT id, name FROM restaurants WHERE deleted_at IS NULL ORDER BY name')->fetchAll();

$pageTitle = 'Cash Flow';
$activeNav = 'cash_flow';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Cash Flow</h2>
    <p class="muted">Three separate money flows, shown one after another but never merged into one number: rider-collected COD cash (Customer &rarr; Rider &rarr; Admin), restaurant commission/payout settlement, and the admin's own UPIPE merchant-account balance. All three read straight from the same ledger tables <a href="rider-settlements.php">Rider Settlements</a>' Record Settlement, <a href="settlements.php">Settlements</a>' Pay Now, and every refund/payout/withdrawal flow already write to — recording anything anywhere in the admin panel updates these numbers immediately, no separate step. The quick "Settle" / "Pay Now" buttons below write through those same functions, so acting from this page updates every number on it the moment it reloads — no separate sync step.</p>
    <p class="muted"><strong>What "outstanding balance" means here:</strong> for a <em>rider</em>, it's COD cash they collected from customers and are still physically holding — <code>Cash Currently Held</code> — which they owe back to admin. For a <em>restaurant</em>, <code>current_due</code> is a running, signed balance: positive means the restaurant owes admin (COD-order commission that built up), negative means admin owes the restaurant (payouts for online-paid orders not yet sent). Neither is late/overdue by default — it's simply "not yet settled"; a rider only turns red when they cross the ₹<?= admin_escape(number_format($settlementLimit, 2)) ?> hold limit above.</p>
</div>

<h2 style="margin:24px 0 8px;">Rider COD Cash</h2>
<div class="grid">
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalCashHeld, 2)) ?></div><div class="label">Cash Currently Held (all riders)</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalCollectedAll, 2)) ?></div><div class="label">Total Ever Collected</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalDepositedAll, 2)) ?></div><div class="label">Total Deposited to Admin</div></div>
    <div class="card stat"><div class="value"><?= (int) $overLimitCount ?></div><div class="label">Riders Over Limit (₹<?= admin_escape(number_format($settlementLimit, 2)) ?>)</div></div>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <input type="hidden" name="from" value="<?= admin_escape($fromDate) ?>">
        <input type="hidden" name="to" value="<?= admin_escape($toDate) ?>">
        <input type="text" name="rider_q" placeholder="Search rider name" value="<?= admin_escape($riderSearch) ?>">
        <button type="submit" class="btn btn-outline">Search</button>
        <?php if ($riderSearch !== ''): ?>
            <a href="cash-flow.php<?= ($fromDate !== '' || $toDate !== '') ? '?from=' . urlencode($fromDate) . '&to=' . urlencode($toDate) : '' ?>" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if (empty($riders)): ?>
        <p class="muted">No riders found.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr>
            <th>Rider</th><th>Mobile</th><th>Cash Currently Held</th>
            <th>Total Ever Collected</th><th>Total Deposited to Admin</th>
            <th>Last Deposit</th><th>Status</th><th></th>
        </tr>
        <?php foreach ($riders as $r): ?>
        <tr>
            <td><?= admin_escape($r['name']) ?></td>
            <td><?= admin_escape($r['mobile'] ?? '—') ?></td>
            <td>₹<?= admin_escape(number_format((float) $r['cod_cash_held'], 2)) ?></td>
            <td>₹<?= admin_escape(number_format($r['total_collected'], 2)) ?></td>
            <td>₹<?= admin_escape(number_format($r['total_deposited'], 2)) ?></td>
            <td><?= $r['last_deposit_at'] ? admin_escape($r['last_deposit_at']) : '<span class="muted">—</span>' ?></td>
            <td>
                <?php if ($r['over_limit']): ?>
                    <span class="badge inactive">⚠ Over limit</span>
                <?php else: ?>
                    <span class="badge active">OK</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <a class="btn btn-outline" href="rider-settlements.php?rider_id=<?= (int) $r['id'] ?>">View</a>
                <?php if ($canEdit && (float) $r['cod_cash_held'] > 0): ?>
                <form method="post" style="display:flex; gap:4px; align-items:center;"
                    onsubmit="return confirm('Record that <?= admin_escape(addslashes($r['name'])) ?> handed over ₹' + this.amount.value + ' cash to admin?');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="rider_settle">
                    <input type="hidden" name="rider_id" value="<?= (int) $r['id'] ?>">
                    <input type="number" name="amount" step="0.01" min="0.01" max="<?= admin_escape(number_format((float) $r['cod_cash_held'], 2, '.', '')) ?>" value="<?= admin_escape(number_format((float) $r['cod_cash_held'], 2, '.', '')) ?>" style="width:90px;" required>
                    <button type="submit" class="btn btn-primary">Settle</button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<h2 style="margin:24px 0 8px;">Restaurant Settlement Summary</h2>
<p class="muted" style="margin-top:-4px;">A separate ledger and separate money flow from the rider cash above — commission restaurants owe on COD orders vs. online-order payouts admin owes restaurants. See <a href="settlements.php">Settlements</a> for the per-restaurant breakdown.</p>

<div class="grid">
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalPayableToRestaurants, 2)) ?></div><div class="label">Total Payable to Restaurants</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalDueFromRestaurants, 2)) ?></div><div class="label">Total Due From Restaurants (COD commission)</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($paidToRestaurants, 2)) ?></div><div class="label">Paid to Restaurants <?= ($fromDate !== '' || $toDate !== '') ? 'This Period' : 'All Time' ?></div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($receivedFromRestaurants, 2)) ?></div><div class="label">Received From Restaurants <?= ($fromDate !== '' || $toDate !== '') ? 'This Period' : 'All Time' ?></div></div>
</div>

<div class="card">
    <h2>Restaurants with an Outstanding Balance</h2>
    <p class="muted">Every restaurant whose <code>current_due</code> isn't zero, biggest balance first — same list <a href="settlements.php">Settlements</a> shows. Positive = restaurant owes admin; negative = admin owes restaurant. "Pay Now" here is the fast path (no UTR/screenshot); use <a href="settlements.php">Settlements</a>' full form when you need to attach proof.</p>
    <?php if (empty($restaurantsWithDue)): ?>
        <p class="muted">No restaurant has an outstanding balance right now.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Restaurant</th><th>Current Due</th><th>Direction</th><th></th></tr>
        <?php foreach ($restaurantsWithDue as $rw): $rwDue = (float) $rw['current_due']; ?>
        <tr>
            <td><?= admin_escape($rw['name']) ?></td>
            <td>₹<?= admin_escape(number_format(abs($rwDue), 2)) ?></td>
            <td><?= $rwDue > 0 ? '<span class="badge inactive">Restaurant owes admin</span>' : '<span class="badge active">Admin owes restaurant</span>' ?></td>
            <td>
                <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <a class="btn btn-outline" href="settlements.php?restaurant_id=<?= (int) $rw['id'] ?>">View / Settle</a>
                <?php if ($canEdit): ?>
                <form method="post" style="display:flex; gap:4px; align-items:center;"
                    onsubmit="return confirm('Record this settlement for <?= admin_escape(addslashes($rw['name'])) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="restaurant_pay">
                    <input type="hidden" name="restaurant_id" value="<?= (int) $rw['id'] ?>">
                    <input type="hidden" name="direction" value="<?= $rwDue > 0 ? 'restaurant_to_admin' : 'admin_to_restaurant' ?>">
                    <input type="number" name="amount" step="0.01" min="0.01" value="<?= admin_escape(number_format(abs($rwDue), 2, '.', '')) ?>" style="width:90px;" required>
                    <button type="submit" class="btn btn-primary">Pay Now</button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<p class="muted" style="margin:16px 0 -4px;">Order revenue for the period below (delivered orders only) — "kitna payment aaya, commission kitna tha, restaurants ke paas kitna aayega". A different question from the running-balance totals above: those are "what's owed right now, all-time"; these are "how much business happened in this window". Same delivered-orders definition <a href="settlements.php">Settlements</a> and each restaurant's own Statement screen use, so all three agree.</p>
<div class="grid">
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalOrderRevenue, 2)) ?></div><div class="label">Order Revenue <?= ($fromDate !== '' || $toDate !== '') ? 'This Period' : 'All Time' ?></div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalCommissionTaken, 2)) ?></div><div class="label">Commission Taken <?= ($fromDate !== '' || $toDate !== '') ? 'This Period' : 'All Time' ?></div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($totalNetToRestaurants, 2)) ?></div><div class="label">Net to Restaurants <?= ($fromDate !== '' || $toDate !== '') ? 'This Period' : 'All Time' ?></div></div>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <input type="hidden" name="rider_q" value="<?= admin_escape($riderSearch) ?>">
        <input type="hidden" name="pl_restaurant_id" value="<?= admin_escape((string) $plRestaurantFilter) ?>">
        <label>From <input type="date" name="from" value="<?= admin_escape($fromDate) ?>"></label>
        <label>To <input type="date" name="to" value="<?= admin_escape($toDate) ?>"></label>
        <button type="submit" class="btn btn-outline">Filter "Paid" figures + Platform Cash Flow below</button>
        <?php if ($fromDate !== '' || $toDate !== ''): ?>
            <a href="cash-flow.php<?= $riderSearch !== '' ? '?rider_q=' . urlencode($riderSearch) : '' ?>" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
    <p class="muted" style="margin-top:8px;margin-bottom:0;">This From/To range drives the "Paid"/"Received" figures above and the Platform Cash Flow entries below — the payable/due totals above stay the live current balance regardless, same as <a href="settlements.php">Settlements</a>' own list.</p>
</div>

<h2 style="margin:24px 0 8px;">Platform Cash Flow (UPIPE Merchant Account)</h2>
<p class="muted" style="margin-top:-4px;">Total money in / out across the admin's own UPIPE merchant account — a third, separate flow from the rider cash and restaurant settlement above (customer payments in; refunds, rider payouts, and wallet withdrawals out).</p>

<div class="grid">
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($plTotalIn, 2)) ?></div><div class="label">Total Money In</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($plTotalOut, 2)) ?></div><div class="label">Total Money Out</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($plNetBalanceHeld, 2)) ?></div><div class="label">Net Balance Held</div></div>
    <div class="card stat"><div class="value">₹<?= admin_escape(number_format($plTotalRevenue, 2)) ?></div><div class="label">Total Platform Revenue</div></div>
</div>

<div class="card">
    <p>
        Reconciliation check:
        <?php if ($plReconciliationOk): ?>
            <span class="badge active">OK — matches restaurants' negative current_due total (₹<?= admin_escape(number_format($plExpectedHeld, 2)) ?>)</span>
        <?php else: ?>
            <span class="badge inactive">Mismatch: ₹<?= admin_escape(number_format($plReconciliationDiff, 2)) ?> off expected ₹<?= admin_escape(number_format($plExpectedHeld, 2)) ?> — worth investigating, not a rounding artifact</span>
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <input type="hidden" name="rider_q" value="<?= admin_escape($riderSearch) ?>">
        <input type="hidden" name="from" value="<?= admin_escape($fromDate) ?>">
        <input type="hidden" name="to" value="<?= admin_escape($toDate) ?>">
        <label>Restaurant
            <select name="pl_restaurant_id">
                <option value="">— All restaurants —</option>
                <?php foreach ($plRestaurantOptions as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $plRestaurantFilter === (int) $r['id'] ? 'selected' : '' ?>><?= admin_escape($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-outline">Filter entries</button>
        <?php if ($plRestaurantFilter !== null): ?>
            <a href="cash-flow.php?<?= http_build_query(array_filter(['rider_q' => $riderSearch, 'from' => $fromDate, 'to' => $toDate])) ?>" class="btn btn-outline">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>Entries</h2>
    <?php if (empty($plEntries)): ?>
        <p class="muted">No platform ledger entries yet for this filter.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Date</th><th>Type</th><th>Restaurant</th><th>Order</th><th>Amount</th><th>Running Balance</th><th>By</th><th>Note</th></tr>
        <?php foreach ($plEntries as $e): ?>
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
