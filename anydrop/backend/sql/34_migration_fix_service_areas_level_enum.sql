-- Migration 34 — fix service_areas.level ENUM drift.
--
-- ROOT CAUSE: migration 30 used `CREATE TABLE IF NOT EXISTS`. That's
-- safe the FIRST time a migration runs, but it means once
-- `service_areas` existed on a given DB, later edits to migration 30's
-- own file (the 'area'->'village' rename, then the 'city'+'village'->
-- 'city_village' merge) never reached that already-created table — a
-- CREATE ... IF NOT EXISTS is a no-op when the table's already there,
-- it does NOT alter existing columns. So the live table's `level` ENUM
-- got stuck at whatever it was the day it was first created, while
-- backend/admin/areas.php moved on to expect 'city_village'/'area'.
-- That mismatch is exactly what threw:
--   PDOException: SQLSTATE[01000]: Warning: 1265 Data truncated for
--   column 'level' at row 1
-- — MySQL's answer to inserting a string that isn't one of the ENUM's
-- actual defined members.
--
-- This migration is idempotent and safe to run regardless of which
-- exact stale ENUM shape you're on (whether it still says
-- ('state','district','city','area') from the very first version, or
-- ('state','district','city','village') from the rename in between) —
-- step 1 widens the ENUM to a superset that accepts every value that
-- could possibly already be sitting in a row, step 2 remaps any old
-- values to their current names, step 3 narrows down to the final
-- correct ENUM. No rows are deleted; nothing here touches data outside
-- the `level` column itself.
--
-- Run this once, after migration 30/32, on any DB where areas.php's
-- Add-area form throws the "Data truncated for column 'level'" error
-- above.

ALTER TABLE service_areas
    MODIFY COLUMN level ENUM('state','district','city','village','city_village','area') NOT NULL;

UPDATE service_areas SET level = 'city_village' WHERE level IN ('city', 'village');

ALTER TABLE service_areas
    MODIFY COLUMN level ENUM('state','district','city_village','area') NOT NULL;
