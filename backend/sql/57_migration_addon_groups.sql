-- Anydrop — Migration 57: Item Customization / Add-on Groups (§1, today.md 2026-08-28)
--
-- Correcting a stale claim carried in docs/18 and today.md: those docs
-- said "menu_item_addon_groups already exist" — they don't. Migration 11
-- explicitly flagged this as never built ("NOTE: this migration does NOT
-- add addon groups... still open") and the Customer App's
-- ItemDetailBottomSheetFragment kdoc says the same (flat checkbox list,
-- no grouping/cap). Verified again this session by grepping the actual
-- schema before writing this migration — only plain `menu_item_addons`
-- exists (name/price/is_active, no group concept at all).
--
-- This migration adds the group table so a restaurant can define e.g.
-- "Choose Size" (required, pick exactly 1) or "Extra Toppings" (optional,
-- pick up to 3) instead of every addon being an ungrouped flat checkbox.
--
-- Deliberately backward-compatible: `menu_item_addons.addon_group_id` is
-- nullable. Existing addons (all of them, today) keep addon_group_id
-- NULL and keep behaving exactly as before — flat checkboxes, no cap —
-- everywhere they're currently read (restaurants/menu.php,
-- lib/orders.php, Customer App's cart/order flow). Only a restaurant
-- that explicitly creates a group and assigns addons to it gets grouped
-- behavior, and even then, only the *restaurant app* (this session's
-- scope) understands groups — the Customer App's item-detail sheet and
-- checkout pricing do NOT read min_select/max_select/is_required yet.
-- That's a separate, still-open Customer-App-side piece (flagged again
-- in this session's handover doc) — a restaurant can create a "pick
-- exactly 1" group here, but nothing stops a customer from picking 0 or
-- 3 in the app today. This migration only makes the schema able to hold
-- the group definition.
--
-- Same idempotent-rerun pattern as migration 54/56 (DELIMITER $$ ...
-- CONTINUE HANDLER), since a plain ALTER TABLE ADD COLUMN/CONSTRAINT
-- isn't naturally safe to re-run.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_57_create_groups_table $$
CREATE PROCEDURE anydrop_migration_57_create_groups_table()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    CREATE TABLE menu_item_addon_groups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        menu_item_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        -- Zomato/Swiggy-style semantics: min_select 0 + max_select 1 ==
        -- "optional, pick at most one"; min_select 1 + max_select 1 ==
        -- "required, pick exactly one" (e.g. Size); min_select 0 +
        -- max_select 3 == "optional, pick up to 3" (e.g. Extra Toppings).
        -- Enforced by addon-groups-create.php/addon-groups-update.php
        -- (min_select <= max_select, max_select >= 1) — this table has
        -- no CHECK constraint for it, matching this codebase's existing
        -- convention of application-level validation over DB CHECKs.
        min_select SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_select SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        -- Soft-disable, same convention as menu_item_addons.is_active —
        -- addon-groups-delete.php sets this to 0 (and cascades the same
        -- soft-disable to every addon inside the group) rather than a
        -- hard DELETE, so re-enabling is just a flag flip and nothing
        -- referencing an addon id (e.g. a past order's addons_json) ever
        -- points at a row that's actually gone.
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        CONSTRAINT fk_addongroup_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_57_add_group_id_column $$
CREATE PROCEDURE anydrop_migration_57_add_group_id_column()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE menu_item_addons ADD COLUMN addon_group_id BIGINT UNSIGNED NULL AFTER menu_item_id;
END $$

DELIMITER ;

CALL anydrop_migration_57_create_groups_table();
CALL anydrop_migration_57_add_group_id_column();

DROP PROCEDURE IF EXISTS anydrop_migration_57_create_groups_table;
DROP PROCEDURE IF EXISTS anydrop_migration_57_add_group_id_column;

-- FK on the new column, added separately so a re-run after a partial
-- failure doesn't choke re-adding a column that's already there but
-- still missing its constraint — same split as migration 56.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_57_add_group_fk $$
CREATE PROCEDURE anydrop_migration_57_add_group_fk()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1826 BEGIN END; -- ER_FK_DUP_NAME
    ALTER TABLE menu_item_addons
        ADD CONSTRAINT fk_addon_group FOREIGN KEY (addon_group_id) REFERENCES menu_item_addon_groups(id);
END $$

DELIMITER ;

CALL anydrop_migration_57_add_group_fk();

DROP PROCEDURE IF EXISTS anydrop_migration_57_add_group_fk;
