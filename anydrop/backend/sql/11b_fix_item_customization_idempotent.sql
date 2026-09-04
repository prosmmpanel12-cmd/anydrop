-- ============================================================
-- Anydrop — Migration 11b: idempotent fix-up for migration 11
--
-- You hit "Duplicate column name 'special_instructions'" running
-- 11_migration_item_customization.sql — that means it (or part of it) was
-- already applied at some point before, so the plain ALTER TABLE ... ADD
-- COLUMN statements fail the second time around.
--
-- This script checks information_schema.columns first and only adds a
-- column if it's actually missing, for all three columns migration 11
-- was supposed to add:
--   - order_items.special_instructions
--   - customer_cart_items.addon_ids
--   - customer_cart_items.special_instructions
--
-- Safe to run as many times as you want, no matter which of the three
-- already exist. Run this INSTEAD of re-running
-- 11_migration_item_customization.sql directly.
-- ============================================================

SET @db := DATABASE();

-- order_items.special_instructions
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = @db AND table_name = 'order_items'
           AND column_name = 'special_instructions') = 0,
        'ALTER TABLE order_items ADD COLUMN special_instructions TEXT NULL AFTER addons_json',
        'SELECT "order_items.special_instructions already exists — skipped"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- customer_cart_items.addon_ids
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = @db AND table_name = 'customer_cart_items'
           AND column_name = 'addon_ids') = 0,
        'ALTER TABLE customer_cart_items ADD COLUMN addon_ids TEXT NULL AFTER quantity',
        'SELECT "customer_cart_items.addon_ids already exists — skipped"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- customer_cart_items.special_instructions
SET @sql := (
    SELECT IF(
        (SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = @db AND table_name = 'customer_cart_items'
           AND column_name = 'special_instructions') = 0,
        'ALTER TABLE customer_cart_items ADD COLUMN special_instructions TEXT NULL AFTER addon_ids',
        'SELECT "customer_cart_items.special_instructions already exists — skipped"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Confirm final state
SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = @db
  AND (
      (table_name = 'order_items' AND column_name = 'special_instructions')
      OR (table_name = 'customer_cart_items' AND column_name IN ('addon_ids', 'special_instructions'))
  );
