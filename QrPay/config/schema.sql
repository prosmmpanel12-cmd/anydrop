-- ============================================================
-- QrPay — Database Schema (Phase 1)
-- Fresh schema for the rebuilt Email+OTP / plan-based gateway.
-- ============================================================

CREATE DATABASE IF NOT EXISTS qrpay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qrpay;

-- ─────────────────────────────────────────────────────────────
-- Developers (dashboard identity — separate from the API key)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS developers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150)  NOT NULL,
    email          VARCHAR(190)  NOT NULL UNIQUE,
    mobile_number  VARCHAR(20)   NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL,
    email_verified TINYINT(1)    NOT NULL DEFAULT 0,
    two_fa_enabled TINYINT(1)    NOT NULL DEFAULT 0,  -- per-user toggle, set from panel/settings
    apikey         VARCHAR(80)   NOT NULL UNIQUE,
    is_admin       TINYINT(1)    NOT NULL DEFAULT 0,
    status         ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apikey (apikey),
    INDEX idx_email  (email),
    INDEX idx_mobile (mobile_number)
);

-- ─────────────────────────────────────────────────────────────
-- OTP codes — used ONLY for the 2FA second login step now.
-- Signup/login identity itself is email+password (see auth/signup.php,
-- auth/login.php). Only the HASH of the OTP is stored, never plaintext.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS otp_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    otp_hash    VARCHAR(255) NOT NULL,
    purpose     ENUM('2fa_login') NOT NULL DEFAULT '2fa_login',
    expires_at  DATETIME     NOT NULL,
    attempts    TINYINT      NOT NULL DEFAULT 0,
    consumed    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_active (email, consumed, expires_at)
);

-- ─────────────────────────────────────────────────────────────
-- Email verification tokens — issued at signup ONLY when
-- admin_settings.email_verification_enabled = 1. Link-based (not OTP):
-- long random token, hashed at rest, emailed as a clickable URL.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    developer_id  INT          NOT NULL,
    token_hash    VARCHAR(255) NOT NULL,
    expires_at    DATETIME     NOT NULL,
    consumed      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    INDEX idx_dev_active (developer_id, consumed, expires_at)
);

-- ─────────────────────────────────────────────────────────────
-- Password reset tokens — "forgot password" flow. Long random
-- token, hashed at rest, single-use, short expiry.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    developer_id  INT          NOT NULL,
    token_hash    VARCHAR(255) NOT NULL,
    expires_at    DATETIME     NOT NULL,
    consumed      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    INDEX idx_dev_active (developer_id, consumed, expires_at)
);

-- ─────────────────────────────────────────────────────────────
-- Plans (fully admin-editable — nothing about pricing is hardcoded)
-- payment_limit = NULL means unlimited (Premium)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(50)    NOT NULL UNIQUE,   -- 'free' | 'basic' | 'pro' | 'premium'
    display_name            VARCHAR(100)   NOT NULL,
    plan_type               ENUM('free','paid') NOT NULL DEFAULT 'paid',
    monthly_price           DECIMAL(10,2)  NOT NULL,
    yearly_price            DECIMAL(10,2)  NOT NULL,
    yearly_discount_percent DECIMAL(5,2)   NOT NULL DEFAULT 0,
    payment_limit           INT            DEFAULT NULL,      -- verified payments/month; NULL = unlimited
    daily_credit_limit      INT            DEFAULT NULL,      -- verified payments/day; only set for plan_type='free'
    is_active               TINYINT(1)     NOT NULL DEFAULT 1,
    sort_order              TINYINT        NOT NULL DEFAULT 0,
    created_at              TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────────────────────
-- Coupons
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coupons (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(40)    NOT NULL UNIQUE,
    discount_type      ENUM('flat','percent') NOT NULL,
    discount_value     DECIMAL(10,2)  NOT NULL,
    applicable_plans   VARCHAR(255)   DEFAULT NULL,  -- comma-separated plan ids; NULL = all plans
    valid_from         DATETIME       NOT NULL,
    valid_till         DATETIME       NOT NULL,
    max_uses           INT            DEFAULT NULL,  -- NULL = unlimited uses
    used_count         INT            NOT NULL DEFAULT 0,
    is_active          TINYINT(1)     NOT NULL DEFAULT 1,
    created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code)
);

-- ─────────────────────────────────────────────────────────────
-- Subscriptions (a developer's purchased plan period)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    developer_id  INT            NOT NULL,
    plan_id       INT            NOT NULL,
    billing_cycle ENUM('monthly','yearly') NOT NULL,
    starts_at     DATETIME       NOT NULL,
    expires_at    DATETIME       NOT NULL,
    status        ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    INDEX idx_dev_status (developer_id, status),
    INDEX idx_expires (expires_at)
);

-- ─────────────────────────────────────────────────────────────
-- Usage counters — one row per developer per billing cycle.
-- Only PAID orders increment verified_count (enforced in app code).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usage_counters (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    developer_id    INT       NOT NULL,
    subscription_id INT       DEFAULT NULL,   -- which subscription this cycle belongs to
    cycle_start     DATETIME  NOT NULL,
    cycle_end       DATETIME  NOT NULL,
    verified_count  INT       NOT NULL DEFAULT 0,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
    INDEX idx_dev_cycle (developer_id, cycle_start, cycle_end)
);

-- ─────────────────────────────────────────────────────────────
-- Daily usage counters — ONLY consulted for developers whose active
-- subscription is on the 'free' plan (see core/plan_limits.php).
-- One row per developer per CALENDAR DAY (resets at midnight, no cron
-- needed — a new row is lazily created for each new date).
-- The free plan's monthly cap (300) is enforced separately via the
-- existing usage_counters table, same mechanism as every paid plan.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS daily_usage_counters (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    developer_id INT       NOT NULL,
    usage_date   DATE      NOT NULL,
    used_count   INT       NOT NULL DEFAULT 0,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    UNIQUE KEY uniq_dev_date (developer_id, usage_date)
);

-- ─────────────────────────────────────────────────────────────
-- Admin settings — the UPI ID / MID QrPay itself gets paid on,
-- used only for order_purpose = 'subscription_purchase'.
-- Singleton table (always id = 1).
-- ─────────────────────────────────────────────────────────────
-- NOTE: the CHECK constraint below is enforced on MySQL 8.0.16+/modern MariaDB,
-- but is silently IGNORED on older MySQL/MariaDB (common on shared hosts like
-- infinityfree). Don't rely on it alone — app code must always read/write id=1
-- explicitly (every query in this project does).
CREATE TABLE IF NOT EXISTS admin_settings (
    id           TINYINT PRIMARY KEY DEFAULT 1,
    owner_upi_id VARCHAR(100) NOT NULL,
    owner_mid    VARCHAR(100) DEFAULT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT 'QrPay',
    -- System-wide toggle (admin-controlled, NOT per-user): when 1, every
    -- new signup must click an emailed verification link before they can
    -- log in. 2FA, by contrast, is a per-user toggle on `developers`.
    email_verification_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_singleton CHECK (id = 1)
);

-- ─────────────────────────────────────────────────────────────
-- Per-developer merchant settings (their OWN UPI ID / MID,
-- used for order_purpose = 'customer_payment')
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_settings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    developer_id INT          NOT NULL UNIQUE,
    upi_id       VARCHAR(100) DEFAULT NULL,
    mid          VARCHAR(100) DEFAULT NULL,
    paytm_merchant_key VARCHAR(255) DEFAULT NULL,  -- per-developer, not a global constant
    display_name VARCHAR(100) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (developer_id) REFERENCES developers(id),
    INDEX idx_developer (developer_id)
);

-- ─────────────────────────────────────────────────────────────
-- Payment orders — QR-only. No deep-link fields; deep links were
-- generated in the API response only, never stored, and are now
-- removed from the response entirely (Phase 4).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payment_orders (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    order_id          VARCHAR(64)    NOT NULL UNIQUE,
    developer_id      INT            NOT NULL,
    order_purpose     ENUM('customer_payment','subscription_purchase') NOT NULL DEFAULT 'customer_payment',

    -- which UPI ID/MID this order was raised against:
    -- customer_payment -> the developer's own user_settings
    -- subscription_purchase -> admin_settings (QrPay's own)
    upi_id            VARCHAR(100)   NOT NULL,
    mid               VARCHAR(100)   DEFAULT NULL,

    customer_id       VARCHAR(100)   NOT NULL,   -- for subscription_purchase, this is the developer's own id
    amount            DECIMAL(10,2)  NOT NULL,
    note              VARCHAR(255)   DEFAULT NULL,
    mode              ENUM('auto','manual') DEFAULT 'auto',
    status            ENUM('PENDING','MANUAL_PENDING','PAID','EXPIRED','REJECTED') DEFAULT 'PENDING',
    auto_attempts     TINYINT        DEFAULT 0,
    utr               VARCHAR(50)    DEFAULT NULL,
    utr_submitted_at  DATETIME       DEFAULT NULL,
    reject_reason     VARCHAR(255)   DEFAULT NULL,
    gateway_response  TEXT           DEFAULT NULL,
    txn_id            VARCHAR(100)   DEFAULT NULL,  -- Paytm TXNID, needed later for refunds

    -- only relevant when order_purpose = 'subscription_purchase'
    sub_plan_id       INT            DEFAULT NULL,
    sub_billing_cycle ENUM('monthly','yearly') DEFAULT NULL,
    sub_coupon_code   VARCHAR(40)    DEFAULT NULL,

    -- was this specific order covered by the developer's free-plan
    -- credits (daily 10 / monthly 300), as opposed to a paid plan cycle?
    is_free_plan_order TINYINT(1)    NOT NULL DEFAULT 0,

    created_at        TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    expire_at         DATETIME       DEFAULT NULL,
    verified_at       DATETIME       DEFAULT NULL,

    FOREIGN KEY (developer_id) REFERENCES developers(id),
    FOREIGN KEY (sub_plan_id) REFERENCES plans(id),
    INDEX idx_order_id     (order_id),
    INDEX idx_developer    (developer_id),
    INDEX idx_status       (status),
    INDEX idx_utr          (utr),
    INDEX idx_purpose      (order_purpose)
);

-- ─────────────────────────────────────────────────────────────
-- Seed data — starter plans (admin can edit all of this later)
-- ─────────────────────────────────────────────────────────────
INSERT INTO plans (name, display_name, plan_type, monthly_price, yearly_price, yearly_discount_percent, payment_limit, daily_credit_limit, sort_order)
VALUES
    ('free',    'Free',    'free', 0.00,    0.00,     0.00,  300,  10,   0),
    ('basic',   'Basic',   'paid', 299.00,  2999.00,  16.67, 200,  NULL, 1),
    ('pro',     'Pro',     'paid', 999.00,  9999.00,  16.60, 1000, NULL, 2),
    ('premium', 'Premium', 'paid', 2499.00, 24999.00, 16.67, NULL, NULL, 3)
ON DUPLICATE KEY UPDATE name = name;

-- admin_settings must be filled in manually before subscription
-- purchases can work — placeholder row so the app doesn't crash
-- on a missing singleton:
INSERT INTO admin_settings (id, owner_upi_id, owner_mid, display_name)
VALUES (1, 'CHANGE-ME@upi', NULL, 'QrPay')
ON DUPLICATE KEY UPDATE id = id;

-- ─────────────────────────────────────────────────────────────
-- Cron reference (Phase 8 will add the actual script):
-- 0 * * * * php /path/to/cron/expire_subscriptions.php
-- 0 * * * * mysql -u root qrpay -e "UPDATE payment_orders SET status='EXPIRED' WHERE status='PENDING' AND expire_at < NOW()"
--
-- NOTE: daily_usage_counters needs NO cron — it's keyed by usage_date,
-- so a new day's row is lazily created the first time it's touched.
-- The free plan's own `subscriptions` row (100-year expiry, see
-- auth/signup.php) should be excluded from expire_subscriptions.php's
-- sweep — it is never meant to expire.
-- ─────────────────────────────────────────────────────────────
