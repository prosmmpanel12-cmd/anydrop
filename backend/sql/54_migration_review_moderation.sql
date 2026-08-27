-- Anydrop — Migration 54: Review Reporting & Admin Moderation
--
-- Closes PENDING.md item 8's remaining checklist: customer report-review
-- action, report reason, admin reported-review queue, moderation,
-- hide/remove workflow, audit log, abuse protection.
--
-- `reviews.is_reported` (01_schema.sql §6) already existed but nothing
-- ever set or read it — kept as-is as a fast "needs attention" flag for
-- the admin queue's WHERE clause, backed now by the real report data in
-- the new review_reports table below (one row per customer per report,
-- so multiple customers reporting the same review is visible instead of
-- collapsing into one boolean).

CREATE TABLE IF NOT EXISTS review_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_review_report_review FOREIGN KEY (review_id) REFERENCES reviews(id),
    CONSTRAINT fk_review_report_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    -- Abuse protection: one report per customer per review — repeatedly
    -- hammering the report button on the same review is a no-op, not a
    -- growing queue entry.
    UNIQUE KEY uq_review_report_once (review_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderation_status $$
CREATE PROCEDURE anydrop_migration_54_add_moderation_status()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END; -- ER_DUP_FIELDNAME
    ALTER TABLE reviews ADD COLUMN moderation_status ENUM('visible','hidden') NOT NULL DEFAULT 'visible' AFTER is_reported;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_54_add_hidden_reason $$
CREATE PROCEDURE anydrop_migration_54_add_hidden_reason()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE reviews ADD COLUMN hidden_reason VARCHAR(255) NULL AFTER moderation_status;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderated_by $$
CREATE PROCEDURE anydrop_migration_54_add_moderated_by()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE reviews ADD COLUMN moderated_by BIGINT UNSIGNED NULL AFTER hidden_reason;
END $$

DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderated_at $$
CREATE PROCEDURE anydrop_migration_54_add_moderated_at()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE reviews ADD COLUMN moderated_at TIMESTAMP NULL AFTER moderated_by;
END $$

DELIMITER ;

CALL anydrop_migration_54_add_moderation_status();
CALL anydrop_migration_54_add_hidden_reason();
CALL anydrop_migration_54_add_moderated_by();
CALL anydrop_migration_54_add_moderated_at();

DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderation_status;
DROP PROCEDURE IF EXISTS anydrop_migration_54_add_hidden_reason;
DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderated_by;
DROP PROCEDURE IF EXISTS anydrop_migration_54_add_moderated_at;

-- ---------- New permission, granted to every role that already has Super Admin's blanket set ----------
-- (mirrors migration 29 §3-4's own pattern exactly)

INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('reviews_moderate', 'reviews', 'manage');

INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
JOIN admin_permissions p ON p.key = 'reviews_moderate'
WHERE r.name = 'Super Admin';
