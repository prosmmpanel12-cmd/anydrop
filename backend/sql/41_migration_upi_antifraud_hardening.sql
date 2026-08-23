-- ============================================================
-- Anydrop — Migration 41: Native UPI Gateway — anti-fraud hardening
-- (app owner request 2026-08-23: "koi payment ko spoof na kar sake,
-- koi loop hole na nikaal le")
--
-- Two gaps closed, both additive to migration 40:
--   1. `utr_attempts` — caps how many times a customer can hammer the
--      submit-utr endpoint with invalid/mismatched values on one
--      transaction (previously unlimited retries — cheap DoS/spam
--      vector against the admin review queue and the utr uniqueness
--      index). See UpipeProvider::submitUtr()'s updated cap check.
--   2. `amount_confirmed` — records the exact amount the ADMIN typed
--      in as "what I actually see in my bank/UPI app" at approval
--      time, separate from `payment_transactions.amount` (what the
--      QR *asked* for). See doc 23 addendum §A5 for why these can
--      legitimately differ (a UPI app that lets the payer edit the
--      amount) and why approval must now be blocked on a mismatch
--      instead of trusting that the admin eyeballed it correctly.
-- ============================================================

ALTER TABLE payment_transactions
    ADD COLUMN utr_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER utr,
    ADD COLUMN amount_confirmed DECIMAL(10,2) NULL AFTER amount;
