-- ============================================================
-- Anydrop — Migration 76: Rider Payout Requests — Screenshot proof +
-- Batch Processing support.
--
-- App-owner ask, 2026-09-09: monthly batch pay — admin picks a fixed
-- date, reviews all pending rider payout requests together, and marks
-- them processed the same way admin/settlements.php already handles a
-- restaurant "Pay Now" (UTR/reference + an optional screenshot proof of
-- the transfer) — but EACH rider still gets their own reference/
-- screenshot (a single batch bank transfer to N different riders can't
-- share one UTR), never a single shared reference for the whole batch.
--
-- Two additions, both purely additive — no existing column/behavior
-- changes, so mark_rider_payout_request_processing()'s existing single-
-- row call sites (if any script still calls it that way) keep working:
--
--   1. payout_screenshot_url — same optional transfer-proof screenshot
--      settlements.php's save_settlement_screenshot() already writes
--      for restaurants, added here for riders. NULL is fine (screenshot
--      was always optional for restaurants too — same UX, not a new
--      requirement invented for riders).
--
--   2. payout_batch_id — a lightweight grouping tag (NOT a foreign key
--      to a new "batches" table — there is no separate batch entity to
--      manage, just a shared timestamp-based string so the admin UI can
--      show "these N requests were processed together on this date" as
--      a filter/label). Nullable — a request processed individually
--      (the existing single-row Mark Processing flow, unchanged) simply
--      never gets one.
-- ============================================================

ALTER TABLE rider_payout_requests
    ADD COLUMN payout_screenshot_url VARCHAR(255) NULL AFTER payout_reference,
    ADD COLUMN payout_batch_id VARCHAR(40) NULL AFTER payout_screenshot_url,
    ADD INDEX idx_rider_payout_batch (payout_batch_id);
