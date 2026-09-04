-- ============================================================
-- Anydrop — Migration 26: fix "delete address" crashing for any
-- address that's ever been used on a real order
--
-- ROOT CAUSE of the reported bug ("delete works for a freshly-added
-- address but fails with a generic network error for an older address,
-- or whichever address is left last"): `orders.delivery_address_id`'s
-- foreign key (01_schema.sql, fk_order_address) was declared with no
-- ON DELETE clause, which InnoDB treats as RESTRICT — MySQL refuses the
-- DELETE with error 1451 ("Cannot delete or update a parent row: a
-- foreign key constraint fails") whenever ANY order still references
-- that address. `customer/addresses.php`'s DELETE handler had no
-- try/catch around the DELETE statement, so that PDOException (PDO's
-- ERRMODE_EXCEPTION is on globally, config/database.php) went uncaught
-- — an uncaught PHP exception here doesn't produce the app's normal
-- {"success":false,...} JSON shape, so the Android client's response
-- parsing itself throws, landing in AddressBookActivity.deleteAddress()'s
-- generic `catch (e: Exception)` and showing "Network error" — nothing
-- about the failure was actually network-related, and nothing told the
-- user *why* it failed.
--
-- A freshly-added test address that's never been attached to an order
-- has no matching `orders` row, so its DELETE always succeeded — which
-- is exactly why this looked like it only affected "old" addresses.
--
-- FIX — change the FK to ON DELETE SET NULL, not CASCADE: deleting an
-- address must never delete the orders that used it (that would nuke
-- order history), it should just stop referencing an address that no
-- longer exists. Confirmed safe to do — grepped every backend endpoint
-- (`grep -rln delivery_address_id backend/api`) and NOTHING currently
-- reads `orders.delivery_address_id` back out for display anywhere
-- (order detail/history, restaurant order screens) — it's write-only
-- today, set at order-creation time and never joined back. So there is
-- no display code that could break from it becoming NULL. (Worth
-- flagging separately: that also means no screen shows a customer's
-- delivery address to the restaurant today — a real, pre-existing gap,
-- but a different bug from this one; not touched here.)
--
-- MySQL doesn't support ALTER-ing an existing FK's ON DELETE clause in
-- place — has to be dropped and recreated. Same idempotent
-- CONTINUE-HANDLER pattern as 11c/25 for the constraint-name-not-found
-- case (error 1091, DROP FOREIGN KEY on a constraint that was already
-- dropped by an earlier partial run), so this is safe to run more than
-- once.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_26_safe $$

CREATE PROCEDURE anydrop_migration_26_safe()
BEGIN
    -- 1091 = ER_CANT_DROP_FIELD_OR_KEY — constraint already dropped by an
    -- earlier partial run of this same migration.
    DECLARE CONTINUE HANDLER FOR 1091
    BEGIN
        -- already dropped — nothing to do, keep going
    END;

    ALTER TABLE orders DROP FOREIGN KEY fk_order_address;
END $$

DELIMITER ;

CALL anydrop_migration_26_safe();
DROP PROCEDURE anydrop_migration_26_safe;

-- Recreate with ON DELETE SET NULL. Plain ALTER (not wrapped) —
-- if this fails because it already exists correctly, the error is loud
-- and obvious (duplicate constraint name) rather than something worth
-- silently swallowing.
ALTER TABLE orders
    ADD CONSTRAINT fk_order_address
    FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id)
    ON DELETE SET NULL;

-- Confirm final state.
SHOW CREATE TABLE orders;
