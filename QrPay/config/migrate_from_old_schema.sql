-- ============================================================
-- QrPay — Migration from the old "UPI PE" schema
-- (old tables: user_settings keyed by apikey, payment_orders
--  keyed by apikey, no developers/plans/subscriptions at all)
-- ============================================================
--
-- Run schema.sql FIRST to create the new tables, then run this.
-- This script assumes your OLD tables are named:
--   old_user_settings, old_payment_orders
-- Rename your existing tables to those before running, e.g.:
--   RENAME TABLE user_settings TO old_user_settings;
--   RENAME TABLE payment_orders TO old_payment_orders;
--
-- IMPORTANT: back up your database before running this. Test on
-- a copy first — this script does not attempt to be re-runnable.
-- ============================================================

USE qrpay;

-- ─────────────────────────────────────────────────────────────
-- Step 1: Create a `developers` row for every distinct apikey
-- seen in the old system. Name/email/mobile are unknown for old
-- accounts — placeholders are inserted, and password_hash is set
-- to a value that CANNOT be matched by any real password (a
-- random hash of random bytes), so login is impossible until each
-- account goes through "Forgot password" to set a real one. This
-- MUST be communicated to migrated developers separately (email
-- blast, in-app banner, etc.) — they cannot self-serve otherwise.
-- ─────────────────────────────────────────────────────────────
INSERT INTO developers (name, email, mobile_number, password_hash, email_verified, two_fa_enabled, apikey, status, created_at)
SELECT
    'Unmigrated Developer' AS name,
    CONCAT('unmigrated+', apikey, '@qrpay.local') AS email,
    CONCAT('0000000', LPAD(ROW_NUMBER() OVER (ORDER BY apikey), 3, '0')) AS mobile_number,
    -- Unusable placeholder hash (password_hash() of random bytes) —
    -- forces every migrated account through "Forgot password".
    SHA2(CONCAT('unmigrated-', apikey, RAND()), 256) AS password_hash,
    0 AS email_verified,
    0 AS two_fa_enabled,
    apikey,
    'active',
    MIN(created_at)
FROM old_user_settings
GROUP BY apikey;

-- ─────────────────────────────────────────────────────────────
-- Step 2: Migrate user_settings (UPI ID / MID) linked to the
-- new developer_id instead of raw apikey.
-- ─────────────────────────────────────────────────────────────
INSERT INTO user_settings (developer_id, upi_id, mid, display_name, created_at, updated_at)
SELECT
    d.id,
    o.upi_id,
    o.mid,
    o.display_name,
    o.created_at,
    o.updated_at
FROM old_user_settings o
JOIN developers d ON d.apikey = o.apikey;

-- ─────────────────────────────────────────────────────────────
-- Step 3: Migrate payment_orders, linked to developer_id.
-- All old orders are tagged as 'customer_payment' (the old
-- system had no concept of subscription purchases).
-- Deep-link fields never existed in storage, so nothing to drop.
-- ─────────────────────────────────────────────────────────────
INSERT INTO payment_orders (
    order_id, developer_id, order_purpose, upi_id, mid, customer_id,
    amount, note, mode, status, auto_attempts, utr, utr_submitted_at,
    reject_reason, gateway_response, created_at, expire_at, verified_at
)
SELECT
    o.order_id,
    d.id,
    'customer_payment',
    o.upi_id,
    o.mid,
    o.customer_id,
    o.amount,
    o.note,
    o.mode,
    o.status,
    o.auto_attempts,
    o.utr,
    o.utr_submitted_at,
    o.reject_reason,
    o.gateway_response,
    o.created_at,
    o.expire_at,
    o.verified_at
FROM old_payment_orders o
JOIN developers d ON d.apikey = o.apikey;

-- ─────────────────────────────────────────────────────────────
-- Step 4: Every migrated developer gets auto-subscribed to the
-- 'free' plan (100-year expiry, same as a fresh signup — see
-- auth/signup.php). Daily/monthly credit counters simply start
-- empty; past order history does NOT retroactively consume them.
-- ─────────────────────────────────────────────────────────────
INSERT INTO subscriptions (developer_id, plan_id, billing_cycle, starts_at, expires_at, status)
SELECT
    d.id,
    (SELECT id FROM plans WHERE plan_type = 'free' AND is_active = 1 LIMIT 1),
    'monthly',
    NOW(),
    DATE_ADD(NOW(), INTERVAL 100 YEAR),
    'active'
FROM developers d;

-- ─────────────────────────────────────────────────────────────
-- Step 5: sanity checks — run these manually and compare counts
-- before dropping the old tables.
-- ─────────────────────────────────────────────────────────────
-- SELECT COUNT(*) FROM old_user_settings;
-- SELECT COUNT(*) FROM user_settings;
-- SELECT COUNT(*) FROM old_payment_orders;
-- SELECT COUNT(*) FROM payment_orders;

-- Only after verifying the counts match and spot-checking a few
-- rows, drop the old tables manually:
-- DROP TABLE old_payment_orders;
-- DROP TABLE old_user_settings;
