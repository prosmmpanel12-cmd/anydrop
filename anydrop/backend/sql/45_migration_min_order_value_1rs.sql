-- ============================================================
-- Anydrop — Migration 45: Minimum cart value = ₹1 (app owner
-- request 2026-08-23)
--
-- Cart minimum is enforced per-restaurant (restaurants.min_order_amount
-- — see price_cart()'s below_min_order_amount check in lib/orders.php),
-- NOT by a single global setting. There's also an area-level FLOOR
-- (area_pricing_rules.min_order_amount / the platform-wide
-- default_min_order_amount app_setting — see lib/delivery_pricing.php's
-- get_min_order_floor_for_area_id()) that a restaurant is not allowed
-- to set its own min_order_amount below (restaurant/profile-update.php
-- enforces this). So setting the floor(s) too, not just the
-- restaurant column, or a floor left above ₹1 would silently block a
-- restaurant from actually saving ₹1 later.
--
-- Safe to re-run.
-- ============================================================

-- 1) Every existing restaurant's own minimum → ₹1.
UPDATE restaurants SET min_order_amount = 1;

-- 2) New restaurants going forward also start at ₹1, not ₹0.
ALTER TABLE restaurants
    ALTER COLUMN min_order_amount SET DEFAULT 1;

-- 3) Platform-wide floor (used whenever an area has no of its own
-- override) → ₹1, so it can never block a restaurant's ₹1 from #1.
UPDATE app_settings SET value = '1' WHERE `key` = 'default_min_order_amount';
INSERT INTO app_settings (`key`, `value`, description)
VALUES ('default_min_order_amount', '1', 'Platform-wide floor for a restaurant''s own min_order_amount')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- 4) Any area-specific floor higher than ₹1 would still block a
-- restaurant from saving ₹1 for customers in that area — cap those
-- down to ₹1 too. (Areas with no override, or an override already
-- <= ₹1, are untouched.)
UPDATE area_pricing_rules
SET min_order_amount = 1
WHERE is_active = 1 AND min_order_amount IS NOT NULL AND min_order_amount > 1;
