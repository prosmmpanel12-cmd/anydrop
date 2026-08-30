-- ============================================================
-- Anydrop — Migration 62: Item availability timing
-- (available_from / available_until on menu_items)
--
-- today.md §1's real gap ("breakfast item, 7am-11am only") — the
-- existing is_available column is a manual owner ON/OFF toggle only;
-- there was no time-window concept anywhere on menu_items. This
-- migration only adds the two columns; it does not change any
-- existing row's visible behavior — both are nullable with no
-- default, and NULL on either means "no time restriction," same
-- always-available behavior every existing item already has today.
-- See backend/lib/menu_item_availability.php (same session) for the
-- actual window-check logic, and docs/68_...md for the full list of
-- call sites wired to use it.
--
-- TIME (not TIMESTAMP/DATETIME) — this is a daily recurring window
-- ("7am-11am every day"), not a one-off date/time, same reasoning
-- restaurants.opening_time/closing_time already use for the
-- restaurant-level equivalent.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as migrations 25/60
-- — this environment's DB user can't read information_schema, so
-- swallowing MySQL error 1060 (ER_DUP_FIELDNAME) is the safe way to
-- make an ADD COLUMN re-runnable. Safe to run any number of times, in
-- any partial-prior-state.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_62_safe $$

CREATE PROCEDURE anydrop_migration_62_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE menu_items
        ADD COLUMN available_from TIME NULL AFTER prep_time_minutes;

    ALTER TABLE menu_items
        ADD COLUMN available_until TIME NULL AFTER available_from;
END $$

DELIMITER ;

CALL anydrop_migration_62_safe();
DROP PROCEDURE anydrop_migration_62_safe;

-- Confirm final state — uses SHOW, not information_schema.
SHOW COLUMNS FROM menu_items LIKE 'available_from';
SHOW COLUMNS FROM menu_items LIKE 'available_until';
