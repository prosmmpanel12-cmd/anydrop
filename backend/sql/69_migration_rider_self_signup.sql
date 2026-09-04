-- ============================================================
-- Anydrop — Migration 69: Rider self-signup (independent rider model)
--
-- Context: the `riders` table created in 01_schema.sql is
-- restaurant-scoped — a restaurant creates the row itself
-- (username/password, `restaurant_id NOT NULL`), the same way
-- `restaurant_staff` works. That's a different product: a delivery
-- boy tied to one restaurant, not a platform-wide rider who can pick
-- up from any restaurant in their area.
--
-- App owner asked for a rider app where the rider signs themself up
-- with name + email + mobile + service area (dropdown, pre-filled by
-- GPS) — i.e. the same self-serve, admin-approved onboarding pattern
-- `restaurants` already uses (status pending/approved/rejected/
-- suspended, resolve_service_area() on signup — see
-- restaurant-signup.php). This migration adds that layer on top of
-- the existing table rather than replacing it:
--
--   - `restaurant_id` is made NULLABLE. Existing restaurant-created
--     rider rows (if any exist in a live DB) are untouched — they
--     keep their restaurant_id and keep working exactly as before,
--     nothing about that path changes. A NEW platform rider (this
--     migration's feature) is inserted with restaurant_id = NULL —
--     "not tied to one restaurant" is the whole point.
--   - `email`, `password_hash` reuse — riders log in the same
--     email-OTP way customers do (rider-request-otp.php /
--     rider-verify-otp.php in this same batch), not username/password
--     like a restaurant-created rider currently does. Both columns
--     already existed (username/password_hash) for the old path;
--     this adds `email` alongside rather than repurposing `username`,
--     since a restaurant-created rider may not have one and we don't
--     want to force a backfill.
--   - `service_area_id` FK -> service_areas(id) (migration 30). This
--     is what the app's signup dropdown writes to, and what
--     resolve_service_area() (lib/geo.php) auto-fills from GPS at
--     signup — same helper restaurant-signup.php already uses, no new
--     resolution logic needed.
--   - `status` ENUM mirrors `restaurants.status` exactly
--     (pending/approved/rejected/suspended) — a self-signed-up rider
--     needs the same admin-approval gate a restaurant does (identity/
--     vehicle verification) before they can go online and receive
--     orders. Existing restaurant-created riders backfill to
--     'approved' (see UPDATE below) so this migration doesn't lock
--     out any rider a restaurant already vetted and added themselves.
--   - `rejection_reason` mirrors `restaurants.rejection_reason` for
--     the same reason restaurant-login.php surfaces it on a blocked
--     login.
--
-- Same idempotent CONTINUE-HANDLER-for-1060 pattern as every other
-- ALTER-TABLE-ADD-COLUMN migration in this project (this environment's
-- DB user can't read information_schema to check column existence
-- directly). Safe to run any number of times, in any partial-prior
-- state.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- 1. Make restaurant_id nullable (platform riders aren't tied to one restaurant) ----------

ALTER TABLE riders MODIFY COLUMN restaurant_id BIGINT UNSIGNED NULL;

-- ---------- 2. Add new columns (idempotent — see header) ----------

DELIMITER $$

CREATE PROCEDURE anydrop_add_column_if_missing_69(
    IN tbl VARCHAR(64), IN col VARCHAR(64), IN coldef TEXT
)
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- 1060 = Duplicate column name
    SET @ddl = CONCAT('ALTER TABLE ', tbl, ' ADD COLUMN ', col, ' ', coldef);
    SET @stmt = @ddl;
    PREPARE s FROM @stmt;
    EXECUTE s;
    DEALLOCATE PREPARE s;
END$$

DELIMITER ;

CALL anydrop_add_column_if_missing_69('riders', 'email', "VARCHAR(150) NULL AFTER name");
CALL anydrop_add_column_if_missing_69('riders', 'service_area_id', "BIGINT UNSIGNED NULL AFTER vehicle_number");
CALL anydrop_add_column_if_missing_69('riders', 'latitude', "DECIMAL(10,8) NULL AFTER service_area_id");
CALL anydrop_add_column_if_missing_69('riders', 'longitude', "DECIMAL(11,8) NULL AFTER latitude");
CALL anydrop_add_column_if_missing_69('riders', 'status', "ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending' AFTER is_active");
CALL anydrop_add_column_if_missing_69('riders', 'rejection_reason', "VARCHAR(255) NULL AFTER status");
CALL anydrop_add_column_if_missing_69('riders', 'vehicle_doc_url', "VARCHAR(255) NULL AFTER vehicle_number");
CALL anydrop_add_column_if_missing_69('riders', 'id_doc_url', "VARCHAR(255) NULL AFTER vehicle_doc_url");

DROP PROCEDURE IF EXISTS anydrop_add_column_if_missing_69;

-- ---------- 3. Backfill existing restaurant-created riders as already-approved ----------
-- A restaurant that added its own rider already vetted them manually —
-- don't retroactively lock them out behind the new admin-approval gate.
-- Only affects rows where status is still the just-added column default.

UPDATE riders SET status = 'approved' WHERE restaurant_id IS NOT NULL AND status = 'pending';

-- ---------- 4. Unique index on email (nullable-safe — MySQL allows multiple NULLs under UNIQUE) ----------

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'riders' AND index_name = 'uq_riders_email'
);
SET @ddl := IF(@idx_exists = 0, 'ALTER TABLE riders ADD UNIQUE INDEX uq_riders_email (email)', 'SELECT 1');
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ---------- 5. FK to service_areas (idempotent — ignore 1826 "duplicate FK" / 1005 if already present) ----------

DELIMITER $$

CREATE PROCEDURE anydrop_add_fk_if_missing_69()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1826 BEGIN END; -- 1826 = Duplicate foreign key constraint name
    DECLARE CONTINUE HANDLER FOR 1005 BEGIN END; -- 1005 = Can't create table (FK already effectively present)
    ALTER TABLE riders
        ADD CONSTRAINT fk_riders_service_area FOREIGN KEY (service_area_id) REFERENCES service_areas(id);
END$$

DELIMITER ;

CALL anydrop_add_fk_if_missing_69();
DROP PROCEDURE IF EXISTS anydrop_add_fk_if_missing_69;

-- ---------- 6. Index for admin's pending-riders queue + area-based order assignment ----------

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'riders' AND index_name = 'idx_riders_status_area'
);
SET @ddl := IF(@idx_exists = 0, 'ALTER TABLE riders ADD INDEX idx_riders_status_area (status, service_area_id)', 'SELECT 1');
PREPARE s FROM @ddl;
EXECUTE s;
DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = 1;
