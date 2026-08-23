-- Migration 33 — Admin Banner Manager (recall.md item 17 / docs/19_Admin_
-- Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md §5).
--
-- Brand-new table — previously the only banner-like thing was
-- `app_settings.home_promo_*` flat fields for a single promo banner
-- (doc 19 §5's own note), which this doesn't touch or migrate data from
-- (app owner hasn't asked for that; the old single promo banner keeps
-- working via app_settings independently of this new multi-banner system).
--
-- NOT the same table as `restaurant_banners` (a restaurant's own
-- open/closed-status carousel banners, migration behind
-- backend/api/v1/restaurant/banner-upload.php) — that's restaurant-
-- managed, per-restaurant, no area targeting. This `banners` table is
-- admin-managed, platform-wide, and is what actually needs area
-- targeting.
--
-- area_id NULL = shown to every area (e.g. a festival banner); set =
-- shown only to that one service_areas node's customers. Per doc 19 §5
-- this is the app owner's explicit ask ("promotional posters ho wo us
-- area ke customer ko hi dikhe") — the customer-facing banner-fetch
-- endpoint (not built yet, out of scope for this migration/admin page)
-- will need `WHERE area_id IS NULL OR area_id = :customer_area_id`.
--
-- area_id can point at EITHER a 'city_village' or 'area' level node
-- (service_areas' now-optional Area level, see migration 30/32's notes)
-- — a banner scoped to a City/Village with no Area breakdown still
-- needs to be assignable, so this isn't restricted to one specific level,
-- same reasoning as restaurants.area_id (backend/admin/restaurants.php).

CREATE TABLE IF NOT EXISTS banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NULL,
    image_url VARCHAR(255) NOT NULL,
    banner_type ENUM('home','offer','festival','popup') NOT NULL DEFAULT 'home',
    deep_link VARCHAR(255) NULL,       -- app-internal route or restaurant/coupon id — free text, app side interprets it
    area_id BIGINT UNSIGNED NULL,      -- NULL = platform-wide, all areas
    priority INT NOT NULL DEFAULT 0,   -- higher shows first when multiple active banners qualify
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banner_area FOREIGN KEY (area_id) REFERENCES service_areas(id),
    INDEX idx_banner_active_priority (is_active, priority),
    INDEX idx_banner_area (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
