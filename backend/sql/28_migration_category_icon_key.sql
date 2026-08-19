-- Migration 28 — bundled category icon picker (docs/22_UI_UX_Overhaul_
-- Feedback item 1, "stop requiring an upload for every category").
--
-- App owner's decision (see NEXT_SESSION_PROMPT.md / 00_Status.md): option
-- 1, a bundled fixed icon set shipped inside the app (drawable resources),
-- restaurant picks from a grid. No network dependency, no "search more"
-- API wired up yet — that stays a possible future addition on top, not
-- built here.
--
-- icon_key stores which bundled icon was picked (e.g. "biryani",
-- "beverages" — see restaurant app's CategoryIcons.kt for the fixed set of
-- valid keys). Deliberately a free VARCHAR, not an ENUM/FK to a lookup
-- table: the valid-key list lives client-side in CategoryIcons.kt (same
-- reasoning as other client-owned enums in this project), so the column
-- just stores whatever key the app sent — categories-create.php/
-- categories-update.php don't validate it against a fixed list server-side
-- (an unrecognized key just means the app falls back to the placeholder
-- icon when rendering, same as a broken image_url would).
--
-- icon_key and image_url (added by 22_migration_category_image.sql) are
-- mutually exclusive at the UI level — a category shows either an
-- uploaded photo or a bundled icon, never both — but this migration does
-- NOT add a CHECK constraint enforcing that. categories-create.php /
-- categories-update.php enforce it in application code instead (setting
-- one clears the other), same "don't over-constrain the schema for a
-- UI-level rule" call as elsewhere in this project. Both columns are
-- simply nullable independently.
--
-- Idempotent conditional-ALTER pattern, same as 22_migration_category_image.sql.

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'menu_categories' AND COLUMN_NAME = 'icon_key');
SET @sql := IF(@c = 0, 'ALTER TABLE menu_categories ADD COLUMN icon_key VARCHAR(40) NULL AFTER image_url', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
