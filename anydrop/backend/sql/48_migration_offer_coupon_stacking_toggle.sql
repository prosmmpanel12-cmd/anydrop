-- ============================================================
-- Anydrop — Migration 48: Offer ↔ Coupon stacking toggle
-- (app owner ask, 2026-08-25: "offer engine mai ek toggle rakho
-- allow user to use coupon on offer item or not")
--
-- doc 20 §13 / migration 47's stacking rule already lets 1 auto-
-- applied item/restaurant offer + 1 coupon + 1 free-delivery offer
-- combine together, hardcoded as always-on. This migration makes the
-- "+ 1 coupon" half of that rule OPT-OUT per offer: a restaurant can
-- mark a specific promo_offers row as not combinable with any coupon
-- (e.g. a steep "Buy 1 Get 1" they don't want stacked with a ₹100-off
-- code on top). Free-delivery offers are untouched — they're a
-- delivery-fee waiver, not an item discount, so doc 20 §13's separate
-- delivery slot was never in tension with a coupon to begin with.
--
-- Defaults to 1 (allowed) so every existing offer keeps today's
-- behavior unchanged — same "don't silently change behavior for
-- existing rows" convention every prior additive column in this
-- table (migration 47 itself) already follows.
--
-- Safe to re-run (CONTINUE-HANDLER-for-1060 pattern, same as every
-- migration since 25/46/47).
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_48_safe $$

CREATE PROCEDURE anydrop_migration_48_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE promo_offers
        ADD COLUMN allow_coupon_stacking TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
END $$

DELIMITER ;

CALL anydrop_migration_48_safe();
DROP PROCEDURE anydrop_migration_48_safe;

-- Confirm final state — SHOW/basic SELECT only (this environment's DB
-- user can't read information_schema, same constraint every migration
-- since 11c has worked around).
SHOW COLUMNS FROM promo_offers LIKE 'allow_coupon_stacking';
