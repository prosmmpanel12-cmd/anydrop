-- ============================================================
-- Anydrop — Migration 74: Rider Self-Service Payout Requests
-- (deep-plan §21, decided 2026-09-05 — the person confirmed this over
-- Rider Documents/§22 when asked which of doc 90's two flagged
-- next-steps to build first).
--
-- Doc 90 (Rider Earnings ledger screen) explicitly scoped out any
-- payout-request action, calling it deep-plan §21's separate piece —
-- this migration is that piece.
--
-- Model: closely mirrors migration 65's wallet_withdrawal design
-- (same admin, same "no real payout gateway, human sends it, this
-- system tracks it" model already established for customer wallet
-- withdrawals, restaurant settlements, and refunds) — NOT a new
-- pattern invented from scratch:
--
--   Rider earnings_balance
--        ↓
--   Payout request (rider app) — debits earnings_balance IMMEDIATELY
--        ↓
--   Admin review: Approve
--        ↓
--   Mark Processing (admin sends the transfer themselves, records ref)
--        ↓
--   Mark Completed (platform_ledger gets the money-out entry)
--
--   (Reject is an off-ramp from 'requested' or 'approved' only — same
--    "once processing, real money already moved externally" reasoning
--    wallet_withdrawals/refunds already use — and credits the hold
--    back via rider_earnings_ledger's existing 'adjustment_credit'
--    entry type, no new entry type needed for that reversal.)
--
-- SECURITY-CRITICAL DESIGN DECISION (same as migration 65's wallet
-- withdrawal, same reasoning, not re-derived here): the earnings
-- balance is debited AT REQUEST TIME, not at admin-approval time, to
-- close the double-spend window an approval-time-only hold would
-- leave open (a rider requesting twice against the same balance
-- before either is reviewed). The debit reuses
-- write_rider_earnings_ledger_entry()'s existing row-locked writer
-- (lib/rider_earnings.php) — no new locking code written for this.
--
-- Deliberately a NEW table, not a new column/status on
-- rider_earnings_ledger itself — a ledger row is an immutable fact
-- ("the balance moved by X"), a payout REQUEST is a stateful workflow
-- object with its own lifecycle (requested/approved/processing/
-- completed/rejected) layered on top of one ledger row (the debit) and
-- potentially a second (the reversal credit, on reject). Same
-- separation of concerns wallet_withdrawals keeps from
-- wallet_transactions.
-- ============================================================

-- Rider's saved payout details — same shape as customer_bank_details
-- (migration 65) and restaurant_bank_details (migration 38),
-- duplicated rather than shared for the same reason
-- customer_bank_details itself gives (different actor, validated/
-- displayed under slightly different rules — a rider payout, like a
-- customer withdrawal, can be UPI-only with no bank fields at all;
-- unlike the restaurant version, which always requires full bank
-- details). No verification_status workflow on this row itself —
-- review happens per REQUEST below (an admin looks at the actual
-- request + its snapshotted payout details before sending money), not
-- per bank-details save, since saving details with no request behind
-- them moves no money.
CREATE TABLE IF NOT EXISTS rider_bank_details (
    rider_id BIGINT UNSIGNED PRIMARY KEY,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(30) NULL,
    ifsc_code VARCHAR(15) NULL,
    upi_id VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rider_bank_rider FOREIGN KEY (rider_id) REFERENCES riders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payout requests. earnings_debit_ledger_id points at the exact
-- rider_earnings_ledger row that placed the 'payout' hold at request
-- time — lets a live reconciliation always trace a request back to
-- the specific ledger row that moved the balance, same "always
-- traceable to a ledger row, never just a mutable status" principle
-- wallet_withdrawals.wallet_debit_txn_id already follows.
--
-- account_holder_name/bank_name/account_number/ifsc_code/upi_id here
-- are a SNAPSHOT of rider_bank_details at request time, not a live
-- join — if the rider edits their saved details after submitting a
-- request, the admin must still see exactly where THIS request asked
-- the money to go, same reasoning wallet_withdrawals' own snapshot
-- columns already follow.
CREATE TABLE IF NOT EXISTS rider_payout_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    earnings_debit_ledger_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payout_method ENUM('bank','upi') NOT NULL,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(30) NULL,
    ifsc_code VARCHAR(15) NULL,
    upi_id VARCHAR(100) NULL,
    -- Lifecycle mirrors wallet_withdrawals.status exactly, on purpose
    -- (same admin mental model, same reasoning that file's own
    -- comment gives):
    --   requested -> approved -> processing -> completed
    --   (rejected is an off-ramp from requested or approved only)
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
    CONSTRAINT fk_rider_payout_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    CONSTRAINT fk_rider_payout_ledger FOREIGN KEY (earnings_debit_ledger_id) REFERENCES rider_earnings_ledger(id),
    CONSTRAINT fk_rider_payout_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_rider_payout_status (status),
    INDEX idx_rider_payout_rider (rider_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- platform_ledger.entry_type widened — a COMPLETED rider payout is
-- real money actually leaving the platform's bank account (the
-- admin's manual bank/UPI transfer), same "money OUT" shape as
-- wallet_withdrawal_out/restaurant_payout_out but its own value since
-- it's a distinct direction/actor worth telling apart in reports.
ALTER TABLE platform_ledger
    MODIFY COLUMN entry_type ENUM(
        'customer_payment_in',
        'restaurant_settlement_in',
        'restaurant_payout_out',
        'refund_out',
        'platform_revenue',
        'manual_adjustment',
        'wallet_withdrawal_out',
        'rider_payout_out'
    ) NOT NULL;

-- notifications.type widened — 'payout' is a new, actor-neutral value
-- (unlike migration 43's 'wallet', which reads as customer-wallet-
-- specific) so it can cover this rider flow now and any future
-- restaurant/customer payout-status notification later without
-- growing a new value per actor.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('order','promo','system','security','review','wallet','payout') NOT NULL DEFAULT 'system';

-- Admin-configurable minimum payout request amount, same "never
-- hardcode a business rule" convention as wallet_withdrawal_min_amount
-- (migration 65).
INSERT IGNORE INTO app_settings (`key`, `value`, description) VALUES
    ('rider_payout_min_amount', '100', 'Minimum ₹ a rider can request in a single self-service payout request.');

-- ---------- RBAC: new permission pair ----------
-- Deliberately separate from the existing `payouts_view`/
-- `payouts_manage` (already shared today by Settlements, Rider
-- Earnings, Platform Ledger, and Commission Rules — an admin-initiated
-- manual-payout/rate-setting surface) — reviewing a RIDER-INITIATED
-- request against an outside bank/UPI account is its own distinct
-- blast radius, same reasoning migration 65 kept `wallet_withdrawals_*`
-- separate from `wallets_manage` rather than folding into it.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('rider_payouts_view', 'rider_payouts', 'view'),
    ('rider_payouts_manage', 'rider_payouts', 'manage');

-- Grant both to every role that already holds `payouts_manage` (i.e.
-- today, just Super Admin) — same "don't silently reduce anyone's
-- access" principle every prior permission-adding migration here uses.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'payouts_manage'
JOIN admin_permissions np ON np.`key` IN ('rider_payouts_view', 'rider_payouts_manage');
