<?php
/**
 * Anydrop — Admin Web UI: Rider COD Settlements (migration 53).
 *
 * Business model (confirmed 2026-08-27): for COD orders, the RIDER
 * collects cash from the customer, not the restaurant — that cash then
 * has to be handed over to admin. This page is the "kitna COD cash abhi
 * rider ke paas pending hai" view + the settlement-limit flag + the
 * Record Settlement action, mirroring settlements.php's restaurant
 * pattern but for riders.
 *
 * List mode (no ?rider_id): every rider with cod_cash_held > 0,
 * highlighting anyone at/over rider_cod_settlement_limit (app_settings,
 * default ₹2000) since they should be settled before taking more COD
 * orders.
 *
 * Detail mode (?rider_id=N): full rider_cod_ledger statement + Record
 * Settlement form. All writes go through lib/rider_ledger.php's
 * record_rider_settlement() — this page never inserts into
 * rider_cod_ledger/riders directly.
 *
 * NOT YET LIVE END-TO-END: cod_cash_held only ever moves today via the
 * manual Record Settlement action below (which only ever subtracts).
 * The automatic 'cod_collected' entry that should fire when a rider
 * actually delivers a COD order isn't wired up yet — no 'delivered'
 * transition/rider-facing API exists in the codebase at all (same gap
 * flagged in lib/ledger.php and settlements.php for the restaurant side).
 * Once the Rider App's delivery-confirmation flow exists, call
 * record_rider_cod_collected() from it and this page will start
 * reflecting real balances immediately — nothing else needs to change.
 *
 * Gated on payouts_view/payouts_manage — same module as Settlements.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/rider_ledger.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission((int) $admin['id'], 'payouts_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';
$riderId = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : null;
$settlementLimit = rider_cod_settlement_limit();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to manage rider settlements.';
        $flashType = 'error';
    } else {
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
                    'rider_id' => $postRiderId, 'amount' => $amount,
                ]);
                $flash = 'Settlement recorded — rider\'s cash-held balance updated.';
            } catch (Throwable $e) {
                $flash = 'Could not record settlement — nothing was saved.';
                $flashType = 'error';
            }
        }
    }
}

$csrf = admin_csrf_token();
$activeNav = 'rider_settlements';

if ($riderId !== null) {
    // ---------- Detail mode ----------
    $rStmt = $db->prepare('SELECT id, name, mobile, cod_cash_held FROM riders WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $riderId]);
    $rider = $rStmt->fetch();

    if (!$rider) {
        $pageTitle = 'Rider Settlements';
        require __DIR__ . '/_layout_head.php';
        echo '<div class="section"><div class="card"><p class="muted">Rider not found.</p><a class="btn btn-outline" href="rider-settlements.php">Back to list</a></div></div>';
        require __DIR__ . '/_layout_foot.php';
        exit;
    }

    $ledgerStmt = $db->prepare(
        'SELECT * FROM rider_cod_ledger WHERE rider_id = :id ORDER BY created_at DESC, id DESC LIMIT 200'
    );
    $ledgerStmt->execute(['id' => $riderId]);
    $ledgerRows = $ledgerStmt->fetchAll();

    $cashHeld = (float) $rider['cod_cash_held'];
    $overLimit = $cashHeld >= $settlementLimit;

    $pageTitle = 'Rider Settlement — ' . $rider['name'];
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">
    <div class="card">
        <a href="rider-settlements.php" class="btn btn-outline" style="margin-bottom:12px;">&larr; All riders</a>
        <h2><?= admin_escape($rider['name']) ?></h2>
        <p class="muted"><?= admin_escape($rider['mobile'] ?? '—') ?></p>
        <p>
            <?php if ($cashHeld <= 0): ?>
                <span class="badge active">No COD cash pending</span>
            <?php elseif ($overLimit): ?>
                <span class="badge inactive">⚠ Holding ₹<?= admin_escape(number_format($cashHeld, 2)) ?> — at/over the ₹<?= admin_escape(number_format($settlementLimit, 2)) ?> limit, settle now</span>
            <?php else: ?>
                <span class="badge active">Holding ₹<?= admin_escape(number_format($cashHeld, 2)) ?> (limit ₹<?= admin_escape(number_format($settlementLimit, 2)) ?>)</span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
        <h2>Record Settlement</h2>
        <p class="muted">Records that this rider handed over COD cash to admin. Reduces their cash-held balance.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="rider_id" value="<?= (int) $riderId ?>">
            <label>Amount (₹)
                <input type="number" name="amount" step="0.01" min="0.01" max="<?= admin_escape(number_format($cashHeld, 2)) ?>" value="<?= admin_escape(number_format($cashHeld, 2)) ?>" required>
            </label>
            <label>Remarks (optional)
                <input type="text" name="remarks">
            </label>
            <button type="submit" class="btn btn-primary">Record Settlement</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Cash Ledger</h2>
        <?php if (empty($ledgerRows)): ?>
            <p class="muted">No entries yet — this rider hasn't collected any COD cash, or the delivered-order trigger isn't wired up yet (see this page's kdoc).</p>
        <?php else: ?>
        <div class="table-responsive">
        <table>
            <tr><th>Date</th><th>Type</th><th>Order</th><th>Amount</th><th>Running Balance</th><th>By</th><th>Note</th></tr>
            <?php foreach ($ledgerRows as $row): ?>
            <tr>
                <td><?= admin_escape($row['created_at']) ?></td>
                <td><?= admin_escape(str_replace('_', ' ', $row['entry_type'])) ?></td>
                <td><?= $row['order_id'] ? '#' . (int) $row['order_id'] : '—' ?></td>
                <td style="color:<?= (float) $row['amount'] >= 0 ? '#1b8a3c' : '#c0392b' ?>;">
                    <?= (float) $row['amount'] >= 0 ? '+' : '' ?><?= admin_escape(number_format((float) $row['amount'], 2)) ?>
                </td>
                <td><?= admin_escape(number_format((float) $row['running_balance'], 2)) ?></td>
                <td><?= admin_escape($row['created_by']) ?></td>
                <td class="muted"><?= admin_escape($row['note'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <?php
} else {
    // ---------- List mode ----------
    $search = trim($_GET['q'] ?? '');
    $sql = "SELECT id, name, mobile, cod_cash_held FROM riders WHERE deleted_at IS NULL";
    $params = [];
    if ($search !== '') {
        $sql .= " AND name LIKE :q";
        $params['q'] = '%' . $search . '%';
    }
    $sql .= " ORDER BY cod_cash_held DESC, name";
    $listStmt = $db->prepare($sql);
    $listStmt->execute($params);
    $riders = $listStmt->fetchAll();

    $pageTitle = 'Rider Settlements';
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">
    <div class="card">
        <h2>Rider Settlements</h2>
        <p class="muted">COD cash each rider is currently holding, not yet handed over to admin. Riders at or over the ₹<?= admin_escape(number_format($settlementLimit, 2)) ?> limit should be settled before taking further COD orders. (Rider payout rate — how much of the delivery fee a rider earns — isn't decided/built yet; this page is cash-collected tracking only.)</p>
        <form method="get" class="filter-row">
            <input type="text" name="q" placeholder="Search rider name" value="<?= admin_escape($search) ?>">
            <button type="submit" class="btn btn-outline">Search</button>
        </form>
    </div>

    <div class="card">
        <?php if (empty($riders)): ?>
            <p class="muted">No riders found.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table>
            <tr><th>Rider</th><th>Mobile</th><th>COD Cash Held</th><th></th></tr>
            <?php foreach ($riders as $r):
                $held = (float) $r['cod_cash_held'];
                $isOver = $held >= $settlementLimit;
            ?>
            <tr>
                <td><?= admin_escape($r['name']) ?></td>
                <td><?= admin_escape($r['mobile'] ?? '—') ?></td>
                <td>
                    ₹<?= admin_escape(number_format($held, 2)) ?>
                    <?php if ($isOver): ?>
                        <span class="badge inactive">⚠ over limit</span>
                    <?php endif; ?>
                </td>
                <td><a href="rider-settlements.php?rider_id=<?= (int) $r['id'] ?>" class="btn btn-outline">View</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <?php
}

require __DIR__ . '/_layout_foot.php';
