-- ============================================================
-- Anydrop — Migration 75: Rider Documents (ID + Vehicle proof)
-- verification workflow (deep-plan §22, decided 2026-09-05 — the
-- person picked this over §23 FCM/Notifications when asked which of
-- doc 90's two flagged next-steps to build second).
--
-- `riders.vehicle_doc_url` / `riders.id_doc_url` already existed
-- (migration 69) but were never written to by anything — rider-signup.php
-- never collected them (deep-plan §22 explicitly deferred document
-- upload off the signup form itself, same "post-approval Complete
-- Profile step" decision noted for vehicle_type/vehicle_number back in
-- the 2026-09-01 Phase 2 session) and no endpoint/admin UI has ever
-- read or written them. This migration adds the verification-workflow
-- columns those endpoints need — closely mirrors migration 59's
-- restaurant bank-details verification shape (see that file's own
-- comment for the full reasoning; not re-derived here):
--
--   documents_status ENUM, same 4-state shape as restaurant bank
--   verification's 3-state one plus an explicit 'not_submitted' —
--   needed here because, unlike bank details (which a restaurant
--   either has submitted or hasn't, with no separate "empty" case
--   worth naming), a PENDING rider's whole admin-approval decision may
--   hinge on "have they even submitted documents yet", so this state
--   needs to be distinguishable in the UI from "submitted, awaiting
--   review" rather than collapsing both into a bare NULL check against
--   id_doc_url.
--
--   documents_reject_reason mirrors riders.rejection_reason itself
--   (migration 69) — kept as a SEPARATE column rather than reusing
--   that one, because a rider's ACCOUNT can be independently
--   pending/approved/rejected/suspended while their DOCUMENTS are
--   independently not_submitted/pending/verified/rejected (an already-
--   approved rider can still have a document flagged and asked to
--   re-submit without the admin having to also reject/suspend the
--   whole account to say so) — same "two independent lifecycles, two
--   independent reason columns" reasoning migration 59 itself doesn't
--   need (bank verification has no separate parent-account status to
--   stay independent from) but restaurants.status/rejection_reason vs.
--   this table's own status/reason pairing already establishes
--   elsewhere in this schema.
--
--   documents_verified_by_admin_id / documents_verified_at mirror
--   migration 59's verified_by_admin_id/verified_at exactly, same
--   "who actioned this, when" audit need.
--
-- profile_photo_url is optional (deep-plan §22's stated scope) and has
-- no verification workflow of its own — it's a display convenience
-- (shown to a restaurant/customer as "your rider"), not a compliance
-- document, so it's just a plain URL column with no status column
-- alongside it, same "photo columns don't get a review workflow"
-- precedent restaurant_logos/restaurant_banners already set.
--
-- SECURITY-CRITICAL DESIGN DECISION: id_doc_url and vehicle_doc_url are
-- NOT served from a public uploads/ directory the way logos/banners/
-- dish-photos are (see backend/uploads/.htaccess, which only blocks
-- *executing* scripts there — plain file GETs are still world-
-- readable). A government ID photo is a materially different risk
-- than a restaurant logo, so this is deliberately this codebase's
-- FIRST private, access-controlled upload: files land in
-- backend/rider_documents/ (new directory, own `Require all denied`
-- .htaccess — same denial this codebase's backend/logs/ directory
-- already uses for "nobody gets this over HTTP, ever, only PHP reads
-- it server-side"), and are only ever returned to a browser/app by
-- streaming them through documents-view.php after that endpoint checks
-- the requester is either the owning rider or an admin holding
-- rider_documents_view — see that file's own kdoc. profile_photo_url,
-- by contrast, stays in the existing public uploads/ tree (new
-- uploads/rider_profile_photos/ subfolder, same convention as every
-- other *_photos folder there) since a profile photo is meant to be
-- publicly displayable, same as a restaurant logo.
-- ============================================================

DELIMITER $$

CREATE PROCEDURE anydrop_add_column_if_missing_75(
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

CALL anydrop_add_column_if_missing_75('riders', 'profile_photo_url', "VARCHAR(255) NULL AFTER id_doc_url");
CALL anydrop_add_column_if_missing_75('riders', 'documents_status', "ENUM('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted' AFTER profile_photo_url");
CALL anydrop_add_column_if_missing_75('riders', 'documents_reject_reason', "VARCHAR(255) NULL AFTER documents_status");
CALL anydrop_add_column_if_missing_75('riders', 'documents_submitted_at', "TIMESTAMP NULL AFTER documents_reject_reason");
CALL anydrop_add_column_if_missing_75('riders', 'documents_verified_by_admin_id', "BIGINT UNSIGNED NULL AFTER documents_submitted_at");
CALL anydrop_add_column_if_missing_75('riders', 'documents_verified_at', "TIMESTAMP NULL AFTER documents_verified_by_admin_id");

DROP PROCEDURE IF EXISTS anydrop_add_column_if_missing_75;

-- FK added separately (the idempotent procedure above only guards
-- duplicate-column errors, not duplicate-constraint ones) — same
-- "CONTINUE HANDLER for the specific expected error code" style,
-- 1826 = Duplicate foreign key constraint name.
DELIMITER $$
CREATE PROCEDURE anydrop_add_fk_if_missing_75()
BEGIN
    DECLARE CONTINUE HANDLER FOR 1826 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1005 BEGIN END;
    ALTER TABLE riders ADD CONSTRAINT fk_rider_docs_verified_by FOREIGN KEY (documents_verified_by_admin_id) REFERENCES admins(id);
END$$
DELIMITER ;

CALL anydrop_add_fk_if_missing_75();
DROP PROCEDURE IF EXISTS anydrop_add_fk_if_missing_75;

-- Any existing rider with both doc URLs already non-NULL (there should
-- be none today — see this file's header — but this keeps the
-- migration correct if it's ever run on a DB where a prior manual
-- admin-side edit set them directly) is backfilled to 'pending' rather
-- than 'not_submitted', so an admin isn't left unaware of documents
-- that already exist on the row.
UPDATE riders
SET documents_status = 'pending', documents_submitted_at = COALESCE(documents_submitted_at, updated_at)
WHERE documents_status = 'not_submitted'
  AND id_doc_url IS NOT NULL
  AND vehicle_doc_url IS NOT NULL;

-- ---------- RBAC: new permission pair ----------
-- Deliberately separate from `riders_view`/`riders_edit`/`riders_approve`
-- (account-lifecycle permissions already seeded by migration 29/69) —
-- viewing a rider's government ID photo is its own distinct blast
-- radius from viewing their name/status/area in a list row, same
-- reasoning migration 74 kept `rider_payouts_*` separate from
-- `payouts_manage` for the equivalent "this specific action touches
-- more sensitive data than the base list view" reason.
INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('rider_documents_view', 'rider_documents', 'view'),
    ('rider_documents_manage', 'rider_documents', 'manage');

-- Grant both to every role that already holds `riders_approve` (i.e.
-- today, just Super Admin) — same "don't silently reduce anyone's
-- access, extend from the nearest existing equivalent permission"
-- principle every prior permission-adding migration here uses.
INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT rp.role_id, np.id
FROM admin_role_permissions rp
JOIN admin_permissions existing ON existing.id = rp.permission_id AND existing.`key` = 'riders_approve'
JOIN admin_permissions np ON np.`key` IN ('rider_documents_view', 'rider_documents_manage');
