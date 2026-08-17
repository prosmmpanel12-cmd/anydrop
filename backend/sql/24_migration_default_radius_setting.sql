-- Migration 24 — global "how far can a customer be from a restaurant to
-- see it at all" setting (app-owner ask, 2026-08-17: "5km default baaki
-- ka admin panel se distance aayega — 5km ke under koi restaurant ho to
-- show karo warna Not Available").
--
-- `restaurants.delivery_radius_km` (01_schema.sql) already lets each
-- *restaurant* set its own radius — that stays as-is (a restaurant that
-- knows it can't deliver far can still set a tighter radius than the
-- platform default). What was missing was an admin-configurable *default*
-- for restaurants that never set one — restaurants/list.php had `5.0`
-- literally hardcoded as that fallback. This adds the app_settings row so
-- that fallback can be changed platform-wide without a code deploy, same
-- "nothing hardcoded" rule 01_schema.sql's own app_settings seed follows.
--
-- ON DUPLICATE KEY UPDATE `key` = `key` (no-op update) — same idempotent-
-- insert pattern the original app_settings seed at the bottom of
-- 01_schema.sql uses, safe to run more than once.

INSERT INTO app_settings (`key`, `value`, description) VALUES
('default_delivery_radius_km', '5', 'Default radius (km) a restaurant is visible to customers within, used when a restaurant has not set its own delivery_radius_km')
ON DUPLICATE KEY UPDATE `key` = `key`;
