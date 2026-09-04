-- Anydrop — Migration: app-version settings for splash-screen update check
-- Run this once against the existing `anydrop` database (phpMyAdmin > Import,
-- or SQL tab > paste and run). Safe to re-run (ON DUPLICATE KEY UPDATE).

INSERT INTO app_settings (`key`, `value`, description) VALUES
('latest_app_version_customer', '1', 'Newest available Customer app version code'),
('latest_app_version_name_customer', '0.1-phase2', 'Newest Customer app version display name'),
('update_message_customer', 'A new version of Anydrop is available with improvements and fixes.', 'Message shown in the Customer app update popup'),
('update_url_customer', '', 'Direct APK / Play Store link for the Customer app (fill in once hosted)'),

('latest_app_version_restaurant', '1', 'Newest available Restaurant app version code'),
('latest_app_version_name_restaurant', '0.1', 'Newest Restaurant app version display name'),
('update_message_restaurant', 'A new version of Anydrop Restaurant is available.', 'Message shown in the Restaurant app update popup'),
('update_url_restaurant', '', 'Direct APK / Play Store link for the Restaurant app'),

('latest_app_version_rider', '1', 'Newest available Rider app version code'),
('latest_app_version_name_rider', '0.1', 'Newest Rider app version display name'),
('update_message_rider', 'A new version of Anydrop Rider is available.', 'Message shown in the Rider app update popup'),
('update_url_rider', '', 'Direct APK / Play Store link for the Rider app'),

('min_app_version_restaurant', '1', 'Minimum supported Restaurant app version code'),
('min_app_version_rider', '1', 'Minimum supported Rider app version code')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Note: `min_app_version_customer` already exists from 01_schema.sql — not re-inserted here.
