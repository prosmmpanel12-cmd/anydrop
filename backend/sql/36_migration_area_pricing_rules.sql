-- ============================================================
-- Anydrop — Migration 36: Area-wise Minimum Order Floor + Distance-Based
-- Delivery Fee
--
-- Implements recall.md Phase B items 13/14 (area-wise minimum order,
-- area-wise delivery fee) — explicitly flagged as still-pending in both
-- migration 35's own comment and cod-rules.php's kdoc ("delivery fee /
-- min order are separate, still-pending items, not folded into this").
--
-- v1 scope (app owner, 2026-08-22):
--   1. MIN ORDER — restaurants keep setting their OWN min_order_amount
--      (restaurants.min_order_amount already existed, already used by
--      price_cart()'s below_min_order_amount check — nothing changes
--      there). What's new: admin can set a per-area FLOOR. A restaurant
--      whose resolved area has a floor cannot save its own
--      min_order_amount below that floor — enforced server-side in
--      restaurant/profile-update.php, never trusted from the app.
--      "Floor, restaurant can go higher" per app owner's explicit
--      answer — NOT "admin's number always wins".
--   2. DELIVERY FEE — distance-based, replacing the old flat
--      `delivery_charge_flat` app_setting as the *default* calculation
--      (that setting stays as the final fallback when either the
--      restaurant or the delivery address has no lat/lng to compute a
--      distance from — same "don't hide behind unresolved data" stance
--      as every other geo feature in this project). Fee =
--      delivery_base_fee + (haversine_km(restaurant, delivery_address)
--      * delivery_rate_per_km), then rounded UP to the nearest ₹5 —
--      app owner's explicit rule: "mera paisa minus nahi hona chahiye"
--      (16→20, 17→20, 18→20, 19→20, 20→20 — ceiling to nearest 5, never
--      down). See lib/delivery_pricing.php's ceil_to_nearest_5().
--
-- One row per service_areas node that has an override, same shape as
-- migration 35's area_cod_rules (city_village or area level, whichever
-- is meaningful) — a node with no row here falls back to the
-- platform-wide app_settings defaults added below. Resolution walks
-- the same nearest-node-then-parent chain as get_effective_cod_rule().
--
-- Same idempotent CREATE-IF-NOT-EXISTS pattern as migrations 30/33/35 —
-- safe to run any number of times, in any partial-prior-state.
-- ============================================================

CREATE TABLE IF NOT EXISTS area_pricing_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL UNIQUE,        -- one rule row per service_areas node
    min_order_amount DECIMAL(8,2) NULL,             -- floor for restaurants in this area; NULL = platform default is the floor
    delivery_rate_per_km DECIMAL(6,2) NULL,         -- ₹ per km; NULL = platform default
    delivery_base_fee DECIMAL(6,2) NULL,            -- ₹ flat, added before the per-km amount; NULL = platform default
    is_active TINYINT(1) NOT NULL DEFAULT 1,        -- disable the override without deleting it (falls back to platform default)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_pricing_rules_area FOREIGN KEY (area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Platform-wide defaults ----------
-- Same app_settings table every other platform-wide flag already uses
-- (lib/settings.php's get_setting()) — INSERT IGNORE so re-running this
-- migration never clobbers a value an admin already changed.
--
-- default_delivery_rate_per_km / default_delivery_base_fee: starting
-- values only (₹8/km, ₹0 base) — app owner should tune these from the
-- new admin screen to match real per-km economics; not a business
-- decision this migration should hardcode as final.
--
-- default_min_order_amount: 0 (no floor by default) — an area only gets
-- a real floor once an admin explicitly sets one via the new screen.

INSERT IGNORE INTO app_settings (`key`, `value`) VALUES
    ('default_min_order_amount', '0'),
    ('default_delivery_rate_per_km', '8'),
    ('default_delivery_base_fee', '0');
