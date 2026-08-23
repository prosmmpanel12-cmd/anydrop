-- ============================================================
-- Anydrop — Migration 40: Native UPI Payment Gateway
-- (docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md §6)
--
-- Additive only — extends migration 39's payment_providers /
-- payment_transactions tables, doesn't replace them. Adds what the
-- manual-admin-verification, QR-only, no-deep-link flow needs:
--   - payment_transactions: utr (customer-submitted bank reference),
--     who approved/rejected it and why, and a hard expiry timestamp
--     so verify.php can expire a stale order without a cron job.
--   - payment_providers.config_json (UPIPE row): real shape documented
--     inline below — still admin-empty (no real UPI ID) until an
--     admin fills the Payment Gateways screen in, same "stub-safe by
--     default" spirit as migration 39's is_test_mode=1 default.
-- ============================================================

ALTER TABLE payment_transactions
    ADD COLUMN utr VARCHAR(12) NULL AFTER provider_txn_id,
    ADD COLUMN verified_by_admin_id BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN reject_reason VARCHAR(255) NULL AFTER verified_by_admin_id,
    ADD COLUMN expires_at TIMESTAMP NULL AFTER amount;

-- 'utr_submitted' — the manual-review-pending state between "customer
-- typed in a UTR" and "admin approved/rejected it" (doc 23 §5 model
-- B). Everything else keeps migration 39's original 4 values;
-- 'rejected' deliberately does NOT get its own ENUM value — an
-- admin rejection is stored as 'failed' + reject_reason populated,
-- so every caller that already only checks for 'success'/'failed'
-- keeps working unmodified.
ALTER TABLE payment_transactions
    MODIFY COLUMN status ENUM('initiated','utr_submitted','success','failed','refunded') NOT NULL DEFAULT 'initiated';

-- One UTR can only ever belong to one transaction — same anti-reuse
-- rule the UPIPE reference source enforces (docs/payment_reference/
-- upipe_source/upi/config/schema.sql). NULL utr (not-yet-submitted,
-- or a non-UPI transaction) is allowed to repeat — MySQL's UNIQUE
-- index already treats multiple NULLs as non-conflicting.
ALTER TABLE payment_transactions
    ADD CONSTRAINT uq_ptxn_utr UNIQUE (utr);

ALTER TABLE payment_transactions
    ADD CONSTRAINT fk_ptxn_verified_by FOREIGN KEY (verified_by_admin_id) REFERENCES admins(id);

CREATE INDEX idx_ptxn_expires ON payment_transactions (expires_at);

-- Real config_json shape for the UPIPE-pattern (native) row — see
-- doc 23 §6. `upi_id`/`payee_name` start empty on purpose: nothing
-- can look like a receivable payment QR until an admin deliberately
-- fills these in via admin/payment-gateways.php (same "empty JSON is
-- fine while it's still a stub" note migration 39 already made for
-- config_json, now made concrete).
UPDATE payment_providers
SET config_json = '{"upi_id":"","payee_name":"Anydrop","expiry_sec":900,"utr_window_sec":300,"utr_required":true}'
WHERE driver_key = 'upipe' AND config_json = '{}';
