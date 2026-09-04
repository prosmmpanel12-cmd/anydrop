-- ============================================================
-- Anydrop — Migration 49: Offer apply_mode (default vs coupon_based)
-- (app owner ask, 2026-08-25 — "offer engine mein B1G1 jaisa offer 2
-- tarah ka ho: ek copun use karne par apply, dusra abhi jaisa default
-- auto-apply already chal raha hai")
--
-- Every promo_offers row (any offer_type — buy_x_get_y, percent_discount,
-- flat_discount, quantity_deal, buy_x_for_y, free_delivery) can now be
-- created in one of two apply_mode values:
--   'default'      — unchanged existing behaviour. Auto-applied by
--                     select_best_auto_offer()/select_best_free_delivery_offer()
--                     with no code entry, exactly as today.
--   'coupon_based' — same offer mechanics, but only considered when the
--                     customer types the offer's own `code` at checkout
--                     (same input box coupons already use — price_cart()'s
--                     coupon block now also checks promo_offers by code
--                     when no matching row exists in `coupons`). Never
--                     auto-applied; select_best_auto_offer() /
--                     select_best_free_delivery_offer() both skip these.
--
-- `code` is only meaningful (and required by offers-create.php/
-- offers-update.php) when apply_mode = 'coupon_based'; NULL for
-- 'default' offers. UNIQUE (nullable — MySQL allows multiple NULLs)
-- so two coupon_based offers can never collide on the same code, same
-- convention coupons.code already follows.
--
-- `is_public` mirrors coupons.is_public (migration 18) — governs only
-- whether a coupon_based offer is *suggested* on the "View all offers"
-- list (coupons/list.php); a private coupon_based offer remains fully
-- redeemable by typed code either way, same "list vs redeem are
-- separate checks" split coupons.is_public already established. Not
-- read at all for apply_mode = 'default' offers (nothing to list —
-- they're never coupon-entry-based in the first place).
--
-- Idempotent conditional-ALTER pattern, same as 06/16/17/18.
-- ============================================================

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_offers' AND COLUMN_NAME = 'apply_mode');
SET @sql1 := IF(@c1 = 0, "ALTER TABLE promo_offers ADD COLUMN apply_mode ENUM('default','coupon_based') NOT NULL DEFAULT 'default' AFTER offer_type", 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_offers' AND COLUMN_NAME = 'code');
SET @sql2 := IF(@c2 = 0, 'ALTER TABLE promo_offers ADD COLUMN code VARCHAR(50) NULL AFTER apply_mode', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @c3 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_offers' AND COLUMN_NAME = 'is_public');
SET @sql3 := IF(@c3 = 0, 'ALTER TABLE promo_offers ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER code', 'SELECT 1');
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Unique index on code — separate idempotent step since "index already
-- exists" raises error 1061, not 1060, same reasoning migration 47's
-- own FK step documents for why these can't share one CONTINUE HANDLER.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promo_offers' AND INDEX_NAME = 'uniq_offer_code');
SET @sqlidx := IF(@idx = 0, 'ALTER TABLE promo_offers ADD UNIQUE INDEX uniq_offer_code (code)', 'SELECT 1');
PREPARE stmtidx FROM @sqlidx;
EXECUTE stmtidx;
DEALLOCATE PREPARE stmtidx;

SHOW COLUMNS FROM promo_offers LIKE 'apply_mode';
SHOW COLUMNS FROM promo_offers LIKE 'code';
SHOW COLUMNS FROM promo_offers LIKE 'is_public';
