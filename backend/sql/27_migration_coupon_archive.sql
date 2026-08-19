-- Migration 27 — Coupons: real archive/delete state.
--
-- App-owner UI/UX feedback session (doc 22, 2026-08-18): "toggle off
-- forever" (is_active = 0) was the only lifecycle control a restaurant
-- had for a coupon. App owner asked for a real delete/archive option
-- IN ADDITION to the existing on/off toggle, not instead of it — see
-- doc 22 and NEXT_SESSION_PROMPT.md's follow-up answer ("also add off
-- on delete and other possible option").
--
-- Deliberately a soft `is_archived` flag, never a hard DELETE FROM
-- coupons: coupon_usages (01_Database_Schema.md §6) has a FK to
-- coupons.id, and a restaurant's usage history/reporting needs that
-- row to keep existing even once the coupon itself is retired — same
-- reasoning coupons-update.php's own kdoc already gives for why
-- is_active is a flip, not a delete.
--
-- is_active vs is_archived stay orthogonal on purpose:
--   is_active   = "off" but still visible/manageable in the coupon list,
--                 can be flipped back on any time (existing behaviour).
--   is_archived = "removed" from the restaurant's active coupon list,
--                 kept only for history — /cart/validate must reject an
--                 archived coupon exactly like an inactive one (both
--                 checked together, see coupons-update.php's companion
--                 change and cart-validate's existing is_active check).
--   archived_at = when it was archived, for display / eventual cleanup
--                 tooling; NULL means "never archived" and is cleared
--                 again on unarchive so it always reflects the most
--                 recent archive event, not the first one.
--
-- Idempotent conditional-ALTER pattern, same as 18/22/16/06/17 —
-- IF-COUNT-THEN-ALTER via PREPARE/EXECUTE so re-running this migration
-- against a DB that already has the columns is a safe no-op.

SET @c1 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'is_archived');
SET @sql1 := IF(@c1 = 0, 'ALTER TABLE coupons ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public', 'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @c2 := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'archived_at');
SET @sql2 := IF(@c2 = 0, 'ALTER TABLE coupons ADD COLUMN archived_at DATETIME NULL AFTER is_archived', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Confirm final state.
SHOW COLUMNS FROM coupons LIKE 'is_archived';
SHOW COLUMNS FROM coupons LIKE 'archived_at';
