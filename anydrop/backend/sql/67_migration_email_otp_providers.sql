-- Migration 67 — Email OTP Multi-Provider Delivery (docs/19 §7,
-- AnyDrop_Email_OTP_MultiProvider_Plan.md)
--
-- Adds the provider registry + delivery log tables. No app_settings
-- changes needed — RBAC permission `email_providers_manage` was already
-- seeded by migration 29, ahead of this feature actually being built.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE.

CREATE TABLE IF NOT EXISTS email_otp_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    driver_key VARCHAR(50) NOT NULL UNIQUE,
    config_json TEXT NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    daily_quota INT NULL,
    monthly_quota INT NULL,
    daily_used INT NOT NULL DEFAULT 0,
    monthly_used INT NOT NULL DEFAULT 0,
    quota_reset_date DATE NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    consecutive_failures INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_otp_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(150) NOT NULL,
    purpose VARCHAR(30) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error_reason VARCHAR(100) NULL,
    provider_http_status INT NULL,
    provider_message_id VARCHAR(150) NULL,
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otplog_provider FOREIGN KEY (provider_id)
        REFERENCES email_otp_providers(id) ON DELETE SET NULL,
    INDEX idx_otplog_created (created_at),
    INDEX idx_otplog_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the 6 providers from the plan. Priority = try order (lower first).
-- config_json starts empty ({"api_key":"","sender_email":"","sender_name":""})
-- — real keys are entered from the Admin Panel (Email Providers screen),
-- never seeded here / never committed to SQL or Git.
-- All start is_active = 0 so nothing fires until an admin actually pastes
-- a key in and flips it on. Mailjet additionally stays last + optional
-- per the plan ("OPTIONAL / NOT CONFIRMED").
INSERT INTO email_otp_providers (name, driver_key, config_json, priority, is_active, daily_quota, monthly_quota, quota_reset_date)
VALUES
    ('Resend',     'resend',     '{"api_key":"","sender_email":"","sender_name":"AnyDrop"}', 1, 0, NULL, NULL, CURDATE()),
    ('Brevo',      'brevo',      '{"api_key":"","sender_email":"","sender_name":"AnyDrop"}', 2, 0, NULL, NULL, CURDATE()),
    ('MailerSend', 'mailersend', '{"api_key":"","sender_email":"","sender_name":"AnyDrop"}', 3, 0, NULL, NULL, CURDATE()),
    ('Sendix',     'sendix',     '{"api_key":"","sender_email":"","sender_name":"AnyDrop"}', 4, 0, NULL, NULL, CURDATE()),
    ('Maileroo',   'maileroo',   '{"api_key":"","sender_email":"","sender_name":"AnyDrop"}', 5, 0, NULL, NULL, CURDATE()),
    ('Mailjet',    'mailjet',    '{"api_key":"","api_secret":"","sender_email":"","sender_name":"AnyDrop"}', 6, 0, NULL, NULL, CURDATE())
ON DUPLICATE KEY UPDATE driver_key = driver_key;
