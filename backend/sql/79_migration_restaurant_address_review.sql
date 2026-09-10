-- Anydrop — Migration 79: Restaurant Address Change — Admin Review
-- Workflow
--
-- App owner ask: a restaurant changing its own address should NOT take
-- effect immediately — it goes to admin review first, same
-- "self-submitted change with real-world consequences starts pending"
-- reasoning as migration 59's restaurant_bank_details verification
-- workflow. An address is what customers/riders navigate to and what
-- resolve_service_area() uses to (re)confirm the restaurant's service
-- area, so an unreviewed typo or bad-faith change has the same kind of
-- blast radius as an unreviewed bank account change.
--
-- Design mirrors migration 59 exactly:
--   - `restaurants.address` (existing column) is left untouched and
--     keeps showing the CURRENT, admin-approved address everywhere
--     (customer app, restaurant app's own profile view, admin list) —
--     nothing reads a not-yet-approved address as if it were live.
--   - `pending_address` / `pending_latitude` / `pending_longitude`
--     hold the restaurant's proposed new values while awaiting review.
--     Nullable — NULL means "no pending change", same sentinel style
--     used elsewhere in this schema (e.g. restaurants.area_id).
--   - `address_review_status` is an ENUM (not a boolean) for the same
--     reason verification_status is: 'rejected' needs to be shown
--     differently from 'pending' so the restaurant knows *why* their
--     submitted address never took effect, and 'none' (the default)
--     covers restaurants that have never requested a change so the UI
--     doesn't show a stale review row for accounts that never touched
--     this flow.
--   - `address_review_remarks` / `address_reviewed_by_admin_id` /
--     `address_reviewed_at` give the admin UI the same "who actioned
--     this, when, why" trail as verified_by_admin_id/verified_at/
--     admin_remarks on restaurant_bank_details.
--
-- profile-update.php (the endpoint) changes separately to write into
-- these new pending_* columns instead of `address` directly whenever
-- `address` (or latitude/longitude) is present in the request body —
-- no schema change needed for that, just endpoint logic.
ALTER TABLE restaurants
    ADD COLUMN pending_address TEXT NULL AFTER address,
    ADD COLUMN pending_latitude DECIMAL(10,8) NULL AFTER pending_address,
    ADD COLUMN pending_longitude DECIMAL(11,8) NULL AFTER pending_latitude,
    ADD COLUMN address_review_status ENUM('none', 'pending', 'rejected') NOT NULL DEFAULT 'none' AFTER pending_longitude,
    ADD COLUMN address_review_remarks VARCHAR(255) NULL AFTER address_review_status,
    ADD COLUMN address_reviewed_by_admin_id BIGINT UNSIGNED NULL AFTER address_review_remarks,
    ADD COLUMN address_reviewed_at TIMESTAMP NULL AFTER address_reviewed_by_admin_id,
    ADD CONSTRAINT fk_restaurant_address_reviewed_by FOREIGN KEY (address_reviewed_by_admin_id) REFERENCES admins(id);
