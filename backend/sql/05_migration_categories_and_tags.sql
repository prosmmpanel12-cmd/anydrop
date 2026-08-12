-- Phase 3.5 — Home screen categories row (Pizza/Rolls/Burger...), restaurant
-- badges (Near & Fast / Pure Veg / Under 250), and item-level tag support.
-- Safe to re-run (CREATE TABLE IF NOT EXISTS, ADD COLUMN guarded below).

-- ------------------------------------------------------------
-- 1. food_categories — platform-wide category chips shown under the
--    search bar on Home (screenshot reference: All / Pizza / Rolls / Burger).
--    Admin-manageable later (Phase 5); for now seeded directly + icon_url
--    points at a hosted image. `slug` is what the app filters on.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS food_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    slug VARCHAR(60) UNIQUE NOT NULL,
    icon_url VARCHAR(255) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. menu_item_categories — many-to-many: one dish (e.g. "Cheese Pizza")
--    can appear under the "Pizza" chip regardless of which restaurant sells
--    it. This is what powers "tap Pizza -> see pizza from every restaurant".
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_item_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    food_category_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_mic_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CONSTRAINT fk_mic_category FOREIGN KEY (food_category_id) REFERENCES food_categories(id),
    UNIQUE KEY uq_item_category (menu_item_id, food_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. restaurant_tags — small badges under a restaurant card / list filter
--    row (screenshot reference: "Near & Fast", "Filters", "Under ₹200",
--    "Pure Veg restaurant"). Kept as its own table (not a VARCHAR column)
--    so a restaurant can carry more than one and the filter row can be
--    driven by data instead of hardcoded strings.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS restaurant_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    slug VARCHAR(60) UNIQUE NOT NULL,
    icon_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS restaurant_tag_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    restaurant_tag_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_rtm_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_rtm_tag FOREIGN KEY (restaurant_tag_id) REFERENCES restaurant_tags(id),
    UNIQUE KEY uq_restaurant_tag (restaurant_id, restaurant_tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. menu_items.discount_percent already exists — add a simple flag for
--    "select items at X% off" style restaurant-card badges (screenshot
--    reference: "50% OFF select items", "Flat ₹80 OFF above ₹149") without
--    a full coupon needed for the card preview text. NULL = no offer badge.
-- ------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'offer_badge_text'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE restaurants ADD COLUMN offer_badge_text VARCHAR(80) NULL AFTER rating_avg',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. Seed the category chips + restaurant tags (idempotent).
-- ------------------------------------------------------------
INSERT INTO food_categories (name, slug, icon_url, sort_order) VALUES
('Pizza',   'pizza',   'https://cdn-icons-png.flaticon.com/512/599/599995.png', 1),
('Rolls',   'rolls',   'https://cdn-icons-png.flaticon.com/512/2276/2276931.png', 2),
('Burger',  'burger',  'https://cdn-icons-png.flaticon.com/512/3075/3075929.png', 3),
('Biryani', 'biryani', 'https://cdn-icons-png.flaticon.com/512/2515/2515263.png', 4),
('Thali',   'thali',   'https://cdn-icons-png.flaticon.com/512/2276/2276941.png', 5),
('Chinese', 'chinese', 'https://cdn-icons-png.flaticon.com/512/857/857681.png', 6),
('Desserts','desserts','https://cdn-icons-png.flaticon.com/512/3081/3081967.png', 7),
('Sandwich','sandwich','https://cdn-icons-png.flaticon.com/512/1927/1927681.png', 8),
('South Indian','south-indian','https://cdn-icons-png.flaticon.com/512/2515/2515183.png', 9),
('Beverages','beverages','https://cdn-icons-png.flaticon.com/512/2405/2405479.png', 10)
ON DUPLICATE KEY UPDATE name = VALUES(name), icon_url = VALUES(icon_url), sort_order = VALUES(sort_order);

INSERT INTO restaurant_tags (name, slug, icon_url) VALUES
('Near & Fast', 'near_fast', NULL),
('Pure Veg', 'pure_veg', NULL),
('Under ₹200', 'under_200', NULL),
('Extra 10% OFF (Gold)', 'gold_extra_10', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);
