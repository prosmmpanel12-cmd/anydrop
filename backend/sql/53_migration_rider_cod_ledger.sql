-- Anydrop — Migration 53: Rider COD Cash Ledger
--
-- Business model clarified 2026-08-27 (docs handover pending): ALL money
-- (COD + delivery charge + prepaid) ultimately lands with the admin, not
-- the restaurant. Specifically for COD orders: the RIDER collects cash
-- from the customer at drop-off, then transfers that cash to the admin
-- in a batch settlement — NOT the restaurant. This is a different flow
-- than restaurant_due_ledger's 'commission_cod' entry (which only tracks
-- the commission the restaurant owes, and assumed the restaurant held the
-- cash). That entry keeps working for the commission side; this new
-- table tracks the actual CASH the rider is holding, separately.
--
-- Rider payout AMOUNT (how much of the delivery_charge the rider actually
-- earns) is intentionally NOT part of this migration — that rate hasn't
-- been decided yet (flat/per-km/etc., per 2026-08-27 conversation). This
-- migration only builds the COD cash-collected tracking + settlement-limit
-- enforcement, mirroring the existing restaurant_due_limit pattern
-- (lib/orders.php ~line 155) so a rider who is holding too much
-- uncollected cash can be flagged/blocked the same way an over-due
-- restaurant already is.

CREATE TABLE IF NOT EXISTS rider_cod_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    entry_type ENUM(
        'cod_collected',        -- +amount: rider collected cash from customer on a delivered COD order
        'settlement_to_admin',  -- -amount: rider handed cash over to admin (Record Settlement)
        'manual_adjustment'
    ) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    running_balance DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_by ENUM('system','admin') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rider_cod_ledger_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    CONSTRAINT fk_rider_cod_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_rider_cod_ledger_rider_created (rider_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Running total of cash a rider is currently holding, not yet settled to
-- admin. Same role as restaurants.current_due, but always >= 0 here since
-- a rider can't be "owed" cash the way a restaurant can (only ever
-- collects, then hands over — no negative direction).
ALTER TABLE riders
    ADD COLUMN cod_cash_held DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER is_active;

-- Same settings table other limits already live in (restaurant_due_limit,
-- gst_percent, etc.) — get_setting()/set_setting() already know how to
-- read/write app_settings, nothing new needed there.
INSERT INTO app_settings (`key`, `value`, description)
VALUES ('rider_cod_settlement_limit', '2000', 'Max COD cash (₹) a rider may hold before being flagged/blocked from further COD deliveries until they settle with admin.')
ON DUPLICATE KEY UPDATE `key` = `key`;
