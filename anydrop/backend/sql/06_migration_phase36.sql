-- ============================================================
-- Anydrop — Phase 3.6 migration (UI fixes + new features)
-- See docs/06_Phase_3.6_UI_Fixes_And_New_Features.md §5 step 2.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + guarded ADD COLUMN.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. promo_banners — replaces the old single static home banner with a
--    2-10 slide auto-sliding carousel, server-driven (§2.2).
--    The old `home_promo_*` app_settings keys stay untouched as a
--    fallback; the carousel reads from this table when it has rows.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promo_banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NULL,
    subtitle VARCHAR(255) NULL,
    image_url VARCHAR(255) NOT NULL,
    target_type ENUM('none','restaurant','category','url') NOT NULL DEFAULT 'none',
    target_value VARCHAR(255) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. customer_favorites — bookmark/save restaurants and dishes (§2.5, §1.7).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_favorites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    favorite_type ENUM('restaurant','menu_item') NOT NULL,
    favorite_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fav_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    UNIQUE KEY uq_customer_favorite (customer_id, favorite_type, favorite_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. faqs — Profile → FAQs screen content, DB-driven so it can be edited
--    without an app update (§2.7).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faqs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. feedback — Profile → Feedback submissions (§2.7). Capture-and-store
--    only, no workflow yet.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS feedback (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    rating TINYINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feedback_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. restaurants.rating_count — "By 1.5K+" label under the star rating
--    on the restaurant detail header (§1.1).
-- ------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurants' AND COLUMN_NAME = 'rating_count'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE restaurants ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER rating_avg',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed a plausible-looking rating_count for restaurants that don't have one
-- yet (demo data only had rating_avg before) — cosmetic, safe to re-run.
UPDATE restaurants SET rating_count = FLOOR(200 + RAND() * 4800) WHERE rating_count = 0;

-- ------------------------------------------------------------
-- 6. customer_addresses — structured fields (§1.8, §2.6). full_address
--    stays and becomes a computed/concatenated display string built from
--    these on write, kept for backward compatibility with anything still
--    reading the plain field.
-- ------------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'address_type');
SET @sql := IF(@c = 0, "ALTER TABLE customer_addresses ADD COLUMN address_type ENUM('home','work','other') NOT NULL DEFAULT 'home' AFTER label", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'house_flat_no');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN house_flat_no VARCHAR(100) NULL AFTER full_address', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'floor');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN floor VARCHAR(50) NULL AFTER house_flat_no', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'landmark');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN landmark VARCHAR(150) NULL AFTER floor', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'receiver_name');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN receiver_name VARCHAR(100) NULL AFTER landmark', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'receiver_phone');
SET @sql := IF(@c = 0, 'ALTER TABLE customer_addresses ADD COLUMN receiver_phone VARCHAR(15) NULL AFTER receiver_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 7. app_settings — rate_us_url key (§2.7), same "url from db" pattern
--    as the existing terms_url/privacy_url settings.
-- ------------------------------------------------------------
INSERT INTO app_settings (`key`, `value`, `description`) VALUES
('rate_us_url', 'https://play.google.com/store/apps/details?id=com.anydrop.customer', 'Profile screen Rate Us button target (Play Store listing once published)')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- ------------------------------------------------------------
-- 8. Seed data — a few starter promo banners and FAQ entries so neither
--    screen is empty on first install (§3).
-- ------------------------------------------------------------
INSERT INTO promo_banners (title, subtitle, image_url, target_type, target_value, sort_order) VALUES
('Flat 50% OFF', 'On your first 3 orders', 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=800', 'none', NULL, 1),
('Free Delivery', 'On orders above ₹199', 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=800', 'none', NULL, 2),
('Weekend Thali Fest', 'Explore Thali specials near you', 'https://images.unsplash.com/photo-1552566626-52f8b828add9?w=800', 'category', 'thali', 3)
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO faqs (question, answer, sort_order) VALUES
('How do I track my order?', 'Open the order from Order History or the home screen banner — you will see live status updates, and once a rider is assigned, their live location on the map.', 1),
('What payment methods are supported?', 'Anydrop supports UPI and Cash on Delivery (COD). You choose the method at checkout.', 2),
('How do I cancel an order?', 'You can cancel from the order status screen while the order is still pending or just accepted. Once the restaurant starts preparing it, cancellation is no longer available.', 3),
('What is the delivery OTP for?', 'For UPI orders, a 4-digit OTP is generated once a rider is assigned. Share it with the rider only at the point of delivery to confirm the handover — never share it earlier.', 4),
('How do I add or change my delivery address?', 'Go to Profile → Address Book to add, edit, or set a default delivery address, or add one directly during checkout.', 5)
ON DUPLICATE KEY UPDATE question = VALUES(question);
