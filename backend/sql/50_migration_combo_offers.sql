-- ============================================================
-- Anydrop — Migration 50: Combo/Bundle Offers
-- (recall.md Phase D item 29; docs/29 + docs/40's own plan doc —
-- "explicitly deferred" in migration 47 because it needs a real
-- multi-item bundle model, not a reuse of promo_offers.scope)
--
-- Every OTHER offer_type (quantity_deal, buy_x_for_y, buy_x_get_y,
-- percent_discount, flat_discount, free_delivery) is `scope`-bound to
-- exactly ONE item, ONE category, or the whole restaurant —
-- lib/offers.php's get_offer_scoped_lines() matches against a single
-- menu_item_id/food_category_id. A combo needs a *set* of distinct
-- menu items, each with its own required quantity (e.g. 1 Burger + 1
-- Fries + 1 Coke @ a fixed bundle price) — `scope` genuinely cannot
-- express that, hence the new child table below instead of new
-- promo_offers columns alone.
--
-- New offer_type value: 'combo'.
--   - scope stays 'restaurant' for a combo row (unused for matching —
--     matching is entirely driven by offer_combo_items below — kept
--     non-NULL since every existing query already assumes scope has
--     a value; this avoids touching every one of those call sites for
--     a column combo doesn't actually read).
--   - promo_offers.offer_price (already exists, used today by
--     quantity_deal/buy_x_for_y) is REUSED as the combo's fixed
--     bundle price — no new promo_offers column needed for that part.
--   - required_qty/get_qty/discount_percent/discount_flat/
--     max_discount_amount all stay NULL for a combo row, same as they
--     already are for whichever offer_type doesn't use them today.
--
-- New offer_combo_items table — the combo's fixed item list:
--   one promo_offers row (offer_type='combo') -> many offer_combo_items
--   rows, one per distinct menu item required in the bundle.
--
-- Idempotent: offer_type ENUM extension via conditional MODIFY COLUMN
-- (SHOW COLUMNS + string-match check, since information_schema
-- doesn't expose ENUM values directly the way it does column/index
-- existence — a plain "column already exists" check like migration 49
-- uses doesn't apply here, we're changing an existing column's
-- allowed values, not adding a new column); offer_combo_items itself
-- via CREATE TABLE IF NOT EXISTS, same as every table since 25/46.
-- ============================================================

-- ---------- Part 1: add 'combo' to promo_offers.offer_type ----------
SET @enum_has_combo := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'promo_offers'
      AND COLUMN_NAME = 'offer_type'
      AND COLUMN_TYPE LIKE '%''combo''%'
);
SET @sql_enum := IF(
    @enum_has_combo = 0,
    "ALTER TABLE promo_offers MODIFY COLUMN offer_type ENUM('quantity_deal','buy_x_for_y','buy_x_get_y','percent_discount','flat_discount','free_delivery','combo') NOT NULL",
    'SELECT 1'
);
PREPARE stmt_enum FROM @sql_enum;
EXECUTE stmt_enum;
DEALLOCATE PREPARE stmt_enum;

-- ---------- Part 2: offer_combo_items ----------
--
-- required_qty mirrors the same column name/meaning promo_offers
-- already uses for quantity_deal/buy_x_for_y/buy_x_get_y (rather than
-- inventing a differently-named "qty" here) — "how many of this menu
-- item must be in the cart," same concept, just per-item instead of
-- once-per-offer.
--
-- No separate price column here — a combo's price is the single
-- fixed bundle price on promo_offers.offer_price (Part 1's comment
-- above); this table is purely "which items, how many of each."
CREATE TABLE IF NOT EXISTS offer_combo_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    required_qty INT UNSIGNED NOT NULL DEFAULT 1,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_combo_item_offer FOREIGN KEY (offer_id) REFERENCES promo_offers(id),
    CONSTRAINT fk_combo_item_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    UNIQUE INDEX uniq_combo_offer_item (offer_id, menu_item_id),
    INDEX idx_combo_offer (offer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SHOW COLUMNS FROM promo_offers LIKE 'offer_type';
SHOW CREATE TABLE offer_combo_items;
