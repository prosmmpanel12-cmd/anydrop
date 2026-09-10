-- ============================================================
-- Anydrop — Migration 78: notification_broadcasts.target_type — add
-- 'all_riders' / 'area_riders'
--
-- App owner request (2026-09-07): add a Rider option to the admin
-- Push Notification Broadcast page (admin/broadcast.php), alongside
-- the existing customer/restaurant targeting. That page's target_type
-- ENUM only had 'all_customers'/'all_restaurants'/'area_customers'/
-- 'area_restaurants' (migration 61) — inserting 'all_riders' or
-- 'area_riders' without this migration would fail the INSERT outright
-- (strict-mode ENUM violation) the first time an admin tries to send
-- one, same class of gap migration 74/76 each closed for
-- notifications.type ('payout', then 'account').
--
-- Riders already had everything else this feature needed with no
-- other schema change: an `fcm_token` column (01_schema.sql, ahead of
-- the Rider App itself) and an area assignment to filter by
-- (`riders.service_area_id` — migration 69; note this is a DIFFERENT
-- column name than restaurants'/customer_addresses' `area_id`, so
-- admin/broadcast.php's area_riders query path uses that name
-- specifically, not a shared column name assumption).
--
-- notifications.recipient_type already includes 'rider' (01_schema.sql
-- from the very start — restaurant/customer/rider/admin) so
-- create_notification('rider', ...) needs no change at all; only this
-- ENUM, which is notification_broadcasts' own separate "who did this
-- broadcast target" history/receipt column, was missing the values.
--
-- Same idempotent CONTINUE-HANDLER-for-1060-style safety this
-- project's ENUM-widening migrations use — MODIFY COLUMN on an ENUM
-- doesn't throw 1060 (that's for duplicate ADD COLUMN), so this one
-- is naturally safe to re-run: re-declaring the same widened ENUM a
-- second time is a no-op.
-- ============================================================

ALTER TABLE notification_broadcasts
    MODIFY COLUMN target_type ENUM(
        'all_customers', 'all_restaurants', 'all_riders',
        'area_customers', 'area_restaurants', 'area_riders'
    ) NOT NULL;
