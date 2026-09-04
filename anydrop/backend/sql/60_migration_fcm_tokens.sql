-- ============================================================
-- Anydrop — Migration 60: fcm_token on customers + restaurants
--
-- Backs real FCM push delivery (person-requested this session, all 3
-- apps + admin broadcast). `riders.fcm_token` already existed in
-- 01_schema.sql (added ahead of the Rider App itself, which is still
-- Phase 4/not built) — customers and restaurants never got the same
-- column, since nothing before this session ever needed to actually
-- send a push. This migration only adds the column; it does not wire
-- anything to populate or read it — see backend/lib/fcm.php and the
-- new fcm-token-update.php endpoints (this same session) for that.
--
-- Nullable, no default — a fresh install/older app build without the
-- new token-registration call simply never sets this, and
-- create_notification()'s new FCM-send step (see lib/notifications.php)
-- already treats a NULL token as "skip the push, bell-row still
-- writes" rather than an error.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as migration 25 —
-- this environment's DB user can't read information_schema, so
-- swallowing MySQL error 1060 (ER_DUP_FIELDNAME) is the safe way to
-- make an ADD COLUMN re-runnable. Safe to run any number of times, in
-- any partial-prior-state.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_60_safe $$

CREATE PROCEDURE anydrop_migration_60_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE customers
        ADD COLUMN fcm_token VARCHAR(255) NULL AFTER login_type;

    ALTER TABLE restaurants
        ADD COLUMN fcm_token VARCHAR(255) NULL AFTER owner_email;
END $$

DELIMITER ;

CALL anydrop_migration_60_safe();
DROP PROCEDURE anydrop_migration_60_safe;

-- Confirm final state — uses SHOW, not information_schema.
SHOW COLUMNS FROM customers LIKE 'fcm_token';
SHOW COLUMNS FROM restaurants LIKE 'fcm_token';
