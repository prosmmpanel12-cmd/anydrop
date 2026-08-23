-- Migration 32 — Admin Category Management, restaurant-categories half
-- (recall.md item 16 / docs/19_Admin_Panel_Full_Spec_And_Payment_Email_
-- Architecture_2026-08-14.md — Category Management section).
--
-- recall.md item 16 draws an explicit distinction between two kinds of
-- "category" that this project had been conflating risk on:
--
--   restaurant_categories -- business TYPE (Cafe / Bakery / Sweet Shop /
--                             Pharmacy / Grocery / Restaurant). One per
--                             restaurant. Admin-managed only.
--
--   food_categories       -- Home-screen food-type chips (Pizza / Burger /
--                             Biryani...). Already exists as of migration
--                             05 (backend/sql/05_migration_categories_and_
--                             tags.sql) but was DB-seeded only, no admin
--                             UI. This migration doesn't touch its schema,
--                             only backend/admin/categories.php (separate
--                             file) adds CRUD on top of the existing table.
--
-- Do NOT confuse either of these with `menu_categories` (migration 22/28)
-- — that's a restaurant's own menu sections (Starters/Mains), restaurant-
-- managed, unrelated to this migration.
--
-- restaurant_categories is intentionally its own small lookup table (not
-- an ENUM on restaurants) so admin can add a new business type later
-- without a schema migration — same reasoning as service_areas/
-- food_categories being tables rather than fixed enums elsewhere in this
-- project.
--
-- restaurants.restaurant_category_id is additive and nullable (existing
-- restaurants aren't forced to have one retroactively) — same safe-ALTER
-- pattern as migration 30's restaurants.area_id.

CREATE TABLE IF NOT EXISTS restaurant_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    slug VARCHAR(60) UNIQUE NOT NULL,
    icon_url VARCHAR(255) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_32_restaurants_safe $$

CREATE PROCEDURE anydrop_migration_32_restaurants_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE restaurants
        ADD COLUMN restaurant_category_id BIGINT UNSIGNED NULL AFTER area_id,
        ADD CONSTRAINT fk_restaurant_category FOREIGN KEY (restaurant_category_id) REFERENCES restaurant_categories(id);
END $$

DELIMITER ;

CALL anydrop_migration_32_restaurants_safe();
DROP PROCEDURE anydrop_migration_32_restaurants_safe;

-- Seed the six business types recall.md item 16 lists (idempotent).
INSERT INTO restaurant_categories (name, slug, sort_order) VALUES
('Restaurant',  'restaurant',  1),
('Cafe',        'cafe',        2),
('Bakery',      'bakery',      3),
('Sweet Shop',  'sweet-shop',  4),
('Pharmacy',    'pharmacy',    5),
('Grocery',     'grocery',     6)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);
