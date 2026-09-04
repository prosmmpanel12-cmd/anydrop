-- features.md §1 — "Filters and Sorting" bottom sheet's Dietary preference
-- chips (Spicy / Kid's choice) need a real per-item field to filter on.
-- Same pattern as is_bestseller/discount_percent (Known Limitations in
-- Status.md): columns added here, no UI to set them yet — manual
-- `UPDATE menu_items SET is_spicy=1 WHERE id=...` via phpMyAdmin for now,
-- same as the existing bestseller/discount flags. Safe to re-run
-- (guarded ADD COLUMN, same technique 05_migration_categories_and_tags.sql
-- used for restaurants.offer_badge_text).

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'is_spicy'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE menu_items ADD COLUMN is_spicy TINYINT(1) NOT NULL DEFAULT 0 AFTER is_bestseller',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_items' AND COLUMN_NAME = 'is_kids_choice'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE menu_items ADD COLUMN is_kids_choice TINYINT(1) NOT NULL DEFAULT 0 AFTER is_spicy',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
