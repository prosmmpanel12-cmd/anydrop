-- Anydrop — Migration 56: Restaurant-side "Report review" (§7, today.md 2026-08-28)
--
-- Extends migration 54's review_reports table so a restaurant can report
-- its own review (e.g. suspected fake review) the same way a customer
-- can. customer_id becomes nullable, new nullable restaurant_id column
-- added with its own FK + unique constraint mirroring the customer one.
-- Application layer (report-review.php endpoints) enforces that exactly
-- one of customer_id/restaurant_id is set per row — this migration only
-- makes the schema able to hold that shape, it can't express an XOR
-- constraint itself.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_56_customer_id_nullable $$
CREATE PROCEDURE anydrop_migration_56_customer_id_nullable()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    ALTER TABLE review_reports MODIFY COLUMN customer_id BIGINT UNSIGNED NULL;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_56_add_restaurant_id $$
CREATE PROCEDURE anydrop_migration_56_add_restaurant_id()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE review_reports ADD COLUMN restaurant_id BIGINT UNSIGNED NULL AFTER customer_id;
END $$

DELIMITER ;

CALL anydrop_migration_56_customer_id_nullable();
CALL anydrop_migration_56_add_restaurant_id();

DROP PROCEDURE IF EXISTS anydrop_migration_56_customer_id_nullable;
DROP PROCEDURE IF EXISTS anydrop_migration_56_add_restaurant_id;

-- FK + unique constraint added separately (outside the ADD COLUMN
-- procedures) so a re-run after a partial failure doesn't choke trying
-- to add a column that's already there but still needs its constraint.
-- MySQL has no IF NOT EXISTS for constraints, so these use the same
-- "ignore duplicate" trick via a dedicated handler code.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_56_add_fk $$
CREATE PROCEDURE anydrop_migration_56_add_fk()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1826 BEGIN END; -- ER_FK_DUP_NAME
    ALTER TABLE review_reports
        ADD CONSTRAINT fk_review_report_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id);
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_56_add_unique $$
CREATE PROCEDURE anydrop_migration_56_add_unique()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END; -- ER_DUP_KEYNAME
    -- One report per restaurant per review, same abuse-protection shape
    -- as uq_review_report_once (migration 54). NULL restaurant_id rows
    -- (i.e. customer reports) are unaffected — MySQL unique indexes treat
    -- each NULL as distinct, so this never blocks customer-side inserts.
    ALTER TABLE review_reports
        ADD UNIQUE KEY uq_review_report_once_restaurant (review_id, restaurant_id);
END $$

DELIMITER ;

CALL anydrop_migration_56_add_fk();
CALL anydrop_migration_56_add_unique();

DROP PROCEDURE IF EXISTS anydrop_migration_56_add_fk;
DROP PROCEDURE IF EXISTS anydrop_migration_56_add_unique;
