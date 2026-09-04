-- Migration 18 — Coupons: is_public flag.
-- Adds coupons.is_public (default 1, so every existing coupon keeps
-- today's behaviour of being suggested on the customer "view all
-- offers" screen). New restaurant-created coupons (Phase H, not yet
-- built) are expected to default this to 0 at creation time so a
-- restaurant's private/targeted codes aren't auto-suggested — but that
-- write path doesn't exist yet, this migration only adds the column and
-- flips coupons/list.php to filter by it.
-- Idempotent conditional-ALTER pattern, same as 06/16/17.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'is_public');
SET @sql := IF(@c = 0, 'ALTER TABLE coupons ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
