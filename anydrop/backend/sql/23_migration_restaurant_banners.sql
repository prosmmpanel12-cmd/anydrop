-- Migration 23 — restaurant banners (app-owner feedback item #3,
-- 2026-08-17: "restaurant open ke baad restaurant banners dikhenge,
-- multiple with a transition, single ho to fixed"). A restaurant can
-- upload any number of its own promotional banner images; the Customer
-- app's restaurant-detail header shows them as an auto-advancing
-- carousel when there are 2+, or a single static image when there's
-- exactly 1 (nothing to transition between) — see
-- RestaurantBannerCarouselView.kt (Customer app) for that display logic.
--
-- Deliberately its own table rather than reusing restaurant_gallery_photos
-- (see 00_Status.md's "WhatsApp-Stories dish carousel" entry for why that
-- table was retired from the dish-photo carousel this same session) —
-- banners are a restaurant-curated upload (whatever promotional image the
-- owner wants shown, not tied to a menu item), so they need their own
-- upload/delete/reorder endpoints and shouldn't be derived from menu_items.
--
-- New table, so a plain CREATE TABLE IF NOT EXISTS is enough — no
-- conditional-ALTER dance needed (that pattern's only for adding a column
-- to a table that might already exist from an earlier migration/session).

CREATE TABLE IF NOT EXISTS restaurant_banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_banner_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    INDEX idx_banners_restaurant (restaurant_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
