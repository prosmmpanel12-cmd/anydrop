-- ============================================================
-- Anydrop — Migration 52: Customer Support / Ticket System
-- (recall.md item 20; doc 21 §2.3 "Customer Support / Ticket System" +
-- §4.15 "Support Center")
--
-- SCOPE OF THIS MIGRATION/SESSION (admin side only — matches the app
-- owner's own framing: "Support/Ticket system ka admin side"):
--   - support_tickets + support_ticket_messages schema (this file)
--   - lib/support.php — create/reply/status/assignment functions
--   - admin/support.php — Open/In Progress/Resolved/Closed queue +
--     per-ticket conversation thread, gated on the existing
--     support_view/support_manage keys (migration 29 already seeded
--     these — reserved, unused until now, same situation
--     reports_view was in before migration 44's analytics.php)
--
-- NOT built this session (flagged, not forgotten):
--   - Customer/Restaurant/Rider App "Help & Support" screens — no app
--     UI creates a ticket yet; admin/support.php can create one
--     manually (phone/WhatsApp-reported issue logged by staff) but the
--     doc 21 §2.3 self-service flow (Profile → Help & Support → pick a
--     category → describe issue) is separate app-side work, one app
--     at a time, not started here.
--   - Doc 21 §2.8's "Having an issue?" order-screen shortcut that
--     "should directly create a support ticket" — needs the Customer
--     App order-detail screen touched; out of scope for an admin-only
--     migration.
--   - Doc 21 §21's future AI Support Chat layer — explicitly described
--     there as sitting "on top of the normal ticket/support
--     architecture", i.e. depends on this migration but is its own
--     later item.
--   - Push/FCM on new-message — this session uses the existing
--     create_notification() bell-only path (Phase J's FCM push still
--     isn't live for ANY notification type in this codebase, per every
--     prior handover that touches notifications).
--
-- Raiser is polymorphic (raiser_type/raiser_id), matching the existing
-- notifications.recipient_type / order_status_history.changed_by_type
-- convention — doc 21 discusses tickets primarily from the Customer
-- App's Profile screen, but recall.md's own Phase E lists "Rider
-- support" as a separate near-term need (item in the same phase as
-- Support Tickets) and nothing about the admin-side schema should have
-- to change again just because a second app grows a Help screen later.
--
-- Status vs priority, a deliberate correction from doc 21 §4.15's
-- literal "Open / In Progress / Urgent / Resolved" list: "Urgent" is
-- not a state a ticket moves through and back out of the way the other
-- three are — it's an attribute a ticket can carry *while* open or in
-- progress. Modeling it as a fourth status value would make an urgent
-- ticket's actual progress (open vs being worked) invisible in the
-- same field. Split into `status` ENUM('open','in_progress','resolved',
-- 'closed') + a separate `priority` ENUM('normal','urgent') instead —
-- the admin queue can still filter/highlight on priority exactly the
-- way doc 21 pictures, it's just not conflated with the workflow state.
--
-- Safe to re-run — plain CREATE TABLE IF NOT EXISTS, no ALTER on any
-- existing table, so none of the other migrations' CONTINUE-HANDLER
-- machinery is needed here.
-- ============================================================

CREATE TABLE IF NOT EXISTS support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_code VARCHAR(20) UNIQUE NOT NULL,      -- e.g. 'TKT-000123', generated in lib/support.php
    raiser_type ENUM('customer','restaurant','rider') NOT NULL,
    raiser_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,                -- optional — doc 21 §2.3's "Order association"
    category ENUM(
        'order_issue',
        'missing_item',
        'wrong_item',
        'food_quality',
        'delivery_issue',
        'payment_issue',
        'refund_issue',
        'account_issue',
        'coupon_issue',
        'general_issue'
    ) NOT NULL,
    subject VARCHAR(150) NULL,
    status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    priority ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
    assigned_admin_id BIGINT UNSIGNED NULL,       -- doc 21 §4.15's "Assigned to: Support Staff"
    resolved_at TIMESTAMP NULL,
    resolution_note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_ticket_assigned_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins(id),
    INDEX idx_tickets_status_priority (status, priority),
    INDEX idx_tickets_raiser (raiser_type, raiser_id),
    INDEX idx_tickets_assigned (assigned_admin_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The first message (the raiser's own description) is stored as row 1
-- here too, not duplicated into support_tickets — the ticket's
-- "description" IS the first message in the thread, same "one
-- append-only source of truth" spirit as restaurant_due_ledger. A
-- ticket with zero messages should never exist; lib/support.php's
-- create_ticket() writes both rows in a single transaction.
CREATE TABLE IF NOT EXISTS support_ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('customer','restaurant','rider','admin','system') NOT NULL,
    sender_id BIGINT UNSIGNED NULL,               -- NULL for 'system' (auto status-change notes)
    message TEXT NOT NULL,
    attachment_url VARCHAR(255) NULL,              -- single attachment per message, same convention
                                                    -- as restaurant_payments.screenshot_url — one
                                                    -- file per event, not a multi-file gallery
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticketmsg_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    INDEX idx_ticketmsg_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No new admin_permissions rows needed — 'support_view' / 'support_manage'
-- were already seeded by migration 29 and have sat unused ever since
-- (grepped backend/admin and backend/lib: nothing referenced either key
-- before this migration). Confirms both exist rather than assuming:
SELECT `key` FROM admin_permissions WHERE `key` IN ('support_view', 'support_manage');
