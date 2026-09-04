<?php
/**
 * Anydrop — Admin Web UI: Rider Earnings (deep-plan §19-20, migration 73).
 *
 * Mirrors rider-settlements.php's shape closely (list mode / detail
 * mode split, same table styling, same lock-then-write ledger
 * pattern) but for the OPPOSITE direction of money — what the
 * platform owes the rider for completed deliveries, not cash the
 * rider is holding. Deliberately a separate page/table/balance
 * column, never merged with Rider Settlements — see migration 73's
 * kdoc and deep-plan §20's explicit "do not mix" instruction.
 *
 * Rate settings card (rider_earning_share_percent / minimum) lives on
 * this same page rather than app-settings.php or directions-settings.php
 * — those are per-app-update-check and one-shared-API-key concerns
 * respectively; this is a rider-money concern, so it belongs next to
 * the ledger it drives, same "the setting lives next to what reads it"
 * placement fcm-settings.php uses for its own service-account field.
 *
 * List mode (no ?rider_id): every rider with earnings_balance > 0,
 * i.e. money currently owed and not yet paid out.
 *
 * Detail mode (?rider_id=N): full rider_earnings_ledger statement +
 * Record Payout form + Manual Adjustment form. All writes go through
 * lib/rider_earnings.php's record_rider_payout()/
 * record_rider_earnings_adjustment() — this page never inserts into
 * rider_earnings_ledger/riders directly, same discipline
 * rider-settlements.php already established.
 *
 * Gated on payouts_view/payouts_manage — same module as Settlements
 * and Rider Settlements.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/rider_earnings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission((int) $admin['id'], 'payouts_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';
$riderId = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to manage rider earnings.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'save_rate_settings') {
            $sharePercent = trim((string) ($_POST['share_percent'] ?? ''));
            $minimum = trim((string) ($_POST['minimum'] ?? ''));
            if (!is_numeric($sharePercent) || (float) $sharePercent < 0 || (float) $sharePercent > 100) {
                $flash = 'Share percent must be a number between 0 and 100.';
                $flashType = 'error';
            } elseif (!is_numeric($minimum) || (float) $minimum < 0) {
                $flash = 'Minimum must be a number 0 or above.';
                $flashType = 'error';
            } else {
                set_setting('rider_earning_share_percent', (string) (float) $sharePercent);
                set_setting('rider_earning_minimum', (string) (float) $minimum);
                write_audit_log('admin', $admin['id'], 'rider_earning_rate_updated', [
                    'share_percent' => $sharePercent, 'minimum' => $minimum,
                ]);
                $flash = 'Rider earning rate saved — applies to deliveries completed from now on (does not recalculate past deliveries).';
            }
        } elseif ($formAction === 'record_payout') {
            $postRiderId = (int) ($_POST['rider_id'] ?? 0);
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;

            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid payout amount.';
                $flashType = 'error';
            } else {
                try {
                    record_rider_payout($db, $postRiderId, (float) $amount, (int) $admin['id'], $remarks);
                    write_audit_log('admin', $admin['id'], 'rider_payout_recorded', [
                        'rider_id' => $postRiderId, 'amount' => $amount,
                    ]);
                    $flash = 'Payout recorded — rider\'s earnings balance updated.';
                    $riderId = $postRiderId;
                } catch (Throwable $e) {
                    $flash = 'Could not record payout — nothing was saved.';
                    $flashType = 'error';
                }
            }
        } elseif ($formAction === 'record_adjustment') {
            $postRiderId = (int) ($_POST['rider_id'] ?? 0);
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $direction = $_POST['direction'] ?? 'credit';
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;

            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid adjustment amount.';
                $flashType = 'error';
            } elseif (!in_array($direction, ['credit', 'debit'], true)) {
                $flash = 'Unknown adjustment direction.';
                $flashType = 'error';
            } else {
                try {
                    record_rider_earnings_adjustment(
                        $db, $postRiderId, (float) $amount, $direction === 'credit', (int) $admin['id'], $remarks
                    );
                    write_audit_log('admin', $admin['id'], 'rider_earning_adjustment_recorded', [
                        'rider_id' => $postRiderId, 'amount' => $amount, 'direction' => $direction,
                    ]);
                    $flash = 'Adjustment recorded.';
                    $riderId = $postRiderId;
                } catch (Throwable $e) {
                    $flash = 'Could not record adjustment — nothing was saved.';
                    $flashType = 'error';
                }
            }
        }
    }
}

$csrf = admin_csrf_token();
$activeNav = 'rider_earnings';
$currentSharePercent = rider_earning_share_percent();
$currentMinimum = rider_earning_minimum();

if ($riderId !== null) {
    // ---------- Detail mode ----------
    $rStmt = $db->prepare('SELECT id, name, mobile, earnings_balance FROM riders WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $riderId]);
    $rider = $rStmt->fetch();

    if (!$rider) {
        $pageTitle = 'Rider Earnings';
        require __DIR__ . '/_layout_head.php';
        echo '<div class="section"><div class="card"><p class="muted">Rider not found.</p><a class="btn btn-outline" href="rider-earnings.php">Back to list</a></div></div>';
        require __DIR__ . '/_layout_foot.php';
        exit;
    }

    $ledgerStmt = $db->prepare(
        'SELECT rel.*, o.order_code
         FROM rider_earnings_ledger rel
         LEFT JOIN orders o ON o.id = rel.order_id
         WHERE rel.rider_id = :id
         ORDER BY rel.created_at DESC, rel.id DESC LIMIT 200'
    );
    $ledgerStmt->execute(['id' => $riderId]);
    $ledgerRows = $ledgerStmt->fetchAll();

    $balance = (float) $rider['earnings_balance'];

    $pageTitle = 'Rider Earnings — ' . $rider['name'];
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">
    <div class="card">
        <a href="rider-earnings.php" class="btn btn-outline" style="margin-bottom:12px;">&larr; All riders</a>
        <h2><?= admin_escape($rider['name']) ?></h2>
        <p class="muted"><?= admin_escape($rider['mobile'] ?? '—') ?></p>
        <p>
            <?php if ($balance <= 0): ?>
                <span class="badge active">Nothing owed</span>
            <?php else: ?>
                <span class="badge system">₹<?= admin_escape(number_format($balance, 2)) ?> owed to rider</span>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
        <h2>Record Payout</h2>
        <p class="muted">Records that this rider was paid out. Reduces their earnings balance.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="record_payout">
            <input type="hidden" name="rider_id" value="<?= (int) $riderId ?>">
            <label>Amount (₹)
                <input type="number" name="amount" step="0.01" min="0.01" max="<?= admin_escape(number_format($balance, 2)) ?>" value="<?= admin_escape(number_format($balance, 2)) ?>" required>
            </label>
            <label>Remarks (optional)
                <input type="text" name="remarks">
            </label>
            <button type="submit" class="btn btn-primary">Record Payout</button>
        </form>
    </div>

    <div class="card">
        <h2>Manual Adjustment</h2>
        <p class="muted">One-off correction — dispute resolution, a mis-calculated historical order, or an incentive/bonus not tied to any specific delivery.</p>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="record_adjustment">
            <input type="hidden" name="rider_id" value="<?= (int) $riderId ?>">
            <label style="min-width:140px">Amount (₹)
                <input type="number" name="amount" step="0.01" min="0.01" required>
            </label>
            <label style="min-width:160px">Direction
                <select name="direction">
                    <option value="credit">Credit (add to balance)</option>
                    <option value="debit">Debit (subtract from balance)</option>
                </select>
            </label>
            <label style="flex:1;min-width:200px">Remarks
                <input type="text" name="remarks" placeholder="Reason for this adjustment">
            </label>
            <button type="submit" class="btn btn-outline">Record Adjustment</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Earnings Ledger</h2>
        <?php if (empty($ledgerRows)): ?>
            <p class="muted">No entries yet — this rider hasn't completed a delivery since this feature was built.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table>
            <tr><th>Date</th><th>Type</th><th>Order</th><th>Amount</th><th>Running Balance</th><th>By</th><th>Note</th></tr>
            <?php foreach ($ledgerRows as $row): ?>
            <tr>
                <td><?= admin_escape($row['created_at']) ?></td>
                <td><?= admin_escape(str_replace('_', ' ', $row['entry_type'])) ?></td>
                <td><?= $row['order_code'] ? admin_escape($row['order_code']) : '—' ?></td>
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
    $sql = "SELECT id, name, mobile, earnings_balance FROM riders WHERE deleted_at IS NULL";
    $params = [];
    if ($search !== '') {
        $sql .= " AND name LIKE :q";
        $params['q'] = '%' . $search . '%';
    }
    $sql .= " ORDER BY earnings_balance DESC, name";
    $listStmt = $db->prepare($sql);
    $listStmt->execute($params);
    $riders = $listStmt->fetchAll();

    $pageTitle = 'Rider Earnings';
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">

    <?php if ($canEdit): ?>
    <div class="card">
        <h2>Payout Rate</h2>
        <p class="muted">
            Applies to every delivery completed FROM NOW ON — changing
            this does not recalculate earnings already recorded for
            past deliveries. Rider earning = <strong>share %</strong>
            of that order's delivery charge (already distance/area-based,
            see Delivery Pricing), floored at the minimum below.
        </p>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="save_rate_settings">
            <label style="min-width:160px">Rider share of delivery charge (%)
                <input type="number" name="share_percent" step="0.1" min="0" max="100" value="<?= admin_escape((string) $currentSharePercent) ?>" required>
            </label>
            <label style="min-width:160px">Minimum earning per delivery (₹)
                <input type="number" name="minimum" step="0.01" min="0" value="<?= admin_escape((string) $currentMinimum) ?>" required>
            </label>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Rider Earnings</h2>
        <p class="muted">What the platform currently owes each rider for completed deliveries — separate from Rider Settlements (COD cash the rider is holding, opposite direction of money).</p>
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
            <tr><th>Rider</th><th>Mobile</th><th>Earnings Balance</th><th></th></tr>
            <?php foreach ($riders as $r):
                $owed = (float) $r['earnings_balance'];
            ?>
            <tr>
                <td><?= admin_escape($r['name']) ?></td>
                <td><?= admin_escape($r['mobile'] ?? '—') ?></td>
                <td>
                    ₹<?= admin_escape(number_format($owed, 2)) ?>
                    <?php if ($owed > 0): ?>
                        <span class="badge system">owed</span>
                    <?php endif; ?>
                </td>
                <td><a href="rider-earnings.php?rider_id=<?= (int) $r['id'] ?>" class="btn btn-outline">View</a></td>
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
