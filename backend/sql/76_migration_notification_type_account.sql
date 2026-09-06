-- ============================================================
-- Anydrop — Migration 76: notifications.type — add 'account'
--
-- Deep-plan §23 (Rider Notifications), Account category. This
-- session wired create_notification('rider', ..., 'account', ...)
-- calls into admin/riders.php (approve/reject/suspend/document-
-- verify/document-reject) and the type value 'account' does not
-- exist in the notifications.type ENUM yet — same gap migration 74's
-- own header called out when it added 'payout' for the equivalent
-- reason. Without this migration, every one of those new
-- create_notification() calls throws inside its own try/catch
-- (caught, logged to error_log, non-fatal to the admin action that
-- triggered it — see lib/notifications.php's own header) and the
-- bell-row silently never gets written; the FCM push step runs
-- independently of the bell-row try/catch and is unaffected either
-- way, but the in-app notification history would be empty for these
-- events until this migration runs.
--
-- 'account' is deliberately actor-neutral (same reasoning migration
-- 74 gives for 'payout' over a customer/restaurant/rider-specific
-- value) — it covers this rider flow now and can cover a future
-- customer/restaurant account-status notification later without
-- growing a new value per actor.
--
-- Same idempotent MODIFY COLUMN pattern as migrations 31/43/74 —
-- MODIFY COLUMN is naturally re-runnable (it just re-sets the same
-- definition), so no CONTINUE HANDLER guard is needed here, matching
-- those three migrations' own style exactly.
-- ============================================================

ALTER TABLE notifications
    MODIFY COLUMN type ENUM('order','promo','system','security','review','wallet','payout','account') NOT NULL DEFAULT 'system';

-- Confirm final state — uses SHOW, not information_schema (same
-- constraint noted in migration 60's own footer: this environment's
-- DB user can't read information_schema).
SHOW COLUMNS FROM notifications LIKE 'type';
