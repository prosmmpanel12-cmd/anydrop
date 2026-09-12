-- ============================================================
-- Anydrop — Migration 84: Rider assignment offer timeout, 40s -> 180s
--
-- App-owner ask, 2026-09-11: a rider was getting only 40 seconds (the
-- original deep-plan §8 default) to accept/reject an incoming
-- delivery offer — too short in practice. Raised to 180s (3 minutes).
--
-- Migration 72's own INSERT used `ON DUPLICATE KEY UPDATE key = key`
-- (a deliberate no-op on conflict, so re-running that file never
-- clobbers an admin's own later edit to the setting) — which means it
-- will NOT pick up a changed seed value on an already-migrated DB.
-- This migration explicitly UPDATEs the existing row instead, so it
-- actually takes effect regardless of whether migration 72 already
-- ran. Uses UPDATE, not another INSERT..ON DUPLICATE KEY, since the
-- row is known to already exist by this point in the migration chain
-- and the intent here genuinely is "change the value", not "seed it
-- if missing" (72 already covers the fresh-install case, with the
-- same 180 default via 72's own file being edited alongside this one
-- — see that file's comment).
-- ============================================================

UPDATE app_settings
SET `value` = '180',
    description = 'Seconds an offered delivery stays open before it expires and moves to the next eligible rider. Raised from the original 40s default to 180s (3 min) per app-owner ask, 2026-09-11 — see migration 84.'
WHERE `key` = 'rider_assignment_timeout_seconds';

-- Safety net for a database that somehow never ran migration 72's
-- INSERT at all (shouldn't happen in practice, since 72 is a
-- prerequisite for the whole assignment engine to function, but this
-- keeps 84 safely re-runnable/order-independent rather than assuming).
INSERT INTO app_settings (`key`, `value`, description)
SELECT 'rider_assignment_timeout_seconds', '180',
       'Seconds an offered delivery stays open before it expires and moves to the next eligible rider. 180s (3 min) per app-owner ask, 2026-09-11 — see migration 84.'
WHERE NOT EXISTS (
    SELECT 1 FROM app_settings WHERE `key` = 'rider_assignment_timeout_seconds'
);
