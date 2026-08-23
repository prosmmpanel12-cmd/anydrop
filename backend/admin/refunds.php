<?php
/**
 * Anydrop — Admin Web UI: Refunds (recall.md Phase C item 25,
 * section 19; migration 42; doc 21 §2.2/§5.7).
 *
 * Lifecycle:
 *
 *   requested -> under_review -> approved -> processing -> refunded  (manual bank/UPI transfer, method default)
 *                              -> approved -> refunded               (wallet credit, item 15 — skips processing)
 *                                          \-> rejected (from under_review/approved)
 *
 * NO REAL GATEWAY REFUND API — same manual model as
 * admin/payment-pending.php's payment verification: the admin sends
 * the money back via their own UPI/bank app OUTSIDE this system, then
 * records the reference here. This page tracks and reconciles that
 * transfer; it never moves money itself. The wallet path (item 15) is
 * the one exception — crediting the wallet IS the actual refund
 * action, performed inside Anydrop, not tracked after the fact.
 *
 * Method is chosen at Approve time (see promptApprove()'s JS prompt) —
 * defaults to manual_upi_bank_transfer if left blank, matching
 * migration 42's own column default.
 *
 * Gated on `refunds_view` (list) / `refunds_manage` (act) — new
 * permission pair from migration 42, deliberately separate from
 * `payment_providers_manage` since refunds are a distinct blast
 * radius (money OUT to customers) from gateway config.
 *
 * STATUS: 🟡 BUILT 2026-08-23 — NOT build/device-verified, same
 * standing limitation as every other admin page this session (no PHP
 * CLI/live DB here). Needs migration 42 run, then a live
 * click-through: cancel a UPI-paid order as a customer -> confirm a
 * 'requested' row appears here -> Approve -> Mark Processing (enter a
 * UTR) -> Mark Refunded -> confirm orders.payment_status flips to
 * 'refunded' and a platform_ledger 'refund_out' row lands. Also test
 * Reject from both 'requested' and 'approved' states. Item 15's wallet
 * path (Approve with method=wallet -> Credit to Wallet) additionally
 * needs: confirm the customer's wallet balance actually increases,
 * confirm NO platform_ledger row lands for this path (by design), and
 * confirm OrderStatusActivity's refund card shows "Anydrop Wallet" as
 * the method.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/refunds.php';

$admin = admin_require_login();
admin_require_permission($admin, 'refunds_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'refunds_manage');

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'refunds_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $refundId = (int) ($_POST['refund_id'] ?? 0);

        if ($formAction === 'approve') {
            $expected = trim($_POST['expected_by_date'] ?? '');
            $method = trim($_POST['approve_method'] ?? '');
            $methodArg = in_array($method, ['manual_upi_bank_transfer', 'wallet'], true) ? $method : null;
            $result = approve_refund($db, $refundId, $admin['id'], $expected !== '' ? $expected : null, $methodArg);
            $flash = $result['ok'] ? 'Refund approved.' : 'Could not approve (it may have already been resolved).';
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'processing') {
            $reference = trim($_POST['refund_reference'] ?? '');
            $result = mark_refund_processing($db, $refundId, $admin['id'], $reference);
            $flash = $result['ok'] ? 'Marked as processing — money in flight.' : ('Could not update: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'complete') {
            $result = complete_refund($db, $refundId, $admin['id']);
            $flash = $result['ok'] ? 'Refund completed — ledger updated.' : ('Could not complete: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'complete_to_wallet') {
            $result = complete_refund_to_wallet($db, $refundId, $admin['id']);
            $flash = $result['ok'] ? 'Refund credited to customer wallet.' : ('Could not complete: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            $result = reject_refund($db, $refundId, $admin['id'], $reason);
            $flash = $result['ok'] ? 'Refund request rejected.' : ('Could not reject: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$refunds = admin_list_refunds($db);

$statusLabels = [
    'requested' => 'Requested',
    'under_review' => 'Under Review',
    'approved' => 'Approved',
    'processing' => 'Processing',
    'refunded' => 'Refunded',
    'rejected' => 'Rejected',
];

$csrf = admin_csrf_token();
$pageTitle = 'Refunds';
$activeNav = 'refunds';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Refunds</h2>
    <p class="muted">
        Requests here are almost always auto-created when a UPI-paid
        order gets cancelled or rejected. There is no gateway refund
        API (native UPI collection has none) — when you Approve a
        request you choose the method: the default is a manual
        bank/UPI transfer you send yourself, then record the reference
        here; the alternative is crediting the customer's Anydrop
        Wallet instantly instead, with no external transfer needed.
        Nothing is marked "Refunded" until the manual transfer is
        confirmed or the wallet credit is applied.
    </p>
    <?php if (empty($refunds)): ?>
        <p class="muted">Nothing pending right now.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Order</th><th>Customer</th><th>Amount</th><th>Reason</th><th>Status</th><th>Method</th><th>Expected by</th><th>Requested</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
        <?php foreach ($refunds as $r): ?>
        <tr>
            <td><?= admin_escape($r['order_code']) ?></td>
            <td><?= admin_escape($r['customer_name'] ?: $r['customer_phone']) ?></td>
            <td>₹<?= admin_escape((string) $r['amount']) ?></td>
            <td><?= admin_escape($r['reason']) ?></td>
            <td><span class="badge <?= $r['status'] === 'refunded' ? 'active' : 'inactive' ?>"><?= admin_escape($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
            <td><?= $r['method'] === 'wallet' ? 'Wallet' : 'Bank/UPI transfer' ?></td>
            <td><?= admin_escape($r['expected_by_date'] ?? '—') ?></td>
            <td><?= admin_escape($r['requested_at']) ?></td>
            <?php if ($canManage): ?>
            <td class="row-actions">
                <?php if (in_array($r['status'], ['requested', 'under_review'], true)): ?>
                <form method="post" style="display:inline;" onsubmit="return promptApprove(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="expected_by_date" class="expected-date-field">
                    <input type="hidden" name="approve_method" class="approve-method-field">
                    <button type="submit" class="btn btn-primary">Approve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($r['status'] === 'approved' && $r['method'] === 'wallet'): ?>
                <!-- item 15 — wallet-method refunds skip "Mark Processing"
                     entirely (see complete_refund_to_wallet()'s kdoc: a
                     wallet credit is instant, there's no external
                     transfer to be "in flight" the way a bank/UPI
                     transfer needs a reference captured first). -->
                <form method="post" style="display:inline;" onsubmit="return confirm('Credit ₹<?= admin_escape((string) $r['amount']) ?> to the customer\'s Anydrop Wallet now? This cannot be undone from here.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="complete_to_wallet">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-primary">Credit to Wallet</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($r['status'] === 'approved'): ?>
                <form method="post" style="display:inline;" onsubmit="return promptRefundReference(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="processing">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="refund_reference" class="refund-reference-field">
                    <button type="submit" class="btn btn-primary">Mark Processing</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($r['status'] === 'processing'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Confirm the transfer (ref: <?= admin_escape($r['refund_reference'] ?? '') ?>) actually landed in the customer\'s account before marking this Refunded — this writes the platform ledger entry.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="complete">
                    <input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-primary">Mark Refunded</button>
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
function promptApprove(form) {
    var d = prompt('Expected refund-by date (YYYY-MM-DD), or leave blank to keep the default:');
    if (d === null) { return false; }
    form.querySelector('.expected-date-field').value = d.trim();

    var methodChoice = prompt('Refund method — type "wallet" to credit the customer\'s Anydrop Wallet instantly, or leave blank for the default manual bank/UPI transfer:');
    if (methodChoice === null) { return false; }
    var normalized = methodChoice.trim().toLowerCase();
    form.querySelector('.approve-method-field').value = normalized === 'wallet' ? 'wallet' : '';
    return true;
}
function promptRejectReason(form) {
    var reason = prompt('Reason for rejecting this refund request:');
    if (!reason) { return false; }
    form.querySelector('.reject-reason-field').value = reason;
    return true;
}
function promptRefundReference(form) {
    var ref = prompt('Enter the reference/UTR of the transfer you just sent back to the customer:');
    if (!ref) { return false; }
    form.querySelector('.refund-reference-field').value = ref;
    return true;
}
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
