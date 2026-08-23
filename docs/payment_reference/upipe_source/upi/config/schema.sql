-- ============================================================
-- QRPay API v2 — Database Schema (Updated: paytm_key removed)
-- ============================================================

CREATE DATABASE IF NOT EXISTS qrpay_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qrpay_api;

-- ─── User Settings ────────────────────────────────────────────
-- paytm_key column HATA DIYA — sirf mid se auto-verify hoga
CREATE TABLE IF NOT EXISTS user_settings (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    apikey       VARCHAR(80)  NOT NULL UNIQUE,
    upi_id       VARCHAR(100) DEFAULT NULL,
    mid          VARCHAR(100) DEFAULT NULL,
    display_name VARCHAR(100) DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apikey (apikey)
);

-- ─── Payment Orders ──────────────────────────────────────────
-- paytm_key column HATA DIYA — mid se hi verify hoga
CREATE TABLE IF NOT EXISTS payment_orders (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    order_id         VARCHAR(64)    NOT NULL UNIQUE,
    apikey           VARCHAR(80)    NOT NULL,
    upi_id           VARCHAR(100)   NOT NULL,
    mid              VARCHAR(100)   DEFAULT NULL,
    customer_id      VARCHAR(100)   NOT NULL,
    amount           DECIMAL(10,2)  NOT NULL,
    note             VARCHAR(255)   DEFAULT NULL,
    mode             ENUM('auto','manual') DEFAULT 'auto',
    status           ENUM('PENDING','MANUAL_PENDING','PAID','EXPIRED','REJECTED') DEFAULT 'PENDING',
    auto_attempts    TINYINT        DEFAULT 0,
    utr              VARCHAR(50)    DEFAULT NULL,
    utr_submitted_at DATETIME       DEFAULT NULL,
    reject_reason    VARCHAR(255)   DEFAULT NULL,
    gateway_response TEXT           DEFAULT NULL,
    created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    expire_at        DATETIME       DEFAULT NULL,
    verified_at      DATETIME       DEFAULT NULL,
    INDEX idx_order_id (order_id),
    INDEX idx_apikey   (apikey),
    INDEX idx_status   (status),
    INDEX idx_utr      (utr)
);

-- ─── EXISTING DATABASE UPGRADE (run karo agar pehle se tables hain) ──
-- ALTER TABLE user_settings DROP COLUMN IF EXISTS paytm_key;
-- ALTER TABLE payment_orders DROP COLUMN IF EXISTS paytm_key;

-- Cron job (har ghante chalao):
-- 0 * * * * mysql -u root qrpay_api -e "UPDATE payment_orders SET status='EXPIRED' WHERE status='PENDING' AND expire_at < NOW()"
