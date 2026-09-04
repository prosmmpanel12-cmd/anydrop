-- Migration 72 — Rider Delivery Assignment Engine (Phase 3 R3, doc 83/85)
--
-- Adds the offer/assignment table Rider_Deep_Plan.md section 5 calls for,
-- plus two app_settings the dispatch engine (backend/lib/dispatch.php)
-- reads. Deliberately NOT touching orders.status ENUM or riders table —
-- both already have everything R3 needs (orders.status already includes
-- rider_assigned/picked_up/out_for_delivery/delivered, riders already has
-- is_online/last_lat/last_lng/last_location_at/cod_cash_held).
--
-- This table exists specifically so the engine can answer "who was
-- offered this order, who rejected it, how many attempts, did it
-- expire" — see the deep-plan section 5 "why this table is necessary" —
-- rather than trying to cram that history onto the orders row itself.

CREATE TABLE IF NOT EXISTS rider_order_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    rider_id BIGINT UNSIGNED NOT NULL,
    status ENUM('offered','accepted','rejected','expired','cancelled') NOT NULL DEFAULT 'offered',
    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    offered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    responded_at TIMESTAMP NULL,
    reject_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignment_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_assignment_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    INDEX idx_assignment_order_status (order_id, status),
    INDEX idx_assignment_rider_status (rider_id, status),
    INDEX idx_assignment_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dispatch settings — same app_settings pattern every other tunable in
-- this codebase uses (rider_cod_settlement_limit, restaurant_due_limit,
-- etc.), read via get_setting() so these are admin-adjustable later
-- without a code change, even though there's no admin UI for them yet
-- (that UI is a follow-up, not blocking the engine itself).
INSERT INTO app_settings (`key`, `value`, description)
VALUES
    ('rider_assignment_timeout_seconds', '40', 'Seconds an offered delivery stays open before it expires and moves to the next eligible rider (deep-plan §8 suggests 30-45s).'),
    ('rider_dispatch_radius_km', '8', 'Max straight-line distance (km) from the restaurant a rider can be to receive an offer for it.'),
    ('rider_location_freshness_seconds', '300', 'A rider whose last_location_at is older than this is treated as stale and skipped for new offers, even if is_online=1 (deep-plan §4.1 "recent location freshness").')
ON DUPLICATE KEY UPDATE `key` = `key`;
