-- ============================================================
-- Anydrop — Migration 09: restaurant gallery photos
-- Powers the auto-advancing dish-photo carousel on restaurant cards
-- (Phase E §2.7 — see docs/07_Phase_3.7_Bug_Tracker.md). A restaurant with
-- 0 or 1 row here just shows a plain static cover image, same as before
-- this migration — nothing breaks for restaurants that haven't uploaded
-- gallery photos yet.
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS restaurant_gallery_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    -- Optional "Dish Name · ₹price" overlay tag shown while this photo is
    -- on screen (screenshot reference: "Chilly Paneer Pizza · ₹149"). Both
    -- NULL is fine — the overlay just doesn't show for that photo.
    dish_name VARCHAR(150) NULL,
    price DECIMAL(8,2) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gallery_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    INDEX idx_gallery_restaurant (restaurant_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
