-- ============================================================
-- Anydrop — Migration 25: restaurants.rejection_reason
--
-- Backs the new Admin-side "Approve/Reject pending restaurants" screen
-- (docs/restorent/00_Status.md, doc 18's Recommended build order item —
-- flagged as overdue since 2026-08-14, self-signup has been producing
-- status='pending' rows with no approval UI until now). `restaurants.
-- status` already had 'rejected' as a valid value (01_schema.sql), but
-- nowhere to record *why* — this adds that.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as
-- 11c_fix_item_customization_safe.sql (this environment's DB user can't
-- read information_schema, so a plain "column already exists" check via
-- that route isn't available — swallowing MySQL error 1060
-- (ER_DUP_FIELDNAME) is the safe alternative). Safe to run any number of
-- times, in any partial-prior-state.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_25_safe $$

CREATE PROCEDURE anydrop_migration_25_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE restaurants
        ADD COLUMN rejection_reason TEXT NULL AFTER status;
END $$

DELIMITER ;

CALL anydrop_migration_25_safe();
DROP PROCEDURE anydrop_migration_25_safe;

-- Confirm final state — uses SHOW, not information_schema.
SHOW COLUMNS FROM restaurants LIKE 'rejection_reason';
