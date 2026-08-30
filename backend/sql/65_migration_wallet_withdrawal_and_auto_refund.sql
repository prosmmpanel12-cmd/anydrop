-- ============================================================
-- Anydrop — Migration 65: Wallet Withdrawal + Prepaid-Cancel
-- Auto-Refund-to-Wallet (app owner request, 2026-08-30)
--
-- Two related changes:
--
-- 1. AUTO-REFUND ON SELF-CANCEL (no schema change needed for this
--    half — orders/cancel.php's own cancel-window check already
--    guarantees "jab tak time ho" / within the allowed window before
--    this code path is reached). A customer cancelling their own
--    prepaid order inside `order_cancel_window_minutes` is a policy-
--    safe, no-judgement-call refund — unlike a restaurant-rejected or
--    admin-force-cancelled order, there's nothing for an admin to
--    review here, so lib/refunds.php's new auto_wallet_refund_on_
--    cancel() skips the manual requested -> under_review -> approved
--    queue entirely and credits the customer's Anydrop Wallet
--    instantly, in the same DB transaction as the cancellation
--    itself. Every other refund path (restaurant reject, admin
--    force-cancel) is UNCHANGED — those still land in
--    admin/refunds.php's manual review queue exactly as before; this
--    migration/session does not touch that judgement-call path.
--
-- 2. WALLET WITHDRAWAL — v1 wallet was credit-only from the
--    customer's side (see migration 43's own header: "no customer-
--    initiated top-up or withdrawal"). This adds the missing
--    withdrawal half: a customer can request their wallet balance be
--    paid out to their bank/UPI, an admin reviews + manually sends
--    the money (same "no real payout gateway, human sends it, this
--    system tracks it" model migration 42 already established for
--    refunds and settlements.php already established for restaurant
--    payouts), then marks the request complete.
--
--    SECURITY-CRITICAL DESIGN DECISION: the wallet amount is debited
--    IMMEDIATELY at request time (via the same row-locked,
--    battle-tested debit_wallet() every other wallet debit already
--    uses — no new locking code, no parallel balance-check path to
--    get wrong), not at admin-completion time. This closes the
--    obvious double-spend window a "debit only once admin approves"
--    design would leave open: without an immediate hold, a customer
--    could place a wallet order (or request a second withdrawal)
--    using the same balance a pending withdrawal request is already
--    claiming, and if BOTH went through, the wallet would go
--    negative. Debiting up front, and crediting back on rejection,
--    means the wallet's real-time balance is always the true
--    spendable amount, the same guarantee every other wallet write in
--    this codebase already relies on.
-- ============================================================

-- `wallet_transactions.reason` widened — a withdrawal hold/reversal is
-- its own distinct reason, not squeezed into 'admin_adjustment' (which
-- already means something else: an admin manually crediting/debiting
-- with no order/refund/withdrawal trail behind it).
ALTER TABLE wallet_transactions
    MODIFY COLUMN reason ENUM('refund','admin_adjustment','cashback','order_payment','withdrawal') NOT NULL;

-- `platform_ledger.entry_type` widened — a completed wallet withdrawal
-- payout is real money actually leaving the platform's bank account
-- (the admin's manual bank/UPI transfer), same "money OUT" shape as
-- `refund_out`, but semantically distinct (a customer asked for THEIR
-- OWN balance back vs. an order being refunded) so it gets its own
-- value rather than overloading `refund_out` for something that isn't
-- a refund.
ALTER TABLE platform_ledger
    MODIFY COLUMN entry_type ENUM(
        'customer_payment_in',
        'restaurant_settlement_in',
        'restaurant_payout_out',
        'refund_out',
        'platform_revenue',
        'manual_adjustment',
        'wallet_withdrawal_out'
    ) NOT NULL;

-- Customer's saved payout details — same shape as
-- restaurant_bank_details (migration 38), one row per customer,
-- create-or-replace on save. Deliberately NO verification_status
-- workflow on the details row itself (unlike migration 59's
-- restaurant version) — here, review happens per WITHDRAWAL REQUEST
-- below (an admin looks at the actual request + snapshotted payout
-- details before sending money), not per bank-details save, since a
-- customer saving details with no withdrawal request behind them yet
-- moves no money and needs no admin attention.
CREATE TABLE IF NOT EXISTS customer_bank_details (
    customer_id BIGINT UNSIGNED PRIMARY KEY,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(30) NULL,
    ifsc_code VARCHAR(15) NULL,
    upi_id VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_bank_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Withdrawal requests. `wallet_debit_txn_id` points at the exact
-- wallet_transactions row that put the hold on the money at request
-- time (see class header above) — lets a live reconciliation always
-- trace a withdrawal back to the specific ledger row that moved the
-- balance, same "always traceable to a ledger row, never just a
-- mutable status" principle wallet_transactions.balance_after already
-- follows.
--
-- account_holder_name/bank_name/account_number/ifsc_code/upi_id here
-- are a SNAPSHOT of customer_bank_details at request time, not a live
-- join — if the customer edits their saved details after submitting a
-- request (or before a second one), the admin must still see exactly
-- where THIS request asked the money to go, same reasoning
-- refunds.refund_reference captures a value rather than deriving one
-- later.
CREATE TABLE IF NOT EXISTS wallet_withdrawals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    wallet_debit_txn_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payout_method ENUM('bank','upi') NOT NULL,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(30) NULL,
    ifsc_code VARCHAR(15) NULL,
    upi_id VARCHAR(100) NULL,
    -- Lifecycle mirrors refunds.status exactly, on purpose (same admin
    -- mental model as the Refunds page):
    --   requested -> approved -> processing -> completed
    --   (rejected is an off-ramp from requested or approved only —
    --    once 'processing', the admin has already sent real money, so
    --    rejecting is no longer a valid state to move to; that request
    --    can only be completed or handled manually outside this table)
    status ENUM('requested','approved','processing','completed','rejected') NOT NULL DEFAULT 'requested',
    payout_reference VARCHAR(100) NULL,
    reject_reason VARCHAR(255) NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    processing_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_withdrawal_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_withdrawal_wallet_txn FOREIGN KEY (wallet_debit_txn_id) REFERENCES wallet_transactions(id),
    CONSTRAINT fk_withdrawal_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_withdrawal_status (status),
    INDEX idx_withdrawal_customer (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-configurable minimum withdrawal amount (recall.md rule 34 —
-- never hardcode a business rule). 0 = no minimum.
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('wallet_withdrawal_min_amount', '100');

-- ---------- RBAC: new permission pair, same pattern migrations 29/42/43 used ----------
-- Deliberately separate from `wallets_manage` (which migration 43
-- scoped to admin-initiated credit/debit adjustments with no
-- order/withdrawal trail) — approving a real payout to a customer's
-- outside bank/UPI account is its own distinct blast radius (money
-- actually leaving the platform), same reasoning `refunds_manage` was
-- kept separate from `payment_providers_manage`.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('wallet_withdrawals_view', 'wallet_withdrawals', 'view'),
    ('wallet_withdrawals_manage', 'wallet_withdrawals', 'manage');

-- Grant both to every role that already holds `wallets_manage` (i.e.
-- today, just Super Admin) — same "don't silently reduce anyone's
-- access" principle every prior permission-adding migration here uses.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'wallets_manage'
JOIN admin_permissions np ON np.`key` IN ('wallet_withdrawals_view', 'wallet_withdrawals_manage');
