-- Anydrop — Migration 73: Rider Earnings Ledger
--
-- Deep-plan §19-20. Rate model decided 2026-09-04 (person's own call,
-- superseding the "still a business-rule decision" caution in the plan
-- doc and the "rate model wasn't decided as of 2026-08-27" note in
-- lib/rider_ledger.php/orders-deliver.php's own kdocs):
--
--   rider_earning = round_up_to_nearest_1(
--       max(order.delivery_charge * rider_earning_share_percent / 100,
--           rider_earning_minimum)
--   )
--
-- delivery_charge is the order's ALREADY-COMPUTED distance-based fee
-- (lib/delivery_pricing.php's calculate_delivery_fee(), base fee +
-- rate/km, area-configurable — migration 36) — deliberately NOT a
-- second independent flat-base/per-km formula of its own. The person
-- explicitly chose "percent of delivery_charge" over deep-plan §19's
-- literal "flat base + own per-km rate" wording, specifically so this
-- reuses the pricing engine that already exists (area rules, distance
-- calc, the ceil-to-nearest-5 rounding UX) instead of maintaining two
-- parallel distance-based money formulas that could drift out of sync
-- with each other over time.
--
-- Two platform-wide settings (app_settings, same get_setting()/
-- set_setting() convention as rider_cod_settlement_limit and
-- google_directions_api_key — no per-area override for now, unlike
-- delivery_charge itself; area-level rider-share overrides are a
-- plausible future migration, not built here):
--   rider_earning_share_percent — % of delivery_charge the rider earns.
--   rider_earning_minimum       — floor amount (₹), protects a rider on
--     a very short/cheap delivery from an unreasonably small payout.
--
-- Table shape mirrors rider_cod_ledger (migration 53) closely on
-- purpose — same "running_balance snapshot on every row, one shared
-- writer function locks-then-updates-then-inserts" pattern — but is a
-- SEPARATE table, not a new entry_type on rider_cod_ledger, per
-- deep-plan §20's explicit "do not mix COD cash-holding entries with
-- earnings entries" instruction. COD cash is money the rider is
-- temporarily holding on the platform's behalf and must hand back;
-- earnings are money the platform owes the rider. Conflating the two
-- balances would make neither number trustworthy.

CREATE TABLE IF NOT EXISTS rider_earnings_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rider_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    entry_type ENUM(
        'delivery_earning',   -- +amount: computed payout for one delivered order
        'incentive',          -- +amount: admin-granted (peak/area/streak — not computed anywhere yet, manual for now)
        'bonus',               -- +amount: admin-granted one-off
        'adjustment_credit',  -- +amount: manual correction, admin favor of rider
        'adjustment_debit',   -- -amount: manual correction, against rider
        'payout'              -- -amount: admin paid the rider out (reduces balance owed)
    ) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    running_balance DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_by ENUM('system','admin') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rider_earnings_ledger_rider FOREIGN KEY (rider_id) REFERENCES riders(id),
    CONSTRAINT fk_rider_earnings_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_rider_earnings_ledger_rider_created (rider_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Running total the platform currently owes this rider, not yet paid
-- out. Same role/shape as riders.cod_cash_held (migration 53) but for
-- the opposite direction of money — kept as its own column rather than
-- reusing cod_cash_held, since the two must never be summed together
-- (see kdoc above) and a single admin screen may legitimately want to
-- show both side by side for the same rider.
ALTER TABLE riders
    ADD COLUMN earnings_balance DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cod_cash_held;

INSERT INTO app_settings (`key`, `value`, description) VALUES
    ('rider_earning_share_percent', '80', 'Percent of an order''s delivery_charge the rider earns per delivery (0-100).'),
    ('rider_earning_minimum', '20', 'Minimum ₹ a rider earns for any single delivery, even if the share-of-delivery_charge calculation comes out lower.')
ON DUPLICATE KEY UPDATE `key` = `key`;
