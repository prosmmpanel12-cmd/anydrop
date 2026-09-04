<?php
/**
 * Anydrop — Admin Web UI: Payment / Refund / Settlement Reconciliation
 * (PENDING.md item 24, recall.md section 28, doc 21 §5.6/§5.7,
 * migration 66, lib/reconciliation.php).
 *
 * This page does not move money and does not correct anything by
 * itself — it runs (or shows the last run of) lib/reconciliation.php's
 * read-only checks against orders/payment_transactions/refunds/
 * wallet_transactions/*_ledger, and lets an admin Resolve (fixed
 * outside this system, e.g. manually corrected a DB row or confirmed
 * it was already fine) or Ignore (reviewed, not a real problem —
 * e.g. a known historical gap that predates a fix) each flag. Every
 * resolve/ignore requires a note and is audit-logged
 * (write_audit_log, via lib/reconciliation.php).
 *
 * Gated on `reconciliation_view` (list) / `reconciliation_manage`
 * (run scan + resolve/ignore) — migration 66's new permission pair.
 *
 * STATUS: 🆕 BUILT 2026-08-30 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page here (no PHP
 * CLI/live DB). Needs migration 66 run live, then: a scan with a
 * genuinely clean DB (expect zero or near-zero flags — the platform
 * balance check only fires past ₹0.50 drift), then a scan after
 * deliberately breaking one invariant by hand (e.g. delete a
 * platform_ledger refund_out row for a refunded order) to confirm the
 * matching check actually fires, resolve/ignore both round-trip
 * correctly, and a since-fixed flag disappearing from "open" on the
 * next scan while a still-broken one persists.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/reconciliation.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'reconciliation_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'reconciliation_manage');

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'reconciliation_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'scan') {
            $found = run_reconciliation_scan($db);
            $stats = persist_reconciliation_flags($db, $found);
            write_audit_log('admin', $admin['id'], 'reconciliation_scan_run', [
                'checked' => count($found),
                'new' => $stats['new'],
                'reopened' => $stats['reopened'],
                'total_open' => $stats['total_open'],
            ]);
            $flash = 'Scan complete — ' . $stats['new'] . ' new, ' . $stats['reopened'] . ' reopened, '
                . $stats['total_open'] . ' currently open.';
        } elseif ($formAction === 'resolve') {
            $flagId = (int) ($_POST['flag_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($note === '') {
                $flash = 'A note is required to resolve a flag.';
                $flashType = 'error';
            } else {
                $ok = resolve_reconciliation_flag($db, $flagId, (int) $admin['id'], $note);
                $flash = $ok ? 'Flag marked resolved.' : 'Could not resolve (it may have already been actioned).';
                $flashType = $ok ? 'success' : 'error';
            }
        } elseif ($formAction === 'ignore') {
            $flagId = (int) ($_POST['flag_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($note === '') {
                $flash = 'A note is required to ignore a flag.';
                $flashType = 'error';
            } else {
                $ok = ignore_reconciliation_flag($db, $flagId, (int) $admin['id'], $note);
                $flash = $ok ? 'Flag ignored — will not resurface unless the underlying condition changes.' : 'Could not ignore (it may have already been actioned).';
                $flashType = $ok ? 'success' : 'error';
            }
        }
    }
}

// ---------- Filters ----------
$statusFilter = in_array($_GET['status'] ?? 'open', ['open', 'resolved', 'ignored', 'all'], true) ? $_GET['status'] : 'open';
$typeFilter = trim($_GET['flag_type'] ?? '');

$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'f.status = :status';
    $params['status'] = $statusFilter;
}
if ($typeFilter !== '') {
    $where[] = 'flag_type = :ftype';
    $params['ftype'] = $typeFilter;
}
$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

$stmt = $db->prepare(
    "SELECT f.*, r.name AS restaurant_name, o.order_code
     FROM reconciliation_flags f
     LEFT JOIN restaurants r ON r.id = f.restaurant_id
     LEFT JOIN orders o ON o.id = f.order_id
     $whereSql
     ORDER BY FIELD(f.severity, 'critical', 'warning', 'info'), f.last_seen_at DESC
     LIMIT 300"
);
$stmt->execute($params);
$flags = $stmt->fetchAll();

$typeOptions = $db->query('SELECT DISTINCT flag_type FROM reconciliation_flags ORDER BY flag_type')->fetchAll(PDO::FETCH_COLUMN);

$countsStmt = $db->query(
    "SELECT
        SUM(CASE WHEN status = 'open' AND severity = 'critical' THEN 1 ELSE 0 END) AS open_critical,
        SUM(CASE WHEN status = 'open' AND severity = 'warning' THEN 1 ELSE 0 END) AS open_warning,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_total
     FROM reconciliation_flags"
);
$counts = $countsStmt->fetch();

$lastScanStmt = $db->query("SELECT details_json, created_at FROM audit_logs WHERE action = 'reconciliation_scan_run' ORDER BY id DESC LIMIT 1");
$lastScan = $lastScanStmt->fetch();

$csrf = admin_csrf_token();
$pageTitle = 'Reconciliation';
$activeNav = 'reconciliation';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Payment / Refund / Settlement Reconciliation</h2>
    <p class="muted">
        Read-only checks across orders, payment transactions, refunds,
        wallet transactions, and both ledgers — flags anything that
        doesn't add up (a paid order with no matching transaction, a
        refund with no ledger entry, a wallet balance that doesn't
        match its own history, and so on). This page never changes any
        financial row itself; resolving or ignoring a flag here only
        updates the flag's own status, and always needs a note.
    </p>
    <?php if ($canManage): ?>
    <form method="post" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="scan">
        <button type="submit" class="btn btn-primary">Run Reconciliation Scan</button>
    </form>
    <?php endif; ?>
    <?php if ($lastScan): ?>
        <?php $lastScanDetails = json_decode($lastScan['details_json'] ?? '{}', true) ?: []; ?>
        <p class="muted">Last run: <?= admin_escape($lastScan['created_at']) ?> —
            <?= (int) ($lastScanDetails['new'] ?? 0) ?> new,
            <?= (int) ($lastScanDetails['reopened'] ?? 0) ?> reopened,
            <?= (int) ($lastScanDetails['total_open'] ?? 0) ?> total open.</p>
    <?php else: ?>
        <p class="muted">No scan has been run yet<?= $canManage ? ' — click "Run Reconciliation Scan" above to check now.' : '.' ?></p>
    <?php endif; ?>
    <?php if ($flash): ?><p class="flash <?= $flashType ?>"><?= admin_escape($flash) ?></p><?php endif; ?>
</div>

<div class="grid">
    <div class="card stat"><div class="value" style="color:#c0392b;"><?= (int) ($counts['open_critical'] ?? 0) ?></div><div class="label">Open — Critical</div></div>
    <div class="card stat"><div class="value" style="color:#b8860b;"><?= (int) ($counts['open_warning'] ?? 0) ?></div><div class="label">Open — Warning</div></div>
    <div class="card stat"><div class="value"><?= (int) ($counts['open_total'] ?? 0) ?></div><div class="label">Total Open</div></div>
</div>

<div class="card">
    <form method="get" class="filter-row">
        <label>Status
            <select name="status">
                <?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type
            <select name="flag_type">
                <option value="">— All types —</option>
                <?php foreach ($typeOptions as $t): ?>
                    <option value="<?= admin_escape($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= admin_escape(str_replace('_', ' ', $t)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-outline">Filter</button>
        <a href="reconciliation.php" class="btn btn-outline">Clear</a>
    </form>
</div>

<div class="card">
    <h2>Flags</h2>
    <?php if (empty($flags)): ?>
        <p class="muted"><?= $statusFilter === 'open' ? 'No open flags right now.' : 'Nothing matches this filter.' ?></p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Severity</th><th>Type</th><th>Order</th><th>Restaurant</th><th>Description</th><th>Expected</th><th>Actual</th><th>Status</th><th>Last seen</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
        <?php foreach ($flags as $f): ?>
        <tr>
            <td><span class="badge <?= $f['severity'] === 'critical' ? 'inactive' : 'active' ?>"><?= admin_escape(ucfirst($f['severity'])) ?></span></td>
            <td><?= admin_escape(str_replace('_', ' ', $f['flag_type'])) ?></td>
            <td><?= $f['order_code'] ? admin_escape($f['order_code']) : '—' ?></td>
            <td><?= $f['restaurant_name'] ? admin_escape($f['restaurant_name']) : '—' ?></td>
            <td class="muted"><?= admin_escape($f['description']) ?></td>
            <td><?= admin_escape($f['expected_value'] ?? '—') ?></td>
            <td><?= admin_escape($f['actual_value'] ?? '—') ?></td>
            <td><?= admin_escape(ucfirst($f['status'])) ?>
                <?php if ($f['status'] !== 'open' && $f['resolution_note']): ?>
                    <div class="muted" style="font-size:0.85em;"><?= admin_escape($f['resolution_note']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= admin_escape($f['last_seen_at']) ?></td>
            <?php if ($canManage): ?>
            <td class="row-actions">
                <?php if ($f['status'] === 'open'): ?>
                <form method="post" style="display:inline;" onsubmit="return promptNote(this, 'resolve');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="resolve">
                    <input type="hidden" name="flag_id" value="<?= (int) $f['id'] ?>">
                    <input type="hidden" name="note" class="note-field">
                    <button type="submit" class="btn btn-primary">Resolve</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return promptNote(this, 'ignore');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="ignore">
                    <input type="hidden" name="flag_id" value="<?= (int) $f['id'] ?>">
                    <input type="hidden" name="note" class="note-field">
                    <button type="submit" class="btn btn-outline">Ignore</button>
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
function promptNote(form, action) {
    var note = prompt(action === 'resolve'
        ? 'How was this resolved? (required)'
        : 'Why is this safe to ignore? (required)');
    if (!note || !note.trim()) { return false; }
    form.querySelector('.note-field').value = note.trim();
    return true;
}
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
