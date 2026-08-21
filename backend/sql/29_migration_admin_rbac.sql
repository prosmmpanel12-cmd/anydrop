-- ============================================================
-- Anydrop — Migration 29: Admin Roles & Permissions (RBAC)
--
-- Implements docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_
-- 2026-08-14.md §1 — replaces the flat `admins.role ENUM('super_admin',
-- 'staff')` (never actually enforced anywhere in code — grepped
-- backend/lib and backend/admin, the enum was written once at seed
-- time and never read back) with a real per-module/per-action
-- permission grid: admin_roles / admin_permissions /
-- admin_role_permissions, plus admins.role_id + a few admin-profile
-- columns the spec calls for (name, email, is_active, last_login_at).
--
-- Same idempotent CONTINUE-HANDLER pattern as migrations 11c/25/26
-- (this environment's DB user can't read information_schema, so
-- swallowing the specific "already exists" MySQL error code is the
-- safe re-run strategy). CREATE TABLE uses IF NOT EXISTS; seed rows use
-- INSERT IGNORE (unique keys on `admin_roles.name` / `admin_permissions.
-- key`) so reseeding is a no-op, not a duplicate-row error. Safe to run
-- any number of times, in any partial-prior-state.
--
-- Existing admin rows: every current admin (all of whom had the old
-- ENUM default 'super_admin', or unenforced 'staff' — nothing in code
-- ever branched on that value) is migrated onto the new Super Admin
-- role, not left locked out. The app owner can create narrower roles
-- (Finance Admin, Restaurant Manager, ...) and move specific admins
-- onto them afterwards from the new Roles screen — this migration's
-- only job is to not silently reduce anyone's access.
-- ============================================================

-- ---------- 1. New tables ----------

CREATE TABLE IF NOT EXISTS admin_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,          -- 'Super Admin', 'Finance Admin', ...
    is_system_role TINYINT(1) NOT NULL DEFAULT 0, -- Super Admin = 1, can't be deleted/edited from the UI
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(60) UNIQUE NOT NULL,   -- e.g. 'restaurants_edit', 'payouts_manage'
    module VARCHAR(40) NOT NULL,         -- 'restaurants', 'payouts', 'orders', ...
    action VARCHAR(20) NOT NULL          -- 'view','add','edit','delete','export','approve','reject','manage','send'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_arp_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_arp_perm FOREIGN KEY (permission_id) REFERENCES admin_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- 2. admins table: new profile/role columns ----------
-- Added nullable first (existing rows get backfilled below), each
-- ADD COLUMN wrapped individually so a partial prior run doesn't abort
-- the whole statement on the first already-exists column.

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_role_id $$
CREATE PROCEDURE anydrop_migration_29_add_role_id()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE admins ADD COLUMN role_id BIGINT UNSIGNED NULL AFTER role;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_name $$
CREATE PROCEDURE anydrop_migration_29_add_name()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE admins ADD COLUMN name VARCHAR(100) NULL AFTER role_id;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_email $$
CREATE PROCEDURE anydrop_migration_29_add_email()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE admins ADD COLUMN email VARCHAR(150) NULL AFTER name;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_email_unique $$
CREATE PROCEDURE anydrop_migration_29_add_email_unique()
BEGIN
    -- 1061 = ER_DUP_KEYNAME (index already exists on a re-run)
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;
    ALTER TABLE admins ADD UNIQUE KEY uq_admins_email (email);
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_is_active $$
CREATE PROCEDURE anydrop_migration_29_add_is_active()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_last_login $$
CREATE PROCEDURE anydrop_migration_29_add_last_login()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE admins ADD COLUMN last_login_at TIMESTAMP NULL AFTER is_active;
END $$

DELIMITER ;

CALL anydrop_migration_29_add_role_id();
CALL anydrop_migration_29_add_name();
CALL anydrop_migration_29_add_email();
CALL anydrop_migration_29_add_email_unique();
CALL anydrop_migration_29_add_is_active();
CALL anydrop_migration_29_add_last_login();

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_role_id;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_name;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_email;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_email_unique;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_is_active;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_last_login;

-- ---------- 3. Seed permission keys (doc 19 §1's exact list) ----------

INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('dashboard_view', 'dashboard', 'view'),
    ('restaurants_view', 'restaurants', 'view'),
    ('restaurants_edit', 'restaurants', 'edit'),
    ('restaurants_delete', 'restaurants', 'delete'),
    ('restaurants_approve', 'restaurants', 'approve'),
    ('restaurants_export', 'restaurants', 'export'),
    ('riders_view', 'riders', 'view'),
    ('riders_edit', 'riders', 'edit'),
    ('riders_delete', 'riders', 'delete'),
    ('riders_approve', 'riders', 'approve'),
    ('riders_export', 'riders', 'export'),
    ('customers_view', 'customers', 'view'),
    ('customers_edit', 'customers', 'edit'),
    ('customers_suspend', 'customers', 'suspend'),
    ('customers_delete', 'customers', 'delete'),
    ('customers_export', 'customers', 'export'),
    ('areas_view', 'areas', 'view'),
    ('areas_edit', 'areas', 'edit'),
    ('areas_delete', 'areas', 'delete'),
    ('categories_view', 'categories', 'view'),
    ('categories_edit', 'categories', 'edit'),
    ('categories_delete', 'categories', 'delete'),
    ('coupons_view', 'coupons', 'view'),
    ('coupons_edit', 'coupons', 'edit'),
    ('coupons_delete', 'coupons', 'delete'),
    ('coupons_export', 'coupons', 'export'),
    ('banners_view', 'banners', 'view'),
    ('banners_edit', 'banners', 'edit'),
    ('banners_delete', 'banners', 'delete'),
    ('payouts_view', 'payouts', 'view'),
    ('payouts_manage', 'payouts', 'manage'),
    ('payouts_export', 'payouts', 'export'),
    ('orders_view', 'orders', 'view'),
    ('orders_manage', 'orders', 'manage'),
    ('orders_export', 'orders', 'export'),
    ('reports_view', 'reports', 'view'),
    ('reports_export', 'reports', 'export'),
    ('notifications_send', 'notifications', 'send'),
    ('notifications_view', 'notifications', 'view'),
    ('settings_manage', 'settings', 'manage'),
    ('roles_manage', 'roles', 'manage'),
    ('audit_logs_view', 'audit_logs', 'view'),
    ('app_version_manage', 'app_version', 'manage'),
    ('cms_manage', 'cms', 'manage'),
    ('support_view', 'support', 'view'),
    ('support_manage', 'support', 'manage'),
    ('fraud_view', 'fraud', 'view'),
    ('fraud_manage', 'fraud', 'manage'),
    ('email_providers_manage', 'email_providers', 'manage'),
    ('payment_providers_manage', 'payment_providers', 'manage');

-- ---------- 4. Seed Super Admin role with every permission ----------

INSERT IGNORE INTO admin_roles (name, is_system_role) VALUES ('Super Admin', 1);

INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
JOIN admin_permissions p
WHERE r.name = 'Super Admin';

-- ---------- 5. Backfill existing admins onto Super Admin, activate ----------
-- (Preserves current access for every existing admin row — see header.)

UPDATE admins a
JOIN admin_roles r ON r.name = 'Super Admin'
SET a.role_id = r.id
WHERE a.role_id IS NULL;

UPDATE admins SET is_active = 1 WHERE is_active IS NULL;

-- ---------- 6. Lock down role_id: NOT NULL + FK, drop the old enum ----------

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_role_id_not_null $$
CREATE PROCEDURE anydrop_migration_29_role_id_not_null()
BEGIN
    -- No error expected here (plain MODIFY), but guarded anyway in case
    -- a future partial run leaves a stray NULL row — fails loud instead
    -- via the ordinary 1048 (column cannot be null) if backfill above
    -- somehow missed a row, which is deliberately NOT swallowed: that
    -- would mean an admin row exists with no role, worth surfacing.
    ALTER TABLE admins MODIFY COLUMN role_id BIGINT UNSIGNED NOT NULL;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_add_role_fk $$
CREATE PROCEDURE anydrop_migration_29_add_role_fk()
BEGIN
    -- 1826 = ER_FK_DUP_NAME (MySQL 8), 1005 = generic "can't create
    -- table" wrapping a duplicate key/constraint name on older MySQL.
    DECLARE CONTINUE HANDLER FOR 1826, 1005 BEGIN END;
    ALTER TABLE admins ADD CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES admin_roles(id);
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_29_drop_role_enum $$
CREATE PROCEDURE anydrop_migration_29_drop_role_enum()
BEGIN
    -- 1091 = ER_CANT_DROP_FIELD_OR_KEY — column already dropped by an
    -- earlier partial run.
    DECLARE CONTINUE HANDLER FOR 1091 BEGIN END;
    ALTER TABLE admins DROP COLUMN role;
END $$

DELIMITER ;

CALL anydrop_migration_29_role_id_not_null();
CALL anydrop_migration_29_add_role_fk();
CALL anydrop_migration_29_drop_role_enum();

DROP PROCEDURE IF EXISTS anydrop_migration_29_role_id_not_null;
DROP PROCEDURE IF EXISTS anydrop_migration_29_add_role_fk;
DROP PROCEDURE IF EXISTS anydrop_migration_29_drop_role_enum;

-- Confirm final state — uses SHOW, not information_schema (this
-- environment's DB user can't read information_schema).
SHOW COLUMNS FROM admins;
SELECT r.name, COUNT(p.id) AS permission_count
FROM admin_roles r
LEFT JOIN admin_role_permissions rp ON rp.role_id = r.id
LEFT JOIN admin_permissions p ON p.id = rp.permission_id
GROUP BY r.id, r.name;
