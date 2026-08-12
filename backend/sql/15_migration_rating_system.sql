-- ------------------------------------------------------------
-- 15. Rating system — one review per order + lookup index
-- ------------------------------------------------------------
-- The `reviews` table itself already exists (01_schema.sql §6), it was
-- part of the original design but nothing ever wrote to it. This migration
-- only adds the constraints needed to safely wire it up:
--   1. UNIQUE on order_id — a customer can rate an order exactly once
--      (the API also checks this, but the DB should enforce it too).
--   2. Index on restaurant_id — for fast "reviews for this restaurant" reads
--      later (e.g. a future restaurant-detail reviews list).
-- Idempotent — safe to re-run (checks INFORMATION_SCHEMA first, same
-- pattern as 06_migration_phase36.sql's rating_count column add).

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'uniq_reviews_order_id'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE reviews ADD UNIQUE KEY uniq_reviews_order_id (order_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx2_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_restaurant'
);
SET @sql2 = IF(@idx2_exists = 0,
  'ALTER TABLE reviews ADD INDEX idx_reviews_restaurant (restaurant_id, created_at DESC)',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
