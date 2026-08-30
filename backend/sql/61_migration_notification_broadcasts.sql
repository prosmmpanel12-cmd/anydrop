-- ============================================================
-- Anydrop — Migration 61: notification_broadcasts
--
-- Backs the admin-side broadcast/marketing push feature (person-
-- requested this session, alongside FCM infra in migration 60 +
-- lib/fcm.php). This is what docs/Status.md/lib/notifications.php's
-- own long-standing kdoc called "Type 2 — admin broadcast/targeting,
-- not yet built" — this migration is that build.
--
-- One row per broadcast send. Deliberately a simple audit/history log,
-- not a queue table — sends fire synchronously from the admin page
-- (see admin/broadcast.php, this same session) via a loop over
-- create_notification(), which itself already fires the real FCM push
-- per-recipient (lib/notifications.php, migration 60's own change).
-- This table exists so the admin can see what was sent, to whom, and
-- how many actually had a device token to receive it — not to drive
-- delivery itself.
--
-- target_type/target_area_id together describe *who this was sent to*,
-- after the fact — recorded for the history view, not re-evaluated
-- later. A future targeted customer might move areas or a restaurant
-- might close after the broadcast fires; this table intentionally
-- doesn't try to keep that in sync, same "a receipt, not a live query"
-- reasoning restaurant_due_ledger's rows already use elsewhere in this
-- schema.
--
-- recipient_count/delivered_count: recipient_count is how many
-- customers/restaurants matched the target at send time (the
-- denominator); delivered_count is how many of those actually had a
-- non-null fcm_token and got a successful FCM response (see
-- fcm_send_to_token()'s true/false return) — the gap between the two
-- is exactly "matched the audience but has no working push token,"
-- useful for the admin to see push-adoption isn't 100% without this
-- table trying to explain why.
--
-- Same idempotent CONTINUE-HANDLER-for-1060-style safety this
-- project's other migrations use is not needed here — CREATE TABLE IF
-- NOT EXISTS is already naturally re-runnable, no ALTER COLUMN
-- involved.
-- ============================================================

CREATE TABLE IF NOT EXISTS notification_broadcasts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    body TEXT NOT NULL,
    image_url VARCHAR(255) NULL,
    link_url VARCHAR(255) NULL,
    -- 'all_customers' / 'all_restaurants' / 'area_customers' /
    -- 'area_restaurants' — deliberately separate from target_area_id
    -- rather than inferring "area vs all" from whether the id is null,
    -- so a future 5th target type (e.g. a specific restaurant list)
    -- doesn't have to overload that same null-check.
    target_type ENUM('all_customers', 'all_restaurants', 'area_customers', 'area_restaurants') NOT NULL,
    target_area_id BIGINT UNSIGNED NULL,
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_broadcast_admin FOREIGN KEY (admin_id) REFERENCES admins(id),
    CONSTRAINT fk_broadcast_area FOREIGN KEY (target_area_id) REFERENCES service_areas(id),
    INDEX idx_broadcast_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
