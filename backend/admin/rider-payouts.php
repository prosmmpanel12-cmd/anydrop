<?php
/**
 * Anydrop — Admin Web UI: Rider Payout Requests (deep-plan §21;
 * migration 74).
 *
 * Lifecycle (mirrors admin/wallet-withdrawals.php's shape on purpose —
 * same admin mental model, same underlying design):
 *
 *   requested -> approved -> processing -> completed
 *             \-> rejected (from requested or approved only — once
 *                 'processing', the admin has already sent real money
 *                 externally, so rejecting is no longer a valid move)
 *
 * NO REAL PAYOUT GATEWAY — same manual model as admin/refunds.php,
 * admin/settlements.php, and admin/wallet-withdrawals.php: the admin
 * sends the actual bank/UPI transfer themselves, outside this system,
 * then records the reference here. This page tracks and reconciles
 * that transfer; it never moves money itself. The rider's
 * earnings_balance was already debited at REQUEST time (see
 * lib/rider_payout.php's request_rider_payout() kdoc) — Approve/
 * Processing/Complete here never touch that balance again; only
 * Reject does (credits the hold back via an 'adjustment_credit'
 * ledger row).
 *
 * This is a SEPARATE screen from admin/rider-earnings.php's own
 * "Record Payout" form — that one is an admin proactively paying a
 * rider out with no request behind it; this one is reviewing a
 * RIDER-INITIATED request. Both correctly write to the same
 * rider_earnings_ledger/riders.earnings_balance, they are just two
 * independent entry points into it (see lib/rider_payout.php's file
 * kdoc for why that's safe).
 *
 * Admin sees the FULL, unmasked account number/IFSC/UPI (same as
 * admin/settlements.php and admin/wallet-withdrawals.php already do)
 * since they're the one sending the transfer.
 *
 * Gated on `rider_payouts_view` (list) / `rider_payouts_manage` (act)
 * — migration 74's new permission pair, deliberately separate from
 * the existing `payouts_view`/`payouts_manage` already shared by
 * Settlements/Rider Earnings/Platform Ledger/Commission Rules.
 *
 * STATUS: 🟡 BUILT 2026-09-05 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page in this
 * project (no PHP CLI/live DB here). Needs migration 74 run, then a
 * live click-through: request a payout as a rider -> confirm a
 * 'requested' row appears here -> Approve -> Mark Processing (enter a
 * reference) -> Mark Completed -> confirm platform_ledger gets a
 * 'rider_payout_out' row. Also test Reject from both 'requested' and
 * 'approved' states -> confirm the rider's earnings_balance is
 * credited back exactly.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/rider_payout.php';

$admin = admin_require_login();
admin_require_permission($admin, 'rider_payouts_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'rider_payouts_manage');

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'rider_payouts_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $requestId = (int) ($_POST['request_id'] ?? 0);

        if ($formAction === 'approve') {
            $result = approve_rider_payout_request($db, $requestId, $admin['id']);
            $flash = $result['ok'] ? 'Payout request approved.' : 'Could not approve (it may have already been resolved).';
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'processing') {
            $reference = trim($_POST['payout_reference'] ?? '');
            $result = mark_rider_payout_request_processing($db, $requestId, $admin['id'], $reference);
            $flash = $result['ok'] ? 'Marked as processing — money in flight.' : ('Could not update: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'complete') {
            $result = complete_rider_payout_request($db, $requestId, $admin['id']);
            $flash = $result['ok'] ? 'Payout completed — ledger updated.' : ('Could not complete: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            $result = reject_rider_payout_request($db, $requestId, $admin['id'], $reason);
            $flash = $result['ok'] ? 'Payout request rejected — balance credited back.' : ('Could not reject: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        }
    }
}

$requests = admin_list_rider_payout_requests($db);

$statusLabels = [
    'requested' => 'Requested',
    'approved' => 'Approved',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
];

$csrf = admin_csrf_token();
$pageTitle = 'Rider Payout Requests';
$activeNav = 'rider_payouts';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Rider Payout Requests</h2>
    <p class="muted">
        Riders request their earnings balance be paid out to their
        bank/UPI from the app. The balance is already deducted from
        their earnings the moment they submit the request — Approve,
        Mark Processing, and Mark Completed below never touch that
        balance again, they only track your manual bank/UPI transfer.
        Reject is the one action that credits the held amount back.
        There is no payout gateway — send the transfer yourself, then
        record the reference here. (For paying a rider out WITHOUT a
        request behind it, use the Record Payout form on Rider
        Earnings instead — that is a separate, admin-initiated flow.)
    </p>
    <?php if (empty($requests)): ?>
        <p class="muted">Nothing pending right now.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Rider</th><th>Amount</th><th>Method</th><th>Payout details</th><th>Status</th><th>Reference</th><th>Requested</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
        <?php foreach ($requests as $p): ?>
        <tr>
            <td><?= admin_escape($p['rider_name'] ?: $p['rider_mobile']) ?></td>
            <td>₹<?= admin_escape((string) $p['amount']) ?></td>
            <td><?= $p['payout_method'] === 'upi' ? 'UPI' : 'Bank transfer' ?></td>
            <td>
                <?= admin_escape($p['account_holder_name']) ?><br>
                <?php if ($p['payout_method'] === 'upi'): ?>
                    <span class="muted"><?= admin_escape($p['upi_id']) ?></span>
                <?php else: ?>
                    <span class="muted"><?= admin_escape($p['bank_name'] ?? '') ?> · <?= admin_escape($p['account_number'] ?? '') ?> · <?= admin_escape($p['ifsc_code'] ?? '') ?></span>
                <?php endif; ?>
            </td>
            <td><span class="badge <?= $p['status'] === 'completed' ? 'active' : 'inactive' ?>"><?= admin_escape($statusLabels[$p['status']] ?? $p['status']) ?></span></td>
            <td><?= admin_escape($p['payout_reference'] ?? '—') ?></td>
            <td><?= admin_escape($p['requested_at']) ?></td>
            <?php if ($canManage): ?>
            <td class="row-actions">
                <?php if ($p['status'] === 'requested'): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="approve">
                    <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" class="btn btn-primary">Approve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($p['status'] === 'approved'): ?>
                <form method="post" style="display:inline;" onsubmit="return promptPayoutReference(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="processing">
                    <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="payout_reference" class="payout-reference-field">
                    <button type="submit" class="btn btn-primary">Mark Processing</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptRejectReason(this);">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="reject">
                    <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="reject_reason" class="reject-reason-field">
                    <button type="submit" class="btn btn-outline danger">Reject</button>
                </form>
                <?php elseif ($p['status'] === 'processing'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Confirm the transfer (ref: <?= admin_escape($p['payout_reference'] ?? '') ?>) actually landed in the rider\'s account before marking this Completed — this writes the platform ledger entry.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="complete">
                    <input type="hidden" name="request_id" value="<?= (int) $p['id'] ?>">
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
    var reason = prompt('Reason for rejecting this payout request:');
    if (!reason) { return false; }
    form.querySelector('.reject-reason-field').value = reason;
    return true;
}
function promptPayoutReference(form) {
    var ref = prompt('Enter the reference/UTR of the transfer you just sent to the rider:');
    if (!ref) { return false; }
    form.querySelector('.payout-reference-field').value = ref;
    return true;
}
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
