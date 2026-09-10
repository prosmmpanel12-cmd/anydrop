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
 * STATUS: 🟡 BUILT 2026-09-05, extended 2026-09-09 (migration 76) with
 * monthly batch pay — NOT build/device-verified, same standing sandbox
 * limitation as every other admin page in this project (no PHP CLI/
 * live DB here). Needs migrations 74+76 run, then a live click-through:
 * request a payout as a rider -> confirm a 'requested' row appears
 * here -> Approve -> Mark Processing (enter a reference) -> Mark
 * Completed -> confirm platform_ledger gets a 'rider_payout_out' row.
 * Also test Reject from both 'requested' and 'approved' states ->
 * confirm the rider's earnings_balance is credited back exactly.
 *
 * NEW 2026-09-09 — batch pay: select multiple 'requested' rows and
 * Approve Selected in one click (batch_approve), or select multiple
 * 'approved' rows and Batch Process Selected — a modal collects EACH
 * rider's own reference/screenshot (never one shared reference, see
 * migration 76's kdoc for why), then batch_processing stamps all of
 * them with one shared payout_batch_id (grouping tag only) and moves
 * them to 'processing' individually. The new "Past Batches" card lets
 * the admin Complete an entire batch in one click once every row in it
 * is 'processing'. Needs the same live click-through as above, plus:
 * multi-select 3+ requested rows -> Approve Selected -> select those
 * now-approved rows -> Batch Process Selected -> fill distinct
 * references per rider (+ optional screenshots) -> Submit Batch ->
 * confirm each row got its OWN reference/screenshot and a shared
 * batch id -> Complete Batch -> confirm all rows completed and each
 * wrote its own platform_ledger row.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/rider_payout.php';

$admin = admin_require_login();
admin_require_permission($admin, 'rider_payouts_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'rider_payouts_manage');

const MAX_PAYOUT_SCREENSHOT_BYTES = 5 * 1024 * 1024; // 5 MB — same cap as settlements.php
const PAYOUT_SCREENSHOT_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * App-owner ask, 2026-09-09 (migration 76): same optional transfer-proof
 * screenshot settlements.php's save_settlement_screenshot() already
 * offers for restaurant Pay Now — same size cap, same real-content MIME
 * sniff via finfo, same "no crop, save as-is" behavior. Duplicated
 * rather than shared, same convention support.php's own copy of this
 * pattern already follows in this codebase (each admin page keeps its
 * own copy since PHP has no shared "helpers" file these functions live
 * in yet).
 */
function save_payout_screenshot(array $file, ?string &$error): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Screenshot upload failed.';
        return null;
    }
    if ($file['size'] > MAX_PAYOUT_SCREENSHOT_BYTES) {
        $error = 'Screenshot is too large (max 5 MB).';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(PAYOUT_SCREENSHOT_MIME[$mime])) {
        $error = 'Unsupported file type — use JPG, PNG, or WEBP.';
        return null;
    }
    $ext = PAYOUT_SCREENSHOT_MIME[$mime];

    $uploadDir = __DIR__ . '/../uploads/rider_payout_screenshots';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'payout_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $error = 'Could not save the uploaded screenshot.';
        return null;
    }
    return 'uploads/rider_payout_screenshots/' . $filename;
}

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
            $screenshotError = null;
            $screenshotUrl = isset($_FILES['screenshot']) ? save_payout_screenshot($_FILES['screenshot'], $screenshotError) : null;
            if ($screenshotError !== null) {
                $flash = $screenshotError;
                $flashType = 'error';
            } else {
                $result = mark_rider_payout_request_processing($db, $requestId, $admin['id'], $reference, $screenshotUrl);
                $flash = $result['ok'] ? 'Marked as processing — money in flight.' : ('Could not update: ' . ($result['error'] ?? 'unknown'));
                $flashType = $result['ok'] ? 'success' : 'error';
            }
        } elseif ($formAction === 'complete') {
            $result = complete_rider_payout_request($db, $requestId, $admin['id']);
            $flash = $result['ok'] ? 'Payout completed — ledger updated.' : ('Could not complete: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? '');
            $result = reject_rider_payout_request($db, $requestId, $admin['id'], $reason);
            $flash = $result['ok'] ? 'Payout request rejected — balance credited back.' : ('Could not reject: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
        } elseif ($formAction === 'batch_approve') {
            // Bulk version of the single 'approve' action above — same
            // per-row approve_rider_payout_request() call, just looped.
            // No batch_id stamped here: approval doesn't move money yet
            // (see class kdoc), only the processing step below groups
            // requests into a batch, since that's the step that
            // actually corresponds to "I sent this month's transfers".
            $ids = array_map('intval', $_POST['request_ids'] ?? []);
            $okCount = 0;
            foreach ($ids as $id) {
                if (approve_rider_payout_request($db, $id, $admin['id'])['ok']) {
                    $okCount++;
                }
            }
            $flash = $okCount > 0
                ? "$okCount of " . count($ids) . ' request(s) approved.'
                : 'Nothing was approved — selected requests may have already been resolved.';
            $flashType = $okCount > 0 ? 'success' : 'error';
        } elseif ($formAction === 'batch_processing') {
            // App-owner ask, 2026-09-09: monthly batch pay. Every
            // selected request keeps its OWN reference/screenshot (see
            // migration 76's kdoc — a single bank transfer can't cover
            // N different riders with one UTR); only the grouping tag
            // (payout_batch_id) is shared, purely for the admin UI's
            // "these were processed together" history view.
            $ids = array_map('intval', $_POST['request_ids'] ?? []);
            $batchId = generate_rider_payout_batch_id();
            $okCount = 0;
            $skipped = [];
            foreach ($ids as $id) {
                $reference = trim($_POST['reference'][$id] ?? '');
                if ($reference === '') {
                    $skipped[] = $id;
                    continue;
                }
                $screenshotError = null;
                $screenshotUrl = isset($_FILES['screenshot']['error'][$id]) && $_FILES['screenshot']['error'][$id] !== UPLOAD_ERR_NO_FILE
                    ? save_payout_screenshot([
                        'error' => $_FILES['screenshot']['error'][$id],
                        'size' => $_FILES['screenshot']['size'][$id],
                        'tmp_name' => $_FILES['screenshot']['tmp_name'][$id],
                    ], $screenshotError)
                    : null;
                // A bad screenshot on one rider's row skips ONLY that
                // row (added to $skipped) rather than aborting the
                // whole batch — the admin already has valid references
                // typed in for the rest, losing that work over one
                // rider's bad file would be worse than flagging just
                // that one for a manual retry.
                if ($screenshotError !== null) {
                    $skipped[] = $id;
                    continue;
                }
                $result = mark_rider_payout_request_processing($db, $id, $admin['id'], $reference, $screenshotUrl, $batchId);
                if ($result['ok']) {
                    $okCount++;
                } else {
                    $skipped[] = $id;
                }
            }
            if ($okCount > 0) {
                $flash = "$okCount request(s) marked processing under batch $batchId.";
                if (!empty($skipped)) {
                    $flash .= ' Skipped (missing reference or already resolved): ' . implode(', ', $skipped) . '.';
                }
                $flashType = 'success';
            } else {
                $flash = 'Nothing was processed — every selected request needs its own reference.';
                $flashType = 'error';
            }
        } elseif ($formAction === 'batch_complete') {
            // Marks every 'processing' request in one batch 'completed'
            // in one click — same per-row complete_rider_payout_request()
            // call the single-row Mark Completed button already uses,
            // just looped across the batch instead of typed one at a
            // time. Still per-row underneath: each row gets its own
            // platform_ledger 'rider_payout_out' entry (unchanged).
            $batchId = trim($_POST['batch_id'] ?? '');
            $rows = $batchId !== '' ? list_rider_payout_requests_by_batch($db, $batchId) : [];
            $okCount = 0;
            foreach ($rows as $row) {
                if ($row['status'] === 'processing' && complete_rider_payout_request($db, (int) $row['id'], $admin['id'])['ok']) {
                    $okCount++;
                }
            }
            $flash = $okCount > 0 ? "$okCount request(s) in batch $batchId marked completed." : 'Nothing to complete in that batch.';
            $flashType = $okCount > 0 ? 'success' : 'error';
        }
    }
}

$requests = admin_list_rider_payout_requests($db);
$batches = admin_has_permission((int) $admin['id'], 'rider_payouts_view') ? list_rider_payout_batches($db) : [];

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
    <p class="muted">
        <strong>Monthly batch pay:</strong> select multiple requests
        below with the checkboxes to Approve them together, or — once
        approved — to open the batch processing form where you enter
        each rider's own transfer reference/UTR (and optionally a
        screenshot) in one go. Every request still gets its own
        reference; only the "batch" label grouping them for your
        records is shared.
    </p>
    <?php if (empty($requests)): ?>
        <p class="muted">Nothing pending right now.</p>
    <?php else: ?>
    <form method="post" id="batchForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" id="batchFormAction" value="">
        <?php if ($canManage): ?>
        <div class="row-actions" style="margin-bottom:12px;">
            <button type="button" class="btn btn-outline" id="btnBatchApprove">Approve Selected</button>
            <button type="button" class="btn btn-primary" id="btnBatchProcess">Batch Process Selected (enter references)</button>
        </div>
        <?php endif; ?>
    <div class="table-responsive">
    <table>
        <tr>
            <?php if ($canManage): ?><th></th><?php endif; ?>
            <th>Rider</th><th>Amount</th><th>Method</th><th>Payout details</th><th>Status</th><th>Reference</th><th>Requested</th><?php if ($canManage): ?><th></th><?php endif; ?>
        </tr>
        <?php foreach ($requests as $p): ?>
        <tr>
            <?php if ($canManage): ?>
            <td>
                <?php if (in_array($p['status'], ['requested', 'approved'], true)): ?>
                <input type="checkbox" name="request_ids[]" value="<?= (int) $p['id'] ?>" class="batch-checkbox" data-status="<?= admin_escape($p['status']) ?>">
                <?php endif; ?>
            </td>
            <?php endif; ?>
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
            <td>
                <?= admin_escape($p['payout_reference'] ?? '—') ?>
                <?php if (!empty($p['payout_screenshot_url'])): ?>
                    <br><a href="<?= admin_escape(admin_base_url()) ?>/<?= admin_escape($p['payout_screenshot_url']) ?>" target="_blank" rel="noopener">
                        <img src="<?= admin_escape(admin_base_url()) ?>/<?= admin_escape($p['payout_screenshot_url']) ?>" alt="Transfer screenshot" style="height:32px;border-radius:4px;vertical-align:middle;">
                    </a>
                <?php endif; ?>
                <?php if (!empty($p['payout_batch_id'])): ?>
                    <br><span class="muted" style="font-size:11px;">Batch: <?= admin_escape($p['payout_batch_id']) ?></span>
                <?php endif; ?>
            </td>
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
                <form method="post" style="display:inline;" enctype="multipart/form-data" onsubmit="return promptPayoutReference(this);">
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
    </form>
    <?php endif; ?>
</div>

<?php if ($canManage && !empty($batches)): ?>
<div class="card">
    <h2>Past Batches</h2>
    <p class="muted">Requests processed together via "Batch Process Selected". Once every request in a batch is Processing, use Complete Batch here to mark them all Completed in one click (still writes one platform-ledger entry per request, as before).</p>
    <div class="table-responsive">
    <table>
        <tr><th>Batch</th><th>Requests</th><th>Total Amount</th><th>Processed</th><th>Status</th><th></th></tr>
        <?php foreach ($batches as $b): ?>
        <tr>
            <td><?= admin_escape($b['payout_batch_id']) ?></td>
            <td><?= (int) $b['request_count'] ?></td>
            <td>₹<?= admin_escape((string) $b['total_amount']) ?></td>
            <td><?= admin_escape($b['processed_at'] ?? '—') ?></td>
            <td>
                <?php if ((int) $b['completed_count'] === (int) $b['request_count']): ?>
                    <span class="badge active">All completed</span>
                <?php else: ?>
                    <span class="badge inactive"><?= (int) $b['completed_count'] ?> / <?= (int) $b['request_count'] ?> completed</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ((int) $b['completed_count'] < (int) $b['request_count']): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Mark every Processing request in this batch as Completed? Confirm the transfers actually landed first.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="batch_complete">
                    <input type="hidden" name="batch_id" value="<?= admin_escape($b['payout_batch_id']) ?>">
                    <button type="submit" class="btn btn-primary">Complete Batch</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>
</div>

<!-- Batch Processing modal: built client-side from the checked rows so
     the admin can type each rider's own reference (and optionally
     attach a screenshot) without leaving the page or losing their
     checkbox selection. Submits into the same #batchForm above via
     form_action=batch_processing, with reference[]/screenshot[] keyed
     by request_id (see the batch_processing POST handler's use of
     $_POST['reference'][$id]/$_FILES['screenshot'][...][$id]). -->
<div id="batchProcessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div class="card" style="max-width:640px;width:92%;max-height:80vh;overflow-y:auto;">
        <h2>Batch Process — Enter Each Rider's Reference</h2>
        <p class="muted">Every selected rider needs their own transfer reference/UTR — a screenshot is optional per rider.</p>
        <div id="batchProcessRows"></div>
        <div class="row-actions" style="margin-top:16px;">
            <button type="button" class="btn btn-primary" id="btnBatchProcessSubmit">Submit Batch</button>
            <button type="button" class="btn btn-outline" id="btnBatchProcessCancel">Cancel</button>
        </div>
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

(function () {
    var batchForm = document.getElementById('batchForm');
    var batchFormAction = document.getElementById('batchFormAction');
    var btnBatchApprove = document.getElementById('btnBatchApprove');
    var btnBatchProcess = document.getElementById('btnBatchProcess');
    var overlay = document.getElementById('batchProcessOverlay');
    var rowsContainer = document.getElementById('batchProcessRows');
    var btnSubmit = document.getElementById('btnBatchProcessSubmit');
    var btnCancel = document.getElementById('btnBatchProcessCancel');
    if (!batchForm) { return; }

    function checkedBoxes(status) {
        return Array.prototype.filter.call(
            document.querySelectorAll('.batch-checkbox:checked'),
            function (cb) { return !status || cb.dataset.status === status; }
        );
    }

    btnBatchApprove.addEventListener('click', function () {
        var boxes = checkedBoxes('requested');
        if (boxes.length === 0) {
            alert('Select at least one "Requested" row to approve.');
            return;
        }
        if (!confirm('Approve ' + boxes.length + ' selected request(s)?')) { return; }
        batchFormAction.value = 'batch_approve';
        batchForm.submit();
    });

    btnBatchProcess.addEventListener('click', function () {
        var boxes = checkedBoxes('approved');
        if (boxes.length === 0) {
            alert('Select at least one "Approved" row to batch-process (only Approved rows can move to Processing).');
            return;
        }
        rowsContainer.innerHTML = '';
        boxes.forEach(function (cb) {
            var tr = cb.closest('tr');
            var riderName = tr.children[1].textContent.trim();
            var amount = tr.children[2].textContent.trim();
            var id = cb.value;
            var row = document.createElement('div');
            row.style.marginBottom = '14px';
            row.innerHTML =
                '<label style="font-weight:600;">' + riderName + ' — ' + amount + '</label>' +
                '<input type="text" name="reference[' + id + ']" placeholder="Transfer reference / UTR" required style="margin-top:4px;">' +
                '<input type="file" name="screenshot[' + id + ']" accept="image/*" style="margin-top:4px;">';
            rowsContainer.appendChild(row);
        });
        overlay.style.display = 'flex';
    });

    btnCancel.addEventListener('click', function () {
        overlay.style.display = 'none';
        rowsContainer.innerHTML = '';
    });

    btnSubmit.addEventListener('click', function () {
        var inputs = rowsContainer.querySelectorAll('input[type="text"]');
        for (var i = 0; i < inputs.length; i++) {
            if (!inputs[i].value.trim()) {
                alert('Every rider needs a transfer reference before submitting.');
                return;
            }
        }
        // Move the reference/screenshot inputs from the modal into the
        // main batchForm so they submit together with the checked
        // request_ids[] boxes already inside it.
        var toMove = rowsContainer.querySelectorAll('input');
        toMove.forEach(function (input) { batchForm.appendChild(input); });
        batchFormAction.value = 'batch_processing';
        batchForm.submit();
    });
})();
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
