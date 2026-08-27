<?php
/**
 * Anydrop — Customer Support / Ticket System (recall.md item 20; doc 21
 * §2.3/§4.15). Migration 52.
 *
 * Same "one function everyone calls" shape as lib/refunds.php and
 * lib/ledger.php — every ticket write (create, reply, status change,
 * assignment) goes through this file so support_tickets and
 * support_ticket_messages can never drift apart the way separate
 * inline INSERT/UPDATEs at each call site would risk.
 *
 * ADMIN-SIDE SCOPE (this session): admin/support.php is the only
 * caller today (a staff member logging a phone/WhatsApp-reported
 * issue manually, or replying to/resolving one). create_ticket() is
 * written generically (any $raiserType) specifically so that whichever
 * app (Customer, Restaurant, or Rider) builds its own "Help & Support"
 * screen first can call straight into this same function later without
 * this file needing to change — see migration 52's header for why
 * nothing app-side exists yet.
 *
 * Status lifecycle: open -> in_progress -> resolved -> closed
 *   (in_progress -> open is also allowed — an admin re-opening a
 *   ticket after receiving a new reply, not a special free-form
 *   transition to any state from any state).
 * `priority` (normal/urgent) is independent of status — see migration
 * 52's header for why "Urgent" isn't a status.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('generate_ticket_code')) {
    /**
     * 'TKT-' + zero-padded id would be simplest, but the id isn't known
     * until after the INSERT — so instead this generates a
     * time+random code up front, matching how orders.order_code and
     * restaurant_payments' settlement filenames are already generated
     * elsewhere in this codebase (never derived from an
     * auto-increment id you don't have yet).
     */
    function generate_ticket_code(): string
    {
        return 'TKT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('create_ticket')) {
    /**
     * Creates a new ticket AND its first message (the raiser's own
     * description) in one transaction — a ticket with zero messages
     * should never exist, per migration 52's own note.
     *
     * @param string $raiserType 'customer' | 'restaurant' | 'rider'
     * @param int    $raiserId   customer_id / restaurant_id / rider_id
     * @param string $category   one of migration 52's category ENUM values
     * @param string $description The raiser's initial message — becomes message #1
     * @param int|null $orderId  optional order association
     * @param string|null $subject optional short subject line
     * @param string|null $attachmentUrl optional photo/screenshot on the first message
     *
     * @return array{ok:bool, ticket_id?:int, ticket_code?:string, error?:string}
     */
    function create_ticket(
        PDO $db,
        string $raiserType,
        int $raiserId,
        string $category,
        string $description,
        ?int $orderId = null,
        ?string $subject = null,
        ?string $attachmentUrl = null
    ): array {
        if (!in_array($raiserType, ['customer', 'restaurant', 'rider'], true)) {
            return ['ok' => false, 'error' => 'invalid_raiser_type'];
        }
        $validCategories = [
            'order_issue', 'missing_item', 'wrong_item', 'food_quality',
            'delivery_issue', 'payment_issue', 'refund_issue',
            'account_issue', 'coupon_issue', 'general_issue',
        ];
        if (!in_array($category, $validCategories, true)) {
            return ['ok' => false, 'error' => 'invalid_category'];
        }
        if (trim($description) === '') {
            return ['ok' => false, 'error' => 'description_required'];
        }

        $ticketCode = generate_ticket_code();

        $db->beginTransaction();
        try {
            $ins = $db->prepare(
                'INSERT INTO support_tickets
                    (ticket_code, raiser_type, raiser_id, order_id, category, subject, status, priority)
                 VALUES
                    (:code, :rtype, :rid, :oid, :cat, :subj, \'open\', \'normal\')'
            );
            $ins->execute([
                'code' => $ticketCode,
                'rtype' => $raiserType,
                'rid' => $raiserId,
                'oid' => $orderId,
                'cat' => $category,
                'subj' => $subject !== null && trim($subject) !== '' ? trim($subject) : null,
            ]);
            $ticketId = (int) $db->lastInsertId();

            $msgIns = $db->prepare(
                'INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, message, attachment_url)
                 VALUES (:tid, :stype, :sid, :msg, :att)'
            );
            $msgIns->execute([
                'tid' => $ticketId,
                'stype' => $raiserType,
                'sid' => $raiserId,
                'msg' => $description,
                'att' => $attachmentUrl,
            ]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('create_ticket failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error'];
        }

        write_audit_log($raiserType, $raiserId, 'support_ticket_created', [
            'ticket_id' => $ticketId, 'ticket_code' => $ticketCode, 'category' => $category,
        ]);

        return ['ok' => true, 'ticket_id' => $ticketId, 'ticket_code' => $ticketCode];
    }
}

if (!function_exists('add_ticket_message')) {
    /**
     * Appends a reply to an existing ticket. Does NOT change status —
     * callers that want "admin replied, so bump from open to
     * in_progress" (admin/support.php's usual flow) call
     * update_ticket_status() separately right after this, so a
     * message-only reply (e.g. a raiser just adding more detail) never
     * silently changes the workflow state as a side effect.
     */
    function add_ticket_message(
        PDO $db,
        int $ticketId,
        string $senderType,
        ?int $senderId,
        string $message,
        ?string $attachmentUrl = null
    ): array {
        if (!in_array($senderType, ['customer', 'restaurant', 'rider', 'admin', 'system'], true)) {
            return ['ok' => false, 'error' => 'invalid_sender_type'];
        }
        if (trim($message) === '') {
            return ['ok' => false, 'error' => 'message_required'];
        }

        $ticketStmt = $db->prepare('SELECT * FROM support_tickets WHERE id = :id LIMIT 1');
        $ticketStmt->execute(['id' => $ticketId]);
        $ticket = $ticketStmt->fetch();
        if (!$ticket) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $ins = $db->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, message, attachment_url)
             VALUES (:tid, :stype, :sid, :msg, :att)'
        );
        $ins->execute([
            'tid' => $ticketId, 'stype' => $senderType, 'sid' => $senderId,
            'msg' => $message, 'att' => $attachmentUrl,
        ]);

        // touch updated_at so the ticket surfaces near the top of a
        // "recently active" admin sort even with no status change
        $db->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = :id')->execute(['id' => $ticketId]);

        if ($senderType === 'admin') {
            create_notification(
                $ticket['raiser_type'], (int) $ticket['raiser_id'],
                'Reply on ticket ' . $ticket['ticket_code'],
                mb_strimwidth($message, 0, 120, '…'),
                'system',
                ['ticket_id' => $ticketId, 'screen' => 'ticket_detail']
            );
        }

        return ['ok' => true];
    }
}

if (!function_exists('update_ticket_status')) {
    /**
     * Allowed transitions only — see this file's header. Setting
     * status to 'resolved' requires $resolutionNote (doc 21 §4.15's
     * "Resolution/closure" requirement — a resolved ticket should
     * always say what resolved it, even briefly); 'closed' does not,
     * since closing typically follows an already-recorded resolution
     * or is a no-response-from-raiser close.
     */
    function update_ticket_status(
        PDO $db,
        int $ticketId,
        int $adminId,
        string $newStatus,
        ?string $resolutionNote = null
    ): array {
        $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }

        $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $allowed = [
            'open' => ['in_progress', 'closed'],
            'in_progress' => ['open', 'resolved', 'closed'],
            'resolved' => ['closed', 'in_progress'], // reopening a wrongly-resolved ticket
            'closed' => [], // terminal — reopen by creating a fresh ticket referencing the old one in a message
        ];
        if (!in_array($newStatus, $allowed[$ticket['status']] ?? [], true)) {
            return ['ok' => false, 'error' => 'invalid_transition'];
        }

        if ($newStatus === 'resolved' && trim((string) $resolutionNote) === '') {
            return ['ok' => false, 'error' => 'resolution_note_required'];
        }

        $upd = $db->prepare(
            'UPDATE support_tickets
                SET status = :status,
                    resolution_note = COALESCE(:note, resolution_note),
                    resolved_at = CASE WHEN :status2 = \'resolved\' THEN NOW() ELSE resolved_at END
             WHERE id = :id'
        );
        $upd->execute([
            'status' => $newStatus,
            'note' => $resolutionNote,
            'status2' => $newStatus,
            'id' => $ticketId,
        ]);

        write_audit_log('admin', $adminId, 'support_ticket_status_changed', [
            'ticket_id' => $ticketId, 'from' => $ticket['status'], 'to' => $newStatus,
        ]);

        $statusLabel = str_replace('_', ' ', $newStatus);
        create_notification(
            $ticket['raiser_type'], (int) $ticket['raiser_id'],
            'Ticket ' . $ticket['ticket_code'] . ' is now ' . $statusLabel,
            $resolutionNote,
            'system',
            ['ticket_id' => $ticketId, 'screen' => 'ticket_detail']
        );

        return ['ok' => true];
    }
}

if (!function_exists('assign_ticket')) {
    /**
     * Doc 21 §4.15's "Assigned to: Support Staff". $adminId = null
     * unassigns. Assignment alone doesn't move status — an admin might
     * assign a still-open ticket to themselves before starting work;
     * the UI's own "Start Work" action calls update_ticket_status()
     * separately.
     */
    function assign_ticket(PDO $db, int $ticketId, int $actingAdminId, ?int $assignToAdminId): array
    {
        $stmt = $db->prepare('SELECT id FROM support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        if (!$stmt->fetch()) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        if ($assignToAdminId !== null) {
            $adminCheck = $db->prepare('SELECT id FROM admins WHERE id = :id AND is_active = 1 LIMIT 1');
            $adminCheck->execute(['id' => $assignToAdminId]);
            if (!$adminCheck->fetch()) {
                return ['ok' => false, 'error' => 'invalid_admin'];
            }
        }

        $db->prepare('UPDATE support_tickets SET assigned_admin_id = :aid WHERE id = :id')
            ->execute(['aid' => $assignToAdminId, 'id' => $ticketId]);

        write_audit_log('admin', $actingAdminId, 'support_ticket_assigned', [
            'ticket_id' => $ticketId, 'assigned_to' => $assignToAdminId,
        ]);

        return ['ok' => true];
    }
}

if (!function_exists('fetch_ticket_with_messages')) {
    /**
     * Single call for admin/support.php's detail view: the ticket row
     * (joined to raiser display name where possible) + its full
     * chronological message thread.
     */
    function fetch_ticket_with_messages(PDO $db, int $ticketId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }

        $raiserName = null;
        if ($ticket['raiser_type'] === 'customer') {
            $r = $db->prepare('SELECT name, email FROM customers WHERE id = :id LIMIT 1');
            $r->execute(['id' => $ticket['raiser_id']]);
            $row = $r->fetch();
            $raiserName = $row ? ($row['name'] ?: $row['email']) : null;
        } elseif ($ticket['raiser_type'] === 'restaurant') {
            $r = $db->prepare('SELECT name FROM restaurants WHERE id = :id LIMIT 1');
            $r->execute(['id' => $ticket['raiser_id']]);
            $row = $r->fetch();
            $raiserName = $row['name'] ?? null;
        } elseif ($ticket['raiser_type'] === 'rider') {
            $r = $db->prepare('SELECT name FROM riders WHERE id = :id LIMIT 1');
            $r->execute(['id' => $ticket['raiser_id']]);
            $row = $r->fetch();
            $raiserName = $row['name'] ?? null;
        }
        $ticket['raiser_name'] = $raiserName;

        $msgStmt = $db->prepare(
            'SELECT * FROM support_ticket_messages WHERE ticket_id = :id ORDER BY created_at ASC, id ASC'
        );
        $msgStmt->execute(['id' => $ticketId]);
        $ticket['messages'] = $msgStmt->fetchAll();

        return $ticket;
    }
}
