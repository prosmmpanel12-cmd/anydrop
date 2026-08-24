-- ============================================================
-- Anydrop — Migration 47: Restaurant Offers Engine
-- (recall.md Phase D item 28; doc 20 §1/§2/§12/§13)
--
-- This is NOT the same thing as `coupons` (admin/restaurant-issued
-- CODES a customer types in). This is restaurant-created, auto-applied
-- promotions shown directly on the menu/checkout with no code entry —
-- doc 20 §1's own opening line: "Anydrop should support
-- restaurant-created offers, not only admin-created coupons."
--
-- SCOPE OF THIS MIGRATION/SESSION (backend + admin only — see recall.md
-- item 28's own status note and docs/29 for the full breakdown):
--   - promo_offers + offer_usages schema (this file)
--   - lib/offers.php pricing engine
--   - price_cart()/orders/create.php wired to auto-apply the single
--     best-fit offer + a free-delivery offer, same "1 item/restaurant
--     offer + 1 coupon + 1 delivery offer" stacking rule doc 20 §13
--     recommends as the initial rule
--   - Restaurant App REST endpoints (offers-list/create/update)
--   - Admin oversight page (view all, pause/disable any restaurant's
--     offer)
-- NOT built this session (flagged, not forgotten):
--   - Restaurant App "Offers" screen UI (Kotlin/XML) — doc 20 §14's
--     Active/Scheduled/Expired/Paused tab layout
--   - Customer App offer display (menu badges, checkout offer strip)
--   - Combo/bundle offers (multi-different-item bundles — the "Combo
--     Offer" bullet in recall.md §9's type list) — needs a many-item
--     selection model this schema doesn't cover yet, deliberately
--     deferred rather than half-modeled
--   - Offer analytics (doc 20 §16 — views/orders/revenue/discount-given
--     roll-ups) — offer_usages here is the raw ledger those would
--     aggregate from, but no reporting query/page exists yet
--   - Admin approve/reject queue (doc 20 §15) — v1 auto-approves every
--     restaurant-created offer (status starts 'active'); admin's only
--     lever this session is pause/disable after the fact, not a
--     pre-publish review gate
--
-- Safe to re-run (CREATE TABLE IF NOT EXISTS + idempotent
-- CONTINUE-HANDLER-for-1060 ALTER pattern, same as every migration
-- since 25/46).
-- ============================================================

-- ---------- Part 1: promo_offers ----------
--
-- offer_type mechanics (see lib/offers.php for the actual pricing
-- math, kept here as a summary):
--   quantity_deal / buy_x_for_y — mechanically IDENTICAL (both are
--     "buy required_qty of the scoped item(s) for offer_price flat");
--     kept as two labels because doc 20 §1.1 vs §1.2 phrase them
--     differently to the restaurant ("3 Samosa @ ₹50" vs "Buy 2
--     Burgers for ₹199") and the Restaurant App create-offer form will
--     want the wording distinction even though the backend treats them
--     the same way. Applies once per complete matched set — ordering 6
--     when required_qty=3 applies twice, not once.
--   buy_x_get_y — required_qty paid units + get_qty free units per
--     matched set (e.g. "Buy 2 Get 1 Free" = required_qty=2, get_qty=1).
--   percent_discount / flat_discount — plain % or ₹ off the scoped
--     subtotal (item/category/whole-restaurant), same math coupons
--     already use, just restaurant-auto-applied instead of code-entry.
--   free_delivery — zeroes delivery_charge when min_order_amount is
--     met; scope/menu_item_id/food_category_id are ignored for this
--     type (always effectively restaurant-wide by nature of "waiving
--     the delivery fee").
--
-- scope governs which line(s) of the cart a quantity/percent/flat
-- offer can match against:
--   'item'       — menu_item_id required, exactly one item
--   'category'   — food_category_id required, any item tagged with it
--   'restaurant' — whole cart; NOT valid for quantity_deal/
--                  buy_x_for_y/buy_x_get_y (counting a "quantity" only
--                  makes sense against a specific item or category, not
--                  an arbitrary mixed cart) — enforced in
--                  offers-create.php/offers-update.php, not a DB
--                  constraint (this MySQL version's CHECK support is
--                  inconsistent across the environments this project
--                  has been deployed to elsewhere in this repo, so
--                  every other multi-field invariant here is
--                  app-validated too, not DB-enforced).
--
-- status is restaurant/admin controlled (active/paused/disabled) —
-- 'scheduled' and 'expired' are DERIVED from start_date/end_date at
-- read time (lib/offers.php's is_offer_currently_active()), never
-- stored, so there's exactly one source of truth for "is this offer
-- live right now" instead of a stored status that could drift out of
-- sync with today's date. 'disabled' is admin-only in practice (the
-- Restaurant App's pause/resume toggle only ever writes
-- active<->paused — see offers-update.php) so an admin override always
-- wins over whatever the restaurant last set, without needing a
-- separate is_admin_disabled flag.
CREATE TABLE IF NOT EXISTS promo_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    offer_type ENUM('quantity_deal','buy_x_for_y','buy_x_get_y','percent_discount','flat_discount','free_delivery') NOT NULL,
    title VARCHAR(150) NOT NULL,
    scope ENUM('item','category','restaurant') NOT NULL DEFAULT 'restaurant',
    menu_item_id BIGINT UNSIGNED NULL,
    food_category_id BIGINT UNSIGNED NULL,

    -- quantity_deal / buy_x_for_y / buy_x_get_y fields
    required_qty INT UNSIGNED NULL,
    get_qty INT UNSIGNED NULL,           -- buy_x_get_y only
    offer_price DECIMAL(8,2) NULL,       -- quantity_deal / buy_x_for_y only

    -- percent_discount / flat_discount fields
    discount_percent DECIMAL(5,2) NULL,
    discount_flat DECIMAL(8,2) NULL,
    max_discount_amount DECIMAL(8,2) NULL,

    -- shared restrictions (doc 20 §12)
    min_order_amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    customer_eligibility ENUM('all','new_customer','existing_customer') NOT NULL DEFAULT 'all',
    start_date DATE NULL,
    end_date DATE NULL,
    start_time TIME NULL,                -- happy-hour window start, e.g. '16:00:00'
    end_time TIME NULL,                  -- happy-hour window end, e.g. '18:00:00'
    weekdays VARCHAR(20) NULL,           -- CSV '1,2,3,4,5,6,7' (1=Mon..7=Sun), same convention as restaurants.working_days; NULL = every day
    daily_limit INT UNSIGNED NULL,       -- max redemptions across all customers, per calendar day
    total_limit INT UNSIGNED NULL,       -- max redemptions, all time
    per_customer_limit INT UNSIGNED NULL,

    status ENUM('active','paused','disabled') NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,           -- soft delete, same convention as menu_items/restaurants

    CONSTRAINT fk_offer_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_offer_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CONSTRAINT fk_offer_category FOREIGN KEY (food_category_id) REFERENCES food_categories(id),
    INDEX idx_offer_restaurant_status (restaurant_id, status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 2: offer_usages ----------
--
-- Append-only redemption ledger — same "never trust a mutable counter
-- alone" reasoning coupon_usages already established (price_cart()'s
-- coupon block COUNT()s this table live rather than trusting a
-- times_used column). discount_amount is snapshotted per-redemption
-- (not recomputed from the offer's current config later) for the same
-- historical-accuracy reason order_items snapshots item_name/price —
-- a restaurant editing/pausing an offer tomorrow must never change
-- what an already-placed order's bill said today.
CREATE TABLE IF NOT EXISTS offer_usages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    discount_amount DECIMAL(8,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_offer_usage_offer FOREIGN KEY (offer_id) REFERENCES promo_offers(id),
    CONSTRAINT fk_offer_usage_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_offer_usage_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    INDEX idx_offer_usage_offer (offer_id),
    INDEX idx_offer_usage_customer (offer_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Part 3: orders — offer + free-delivery snapshot columns ----------
--
-- Kept SEPARATE from orders.coupon_id/discount_amount (not merged into
-- the same columns) so a bill can show "Item Discount" (this offer)
-- and "Coupon" (the existing coupon_id/discount_amount pair) as two
-- distinct lines, per doc 20 §42's price-breakdown mock — merging them
-- would make that breakdown impossible to reconstruct after the fact.
-- Same reasoning for free_delivery_offer_id/free_delivery_discount_amount
-- being separate from delivery_charge — doc 20 §4 explicitly requires
-- recording "Original Delivery Fee" vs "Customer Delivery Fee" vs
-- "Delivery Discount" as distinct numbers for financial reconciliation,
-- not just a already-net delivery_charge.
DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_47_safe $$

CREATE PROCEDURE anydrop_migration_47_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- one or more columns already exist — nothing to do, keep going
    END;

    ALTER TABLE orders
        ADD COLUMN offer_id BIGINT UNSIGNED NULL AFTER coupon_id,
        ADD COLUMN offer_discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER offer_id,
        ADD COLUMN free_delivery_offer_id BIGINT UNSIGNED NULL AFTER offer_discount_amount,
        ADD COLUMN free_delivery_discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER free_delivery_offer_id;
END $$

DELIMITER ;

CALL anydrop_migration_47_safe();
DROP PROCEDURE anydrop_migration_47_safe;

-- FKs added as a second idempotent step (can't put them inside the
-- CONTINUE-HANDLER ALTER above the usual way this repo does it — a
-- constraint whose columns already exist but whose CONSTRAINT name
-- collides raises a DIFFERENT error code (1826/1005) than 1060, so it
-- gets its own safe wrapper rather than silently relying on the same
-- handler catching the wrong error).
DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_47_fk_safe $$

CREATE PROCEDURE anydrop_migration_47_fk_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1005, 1826, 1061
    BEGIN
        -- constraint already exists — nothing to do, keep going
    END;

    ALTER TABLE orders
        ADD CONSTRAINT fk_order_offer FOREIGN KEY (offer_id) REFERENCES promo_offers(id),
        ADD CONSTRAINT fk_order_free_delivery_offer FOREIGN KEY (free_delivery_offer_id) REFERENCES promo_offers(id);
END $$

DELIMITER ;

CALL anydrop_migration_47_fk_safe();
DROP PROCEDURE anydrop_migration_47_fk_safe;

-- ---------- Part 4: admin permissions ----------
--
-- Separate from coupons_view/coupons_edit (migration 29) even though
-- the two features are conceptually siblings — this is restaurant-
-- initiated content an admin only ever *moderates* (pause/disable),
-- never authors, a narrower blast radius than the admin-authored
-- coupons_edit grant. Granted here to every role that already holds
-- coupons_edit (today, just Super Admin) — same "don't silently
-- reduce anyone's access" pattern as migrations 42/43.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('offers_view', 'offers', 'view'),
    ('offers_manage', 'offers', 'manage');

INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'coupons_edit'
JOIN admin_permissions np ON np.`key` IN ('offers_view', 'offers_manage');

-- Confirm final state — uses SHOW/basic SELECTs, not information_schema
-- (this environment's DB user can't read information_schema, same
-- constraint every migration since 11c has worked around).
SHOW COLUMNS FROM orders LIKE 'offer_id';
SHOW COLUMNS FROM orders LIKE 'free_delivery_offer_id';
SELECT `key` FROM admin_permissions WHERE `key` IN ('offers_view', 'offers_manage');
