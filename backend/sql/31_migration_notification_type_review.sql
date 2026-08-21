-- ============================================================
-- Anydrop — Migration 31: notifications.type gains 'review'
--
-- Backs the new "Reviews reply" feature (restaurant replies to a
-- customer's review, customer gets notified) — 01_schema.sql's
-- notifications.type ENUM only had 'order','promo','system','security',
-- none of which fit. Widening an ENUM via MODIFY COLUMN is naturally
-- idempotent (re-running it just re-sets the same column definition, no
-- "already exists" error like ADD COLUMN has), so this doesn't need the
-- CONTINUE-HANDLER-for-1060 guard 25_migration_restaurant_rejection_
-- reason.sql needed for an ADD COLUMN. Safe to run any number of times.
-- ============================================================

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('order','promo','system','security','review') NOT NULL DEFAULT 'system';

SHOW COLUMNS FROM notifications LIKE 'type';
