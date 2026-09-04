-- ============================================================
-- Anydrop — Migration 43: Customer Wallet (recall.md item 26,
-- section 18; doc 19 §3/§12)
--
-- Schema follows doc 19 §3's own design closely (customer_wallets +
-- wallet_transactions, append-only ledger — same reasoning as
-- restaurant_due_ledger/platform_ledger: a mutable balance column
-- alone can silently drift from reality after a bug or a race; a
-- ledger with `balance_after` snapshotted on every row means the
-- balance can always be recomputed/audited from history, not just
-- trusted).
--
-- SCOPE DECISION made explicit here because doc 19 §12 flagged it as
-- open and unresolved: this is WALLET-AS-FULL-PAYMENT-METHOD-ONLY,
-- not a split/hybrid payment. `orders.payment_method` gets a third
-- value, `'wallet'` — a wallet order is paid entirely from wallet
-- balance or not placed at all (checked/debited atomically at order
-- creation, see lib/wallet.php's debit_wallet_for_order()). No
-- "wallet covers part, UPI covers the rest" model in v1 — same
-- "doesn't model partial payments" constraint this codebase already
-- applies twice (doc 23 §A5 for UPI payments, migration 42's own
-- header for refunds). Flagged here for whoever builds split-payment
-- later rather than half-built now.
--
-- NOT wired into checkout UI this migration/session — see
-- recall.md item 26's own status note for what's built vs what a
-- follow-up session still needs (CheckoutActivity radio option,
-- PaymentMethodsResult.walletAllowed, orders/create.php accepting
-- 'wallet'). This migration + backend lib + admin screens + the
-- customer-app Wallet balance/history screen are the actual scope
-- here.
-- ============================================================

CREATE TABLE IF NOT EXISTS customer_wallets (
    customer_id BIGINT UNSIGNED PRIMARY KEY,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallet_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    type ENUM('credit','debit') NOT NULL,
    -- Always positive — `type` carries the direction, same convention
    -- restaurant_due_ledger uses signed amounts for but wallet_transactions
    -- doesn't need to (no "which side owes" ambiguity here, just money
    -- in or out of one customer's own balance).
    amount DECIMAL(10,2) NOT NULL,
    reason ENUM('refund','admin_adjustment','cashback','order_payment') NOT NULL,
    note VARCHAR(255) NULL,
    -- Snapshot of the balance immediately after this row — lets the
    -- wallet screen and any admin audit show a running total without
    -- recomputing SUM() over the whole table every read, and lets a
    -- future reconciliation job catch drift (recompute SUM(credit)-
    -- SUM(debit) and compare against the latest balance_after).
    balance_after DECIMAL(10,2) NOT NULL,
    created_by ENUM('system','admin') NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallettxn_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_wallettxn_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_wallettxn_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    INDEX idx_wallettxn_customer (customer_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `notifications.type` widened — wallet credits/debits are common
-- enough (every refund-to-wallet, cashback, admin adjustment) to
-- deserve their own filterable type rather than bucketing under
-- 'system', same reasoning migration 31 used adding 'review'.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('order','promo','system','security','review','wallet') NOT NULL DEFAULT 'system';

-- `orders.payment_method` widened to accept 'wallet' — additive, the
-- existing 'upi'/'cod' rows are untouched. Not yet written by
-- orders/create.php (see this file's header) — safe to add now so a
-- later session doesn't need another migration just for this column.
ALTER TABLE orders
    MODIFY COLUMN payment_method ENUM('upi','cod','wallet') NOT NULL DEFAULT 'cod';

-- ---------- RBAC: new permission keys (same pattern migrations 29/42 used) ----------
-- Separate from refunds_manage — an admin adjustment moves money into
-- a customer's wallet directly (no order/refund trail backing it),
-- a distinct, more open-ended blast radius worth gating on its own.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('wallets_view', 'wallets', 'view'),
    ('wallets_manage', 'wallets', 'manage');

-- Grant both to every role that already holds `refunds_manage` (i.e.
-- today, just Super Admin) — same "don't silently reduce anyone's
-- access" principle as migration 42.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'refunds_manage'
JOIN admin_permissions np ON np.`key` IN ('wallets_view', 'wallets_manage');

-- Admin-configurable cashback expiry window, days — recall.md section
-- 18's "Cashback expiry if required" line. 0 = never expires (v1
-- default — no expiry job exists yet either, see lib/wallet.php's
-- kdoc on why this setting is stored but not yet enforced).
INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('wallet_cashback_expiry_days', '0');
