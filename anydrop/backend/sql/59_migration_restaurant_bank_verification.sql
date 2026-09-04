-- Anydrop — Migration 59: Restaurant Bank Details — Restaurant-Side
-- Submission + Verification Workflow (PENDING.md §15)
--
-- `restaurant_bank_details` already existed (migration 38) and was
-- already writable from the admin side (admin/settlements.php's
-- "Bank Details" form, save_bank_details action) — that part of §15
-- ("Admin-side settlement/bank infrastructure exists") is unchanged
-- by this migration.
--
-- What was missing, and what this migration adds columns for:
--   - Restaurant-side submission — a restaurant currently has no way
--     to enter/update its own bank details; only an admin can, on its
--     behalf. That gap is closed by new endpoints
--     (bank-details-get.php / bank-details-save.php), not by this
--     migration — this migration only adds the columns those
--     endpoints (and the admin form) need to represent the review
--     workflow that comes with letting the restaurant self-submit
--     payment-routing details unsupervised.
--   - Verification status — self-submitted bank details are exactly
--     the kind of field where a typo or bad-faith entry has real
--     financial consequences (a payout going to the wrong account),
--     so every restaurant-submitted change starts 'pending' rather
--     than being trusted immediately. Admin-entered details (the
--     existing settlements.php form) are saved as 'verified' directly
--     — an admin typing values on the restaurant's behalf is already
--     a supervised entry, unlike a restaurant self-submitting.
--   - Admin verification — `verified_by_admin_id` /`verified_at` give
--     the admin UI something to act on and audit (who verified this,
--     when), same shape as `restaurant_payments.settled_by_admin_id`
--     from migration 38 for the equivalent "who actioned this" need
--     on that table.
--   - Audit trail — the actual audit_logs rows are written by the
--     endpoints/admin actions, not by this migration; no schema
--     changes needed there since `audit_logs` already exists
--     (schema-agnostic details_json column, no migration needed for a
--     new `action` string).
--
-- verification_status starts as an ENUM rather than a boolean since
-- 'rejected' needs to be distinguishable from 'pending' in the UI —
-- an admin rejecting a submission (bad IFSC, mismatched name, etc.)
-- should show the restaurant *why it's not active yet* differently
-- from a submission that's simply not been looked at.
ALTER TABLE restaurant_bank_details
    ADD COLUMN verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending' AFTER upi_id,
    ADD COLUMN admin_remarks VARCHAR(255) NULL AFTER verification_status,
    ADD COLUMN verified_by_admin_id BIGINT UNSIGNED NULL AFTER admin_remarks,
    ADD COLUMN verified_at TIMESTAMP NULL AFTER verified_by_admin_id,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER verified_at,
    ADD CONSTRAINT fk_bank_verified_by FOREIGN KEY (verified_by_admin_id) REFERENCES admins(id);

-- Existing rows (all admin-entered so far, per §15's "admin-side
-- infrastructure exists" note — no restaurant-side submission path
-- existed before this migration for any row to have come from) are
-- backfilled as already-verified rather than defaulting to 'pending'
-- and forcing a re-review of data an admin already typed in
-- themselves.
UPDATE restaurant_bank_details SET verification_status = 'verified' WHERE verification_status = 'pending';
