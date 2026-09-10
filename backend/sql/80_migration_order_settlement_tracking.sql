-- ============================================================
-- Anydrop — Migration 80: Order Settlement Status Tracking
-- (Deep Plan doc docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md, Phase 1)
--
-- Backs the new Restaurant/Rider Statement screens' per-order
-- "Settled / Pending T+1" badge. Nothing here changes existing money
-- calculation (commission_amount, current_due, etc.) — this is purely
-- a STATUS LABEL layer on top of the existing ledger, computed lazily
-- (no cron job required for v1 — see lib/settlement_status.php).
--
-- T+1 = calendar days (delivered_at + 1 day), per owner's 2026-09-09
-- ask — "T+1 hai" with no business-day qualifier. Revisit if the owner
-- later wants business-day counting (skip Sundays etc.).
--
-- ---------- Part 1: per-order settlement status ----------
ALTER TABLE orders
    ADD COLUMN settlement_eligible_at TIMESTAMP NULL AFTER delivered_at,
    ADD COLUMN settlement_status ENUM('not_applicable', 'pending', 'eligible', 'settled')
        NOT NULL DEFAULT 'not_applicable' AFTER settlement_eligible_at;

-- Backfill: every already-delivered order gets eligible_at = delivered_at + 1 day,
-- and status computed against "now" once, at migration time. Orders that were
-- never delivered (cancelled/rejected/etc.) stay 'not_applicable' (the default).
UPDATE orders
SET settlement_eligible_at = DATE_ADD(delivered_at, INTERVAL 1 DAY)
WHERE delivered_at IS NOT NULL AND settlement_eligible_at IS NULL;

UPDATE orders
SET settlement_status = 'pending'
WHERE delivered_at IS NOT NULL
  AND settlement_status = 'not_applicable'
  AND settlement_eligible_at > NOW();

UPDATE orders
SET settlement_status = 'eligible'
WHERE delivered_at IS NOT NULL
  AND settlement_status = 'not_applicable'
  AND settlement_eligible_at <= NOW();

-- ---------- Part 2: which orders a given restaurant Pay Now actually covered ----------
--
-- admin/settlements.php's existing "Pay Now" writes ONE restaurant_payments
-- row for a date-range ledger balance — it never recorded which specific
-- orders that balance came from. Without that link, a Statement screen
-- can never say "this order specifically got paid out" — only "restaurant's
-- balance was zero at some point". This join table is purely additive:
-- existing Pay Now behavior/columns are untouched, this just also writes
-- rows here from now on (lib/settlement_status.php's mark-settled step) so
-- future Pay Now actions become order-traceable. Historical (pre-migration)
-- restaurant_payments rows have no rows here — those old orders are simply
-- left however the backfill above set them (best-effort 'eligible', since we
-- don't know if they were actually paid out).
CREATE TABLE IF NOT EXISTS restaurant_payment_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rpo_payment FOREIGN KEY (payment_id) REFERENCES restaurant_payments(id),
    CONSTRAINT fk_rpo_order FOREIGN KEY (order_id) REFERENCES orders(id),
    UNIQUE KEY uq_rpo_order (order_id), -- one order settled at most once
    INDEX idx_rpo_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
