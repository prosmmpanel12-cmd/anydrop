-- ============================================================
-- Anydrop — Migration 82: Rider COD Self-Deposit via UPI
-- (Deep Plan Phase 5, docs/00_Deep_Plan_Statement_CODLimit_QRPay_
-- CashFlow_2026-09-09.md §5)
--
-- Reuses the EXISTING native-UPI payment_transactions table/flow
-- (migrations 39-42, backend/lib/payment/*) instead of building a
-- second payment pipeline — a rider depositing held COD cash to
-- admin is the same underlying mechanic as a customer paying admin
-- for an order (a QR pointing at admin's own UPI ID, manual UTR
-- fallback, admin approve/reject), just with no `orders` row behind
-- it. This migration makes that table able to represent BOTH shapes:
--
--   purpose = 'customer_order'    -> order_id required, rider_id NULL
--   purpose = 'rider_cod_deposit' -> rider_id required, order_id NULL
--
-- `order_id` is loosened from NOT NULL to NULL for this reason only —
-- every existing row already has purpose defaulting to
-- 'customer_order' and a real order_id, so this is purely additive,
-- no data migration needed.
--
-- Partial deposits ARE allowed (app owner decision, 2026-09-09) — a
-- rider holding ₹850 may deposit any amount from ₹1 up to ₹850, not
-- only the full amount. Nothing in this migration enforces "full
-- amount only"; that would be an app/API-layer choice, not a schema
-- one, and the owner explicitly chose the more flexible option.
-- ============================================================

ALTER TABLE payment_transactions
    MODIFY COLUMN order_id BIGINT UNSIGNED NULL;

ALTER TABLE payment_transactions
    ADD COLUMN purpose ENUM('customer_order', 'rider_cod_deposit') NOT NULL DEFAULT 'customer_order' AFTER order_id,
    ADD COLUMN rider_id BIGINT UNSIGNED NULL AFTER purpose;

ALTER TABLE payment_transactions
    ADD CONSTRAINT fk_ptxn_rider FOREIGN KEY (rider_id) REFERENCES riders(id);

CREATE INDEX idx_ptxn_rider ON payment_transactions (rider_id);
CREATE INDEX idx_ptxn_purpose ON payment_transactions (purpose);

-- Best-effort shape guard — enforced by MySQL 8.0.16+/MariaDB 10.2+;
-- silently not enforced on older engines (same caveat this project's
-- other CHECK constraints, e.g. migration 37, already carry). The
-- real enforcement lives in PaymentService.php either way — this is
-- a second line of defense, not the only one.
ALTER TABLE payment_transactions
    ADD CONSTRAINT chk_ptxn_purpose_shape CHECK (
        (purpose = 'customer_order' AND order_id IS NOT NULL AND rider_id IS NULL)
        OR
        (purpose = 'rider_cod_deposit' AND rider_id IS NOT NULL AND order_id IS NULL)
    );

-- Links a rider_cod_ledger 'settlement_to_admin' row back to the exact
-- payment_transactions row that produced it, when the settlement came
-- from this new self-service UPI flow rather than admin manually
-- clicking "Record Settlement" (rider-settlements.php, which leaves
-- this NULL, same as before this migration). Doubles as the
-- idempotency guard PaymentService needs so a customer's repeated
-- status poll after success can never double-credit the same deposit
-- twice — see PaymentService::promoteRiderDepositIfNeeded()'s own
-- comment.
ALTER TABLE rider_cod_ledger
    ADD COLUMN payment_transaction_id BIGINT UNSIGNED NULL AFTER order_id;

ALTER TABLE rider_cod_ledger
    ADD CONSTRAINT fk_rider_cod_ledger_ptxn FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id);

-- NULLs don't collide under a UNIQUE index (every admin-recorded
-- settlement row, and every pre-existing row, has this NULL) — only
-- guards against the same payment_transactions row ever producing two
-- ledger entries.
ALTER TABLE rider_cod_ledger
    ADD UNIQUE INDEX uq_rider_cod_ledger_ptxn (payment_transaction_id);
