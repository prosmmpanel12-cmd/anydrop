-- Anydrop — Migration 58: Temp Closure / Holiday Scheduling (§3, today.md 2026-08-28)
--
-- today.md §3 flagged the existing temp-closed toggle
-- (restaurants.operational_status = 'temp_closed', see status-update.php)
-- as plain ON/OFF only — no resume-time, no multi-day range, no
-- recurring weekly holiday. This migration adds both pieces asked for:
--
-- 1. `restaurants.temp_closed_until` — an optional resume-timestamp for
--    the existing on-demand toggle, so "closed until 6 PM" is
--    expressible without a restaurant having to remember to flip the
--    switch back manually. Nullable, backward-compatible: every
--    existing temp_closed row simply has NULL here and behaves exactly
--    as before (indefinite pause until manually resumed) — see
--    lib/restaurant_status.php's compute_restaurant_status() for how
--    this is read.
--
-- 2. New `restaurant_closures` table — separate from the single-column
--    resume-time above because "multi-day range" (e.g. Diwali week) and
--    "recurring weekly holiday" (e.g. every Monday) are fundamentally a
--    *list* of scheduled closures, not a single value a restaurant can
--    only ever have one of at a time. A restaurant can have several
--    (a standing "closed every Monday" PLUS a one-off "closed for
--    renovation Oct 5-8") simultaneously — a single column can't hold
--    that.
--
-- Same idempotent-rerun pattern as migration 56/57 (DELIMITER $$ ...
-- CONTINUE HANDLER), since a plain ALTER TABLE/CREATE TABLE isn't
-- naturally safe to re-run.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_58_add_temp_closed_until $$
CREATE PROCEDURE anydrop_migration_58_add_temp_closed_until()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE restaurants ADD COLUMN temp_closed_until DATETIME NULL AFTER operational_status;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_58_create_closures_table $$
CREATE PROCEDURE anydrop_migration_58_create_closures_table()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    CREATE TABLE restaurant_closures (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        restaurant_id BIGINT UNSIGNED NOT NULL,
        -- 'date_range': one-off closure spanning start_date..end_date
        -- inclusive (both dates, e.g. a 3-day Diwali closure).
        -- 'weekly_recurring': closed every week on day_of_week — same
        -- 1=Monday..7=Sunday convention restaurants.working_days
        -- already uses (see lib/restaurant_status.php), so no new
        -- day-numbering scheme to keep straight against the existing
        -- working-hours logic.
        closure_type ENUM('date_range', 'weekly_recurring') NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        day_of_week TINYINT UNSIGNED NULL,
        reason VARCHAR(255) NULL,
        -- Soft-disable, same convention as menu_item_addon_groups.is_active
        -- (migration 57) and every other restaurant-facing "delete" in
        -- this app — closures-delete.php flips this rather than a hard
        -- DELETE, so an accidentally-cancelled holiday schedule can be
        -- restored without re-entering it (no dedicated re-enable
        -- endpoint yet either, same asymmetry addon-groups-delete.php's
        -- kdoc already flags for that feature).
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_closure_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END $$

DELIMITER ;

CALL anydrop_migration_58_add_temp_closed_until();
CALL anydrop_migration_58_create_closures_table();

DROP PROCEDURE IF EXISTS anydrop_migration_58_add_temp_closed_until;
DROP PROCEDURE IF EXISTS anydrop_migration_58_create_closures_table;

-- Index for the batch "which of these restaurant ids have an active
-- closure covering today/this day-of-week" lookup used by
-- restaurants/list.php, search.php, and restaurants/menu.php (see
-- lib/restaurant_closures.php's get_restaurants_with_active_closure()) —
-- that query always filters on restaurant_id + is_active together.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_58_add_closure_index $$
CREATE PROCEDURE anydrop_migration_58_add_closure_index()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END; -- ER_DUP_KEYNAME
    ALTER TABLE restaurant_closures ADD INDEX idx_closure_restaurant_active (restaurant_id, is_active);
END $$

DELIMITER ;

CALL anydrop_migration_58_add_closure_index();

DROP PROCEDURE IF EXISTS anydrop_migration_58_add_closure_index;
