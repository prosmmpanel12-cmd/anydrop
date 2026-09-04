-- ============================================================
-- Anydrop — Migration 70: Generic per-IP signup rate limiting
--
-- Context: doc 79 (Rider App Phase 1) flagged "no rate-limit/abuse
-- guard on rider-signup.php beyond the existing OTP cooldown" as a
-- known gap, explicitly noting restaurant-signup.php has the exact
-- same gap and it's not new to that session. Rather than build a
-- rider-only table, this migration adds one generic table any
-- self-signup endpoint can log against — `endpoint` distinguishes
-- rider/restaurant/customer if this is ever reused there too.
--
-- Deliberately modeled on migration 51's admin_login_attempts shape
-- (one row per attempt, not a running counter, so a rolling window is
-- a plain COUNT(*) WHERE created_at > NOW() - INTERVAL query — no
-- separate reset job needed) rather than inventing a new pattern.
--
-- This is a NEW table only — no ALTER TABLE, so there's nothing for
-- the usual "duplicate column" CONTINUE HANDLER to guard here;
-- CREATE TABLE IF NOT EXISTS is already idempotent on its own, same
-- as every other brand-new-table migration in this project (29, 30,
-- 51 itself, etc).
-- ============================================================

CREATE TABLE IF NOT EXISTS signup_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(40) NOT NULL,       -- e.g. 'rider_signup'
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(150) NULL,             -- logged for audit/debugging; not used in the throttle query itself
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_signup_attempts_endpoint_ip_time (endpoint, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Confirm final state — uses SHOW, not information_schema (this
-- environment's DB user can't read information_schema).
SHOW COLUMNS FROM signup_attempts;
