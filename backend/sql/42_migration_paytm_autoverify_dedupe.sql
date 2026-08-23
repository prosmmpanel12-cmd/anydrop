-- ============================================================
-- Anydrop — Migration 42: Paytm auto-verify dedupe
-- (doc 23 addendum §A6 — Paytm MID-based auto-verify)
--
-- Mirrors what migration 40's `uq_ptxn_utr` already does for the
-- manual path, applied to the auto path: the UPIPE reference source
-- checks its equivalent of BANKTXNID for reuse even on its auto-verify
-- branch (upi/api/verify_payment.php — "Transaction already used for
-- another order."), not just on manually-submitted UTRs. Anydrop's
-- auto path (UpipeProvider::tryAutoVerify) was missing that check.
--
-- Separate column from `utr` (VARCHAR(12), sized for a 12-digit bank
-- UTR) because Paytm's TXNID/BANKTXNID values run longer than 12
-- characters — can't share the column without truncation.
-- ============================================================

ALTER TABLE payment_transactions
    ADD COLUMN provider_bank_ref VARCHAR(64) NULL AFTER utr;

ALTER TABLE payment_transactions
    ADD CONSTRAINT uq_ptxn_provider_bank_ref UNIQUE (provider_bank_ref);
