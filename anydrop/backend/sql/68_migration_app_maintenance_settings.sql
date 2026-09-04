-- Anydrop — Migration 68: per-app maintenance-mode settings.
--
-- Backs admin/app-settings.php's new Maintenance Mode section and the
-- maintenance_mode/maintenance_message fields api/v1/system/app-version.php
-- now returns. Replaces the old global `maintenance_mode` key from
-- 01_schema.sql, which was seeded but never read anywhere in the
-- codebase — per-app keys let the Customer app keep running while,
-- say, only the Restaurant app is down for maintenance.
--
-- Safe to re-run (ON DUPLICATE KEY UPDATE is a no-op here since it
-- sets each column to its own current value).

INSERT INTO app_settings (`key`, `value`, description) VALUES
('maintenance_mode_customer', '0', 'Set to 1 to show the Customer app a maintenance screen'),
('maintenance_message_customer', 'We''re currently doing scheduled maintenance. Please check back shortly.', 'Message shown to Customer app users while maintenance_mode_customer is on'),
('maintenance_mode_restaurant', '0', 'Set to 1 to show the Restaurant app a maintenance screen'),
('maintenance_message_restaurant', 'We''re currently doing scheduled maintenance. Please check back shortly.', 'Message shown to Restaurant app users while maintenance_mode_restaurant is on'),
('maintenance_mode_rider', '0', 'Set to 1 to show the Rider app a maintenance screen'),
('maintenance_message_rider', 'We''re currently doing scheduled maintenance. Please check back shortly.', 'Message shown to Rider app users while maintenance_mode_rider is on')
ON DUPLICATE KEY UPDATE `value` = `value`;
