<?php
/**
 * Anydrop — Admin Web UI: Pending UPI Payments
 *
 * Implements docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md
 * §5/§7 — the manual verification queue that's the actual "verify"
 * step in the native UPI flow (there's no live gateway/webhook, see
 * doc 23 §5). Reuses this codebase's existing Payout-approval UI
 * pattern (doc 19 §6: "Pay Now → screenshot/UTR/amount/date/remarks →
 * notification") rather than inventing a new one — same "human
 * reviews evidence, approves or rejects" shape as restaurant
 * settlements.
 *
 * Gated on `payment_providers_manage` — same key as payment-gateways.php.
 *
 * STATUS: 🟡 BUILT 2026-08-23 — NOT build/device-verified, same
 * standing limitation as every other session (no PHP CLI/live DB
 * here). Needs migration 40 run, then a live click-through: create a
 * UPI order, submit a UTR from the customer app after the window
 * opens, approve it here, confirm orders.payment_status flips to
 * 'paid' and the customer's next poll reports `success`.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/payment/PaymentService.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payment_providers_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $txnId = (int) ($_POST['txn_id'] ?? 0);

        if ($formAction === 'approve') {
            $amountConfirmedRaw = trim($_POST['amount_confirmed'] ?? '');
            if ($amountConfirmedRaw === '' || !is_numeric($amountConfirmedRaw)) {
                $flash = 'Enter the exact amount you see credited in your bank/UPI app before approving.';
                $flashType = 'error';
            } else {
                $result = PaymentService::adminDecide($db, $txnId, 'approve', null, $admin['id'], (float) $amountConfirmedRaw);
                if (!$result['ok'] && ($result['error'] ?? '') === 'amount_mismatch') {
                    $flash = 'Amount you entered doesn\'t match this order\'s total — approval blocked. If the customer genuinely paid a different amount, reject this and resolve it manually outside the app.';
                    $flashType = 'error';
                } else {
                    $flash = $result['ok'] && ($result['status'] ?? '') === 'success'
                        ? 'Payment approved — order marked paid.'
                        : 'Could not approve (it may have already been resolved).';
                    $flashType = $result['ok'] && ($result['status'] ?? '') === 'success' ? 'success' : 'error';
                }
            }
        } elseif ($formAction === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            if ($reason === '') {
                $flash = 'A reject reason is required.';
                $flashType = 'error';
            } else {
                $result = PaymentService::adminDecide($db, $txnId, 'reject', $reason, $admin['id']);
                $flash = $result['ok'] && $result['status'] === 'failed'
                    ? 'Payment rejected.'
                    : 'Could not reject (it may have already been resolved).';
                $flashType = $result['ok'] ? 'success' : 'error';
            }
        }
    }
}

$pending = PaymentService::adminPendingTransactions($db);

$csrf = admin_csrf_token();
$pageTitle = 'Pending UPI Payments';
$activeNav = 'payment_pending';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Pending UPI Payments</h2>
    <p class="muted">
        Match the amount + UTR (bank reference number) against your
        own UPI app/bank statement before approving. There is no
        automatic detection today (doc 23 §5) — this is the actual
        "verify" step for every native UPI order.
        <strong>Approval is blocked unless the amount you confirm below
        exactly matches this order's total</strong> — some UPI apps let
        a payer edit the amount before paying, so this checks against
        the order total on Anydrop's own server, not anything the form
        alone could be tricked into accepting (doc 23 addendum §A5).
    </p>
    <?php if (empty($pending)): ?>
        <p class="muted">Nothing waiting on review right now.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Order</th><th>Amount</th><th>Txn Ref</th><th>UTR</th><th>UTR Attempts</th><th>Status</th><th>Created</th><th></th></tr>
        <?php foreach ($pending as $t): ?>
        <tr>
            <td><?= admin_escape($t['order_code']) ?></td>
            <td>₹<?= admin_escape((string) $t['grand_total']) ?></td>
            <td><code><?= admin_escape($t['provider_txn_id']) ?></code></td>
            <td><?= $t['utr'] ? '<code>' . admin_escape($t['utr']) . '</code>' : '<span class="muted">Not submitted yet</span>' ?></td>
            <td><?= (int) $t['utr_attempts'] ?></td>
            <td><span class="badge <?= $t['status'] === 'utr_submitted' ? 'active' : 'inactive' ?>"><?= admin_escape($t['status']) ?></span></td>
            <td><?= admin_escape($t['created_at']) ?></td>
            <td class="row-actions">
                <form method="post" style="display:inline; display:flex; gap:6px; align-items:center;" onsubmit="return promptAmountConfirmed(this, <?= (float) $t['grand_total'] ?>);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="txn_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="amount_confirmed" class="amount-confirmed-field">
                    <button type="submit" class="btn btn-primary">Approve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="txn_id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function promptAmountConfirmed(form, expectedAmount) {
    var entered = prompt('Enter the EXACT amount you see credited in your bank/UPI app (order total is ₹' + expectedAmount + '):');
    if (entered === null || entered.trim() === '') { return false; }
    form.querySelector('.amount-confirmed-field').value = entered.trim();
    return true;
}
function promptRejectReason(form) {
    var reason = prompt('Reason for rejecting this payment:');
    if (!reason) { return false; }
    form.querySelector('.reject-reason-field').value = reason;
    return true;
}
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
