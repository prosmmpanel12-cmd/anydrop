-- ============================================================
-- Anydrop — Migration 30: Service Area Management
--
-- Implements recall.md item 2 / docs/19_Admin_Panel_Full_Spec_And_Payment_
-- Email_Architecture_2026-08-14.md §2 — the State → District → City →
-- Area hierarchy that restaurant visibility, banner targeting, COD
-- rules, and analytics filters all key off (recall.md items 2-5).
--
-- Single self-referencing adjacency-list table (parent_id), not four
-- separate State/District/City/Area tables — matches doc 19 §2's
-- reasoning: simpler to query/extend, and variable depth is fine (some
-- areas may not need a full 4-level chain).
--
-- `center_lat`/`center_lng`/`radius_km` are only meaningful at
-- level='area' — that's what customer_addresses.area_id resolution
-- (recall.md item 3) checks against. v1 is circle-radius, not polygon
-- geofencing (doc 19 §2's explicitly flagged v1 simplification).
--
-- restaurants.area_id / customer_addresses.area_id are additive nullable
-- FKs — existing rows stay NULL (unassigned) until an admin assigns them
-- or the resolution job runs; nothing existing breaks. Per recall.md
-- item 3, area match is meant to be layered ON TOP of the existing
-- delivery_radius_km check, not to replace it.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as migration 25 for
-- the two ALTER TABLE ADD COLUMN statements (this environment's DB user
-- can't read information_schema). CREATE TABLE uses IF NOT EXISTS. Safe
-- to run any number of times, in any partial-prior-state.
-- ============================================================

-- ---------- 1. service_areas hierarchy table ----------

CREATE TABLE IF NOT EXISTS service_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,                 -- NULL = top level (State)
    level ENUM('state','district','city','area') NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,         -- disable without deleting (recall.md §2)
    center_lat DECIMAL(10,8) NULL,                   -- 'area' level only: resolution center
    center_lng DECIMAL(11,8) NULL,
    radius_km DECIMAL(4,1) NULL,                     -- 'area' level only: resolution radius
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_area_parent FOREIGN KEY (parent_id) REFERENCES service_areas(id),
    INDEX idx_area_parent (parent_id),
    INDEX idx_area_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 2. restaurants.area_id ----------

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_30_restaurants_safe $$

CREATE PROCEDURE anydrop_migration_30_restaurants_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE restaurants
        ADD COLUMN area_id BIGINT UNSIGNED NULL AFTER longitude,
        ADD CONSTRAINT fk_restaurant_area FOREIGN KEY (area_id) REFERENCES service_areas(id);
END $$

DELIMITER ;

CALL anydrop_migration_30_restaurants_safe();
DROP PROCEDURE anydrop_migration_30_restaurants_safe;

-- ---------- 3. customer_addresses.area_id ----------

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_30_addresses_safe $$

CREATE PROCEDURE anydrop_migration_30_addresses_safe()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060
    BEGIN
        -- column already exists — nothing to do, keep going
    END;

    ALTER TABLE customer_addresses
        ADD COLUMN area_id BIGINT UNSIGNED NULL AFTER longitude,
        ADD CONSTRAINT fk_address_area FOREIGN KEY (area_id) REFERENCES service_areas(id);
END $$

DELIMITER ;

CALL anydrop_migration_30_addresses_safe();
DROP PROCEDURE anydrop_migration_30_addresses_safe;

-- Confirm final state — uses SHOW, not information_schema.
SHOW TABLES LIKE 'service_areas';
SHOW COLUMNS FROM restaurants LIKE 'area_id';
SHOW COLUMNS FROM customer_addresses LIKE 'area_id';
