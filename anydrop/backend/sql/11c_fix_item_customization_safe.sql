-- ============================================================
-- Anydrop — Migration 11c: idempotent fix-up for migration 11,
-- WITHOUT touching information_schema.
--
-- Supersedes 11b_fix_item_customization_idempotent.sql — that version
-- queried information_schema.columns, which this environment's 'root'
-- user isn't granted access to (error 1044: "Access denied ... to
-- database 'information_schema'"). This version instead wraps each
-- ALTER TABLE in a stored procedure with a CONTINUE HANDLER for MySQL
-- error 1060 (ER_DUP_FIELDNAME — "Duplicate column name"), so a column
-- that already exists is silently skipped instead of aborting the whole
-- script, and no metadata-table permissions are needed at all — only the
-- same ALTER TABLE / CREATE ROUTINE privileges the account already has.
--
-- Adds whichever of these three columns are actually missing (migration
-- 11's original intent):
--   - order_items.special_instructions
--   - customer_cart_items.addon_ids
--   - customer_cart_items.special_instructions
--
-- Safe to run any number of times, in any partial-prior-state.
-- Run this INSTEAD OF 11_migration_item_customization.sql AND INSTEAD OF
-- 11b_fix_item_customization_idempotent.sql.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_11_safe $$

CREATE PROCEDURE anydrop_migration_11_safe()
BEGIN
    -- Swallow "duplicate column name" for every ALTER below — anything
    -- else still surfaces as a real error.
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE order_items
        ADD COLUMN special_instructions TEXT NULL AFTER addons_json;

    ALTER TABLE customer_cart_items
        ADD COLUMN addon_ids TEXT NULL AFTER quantity;

    ALTER TABLE customer_cart_items
        ADD COLUMN special_instructions TEXT NULL AFTER addon_ids;
END $$

DELIMITER ;

CALL anydrop_migration_11_safe();
DROP PROCEDURE anydrop_migration_11_safe;

-- Confirm final state — uses SHOW, not information_schema, so it works
-- under the same restricted grants.
SHOW COLUMNS FROM order_items LIKE 'special_instructions';
SHOW COLUMNS FROM customer_cart_items LIKE 'addon_ids';
SHOW COLUMNS FROM customer_cart_items LIKE 'special_instructions';
