-- Anydrop — Migration 63: Restaurant Staff / RBAC (PENDING.md item 3,
-- today.md §10)
--
-- Today, one restaurant login = the owner, full stop —
-- `restaurants.owner_email`/`password_hash` is the only credential, and
-- every restaurant-side endpoint's `require_auth('restaurant')` call
-- trusts that whoever holds a valid restaurant token IS the owner.
-- This migration adds a second, subordinate login path — named staff
-- accounts with a role — without touching the owner's own login at
-- all: `restaurants.owner_email`/`password_hash` and
-- `auth/restaurant-login.php` are completely unchanged. The owner
-- always has full access and is never represented by a row in the new
-- `restaurant_staff` table — "owner" is not a role you can be assigned,
-- it's the account the restaurant itself is.
--
-- Two pieces:
--
-- 1. `restaurant_staff` (new table) — one row per named staff login.
--    `role` is one of manager/kitchen/cashier (owner deliberately
--    excluded — see above). `username` is globally unique (not scoped
--    per-restaurant) purely because the login endpoint has to find the
--    right restaurant_staff row from a bare username before it knows
--    which restaurant is involved at all — same reason
--    `restaurants.owner_email` is globally unique today.
--
-- 2. `auth_tokens.staff_id` (new nullable column) — lets a staff
--    member's session be distinguished from the owner's own, WITHOUT
--    changing what `owner_id` means for that owner_type='restaurant'
--    token. `owner_id` still always holds the restaurant's own id
--    (never the staff row's id) for both an owner token AND a staff
--    token — this is the key design choice that keeps every one of the
--    ~47 existing `restaurant/*.php` endpoints working completely
--    unchanged: any code that does `(int) $owner['owner_id']` as "the
--    restaurant id" continues to get exactly that, whether an owner or
--    a staff member is actually logged in. `staff_id` is NULL for an
--    owner's own token (the normal, unchanged case) and holds the
--    `restaurant_staff.id` for a staff token — `lib/auth.php`'s
--    `require_auth()` resolves this into a `role` key
--    (`'owner'` when `staff_id` is null, else the staff row's own
--    `role`) that new/updated endpoints can gate on via
--    `lib/permissions.php`'s `require_restaurant_permission()`.
--
-- Same idempotent CONTINUE-HANDLER pattern every ALTER/CREATE TABLE
-- migration in this project uses (see migration 58's own header for
-- the fullest explanation of why) — safe to re-run.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_63_create_staff_table $$
CREATE PROCEDURE anydrop_migration_63_create_staff_table()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    CREATE TABLE restaurant_staff (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        restaurant_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        -- Globally unique, same reasoning as restaurants.owner_email —
        -- see file header. Staff pick their own username at creation
        -- time (set by the owner via staff-create.php), not derived
        -- from anything else.
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        -- 'owner' deliberately not a value here — see file header.
        role ENUM('manager', 'kitchen', 'cashier') NOT NULL DEFAULT 'kitchen',
        -- Owner-facing disable switch (staff-update.php), same
        -- soft-disable convention as menu_item_addon_groups.is_active
        -- (migration 57) — flip this off rather than delete outright
        -- so a mistakenly-removed staff account can be restored. Also
        -- re-checked on every authenticated request by
        -- lib/auth.php's require_auth(), same "don't wait for token
        -- expiry" principle already applied to restaurants.status.
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL,
        CONSTRAINT fk_restaurant_staff_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
END $$

DELIMITER ;

CALL anydrop_migration_63_create_staff_table();

DROP PROCEDURE IF EXISTS anydrop_migration_63_create_staff_table;

-- Lookup index for staff-list.php's "every staff row for this
-- restaurant" query — same shape as every other restaurant-scoped
-- list endpoint in this project.
DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_63_add_staff_restaurant_index $$
CREATE PROCEDURE anydrop_migration_63_add_staff_restaurant_index()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END; -- ER_DUP_KEYNAME
    ALTER TABLE restaurant_staff ADD INDEX idx_staff_restaurant (restaurant_id, deleted_at);
END $$

DELIMITER ;

CALL anydrop_migration_63_add_staff_restaurant_index();

DROP PROCEDURE IF EXISTS anydrop_migration_63_add_staff_restaurant_index;

-- auth_tokens.staff_id — see file header point 2. NULL on every
-- existing row (every token issued before this migration was
-- necessarily an owner token), so no behavior change for anyone
-- already logged in.
DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_63_add_token_staff_id $$
CREATE PROCEDURE anydrop_migration_63_add_token_staff_id()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE auth_tokens ADD COLUMN staff_id BIGINT UNSIGNED NULL AFTER owner_id;
END $$

DELIMITER ;

CALL anydrop_migration_63_add_token_staff_id();

DROP PROCEDURE IF EXISTS anydrop_migration_63_add_token_staff_id;
