-- features.md §6 — Restaurant detail header parity pass. The reference
-- screenshot's offer strip ("Free delivery above ₹49 · 3 offers ⌄") needs a
-- real per-restaurant list of offers, not just the single offer_badge_text
-- string (which stays as-is — it drives the corner badge over the cover
-- image, a separate, already-shipped thing). Same reasoning as
-- 05_migration_categories_and_tags.sql's restaurant_tags: a proper table so
-- a restaurant can carry more than one, instead of overloading one VARCHAR
-- column.
--
-- Also adds two new restaurant_tags rows ("Frequently reordered", "No
-- packaging charges") — these reuse the *existing* generic
-- restaurant_tags/restaurant_tag_map mechanism from migration 05 rather than
-- new boolean columns, since that mechanism already exists for exactly this
-- ("small badge under the restaurant name") purpose. No new demo-flag
-- pattern invented here.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + ON DUPLICATE KEY UPDATE
-- throughout, same conventions as every other migration in this folder.

CREATE TABLE IF NOT EXISTS restaurant_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_restaurant_offers_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CREATE INDEX has no IF NOT EXISTS on the MySQL versions InfinityFree runs,
-- so guard it the same way the ADD COLUMN statements elsewhere in this
-- folder guard themselves, via information_schema — otherwise re-running
-- this file errors with "Duplicate key name" instead of being a no-op.
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_offers' AND INDEX_NAME = 'idx_restaurant_offers_restaurant'
);
SET @sql := IF(@idx_exists = 0,
  'CREATE INDEX idx_restaurant_offers_restaurant ON restaurant_offers (restaurant_id, is_active, sort_order)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO restaurant_tags (name, slug, icon_url) VALUES
('Frequently reordered', 'frequently_reordered', NULL),
('No packaging charges', 'no_packaging_charges', NULL)
ON DUPLICATE KEY UPDATE name = VALUES(name);
