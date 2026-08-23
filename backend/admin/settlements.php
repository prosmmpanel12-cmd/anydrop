<?php
/**
 * Anydrop — Admin Web UI: Restaurant Settlements
 *
 * Implements recall.md Phase C items 21-23 (doc 19 §6's Payout /
 * Settlement System) — the per-restaurant "kitna aaya kitna gaya" view
 * + the "Pay Now" action, and bank details.
 *
 * List mode (no ?restaurant_id): every restaurant with a non-zero
 * current_due, signed per doc 19 §6 (positive = restaurant owes admin,
 * negative = admin owes restaurant), searchable by name.
 *
 * Detail mode (?restaurant_id=N): bank details (view/edit), the full
 * chronological restaurant_due_ledger statement with running balance
 * (a direct unfiltered read — no new aggregation, per doc 19 §6), and
 * the Pay Now form. All settlement writes go through
 * lib/ledger.php's record_settlement() — this page never inserts into
 * restaurant_payments/restaurant_due_ledger/platform_ledger directly,
 * so the three can never drift apart.
 *
 * Gated on payouts_view/payouts_manage (migration 29's existing keys —
 * no new permission needed).
 *
 * NOT WIRED: nothing in the codebase yet automatically writes
 * commission_cod / payout_payable entries when an order is placed or
 * paid (see lib/ledger.php's record_cod_order_ledger_entry() /
 * record_paid_order_ledger_entries() kdocs for why — no 'delivered'
 * transition and no payment-confirmation flow exist yet). Until those
 * land, every restaurant's ledger here will be empty except for
 * whatever Pay Now settlements an admin manually records — that part
 * IS fully live and independent of those two gaps.
 *
 * STATUS: 🆕 BUILT 2026-08-22 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox). Needs migration 38 run live, then: save
 * bank details for a test restaurant, record a manual_adjustment-style
 * Pay Now in both directions, confirm current_due and the ledger
 * statement update correctly and platform-ledger.php's totals move
 * with it.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/ledger.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission($admin['id'], 'payouts_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';
$restaurantId = isset($_GET['restaurant_id']) ? (int) $_GET['restaurant_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canEdit) {
        $flash = 'You don\'t have permission to manage settlements.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $postRestaurantId = (int) ($_POST['restaurant_id'] ?? 0);

        if ($formAction === 'save_bank_details') {
            $holder = trim((string) ($_POST['account_holder_name'] ?? ''));
            $bank = trim((string) ($_POST['bank_name'] ?? ''));
            $accNum = trim((string) ($_POST['account_number'] ?? ''));
            $ifsc = trim((string) ($_POST['ifsc_code'] ?? ''));
            $upi = trim((string) ($_POST['upi_id'] ?? ''));

            if ($holder === '' || $bank === '' || $accNum === '' || $ifsc === '') {
                $flash = 'Account holder name, bank name, account number, and IFSC are all required.';
                $flashType = 'error';
            } else {
                $db->prepare(
                    'INSERT INTO restaurant_bank_details (restaurant_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id)
                     VALUES (:rid, :holder, :bank, :acc, :ifsc, :upi)
                     ON DUPLICATE KEY UPDATE account_holder_name = :holder2, bank_name = :bank2, account_number = :acc2, ifsc_code = :ifsc2, upi_id = :upi2'
                )->execute([
                    'rid' => $postRestaurantId, 'holder' => $holder, 'bank' => $bank, 'acc' => $accNum, 'ifsc' => $ifsc, 'upi' => $upi !== '' ? $upi : null,
                    'holder2' => $holder, 'bank2' => $bank, 'acc2' => $accNum, 'ifsc2' => $ifsc, 'upi2' => $upi !== '' ? $upi : null,
                ]);
                write_audit_log('admin', $admin['id'], 'restaurant_bank_details_saved', ['restaurant_id' => $postRestaurantId]);
                $flash = 'Bank details saved.';
            }
        } elseif ($formAction === 'pay_now') {
            $direction = $_POST['direction'] ?? '';
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $utr = trim((string) ($_POST['utr_number'] ?? '')) ?: null;
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;
            $paymentDate = trim((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d');

            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid settlement amount.';
                $flashType = 'error';
            } elseif (!in_array($direction, ['admin_to_restaurant', 'restaurant_to_admin'], true)) {
                $flash = 'Invalid settlement direction.';
                $flashType = 'error';
            } else {
                try {
                    record_settlement(
                        $db, $postRestaurantId, $direction, (float) $amount, (int) $admin['id'],
                        $utr, null, $remarks, $paymentDate
                    );
                    write_audit_log('admin', $admin['id'], 'settlement_recorded', [
                        'restaurant_id' => $postRestaurantId, 'direction' => $direction, 'amount' => $amount,
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

$csrf = admin_csrf_token();
$activeNav = 'settlements';

if ($restaurantId !== null) {
    // ---------- Detail mode ----------
    $rStmt = $db->prepare('SELECT id, name, current_due FROM restaurants WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $restaurantId]);
    $restaurant = $rStmt->fetch();

    if (!$restaurant) {
        $pageTitle = 'Settlements';
        require __DIR__ . '/_layout_head.php';
        echo '<div class="section"><div class="card"><p class="muted">Restaurant not found.</p><a class="btn btn-outline" href="settlements.php">Back to list</a></div></div>';
        require __DIR__ . '/_layout_foot.php';
        exit;
    }

    $bankStmt = $db->prepare('SELECT * FROM restaurant_bank_details WHERE restaurant_id = :id LIMIT 1');
    $bankStmt->execute(['id' => $restaurantId]);
    $bank = $bankStmt->fetch();

    $ledgerStmt = $db->prepare(
        'SELECT * FROM restaurant_due_ledger WHERE restaurant_id = :id ORDER BY created_at DESC, id DESC LIMIT 200'
    );
    $ledgerStmt->execute(['id' => $restaurantId]);
    $ledgerRows = $ledgerStmt->fetchAll();

    $paymentsStmt = $db->prepare(
        'SELECT * FROM restaurant_payments WHERE restaurant_id = :id ORDER BY created_at DESC LIMIT 50'
    );
    $paymentsStmt->execute(['id' => $restaurantId]);
    $payments = $paymentsStmt->fetchAll();

    $currentDue = (float) $restaurant['current_due'];
    $pageTitle = 'Settlement — ' . $restaurant['name'];
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">
    <div class="card">
        <a href="settlements.php" class="btn btn-outline" style="margin-bottom:12px;">&larr; All restaurants</a>
        <h2><?= admin_escape($restaurant['name']) ?></h2>
        <p>
            <?php if ($currentDue > 0): ?>
                <span class="badge inactive">Restaurant owes admin: ₹<?= admin_escape(number_format($currentDue, 2)) ?></span>
            <?php elseif ($currentDue < 0): ?>
                <span class="badge active">Admin owes restaurant: ₹<?= admin_escape(number_format(abs($currentDue), 2)) ?></span>
            <?php else: ?>
                <span class="badge active">Fully settled</span>
            <?php endif; ?>
        </p>
    </div>

    <div class="card">
        <h2>Bank Details</h2>
        <?php if ($canEdit): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="save_bank_details">
            <input type="hidden" name="restaurant_id" value="<?= (int) $restaurantId ?>">
            <div class="form-grid">
                <label>Account Holder Name
                    <input type="text" name="account_holder_name" value="<?= admin_escape($bank['account_holder_name'] ?? '') ?>" required>
                </label>
                <label>Bank Name
                    <input type="text" name="bank_name" value="<?= admin_escape($bank['bank_name'] ?? '') ?>" required>
                </label>
                <label>Account Number
                    <input type="text" name="account_number" value="<?= admin_escape($bank['account_number'] ?? '') ?>" required>
                </label>
                <label>IFSC Code
                    <input type="text" name="ifsc_code" value="<?= admin_escape($bank['ifsc_code'] ?? '') ?>" required>
                </label>
                <label>UPI ID <span class="muted">(optional)</span>
                    <input type="text" name="upi_id" value="<?= admin_escape($bank['upi_id'] ?? '') ?>">
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Save Bank Details</button>
        </form>
        <?php elseif ($bank): ?>
            <p><?= admin_escape($bank['account_holder_name']) ?> — <?= admin_escape($bank['bank_name']) ?> — <?= admin_escape($bank['account_number']) ?> — <?= admin_escape($bank['ifsc_code']) ?></p>
        <?php else: ?>
            <p class="muted">No bank details on file.</p>
        <?php endif; ?>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
        <h2>Pay Now</h2>
        <p class="muted">Records a settlement in whichever direction the current balance needs — admin paying the restaurant its online-order payouts, or the restaurant paying admin its COD commissions.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="pay_now">
            <input type="hidden" name="restaurant_id" value="<?= (int) $restaurantId ?>">
            <div class="form-grid">
                <label>Direction
                    <select name="direction" required>
                        <option value="admin_to_restaurant" <?= $currentDue < 0 ? 'selected' : '' ?>>Admin pays restaurant</option>
                        <option value="restaurant_to_admin" <?= $currentDue > 0 ? 'selected' : '' ?>>Restaurant pays admin</option>
                    </select>
                </label>
                <label>Amount (₹)
                    <input type="number" name="amount" step="0.01" min="0.01" value="<?= admin_escape(number_format(abs($currentDue), 2, '.', '')) ?>" required>
                </label>
                <label>Payment Date
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>">
                </label>
                <label>UTR / Transaction ID <span class="muted">(optional)</span>
                    <input type="text" name="utr_number">
                </label>
                <label>Remarks <span class="muted">(optional)</span>
                    <input type="text" name="remarks">
                </label>
            </div>
            <button type="submit" class="btn btn-primary"
                data-confirm-title="Record this settlement?"
                data-confirm-text="This updates the restaurant's due balance and the platform cash ledger immediately."
                data-confirm-ok-label="Record Settlement">Record Settlement</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Ledger Statement</h2>
        <?php if (empty($ledgerRows)): ?>
            <p class="muted">No ledger entries yet.</p>
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

    <div class="card">
        <h2>Settlement History</h2>
        <?php if (empty($payments)): ?>
            <p class="muted">No settlements recorded yet.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table>
            <tr><th>Date</th><th>Direction</th><th>Amount</th><th>UTR</th><th>Remarks</th></tr>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= admin_escape($p['payment_date'] ?? $p['created_at']) ?></td>
                <td><?= $p['direction'] === 'admin_to_restaurant' ? 'Admin → Restaurant' : 'Restaurant → Admin' ?></td>
                <td>₹<?= admin_escape(number_format((float) $p['amount'], 2)) ?></td>
                <td><?= admin_escape($p['utr_number'] ?? '—') ?></td>
                <td class="muted"><?= admin_escape($p['remarks'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <?php
    require __DIR__ . '/_layout_foot.php';
    exit;
}

// ---------- List mode ----------
$q = trim($_GET['q'] ?? '');
$sql = "SELECT id, name, current_due FROM restaurants WHERE deleted_at IS NULL";
$params = [];
if ($q !== '') {
    $sql .= " AND name LIKE :q";
    $params['q'] = "%$q%";
}
$sql .= " ORDER BY ABS(current_due) DESC, name LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$restaurants = $stmt->fetchAll();

$pageTitle = 'Settlements';
require __DIR__ . '/_layout_head.php';
?>
<div class="section">
<div class="card">
    <h2>Restaurant Settlements</h2>
    <p class="muted">Positive = restaurant owes admin (COD commissions not yet settled). Negative = admin owes restaurant (online-order payouts not yet paid out). Sorted by largest outstanding balance first.</p>
    <form method="get" class="filter-row">
        <input type="text" name="q" placeholder="Search restaurant name" value="<?= admin_escape($q) ?>">
        <button type="submit" class="btn btn-outline">Search</button>
        <a href="settlements.php" class="btn btn-outline">Clear</a>
    </form>
</div>
<div class="card">
    <?php if (empty($restaurants)): ?>
        <p class="muted">No restaurants found.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Restaurant</th><th>Balance</th><th></th></tr>
        <?php foreach ($restaurants as $r): $due = (float) $r['current_due']; ?>
        <tr>
            <td><?= admin_escape($r['name']) ?></td>
            <td>
                <?php if ($due > 0): ?>
                    <span class="badge inactive">Owes admin ₹<?= admin_escape(number_format($due, 2)) ?></span>
                <?php elseif ($due < 0): ?>
                    <span class="badge active">Owed ₹<?= admin_escape(number_format(abs($due), 2)) ?></span>
                <?php else: ?>
                    <span class="badge active">Settled</span>
                <?php endif; ?>
            </td>
            <td><a class="btn btn-outline" href="settlements.php?restaurant_id=<?= (int) $r['id'] ?>">View / Settle</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
