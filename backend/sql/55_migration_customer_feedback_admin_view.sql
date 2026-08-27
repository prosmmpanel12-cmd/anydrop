-- Anydrop — Migration 55: Admin "Customer Feedback" view
--
-- The `feedback` table (migration 06, Phase 3.6 §2.7 — Profile > Feedback
-- in the customer app, backend/api/v1/customer/feedback.php) has been
-- capture-and-store only since it was built: customers could submit
-- feedback + an optional 1-5 star rating, but nothing in Admin could
-- ever read it back. This migration just adds the permission needed to
-- gate the new admin/customer-feedback.php read-only list page — no
-- schema change, the `feedback` table itself is untouched.
--
-- Same pattern as migration 54's own permission-seed section (new
-- permission key, granted to Super Admin only for now — same as every
-- other admin page here, an app owner with more roles can extend this
-- via admin/roles.php later without another migration).

INSERT IGNORE INTO admin_permissions (`key`, module, action) VALUES
    ('feedback_view', 'feedback', 'view');

INSERT IGNORE INTO admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM admin_roles r
JOIN admin_permissions p ON p.key = 'feedback_view'
WHERE r.name = 'Super Admin';
