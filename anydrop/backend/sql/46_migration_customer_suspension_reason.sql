-- ============================================================
-- Anydrop — Migration 46: customers.suspension_reason
--
-- Backs doc 25's "suspended account keeps full access until token
-- expires" fix (backend/lib/auth.php's per-request status re-check).
-- Once every authenticated request starts rejecting suspended
-- customers with account_suspended, the app needs a reason string to
-- show the customer — same as restaurant-login.php already does for
-- restaurants via restaurants.rejection_reason (migration 25).
--
-- Deliberately NOT touching restaurants here: restaurants.rejection_
-- reason (migration 25) is already reused for suspension reasons —
-- admin/restaurants.php's "suspend" action already writes into it and
-- restaurant-login.php already reads it. Adding a second, differently-
-- named column on that table would just create two sources of truth.
-- customers has no equivalent free-text column, so this adds one.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as migration 25 /
-- 11c (this environment's DB user can't read information_schema).
-- Safe to run any number of times, in any partial-prior-state.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_46_safe $$

CREATE PROCEDURE anydrop_migration_46_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE customers
        ADD COLUMN suspension_reason VARCHAR(255) NULL AFTER is_active;
END $$

DELIMITER ;

CALL anydrop_migration_46_safe();
DROP PROCEDURE anydrop_migration_46_safe;

-- Confirm final state — uses SHOW, not information_schema.
SHOW COLUMNS FROM customers LIKE 'suspension_reason';
