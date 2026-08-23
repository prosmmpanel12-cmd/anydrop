-- ============================================================
-- Anydrop — Migration 42: Refund System
-- (recall.md Phase C item 25, section 19; doc 21 §2.2/§5.7)
--
-- Scope decision made explicit here because nothing upstream pins it
-- down: this is a FULL-refund-only model, one refund row per order
-- (UNIQUE(order_id) below). Doc 21 §5.7's diagram and recall.md
-- section 19 both describe a single amount/timeline per refund, and
-- doc 23 addendum §A5 already established "this design doesn't model
-- partial payments" for the payment side — carrying that same
-- constraint to refunds keeps the two halves symmetric. Splitting one
-- order into multiple partial refunds would need UNIQUE(order_id)
-- dropped and a running-total check added; flagged here for whoever
-- does that later rather than half-built now.
--
-- No real gateway exists (docs/23_..., UpipeProvider::refund() already
-- returns 'manual_refund_required' — see that file's own kdoc), and
-- the Customer Wallet (recall.md item 26) isn't built yet either — so
-- v1's only real `method` is a manual bank/UPI transfer the admin
-- performs OUTSIDE Anydrop and then records here (UTR/reference),
-- same "human does the money movement, this system tracks it" shape
-- payment-pending.php and settlements.php's Pay Now flow already use.
-- `wallet` is reserved in the ENUM now so a future wallet build
-- doesn't need another migration to add it, but nothing writes it yet.
-- ============================================================

CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    payment_transaction_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    initiated_by ENUM('customer','restaurant','admin','system') NOT NULL,
    -- Lifecycle per recall.md section 19 / doc 21 §2.2:
    --   requested -> under_review -> approved -> processing -> refunded
    -- with `rejected` as the off-ramp from under_review or approved
    -- (an admin deciding no refund is warranted). No separate ENUM
    -- value needed for "cancelled" — a refund request only exists
    -- because an order already left paid money uncollected-for, so
    -- withdrawing it isn't a real customer-facing action in v1.
    status ENUM('requested','under_review','approved','processing','refunded','rejected') NOT NULL DEFAULT 'requested',
    method ENUM('manual_upi_bank_transfer','wallet') NOT NULL DEFAULT 'manual_upi_bank_transfer',
    refund_reference VARCHAR(100) NULL, -- UTR of the OUTGOING refund transfer the admin actually sent, distinct from the original payment's UTR
    reject_reason VARCHAR(255) NULL,
    expected_by_date DATE NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    processing_at TIMESTAMP NULL,
    refunded_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    admin_id BIGINT UNSIGNED NULL, -- last admin to act on this row (approve/processing/refunded/rejected)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_refund_txn FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id),
    CONSTRAINT fk_refund_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_refund_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    UNIQUE KEY uq_refund_order (order_id),
    INDEX idx_refund_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-configurable "expected by" window (recall.md rule 34 — never
-- hardcode a business rule like this). Used to compute
-- refunds.expected_by_date at request-creation time; admin can still
-- hand-edit an individual row's date later if a specific case needs it.
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('refund_expected_days', '5');

-- ---------- RBAC: new permission keys (same pattern migration 29 used) ----------
-- Deliberately separate from `payment_providers_manage` / `payouts_manage`
-- — refunds move money OUT to customers, a distinct blast radius from
-- either gateway config or restaurant payouts, so a narrower "Finance
-- Admin" role could plausibly get one without the other.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('refunds_view', 'refunds', 'view'),
    ('refunds_manage', 'refunds', 'manage');

-- Grant both to every role that already exists and holds
-- `payment_providers_manage` (i.e. today, just Super Admin) — same
-- "don't silently reduce anyone's access" principle migration 29's own
-- header comment states, applied to a new permission pair instead of
-- a new role system.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'payment_providers_manage'
JOIN admin_permissions np ON np.`key` IN ('refunds_view', 'refunds_manage');
