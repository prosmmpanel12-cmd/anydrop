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
 * PARTIALLY WIRED (updated 2026-08-26, docs/43 — this note was stale):
 * the online/UPI half now IS live — record_paid_order_ledger_entries()
 * fires automatically the moment a UPI order's payment confirms (see
 * lib/ledger.php's own kdoc), so payout_payable entries for online
 * orders already appear here in real time. The COD half is still the
 * one real gap: record_cod_order_ledger_entry() is never called,
 * because no 'delivered' transition exists anywhere yet (no rider-
 * facing API at all — Phase G, recall.md items 43-48, not built). So
 * today, a restaurant's ledger here shows online-order payouts +
 * whatever Pay Now settlements an admin manually records, but NOT COD
 * commission — that piece stays at ₹0 until the Rider App ships.
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
require_once __DIR__ . '/../lib/settings.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');
$canEdit = admin_has_permission($admin['id'], 'payouts_manage');
$db = Database::get();

const MAX_SETTLEMENT_SCREENSHOT_BYTES = 5 * 1024 * 1024; // 5 MB — same cap as banners.php
const SETTLEMENT_SCREENSHOT_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * Validates and saves an optional Pay Now settlement screenshot/receipt.
 * Mirrors backend/admin/banners.php's save_banner_image() checks (size
 * cap, real-content MIME sniff via finfo, never trust the extension) —
 * no crop support here, a settlement proof should be saved as-is.
 *
 * Returns the stored relative path (screenshot_url), or null with
 * $error set if something's wrong. Returns null with $error left null
 * when no file was actually chosen — screenshot is optional, this is
 * not itself a failure.
 */
function save_settlement_screenshot(array $file, ?string &$error): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Screenshot upload failed.';
        return null;
    }
    if ($file['size'] > MAX_SETTLEMENT_SCREENSHOT_BYTES) {
        $error = 'Screenshot is too large (max 5 MB).';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(SETTLEMENT_SCREENSHOT_MIME[$mime])) {
        $error = 'Unsupported file type — use JPG, PNG, or WEBP.';
        return null;
    }
    $ext = SETTLEMENT_SCREENSHOT_MIME[$mime];

    $uploadDir = __DIR__ . '/../uploads/settlement_screenshots';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'settlement_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $error = 'Could not save the uploaded screenshot.';
        return null;
    }
    return 'uploads/settlement_screenshots/' . $filename;
}

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
                // migration 59 — an admin typing these in directly is
                // already supervised entry (unlike a restaurant
                // self-submitting via bank-details-save.php, which
                // always resets to 'pending'), so this path saves
                // straight as 'verified' and stamps who/when, same
                // shape restaurant_payments.settled_by_admin_id uses
                // for the equivalent "who actioned this" need.
                $db->prepare(
                    'INSERT INTO restaurant_bank_details (restaurant_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id, verification_status, admin_remarks, verified_by_admin_id, verified_at)
                     VALUES (:rid, :holder, :bank, :acc, :ifsc, :upi, \'verified\', NULL, :admin_id, NOW())
                     ON DUPLICATE KEY UPDATE account_holder_name = :holder2, bank_name = :bank2, account_number = :acc2, ifsc_code = :ifsc2, upi_id = :upi2,
                        verification_status = \'verified\', admin_remarks = NULL, verified_by_admin_id = :admin_id2, verified_at = NOW()'
                )->execute([
                    'rid' => $postRestaurantId, 'holder' => $holder, 'bank' => $bank, 'acc' => $accNum, 'ifsc' => $ifsc, 'upi' => $upi !== '' ? $upi : null,
                    'admin_id' => $admin['id'],
                    'holder2' => $holder, 'bank2' => $bank, 'acc2' => $accNum, 'ifsc2' => $ifsc, 'upi2' => $upi !== '' ? $upi : null,
                    'admin_id2' => $admin['id'],
                ]);
                write_audit_log('admin', $admin['id'], 'restaurant_bank_details_saved', ['restaurant_id' => $postRestaurantId]);
                $flash = 'Bank details saved.';
            }
        } elseif ($formAction === 'verify_bank_details' || $formAction === 'reject_bank_details') {
            // migration 59 — the review action for a restaurant's own
            // self-submission (bank-details-save.php, always starts
            // 'pending'). Distinct from save_bank_details above,
            // which is the admin *entering values on the restaurant's
            // behalf* and never needs a separate review step.
            $newStatus = $formAction === 'verify_bank_details' ? 'verified' : 'rejected';
            $remarks = trim((string) ($_POST['admin_remarks'] ?? ''));
            if ($newStatus === 'rejected' && $remarks === '') {
                $flash = 'A remark is required when rejecting bank details, so the restaurant knows what to fix.';
                $flashType = 'error';
            } else {
                $updated = $db->prepare(
                    'UPDATE restaurant_bank_details
                     SET verification_status = :status, admin_remarks = :remarks, verified_by_admin_id = :admin_id, verified_at = NOW()
                     WHERE restaurant_id = :rid'
                );
                $updated->execute([
                    'status' => $newStatus,
                    'remarks' => $remarks !== '' ? $remarks : null,
                    'admin_id' => $admin['id'],
                    'rid' => $postRestaurantId,
                ]);
                write_audit_log('admin', $admin['id'], 'restaurant_bank_details_' . $newStatus, [
                    'restaurant_id' => $postRestaurantId,
                    'admin_remarks' => $remarks !== '' ? $remarks : null,
                ]);
                $flash = $newStatus === 'verified' ? 'Bank details verified.' : 'Bank details rejected.';
            }
        } elseif ($formAction === 'pay_now') {
            $direction = $_POST['direction'] ?? '';
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $utr = trim((string) ($_POST['utr_number'] ?? '')) ?: null;
            $remarks = trim((string) ($_POST['remarks'] ?? '')) ?: null;
            $paymentDate = trim((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d');

            $screenshotError = null;
            $screenshotUrl = isset($_FILES['screenshot'])
                ? save_settlement_screenshot($_FILES['screenshot'], $screenshotError)
                : null;

            if (!is_numeric($amount) || (float) $amount <= 0) {
                $flash = 'Enter a valid settlement amount.';
                $flashType = 'error';
            } elseif (!in_array($direction, ['admin_to_restaurant', 'restaurant_to_admin'], true)) {
                $flash = 'Invalid settlement direction.';
                $flashType = 'error';
            } elseif ($screenshotError !== null) {
                // A bad screenshot (too large / wrong type) blocks the whole
                // settlement rather than silently recording it without proof
                // — same "don't silently drop what the admin thought they
                // attached" reasoning as any other required-evidence upload.
                $flash = $screenshotError;
                $flashType = 'error';
            } else {
                try {
                    record_settlement(
                        $db, $postRestaurantId, $direction, (float) $amount, (int) $admin['id'],
                        $utr, $screenshotUrl, $remarks, $paymentDate
                    );
                    write_audit_log('admin', $admin['id'], 'settlement_recorded', [
                        'restaurant_id' => $postRestaurantId, 'direction' => $direction, 'amount' => $amount,
                        'screenshot' => $screenshotUrl,
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

    // ---------- Payout Analytics (doc 19 §6's Admin flow breakdown) ----------
    // Today / This Week / This Month, same rolling-window convention as
    // analytics.php (today / -6 days / -29 days) so the two ranged admin
    // screens behave consistently.
    $payoutRange = $_GET['payout_range'] ?? 'today';
    if (!in_array($payoutRange, ['today', 'week', 'month'], true)) {
        $payoutRange = 'today';
    }
    $payoutToday = date('Y-m-d');
    if ($payoutRange === 'week') {
        $payoutFromDate = date('Y-m-d', strtotime('-6 days'));
    } elseif ($payoutRange === 'month') {
        $payoutFromDate = date('Y-m-d', strtotime('-29 days'));
    } else {
        $payoutFromDate = $payoutToday;
    }
    $payoutFromDateTime = $payoutFromDate . ' 00:00:00';
    $payoutToDateTime = $payoutToday . ' 23:59:59';
    $payoutNonRevenueStatuses = "'cancelled','rejected','failed','expired'";

    $payoutOrdersStmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM orders WHERE restaurant_id = :id AND created_at BETWEEN :f AND :t'
    );
    $payoutOrdersStmt->execute(['id' => $restaurantId, 'f' => $payoutFromDateTime, 't' => $payoutToDateTime]);
    $payoutTotalOrders = (int) $payoutOrdersStmt->fetch()['c'];

    $payoutMoneyStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN payment_method = 'cod' THEN grand_total ELSE 0 END), 0) AS cash_collected,
            COALESCE(SUM(CASE WHEN payment_method = 'upi' THEN grand_total ELSE 0 END), 0) AS online_collected,
            COALESCE(SUM(CASE WHEN payment_method = 'upi' THEN platform_fee ELSE 0 END), 0) AS online_platform_fee,
            COALESCE(SUM(commission_amount), 0) AS commission
         FROM orders
         WHERE restaurant_id = :id AND created_at BETWEEN :f AND :t AND status NOT IN ($payoutNonRevenueStatuses)"
    );
    $payoutMoneyStmt->execute(['id' => $restaurantId, 'f' => $payoutFromDateTime, 't' => $payoutToDateTime]);
    $payoutMoney = $payoutMoneyStmt->fetch();

    $payoutCashCollected = (float) $payoutMoney['cash_collected'];
    $payoutOnlineCollected = (float) $payoutMoney['online_collected'];
    $payoutOnlinePlatformFee = (float) $payoutMoney['online_platform_fee'];
    $payoutCommission = (float) $payoutMoney['commission'];
    $gstPercent = (float) get_setting('gst_percent', 18);
    $payoutGst = round($payoutCommission * $gstPercent / 100, 2);
    // Net Payable = what admin owes the restaurant for online orders in this
    // range: money collected on the restaurant's behalf, minus commission,
    // minus GST charged on that commission, minus the platform fee (which
    // was always admin's share, not the restaurant's, even though it rode
    // in on the same collected grand_total).
    $payoutNetPayable = $payoutOnlineCollected - $payoutCommission - $payoutGst - $payoutOnlinePlatformFee;

    $payoutPaidStmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS c FROM restaurant_payments
         WHERE restaurant_id = :id AND status = 'verified'
           AND COALESCE(payment_date, DATE(created_at)) BETWEEN :f AND :t"
    );
    $payoutPaidStmt->execute(['id' => $restaurantId, 'f' => $payoutFromDate, 't' => $payoutToday]);
    $payoutAlreadyPaid = (float) $payoutPaidStmt->fetch()['c'];

    // ---------- Export CSV (PENDING.md item 17's remaining gap) ----------
    // Same reports_export permission + Content-Disposition/fputcsv pattern
    // analytics.php established (doc 50) — this page reuses it rather than
    // inventing a second export convention. Gated separately from
    // payouts_view (view alone isn't enough), same "view vs export" split
    // migration 29 already defines and analytics.php already uses.
    $canExportSettlement = admin_has_permission((int) $admin['id'], 'reports_export');
    if ($canExportSettlement && ($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="anydrop_settlement_' . $restaurantId . '_' . $payoutFromDate . '_to_' . $payoutToday . '.csv"');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['Anydrop Settlement Export — ' . $restaurant['name']]);
        fputcsv($out, ['Payout range', $payoutFromDate . ' to ' . $payoutToday]);
        fputcsv($out, []);

        fputcsv($out, ['Payout Analytics']);
        fputcsv($out, ['Total Orders', 'Cash Collected (COD)', 'Online Collected (UPI)', 'Commission', 'GST', 'Net Payable (online orders)', 'Already Paid (in range)', 'Pending (live)']);
        fputcsv($out, [
            $payoutTotalOrders, $payoutCashCollected, $payoutOnlineCollected, $payoutCommission,
            $payoutGst, $payoutNetPayable, $payoutAlreadyPaid, $currentDue,
        ]);
        fputcsv($out, []);

        fputcsv($out, ['Ledger Statement (most recent 200 entries)']);
        fputcsv($out, ['Date', 'Type', 'Order', 'Amount', 'Running Balance', 'By', 'Note']);
        foreach ($ledgerRows as $row) {
            fputcsv($out, [
                $row['created_at'], str_replace('_', ' ', $row['entry_type']),
                $row['order_id'] ? '#' . (int) $row['order_id'] : '',
                (float) $row['amount'], (float) $row['running_balance'], $row['created_by'], $row['note'] ?? '',
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Settlement History (most recent 50)']);
        fputcsv($out, ['Date', 'Direction', 'Amount', 'UTR', 'Remarks']);
        foreach ($payments as $p) {
            fputcsv($out, [
                $p['payment_date'] ?? $p['created_at'],
                $p['direction'] === 'admin_to_restaurant' ? 'Admin to Restaurant' : 'Restaurant to Admin',
                (float) $p['amount'], $p['utr_number'] ?? '', $p['remarks'] ?? '',
            ]);
        }

        write_audit_log('admin', $admin['id'], 'settlement_exported', [
            'restaurant_id' => $restaurantId, 'payout_range' => $payoutRange,
            'from' => $payoutFromDate, 'to' => $payoutToday,
        ]);

        fclose($out);
        exit;
    }

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
        <h2>Payout Analytics</h2>
        <form method="get" class="filter-row">
            <input type="hidden" name="restaurant_id" value="<?= (int) $restaurantId ?>">
            <select name="payout_range" onchange="this.form.submit()">
                <option value="today" <?= $payoutRange === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="week" <?= $payoutRange === 'week' ? 'selected' : '' ?>>This Week</option>
                <option value="month" <?= $payoutRange === 'month' ? 'selected' : '' ?>>This Month</option>
            </select>
            <?php if ($canExportSettlement): ?>
                <a class="btn btn-outline" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">Export CSV</a>
            <?php endif; ?>
        </form>
        <p class="muted" style="margin-top:8px;">Showing <?= admin_escape($payoutFromDate) ?> to <?= admin_escape($payoutToday) ?>. GST is calculated on commission at the platform's configured rate (<?= admin_escape(number_format($gstPercent, 2)) ?>%). Pending is the live running balance, not scoped to this range.</p>
        <div class="grid" style="margin-top:12px;">
            <div class="card stat"><div class="value"><?= $payoutTotalOrders ?></div><div class="label">Total Orders</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format($payoutCashCollected, 2)) ?></div><div class="label">Cash Collected (COD)</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format($payoutOnlineCollected, 2)) ?></div><div class="label">Online Collected (UPI)</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format($payoutCommission, 2)) ?></div><div class="label">Commission</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format($payoutGst, 2)) ?></div><div class="label">GST (<?= admin_escape(number_format($gstPercent, 2)) ?>% on commission)</div></div>
            <div class="card stat"><div class="value" style="color:<?= $payoutNetPayable >= 0 ? '#1b8a3c' : '#c0392b' ?>;">₹<?= admin_escape(number_format($payoutNetPayable, 2)) ?></div><div class="label">Net Payable (online orders)</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format($payoutAlreadyPaid, 2)) ?></div><div class="label">Already Paid (in range)</div></div>
            <div class="card stat"><div class="value">₹<?= admin_escape(number_format(abs($currentDue), 2)) ?></div><div class="label"><?= $currentDue >= 0 ? 'Pending (restaurant owes)' : 'Pending (admin owes)' ?></div></div>
        </div>
    </div>

    <div class="card">
        <h2>Bank Details</h2>
        <?php if ($bank):
            // migration 59 — status badge shown above both the
            // editable form and the read-only fallback below, so an
            // admin without $canEdit still sees it.
            $statusLabel = ['pending' => 'Pending Review', 'verified' => 'Verified', 'rejected' => 'Rejected'][$bank['verification_status'] ?? 'verified'] ?? 'Verified';
            $statusColor = ['pending' => '#b8860b', 'verified' => '#1b8a3c', 'rejected' => '#c0392b'][$bank['verification_status'] ?? 'verified'] ?? '#1b8a3c';
        ?>
        <p><strong style="color:<?= $statusColor ?>;">● <?= admin_escape($statusLabel) ?></strong>
            <?php if (!empty($bank['admin_remarks'])): ?> — <span class="muted"><?= admin_escape($bank['admin_remarks']) ?></span><?php endif; ?>
        </p>
        <?php if ($canEdit && ($bank['verification_status'] ?? 'verified') === 'pending'): ?>
        <form method="post" style="margin-bottom:16px;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="restaurant_id" value="<?= (int) $restaurantId ?>">
            <label>Remarks <span class="muted">(required to reject, optional to verify)</span>
                <input type="text" name="admin_remarks">
            </label>
            <button type="submit" name="form_action" value="verify_bank_details" class="btn btn-primary">Verify</button>
            <button type="submit" name="form_action" value="reject_bank_details" class="btn btn-outline">Reject</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
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
        <form method="post" enctype="multipart/form-data">
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
                <label>Payment Screenshot <span class="muted">(optional, JPG/PNG/WEBP, max 5 MB)</span>
                    <input type="file" name="screenshot" accept="image/jpeg,image/png,image/webp">
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
            <tr><th>Date</th><th>Direction</th><th>Amount</th><th>UTR</th><th>Remarks</th><th>Screenshot</th></tr>
            <?php foreach ($payments as $p): ?>
            <tr>
                <td><?= admin_escape($p['payment_date'] ?? $p['created_at']) ?></td>
                <td><?= $p['direction'] === 'admin_to_restaurant' ? 'Admin → Restaurant' : 'Restaurant → Admin' ?></td>
                <td>₹<?= admin_escape(number_format((float) $p['amount'], 2)) ?></td>
                <td><?= admin_escape($p['utr_number'] ?? '—') ?></td>
                <td class="muted"><?= admin_escape($p['remarks'] ?? '') ?></td>
                <td>
                    <?php if (!empty($p['screenshot_url'])): ?>
                        <a href="../<?= admin_escape($p['screenshot_url']) ?>" target="_blank" rel="noopener">
                            <img src="../<?= admin_escape($p['screenshot_url']) ?>" alt="Settlement screenshot" style="height:40px;border-radius:4px;vertical-align:middle;">
                        </a>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
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
