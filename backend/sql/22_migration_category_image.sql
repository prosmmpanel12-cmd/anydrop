-- Migration 22 — category photo upload (docs/restorent/00_Status.md,
-- app-owner real-device-feedback item 4 of 4, "photo upload for dishes and
-- categories"). menu_items.image_url already existed in 01_schema.sql;
-- menu_categories had no image column at all, hence this migration.
-- Idempotent conditional-ALTER pattern, same as 16_migration_address_photo.sql.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_categories' AND COLUMN_NAME = 'image_url');
SET @sql := IF(@c = 0, 'ALTER TABLE menu_categories ADD COLUMN image_url VARCHAR(255) NULL AFTER name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
