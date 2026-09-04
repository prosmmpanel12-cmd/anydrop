-- ============================================================
-- Anydrop — Migration 66b: Reconciliation permissions only (recovery)
--
-- Use this INSTEAD of re-running the full 66_migration file when that
-- one failed partway with a "Duplicate column 'restaurant_payment_id'"
-- error — that error means Part 1 (the restaurant_due_ledger ALTER)
-- already applied successfully on an earlier attempt, but phpMyAdmin
-- stopped executing the rest of the script right there, so Part 2
-- (the reconciliation_flags table + the two RBAC permissions) never
-- ran. This file is just that tail end, safe to run on its own and
-- safe to re-run if needed (CREATE TABLE IF NOT EXISTS + INSERT
-- IGNORE throughout — nothing here errors on already existing).
-- ============================================================

-- ---------- the admin mismatch queue (safe even if already created) ----------

CREATE TABLE IF NOT EXISTS reconciliation_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flag_type VARCHAR(64) NOT NULL,
    severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
    entity_type VARCHAR(32) NOT NULL,      -- 'order' | 'refund' | 'restaurant_payment' | 'platform'
    entity_id BIGINT UNSIGNED NOT NULL,    -- the row id within entity_type's own table (0 for a platform-wide flag, which has no single row)
    order_id BIGINT UNSIGNED NULL,
    restaurant_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    expected_value VARCHAR(255) NULL,
    actual_value VARCHAR(255) NULL,
    status ENUM('open', 'resolved', 'ignored') NOT NULL DEFAULT 'open',
    resolved_by_admin_id BIGINT UNSIGNED NULL,
    resolved_at TIMESTAMP NULL,
    resolution_note VARCHAR(500) NULL,
    first_detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reconflag_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_reconflag_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_reconflag_resolved_by FOREIGN KEY (resolved_by_admin_id) REFERENCES admins(id),
    UNIQUE KEY uq_reconflag_identity (flag_type, entity_type, entity_id),
    INDEX idx_reconflag_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- RBAC: the two permission keys ----------

INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('reconciliation_view', 'reconciliation', 'view'),
    ('reconciliation_manage', 'reconciliation', 'manage');

-- Grant both to every role that already holds `payment_providers_manage`
-- (i.e. today, just Super Admin).
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'payment_providers_manage'
JOIN admin_permissions np ON np.`key` IN ('reconciliation_view', 'reconciliation_manage');

-- ---------- verify ----------
SELECT * FROM admin_permissions WHERE `key` LIKE 'reconciliation%';
