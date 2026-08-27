-- ============================================================
-- Anydrop — Migration 51: Admin Login Rate Limiting
--
-- Implements docs/AnyDrop_Admin_Management_Plan.md §27 P1.2 (Login
-- Rate Limiting) — admin login currently has zero brute-force
-- protection (grepped backend/admin/login.php and lib/admin_auth.php
-- before writing this: no attempt counter, no lockout, no IP
-- throttling anywhere).
--
-- Two independent layers, both required per the plan doc:
--   1. Per-account lockout — admins.failed_login_attempts +
--      admins.locked_until. Cheap (2 columns on a table already
--      loaded on every login attempt), stops credential-stuffing a
--      single known username.
--   2. Per-IP throttling — new admin_login_attempts table, one row
--      per attempt (not a running counter) so a rolling time window
--      can be queried directly (COUNT(*) WHERE created_at > NOW() -
--      INTERVAL 15 MINUTE) without a separate reset job. Stops
--      spraying many usernames from one IP, which a per-account
--      counter alone can't catch. Rows are cheap and short-lived by
--      access pattern (only ever queried within the last ~15-60 min
--      window) — a periodic cleanup of rows older than a day is a
--      fine follow-up, not required for correctness.
--
-- Same idempotent CONTINUE-HANDLER pattern as migration 29 (this
-- environment's DB user can't read information_schema, so swallowing
-- the specific "already exists" MySQL error code is the safe re-run
-- strategy). Safe to run any number of times, in any partial-prior
-- state.
-- ============================================================

-- ---------- 1. admins: per-account lockout columns ----------

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_51_add_failed_attempts $$
CREATE PROCEDURE anydrop_migration_51_add_failed_attempts()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE admins ADD COLUMN failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_51_add_locked_until $$
CREATE PROCEDURE anydrop_migration_51_add_locked_until()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE admins ADD COLUMN locked_until TIMESTAMP NULL AFTER failed_login_attempts;
END $$

DELIMITER ;

CALL anydrop_migration_51_add_failed_attempts();
CALL anydrop_migration_51_add_locked_until();

DROP PROCEDURE IF EXISTS anydrop_migration_51_add_failed_attempts;
DROP PROCEDURE IF EXISTS anydrop_migration_51_add_locked_until;

-- ---------- 2. Per-IP attempt log ----------
-- One row per attempt (success or failure) — success rows let a
-- correct login from an IP that was also guessing other usernames
-- still show up in the same audit trail; only failures count toward
-- the throttle threshold (enforced in application code, not here).

CREATE TABLE IF NOT EXISTS admin_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alogin_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Confirm final state — uses SHOW, not information_schema (this
-- environment's DB user can't read information_schema).
SHOW COLUMNS FROM admins;
SHOW COLUMNS FROM admin_login_attempts;
