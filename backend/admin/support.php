<?php
/**
 * Anydrop — Admin Web UI: Support / Ticket System (recall.md item 20;
 * doc 21 §2.3/§4.15). Migration 52; business logic in lib/support.php.
 *
 * ADMIN SIDE ONLY — see migration 52's header for exactly what's out
 * of scope this session (no Customer/Restaurant/Rider App "Help &
 * Support" screen exists yet, so every ticket today is logged
 * manually by a staff member from a phone/WhatsApp-reported issue via
 * the "Log a Ticket" form below, not self-service). Once any app grows
 * that screen, it calls the same create_ticket() this page already
 * calls — nothing here needs to change for that to slot in.
 *
 * List mode (no ?ticket_id): Open / In Progress / Resolved / Closed
 * tabs (doc 21 §4.15's own admin state list, "Urgent" implemented as a
 * priority filter alongside these rather than a fifth tab — see
 * migration 52's header for why), searchable by ticket code, newest-
 * activity-first (updated_at DESC, so a raiser's new reply resurfaces
 * a ticket even with no status change).
 *
 * Detail mode (?ticket_id=N): full message thread (doc 21 §4.15's
 * "Messages"), reply form (with optional attachment), status actions
 * (Start Work / Resolve / Close / Reopen — only the transitions
 * lib/support.php's update_ticket_status() actually allows are shown),
 * and an Assign-to dropdown (doc 21 §4.15's "Assigned to: Support
 * Staff").
 *
 * Gated on support_view (list + read detail) / support_manage (reply,
 * change status, assign, log a new ticket) — migration 29's existing
 * keys, unused until this session (confirmed by migration 52's own
 * SELECT — nothing in backend/admin or backend/lib referenced either
 * key before this file).
 *
 * STATUS: 🆕 BUILT 2026-08-27 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox). Needs migration 52 run live, then: log
 * a test ticket, reply as admin (confirm the raiser gets a
 * notification bell entry), Start Work -> Resolve (confirm the
 * resolution note is required and gets saved) -> Close, and confirm a
 * role with only support_view (no support_manage) sees the thread but
 * no reply form / action buttons.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/support.php';

$admin = admin_require_login();
admin_require_permission($admin, 'support_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'support_manage');

const MAX_TICKET_ATTACHMENT_BYTES = 5 * 1024 * 1024; // 5 MB — same cap as settlements.php/banners.php
const TICKET_ATTACHMENT_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * Validates and saves an optional message attachment/photo. Mirrors
 * settlements.php's save_settlement_screenshot() exactly (same cap,
 * same real-content MIME sniff via finfo, same "null+no error means
 * nothing was chosen" contract) — the one difference is the target
 * directory.
 */
function save_ticket_attachment(array $file, ?string &$error): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Attachment upload failed.';
        return null;
    }
    if ($file['size'] > MAX_TICKET_ATTACHMENT_BYTES) {
        $error = 'Attachment is too large (max 5 MB).';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(TICKET_ATTACHMENT_MIME[$mime])) {
        $error = 'Unsupported file type — use JPG, PNG, or WEBP.';
        return null;
    }
    $ext = TICKET_ATTACHMENT_MIME[$mime];

    $uploadDir = __DIR__ . '/../uploads/support_attachments';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'ticket_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $error = 'Could not save the uploaded attachment.';
        return null;
    }
    return 'uploads/support_attachments/' . $filename;
}

$csrf = admin_csrf_token();
$flash = null;
$flashType = 'success';
$ticketId = isset($_GET['ticket_id']) ? (int) $_GET['ticket_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (!$canManage) {
        $flash = "You don't have permission to manage support tickets.";
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'create_ticket') {
            $raiserType = trim($_POST['raiser_type'] ?? '');
            $raiserId = (int) ($_POST['raiser_id'] ?? 0);
            $category = trim($_POST['category'] ?? '');
            $subject = trim($_POST['subject'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $orderIdInput = trim($_POST['order_id'] ?? '');
            $orderId = $orderIdInput !== '' ? (int) $orderIdInput : null;

            $result = create_ticket($db, $raiserType, $raiserId, $category, $description, $orderId, $subject ?: null);
            if ($result['ok']) {
                header('Location: support.php?ticket_id=' . $result['ticket_id']);
                exit;
            }
            $flash = 'Could not log ticket: ' . ($result['error'] ?? 'unknown error');
            $flashType = 'error';
        } elseif ($formAction === 'reply') {
            $replyTicketId = (int) ($_POST['ticket_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $attError = null;
            $attachmentUrl = !empty($_FILES['attachment']) ? save_ticket_attachment($_FILES['attachment'], $attError) : null;

            if ($attError) {
                $flash = $attError;
                $flashType = 'error';
            } else {
                $result = add_ticket_message($db, $replyTicketId, 'admin', (int) $admin['id'], $message, $attachmentUrl);
                $flash = $result['ok'] ? 'Reply sent.' : ('Could not send reply: ' . ($result['error'] ?? 'unknown'));
                $flashType = $result['ok'] ? 'success' : 'error';
            }
            $ticketId = $replyTicketId;
        } elseif ($formAction === 'change_status') {
            $statusTicketId = (int) ($_POST['ticket_id'] ?? 0);
            $newStatus = trim($_POST['new_status'] ?? '');
            $resolutionNote = trim($_POST['resolution_note'] ?? '');
            $result = update_ticket_status($db, $statusTicketId, (int) $admin['id'], $newStatus, $resolutionNote ?: null);
            $flash = $result['ok'] ? 'Ticket status updated.' : ('Could not update status: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
            $ticketId = $statusTicketId;
        } elseif ($formAction === 'assign') {
            $assignTicketId = (int) ($_POST['ticket_id'] ?? 0);
            $assignToInput = trim($_POST['assign_to_admin_id'] ?? '');
            $assignTo = $assignToInput !== '' ? (int) $assignToInput : null;
            $result = assign_ticket($db, $assignTicketId, (int) $admin['id'], $assignTo);
            $flash = $result['ok'] ? 'Assignment updated.' : ('Could not assign: ' . ($result['error'] ?? 'unknown'));
            $flashType = $result['ok'] ? 'success' : 'error';
            $ticketId = $assignTicketId;
        }
    }
}

$categoryLabels = [
    'order_issue' => 'Order Issue', 'missing_item' => 'Missing Item', 'wrong_item' => 'Wrong Item',
    'food_quality' => 'Food Quality', 'delivery_issue' => 'Delivery Issue', 'payment_issue' => 'Payment Issue',
    'refund_issue' => 'Refund Issue', 'account_issue' => 'Account Issue', 'coupon_issue' => 'Coupon Issue',
    'general_issue' => 'General Issue',
];
$statusLabels = ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];

// ---------- Detail mode ----------
if ($ticketId) {
    $ticket = fetch_ticket_with_messages($db, $ticketId);
    if (!$ticket) {
        http_response_code(404);
        $pageTitle = 'Ticket not found';
        $activeNav = 'support';
        require __DIR__ . '/_layout_head.php';
        echo '<div class="section"><div class="card"><p class="muted">Ticket not found.</p><a class="btn btn-outline" href="support.php">&larr; All tickets</a></div></div>';
        require __DIR__ . '/_layout_foot.php';
        exit;
    }

    $adminsStmt = $db->query('SELECT id, username, name FROM admins WHERE is_active = 1 ORDER BY username');
    $activeAdmins = $adminsStmt->fetchAll();

    $nextStatusOptions = [
        'open' => ['in_progress' => 'Start Work', 'closed' => 'Close'],
        'in_progress' => ['resolved' => 'Mark Resolved', 'open' => 'Move back to Open', 'closed' => 'Close'],
        'resolved' => ['closed' => 'Close', 'in_progress' => 'Reopen'],
        'closed' => [],
    ];

    $pageTitle = 'Ticket ' . $ticket['ticket_code'];
    $activeNav = 'support';
    require __DIR__ . '/_layout_head.php';
    ?>
    <div class="section">
    <div class="card">
        <a href="support.php" class="btn btn-outline" style="margin-bottom:12px;">&larr; All tickets</a>
        <h2><?= admin_escape($ticket['ticket_code']) ?>
            <span class="badge <?= $ticket['status'] === 'resolved' || $ticket['status'] === 'closed' ? 'active' : 'inactive' ?>"><?= admin_escape($statusLabels[$ticket['status']]) ?></span>
            <?php if ($ticket['priority'] === 'urgent'): ?><span class="badge inactive">Urgent</span><?php endif; ?>
        </h2>
        <p class="muted">
            <?= admin_escape(ucfirst($ticket['raiser_type'])) ?>: <?= admin_escape($ticket['raiser_name'] ?? ('#' . $ticket['raiser_id'])) ?>
            &nbsp;·&nbsp; Category: <?= admin_escape($categoryLabels[$ticket['category']] ?? $ticket['category']) ?>
            <?php if ($ticket['order_id']): ?>&nbsp;·&nbsp; Order #<?= (int) $ticket['order_id'] ?><?php endif; ?>
            &nbsp;·&nbsp; Opened <?= admin_escape($ticket['created_at']) ?>
        </p>
        <?php if ($ticket['subject']): ?><p><strong><?= admin_escape($ticket['subject']) ?></strong></p><?php endif; ?>
    </div>

    <?php if ($canManage): ?>
    <div class="card">
        <h2>Status &amp; Assignment</h2>
        <div class="filter-row">
            <?php foreach ($nextStatusOptions[$ticket['status']] as $toStatus => $label): ?>
            <form method="post" style="display:inline;" <?= $toStatus === 'resolved' ? 'onsubmit="return promptResolutionNote(this);"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                <input type="hidden" name="form_action" value="change_status">
                <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                <input type="hidden" name="new_status" value="<?= admin_escape($toStatus) ?>">
                <?php if ($toStatus === 'resolved'): ?><input type="hidden" name="resolution_note" class="resolution-note-field"><?php endif; ?>
                <button type="submit" class="btn <?= $toStatus === 'closed' ? 'btn-outline' : 'btn-primary' ?>"><?= admin_escape($label) ?></button>
            </form>
            <?php endforeach; ?>
        </div>
        <form method="post" class="filter-row" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="assign">
            <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
            <select name="assign_to_admin_id" onchange="this.form.submit()">
                <option value="">— Unassigned —</option>
                <?php foreach ($activeAdmins as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= (int) $ticket['assigned_admin_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape($a['name'] ?: $a['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($ticket['resolution_note']): ?>
            <p class="muted" style="margin-top:8px;"><strong>Resolution note:</strong> <?= admin_escape($ticket['resolution_note']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>Conversation</h2>
        <div class="table-responsive">
        <?php foreach ($ticket['messages'] as $m): ?>
            <div style="padding:10px 0;border-bottom:1px solid var(--border,#e5e5e5);">
                <p class="muted" style="margin-bottom:4px;">
                    <strong><?= admin_escape(ucfirst($m['sender_type'])) ?></strong> — <?= admin_escape($m['created_at']) ?>
                </p>
                <p style="white-space:pre-wrap;"><?= admin_escape($m['message']) ?></p>
                <?php if (!empty($m['attachment_url'])): ?>
                    <a href="../<?= admin_escape($m['attachment_url']) ?>" target="_blank" rel="noopener">
                        <img src="../<?= admin_escape($m['attachment_url']) ?>" alt="Attachment" style="height:60px;border-radius:4px;">
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <?php if ($canManage && $ticket['status'] !== 'closed'): ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="reply">
            <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
            <label>Reply
                <textarea name="message" rows="3" required></textarea>
            </label>
            <label>Attachment <span class="muted">(optional, JPG/PNG/WEBP, max 5 MB)</span>
                <input type="file" name="attachment" accept="image/jpeg,image/png,image/webp">
            </label>
            <button type="submit" class="btn btn-primary" style="margin-top:8px;">Send Reply</button>
        </form>
        <?php elseif ($ticket['status'] === 'closed'): ?>
            <p class="muted" style="margin-top:12px;">This ticket is closed. Reopen it above to reply.</p>
        <?php endif; ?>
    </div>
    </div>

    <script>
    function promptResolutionNote(form) {
        var note = prompt('Resolution note (required) — briefly, what resolved this ticket:');
        if (!note || !note.trim()) { return false; }
        form.querySelector('.resolution-note-field').value = note.trim();
        return true;
    }
    </script>
    <?php
    require __DIR__ . '/_layout_foot.php';
    exit;
}

// ---------- List mode ----------
$statusFilter = $_GET['status'] ?? 'open';
if (!in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed', 'all'], true)) {
    $statusFilter = 'open';
}
$priorityFilter = $_GET['priority'] ?? '';
$q = trim($_GET['q'] ?? '');

$sql = 'SELECT t.*,
            (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS message_count
        FROM support_tickets t WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') {
    $sql .= ' AND t.status = :status';
    $params['status'] = $statusFilter;
}
if ($priorityFilter === 'urgent') {
    $sql .= " AND t.priority = 'urgent'";
}
if ($q !== '') {
    $sql .= ' AND t.ticket_code LIKE :q';
    $params['q'] = "%$q%";
}
$sql .= ' ORDER BY t.updated_at DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$pageTitle = 'Support Tickets';
$activeNav = 'support';
require __DIR__ . '/_layout_head.php';
?>
<div class="section">
<div class="card">
    <h2>Support Tickets</h2>
    <p class="muted">Doc 21 §2.3/§4.15. No app self-service flow exists yet — tickets here are logged by staff from a phone/WhatsApp-reported issue until a Help &amp; Support screen ships in an app.</p>
    <div class="filter-row">
        <?php foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'] as $key => $label): ?>
            <a class="btn <?= $statusFilter === $key ? 'btn-primary' : 'btn-outline' ?>" href="support.php?status=<?= $key ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="filter-row" style="margin-top:8px;">
        <input type="hidden" name="status" value="<?= admin_escape($statusFilter) ?>">
        <input type="text" name="q" placeholder="Search ticket code" value="<?= admin_escape($q) ?>">
        <select name="priority" onchange="this.form.submit()">
            <option value="">Any priority</option>
            <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent only</option>
        </select>
        <button type="submit" class="btn btn-outline">Search</button>
    </form>
</div>

<div class="card">
    <?php if (empty($tickets)): ?>
        <p class="muted">No tickets in this view.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Ticket</th><th>Raiser</th><th>Category</th><th>Priority</th><th>Status</th><th>Messages</th><th>Updated</th><th></th></tr>
        <?php foreach ($tickets as $t): ?>
        <tr>
            <td><?= admin_escape($t['ticket_code']) ?></td>
            <td><?= admin_escape(ucfirst($t['raiser_type'])) ?> #<?= (int) $t['raiser_id'] ?></td>
            <td><?= admin_escape($categoryLabels[$t['category']] ?? $t['category']) ?></td>
            <td><?= $t['priority'] === 'urgent' ? '<span class="badge inactive">Urgent</span>' : 'Normal' ?></td>
            <td><span class="badge <?= in_array($t['status'], ['resolved', 'closed'], true) ? 'active' : 'inactive' ?>"><?= admin_escape($statusLabels[$t['status']]) ?></span></td>
            <td><?= (int) $t['message_count'] ?></td>
            <td><?= admin_escape($t['updated_at']) ?></td>
            <td><a class="btn btn-outline" href="support.php?ticket_id=<?= (int) $t['id'] ?>">Open</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="card">
    <h2>Log a Ticket</h2>
    <p class="muted">For an issue reported by phone, WhatsApp, or in person — not from an in-app self-service flow (none exists yet).</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="create_ticket">
        <div class="form-grid">
            <label>Raiser Type
                <select name="raiser_type" required>
                    <option value="customer">Customer</option>
                    <option value="restaurant">Restaurant</option>
                    <option value="rider">Rider</option>
                </select>
            </label>
            <label>Raiser ID
                <input type="number" name="raiser_id" min="1" required>
            </label>
            <label>Order ID <span class="muted">(optional)</span>
                <input type="number" name="order_id" min="1">
            </label>
            <label>Category
                <select name="category" required>
                    <?php foreach ($categoryLabels as $key => $label): ?>
                        <option value="<?= admin_escape($key) ?>"><?= admin_escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Subject <span class="muted">(optional)</span>
                <input type="text" name="subject" maxlength="150">
            </label>
        </div>
        <label>Description
            <textarea name="description" rows="3" required></textarea>
        </label>
        <button type="submit" class="btn btn-primary" style="margin-top:8px;">Log Ticket</button>
    </form>
</div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
