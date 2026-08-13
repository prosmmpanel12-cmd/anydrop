-- Migration 17 — I4: Scheduled orders ("Schedule for later"), same-day only
-- per app owner's explicit scope call (features.md §I4). Adds:
--   1. orders.scheduled_for — nullable DATETIME. NULL = "Now" order (today's
--      default/only behaviour before this migration). Non-null = the
--      customer-picked same-day delivery slot, validated server-side in
--      orders/create.php against the restaurant's opening_time/closing_time
--      before the order is ever written.
--   2. customer_cart_items.scheduled_for — mirrors orders.scheduled_for so a
--      chosen slot survives an app kill/restart via cart-sync.php, same
--      per-row-repeated pattern coupon_code already uses on this table (see
--      07_migration_cart_persistence.sql's comment — a cart is never large
--      enough for the per-row duplication to matter).
-- Idempotent conditional-ALTER pattern, same as 06/16.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'scheduled_for');
SET @sql := IF(@c = 0, 'ALTER TABLE orders ADD COLUMN scheduled_for DATETIME NULL AFTER delivery_instructions', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_cart_items' AND COLUMN_NAME = 'scheduled_for');
SET @sql2 := IF(@c2 = 0, 'ALTER TABLE customer_cart_items ADD COLUMN scheduled_for DATETIME NULL AFTER coupon_code', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Restaurant/rider-side flows can filter/sort on this once that UI exists
-- (not built this session, see docs/Status.md) — cheap to add now.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_scheduled_for');
SET @sql3 := IF(@idx = 0, 'ALTER TABLE orders ADD INDEX idx_orders_scheduled_for (scheduled_for)', 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;
