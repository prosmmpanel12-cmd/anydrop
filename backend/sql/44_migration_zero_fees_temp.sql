-- ============================================================
-- Anydrop — Migration 44: Zero out delivery fee, tax/GST, and
-- platform fee (temporary, app owner request 2026-08-23)
--
-- App owner isn't ready to finalize real delivery/GST/platform-fee
-- numbers yet and wants all three at ₹0 / 0% for now so orders price
-- clean while other things (test-mode UPI, restaurant notification
-- timing) get sorted first. This does NOT delete any pricing logic —
-- price_cart() (lib/orders.php) and calculate_delivery_fee()
-- (lib/delivery_pricing.php) are untouched. It only zeroes the DATA
-- those functions read from, so flipping real numbers back on later
-- is just editing these same rows again — no code change needed.
--
-- Covers every place a non-zero value could come from, per
-- lib/delivery_pricing.php's own fallback chain:
--   1. app_settings — platform-wide flat/flag defaults
--      (platform_fee_flat, tax_percent, packing_charge_flat,
--      delivery_charge_flat, default_delivery_rate_per_km,
--      default_delivery_base_fee)
--   2. area_pricing_rules — per-area overrides that would otherwise
--      beat the platform default above for any area that has one
--      (delivery_rate_per_km / delivery_base_fee columns only —
--      min_order_amount is untouched, app owner didn't ask for that)
--
-- Uses UPDATE, not INSERT IGNORE, because the point is to force these
-- to 0 even where a value already exists (unlike migration 04's
-- INSERT IGNORE seeding, which deliberately never clobbers an
-- admin-set value — this migration is the one time we DO want to
-- clobber, per explicit app owner instruction). Safe to re-run.
-- ============================================================

-- 1) Platform-wide flat defaults (app_settings)
UPDATE app_settings SET value = '0' WHERE `key` IN (
    'platform_fee_flat',
    'tax_percent',
    'packing_charge_flat',
    'delivery_charge_flat',
    'default_delivery_rate_per_km',
    'default_delivery_base_fee'
);

-- In case any of these rows don't exist yet on this DB (fresh-ish
-- install that never ran an earlier migration touching them) —
-- insert at 0 so price_cart()'s get_setting() calls never silently
-- fall back to that function's own hardcoded default instead
-- (get_setting('platform_fee_flat', 5) etc. — see lib/orders.php).
INSERT INTO app_settings (`key`, `value`, description)
VALUES
    ('platform_fee_flat', '0', 'Flat platform fee added to every order'),
    ('tax_percent', '0', 'Tax % applied on item_total for every order'),
    ('packing_charge_flat', '0', 'Flat packing charge added to every order'),
    ('delivery_charge_flat', '0', 'Flat delivery charge — used when a distance-based fee cannot be computed'),
    ('default_delivery_rate_per_km', '0', 'Platform-wide default ₹ per km for distance-based delivery fee'),
    ('default_delivery_base_fee', '0', 'Platform-wide default flat base fee for distance-based delivery fee')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- 2) Per-area overrides (area_pricing_rules) — these beat the
-- platform default above for any area that has an active row, so
-- they need zeroing too or a customer in that specific area would
-- still see a non-zero delivery fee. NULL would also "fall back to
-- platform default" per calculate_delivery_fee()'s own logic, but
-- setting an explicit 0 here is more honest about what's actually
-- happening (an admin reading this table later sees the real number,
-- not an inherited one) and matches the "starts 0, unmistakably" ask.
UPDATE area_pricing_rules
SET delivery_rate_per_km = 0,
    delivery_base_fee = 0
WHERE is_active = 1;
