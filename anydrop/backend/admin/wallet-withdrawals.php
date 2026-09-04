<?php
/**
 * Anydrop — Admin Web UI: Wallet Withdrawals (PENDING.md §37;
 * migration 65; docs/74).
 *
 * Lifecycle (mirrors admin/refunds.php's shape on purpose — same
 * admin mental model):
 *
 *   requested -> approved -> processing -> completed
 *             \-> rejected (from requested or approved only — once
 *                 'processing' the admin has already sent real money
 *                 externally, so rejecting is no longer valid)
 *
 * NO REAL PAYOUT GATEWAY — same manual model as admin/refunds.php and
 * admin/settlements.php: the admin sends the actual bank/UPI transfer
 * themselves, outside this system, then records the reference here.
 * This page tracks and reconciles that transfer; it never moves money
 * itself. The wallet BALANCE was already debited at request time (see
 * lib/customer_wallet_withdrawal.php's request_wallet_withdrawal()
 * kdoc for why) — Approve/Processing/Complete here never touch the
 * wallet again; only Reject does (credits the hold back).
 *
 * Admin sees the FULL, unmasked account number/IFSC/UPI (same as
 * admin/settlements.php already does for restaurant payouts) since
 * they're the one sending the transfer — this is the one place in the
 * app that intentionally does NOT mask payout details.
 *
 * Gated on `wallet_withdrawals_view` (list) / `wallet_withdrawals_manage`
 * (act) — migration 65's new permission pair.
 *
 * STATUS: 🟡 BUILT 2026-08-30 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page in this
 * project (no PHP CLI/live DB here). Needs migration 65 run, then a
 * live click-through: submit a withdrawal request as a customer ->
 * confirm a 'requested' row appears here -> Approve -> Mark
 * Processing (enter a reference) -> Mark Completed -> confirm
 * platform_ledger gets a 'wallet_withdrawal_out' row. Also test Reject
 * from both 'requested' and 'approved' states -> confirm the wallet
 * balance is credited back exactly.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/customer_wallet_withdrawal.php';

$admin = admin_require_login();
admin_require_permission($admin, 'wallet_withdrawals_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'wallet_withdrawals_manage');

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'wallet_withdrawals_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $withdrawalId = (int) ($_POST['withdrawal_id'] ?? 0);

        if ($formAction === 'approve') {
            $result = approve_wallet_withdrawal($db, $withdrawalId, $admin['id']);
            $flash = $result['ok'] ? 'Withdrawal approved.' : 'Could not approve (it may have already been resolved).';
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'processing') {
            $reference = trim($_POST['payout_reference'] ?? '');
            $result = mark_wallet_withdrawal_processing($db, $withdrawalId, $admin['id'], $reference);
            $flash = $result['ok'] ? 'Marked as processing — money in flight.' : ('Could not update: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'complete') {
            $result = complete_wallet_withdrawal($db, $withdrawalId, $admin['id']);
            $flash = $result['ok'] ? 'Withdrawal completed — ledger updated.' : ('Could not complete: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            $result = reject_wallet_withdrawal($db, $withdrawalId, $admin['id'], $reason);
            $flash = $result['ok'] ? 'Withdrawal request rejected — balance credited back.' : ('Could not reject: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$withdrawals = admin_list_wallet_withdrawals($db);

$statusLabels = [
    'requested' => 'Requested',
    'approved' => 'Approved',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
];

$csrf = admin_csrf_token();
$pageTitle = 'Wallet Withdrawals';
$activeNav = 'wallet_withdrawals';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Wallet Withdrawals</h2>
    <p class="muted">
        Customers request their Anydrop Wallet balance be paid out to
        their bank/UPI. The balance is already deducted from their
        wallet the moment they submit the request — Approve, Mark
        Processing, and Mark Completed below never touch the wallet
        again, they only track your manual bank/UPI transfer. Reject
        is the one action that credits the held amount back to the
        customer's wallet. There is no payout gateway — send the
        transfer yourself, then record the reference here.
    </p>
    <?php if (empty($withdrawals)): ?>
        <p class="muted">Nothing pending right now.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Customer</th><th>Amount</th><th>Method</th><th>Payout details</th><th>Status</th><th>Reference</th><th>Requested</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
        <?php foreach ($withdrawals as $w): ?>
        <tr>
            <td><?= admin_escape($w['customer_name'] ?: $w['customer_phone']) ?></td>
            <td>₹<?= admin_escape((string) $w['amount']) ?></td>
            <td><?= $w['payout_method'] === 'upi' ? 'UPI' : 'Bank transfer' ?></td>
            <td>
                <?= admin_escape($w['account_holder_name']) ?><br>
                <?php if ($w['payout_method'] === 'upi'): ?>
                    <span class="muted"><?= admin_escape($w['upi_id']) ?></span>
                <?php else: ?>
                    <span class="muted"><?= admin_escape($w['bank_name'] ?? '') ?> · <?= admin_escape($w['account_number'] ?? '') ?> · <?= admin_escape($w['ifsc_code'] ?? '') ?></span>
                <?php endif; ?>
            </td>
            <td><span class="badge <?= $w['status'] === 'completed' ? 'active' : 'inactive' ?>"><?= admin_escape($statusLabels[$w['status']] ?? $w['status']) ?></span></td>
            <td><?= admin_escape($w['payout_reference'] ?? '—') ?></td>
            <td><?= admin_escape($w['requested_at']) ?></td>
            <?php if ($canManage): ?>
            <td class="row-actions">
                <?php if ($w['status'] === 'requested'): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                    <button type="submit" class="btn btn-primary">Approve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($w['status'] === 'approved'): ?>
                <form method="post" style="display:inline;" onsubmit="return promptPayoutReference(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="processing">
                    <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                    <input type="hidden" name="payout_reference" class="payout-reference-field">
                    <button type="submit" class="btn btn-primary">Mark Processing</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($w['status'] === 'processing'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Confirm the transfer (ref: <?= admin_escape($w['payout_reference'] ?? '') ?>) actually landed in the customer\'s account before marking this Completed — this writes the platform ledger entry.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="complete">
                    <input type="hidden" name="withdrawal_id" value="<?= (int) $w['id'] ?>">
                    <button type="submit" class="btn btn-primary">Mark Completed</button>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function promptRejectReason(form) {
    var reason = prompt('Reason for rejecting this withdrawal request:');
    if (!reason) { return false; }
    form.querySelector('.reject-reason-field').value = reason;
    return true;
}
function promptPayoutReference(form) {
    var ref = prompt('Enter the reference/UTR of the transfer you just sent to the customer:');
    if (!ref) { return false; }
    form.querySelector('.payout-reference-field').value = ref;
    return true;
}
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
