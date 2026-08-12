-- Migration 16 — H6 part 2: door/building photo on customer_addresses.
-- New, not in the original §7 spec — explicitly requested alongside the
-- map pin-drop screen (see docs/12_Handover_H6_Map_PinDrop_Photo.md).
-- Idempotent conditional-ALTER pattern, same as 06_migration_phase36.sql.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'photo_url');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN photo_url VARCHAR(255) NULL AFTER receiver_phone', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
