-- ============================================================
-- Anydrop — Migration 85: Seed every setting's code-default into
-- app_settings, with a description, so it's actually visible/checkable
-- (app-owner ask, 2026-09-12: "sari default values ke liye ... table
-- mai different column bnao taki dekh/check kar shke").
--
-- WHY THIS IS NEEDED:
-- lib/settings.php's get_setting($key, $default) silently falls back
-- to a hardcoded PHP default whenever a `key` has no row in
-- app_settings yet. That's convenient for the app to keep running,
-- but it means dozens of settings that genuinely control real money/
-- behaviour (commission %, rider earning %, delivery pricing, COD
-- limits, OTP config, etc.) were only ever visible by reading PHP
-- source — nothing to look at in the database or an admin settings
-- list unless someone had already saved a value through whichever
-- topic-specific admin page manages it (commission-rules.php,
-- rider-earnings.php, pricing-rules.php, cod-rules.php, ...).
--
-- This migration inserts a row for EVERY key this codebase's
-- get_setting() calls reference, using that exact call's own default
-- as the seed value — so nothing about actual app behaviour changes
-- (a key that had no row before still resolves to the same value it
-- always fell back to; now it also has a real row to look at). Every
-- row gets a plain-English description of what it controls.
--
-- SAFE / NON-DESTRUCTIVE: `ON DUPLICATE KEY UPDATE key = key` (same
-- deliberate no-op pattern migration 72 already established) means
-- this NEVER overwrites a value an admin already set for a key that
-- exists — it only fills in the gaps for keys that had no row at all.
-- Re-running this migration is always safe.
-- ============================================================

INSERT INTO app_settings (`key`, `value`, description) VALUES
('commission_default_percent', '15', 'Default restaurant commission %, used when no category/area/restaurant-specific commission rule applies (admin/commission-rules.php).'),
('coupon_field_enabled', '1', 'Whether the coupon-code field shows on the customer checkout screen. 1 = shown, 0 = hidden.'),
('debug_otp_enabled', '0', 'When 1, OTP screens show/accept a fixed debug OTP for testing. Must be 0 in production.'),
('default_cod_allowed', '1', 'Fallback: whether COD is allowed for an area/restaurant with no specific rule (admin/cod-rules.php, admin/payment-restrictions.php).'),
('default_cod_enabled', '1', 'Fallback: whether COD is offered as a payment option at all when no area-specific override exists.'),
('default_cod_max_order_amount', '', 'Fallback: max order value allowed on COD when no area/restaurant-specific limit is set. Empty = no cap.'),
('default_cod_max_orders_per_day', '', 'Fallback: max COD orders a single customer can place per day when no area-specific limit is set. Empty = no cap.'),
('default_cod_min_prepaid_orders', '0', 'Fallback: number of prepaid (online-paid) orders a new customer must complete before COD unlocks, when no area-specific rule exists.'),
('default_cod_new_customer_blocked', '0', 'Fallback: whether a brand-new customer is blocked from COD on their very first order(s), when no area-specific rule exists.'),
('default_delivery_base_fee', '0', 'Fallback flat base component of the delivery fee formula, used by calculate_delivery_fee() when no area-specific pricing rule exists.'),
('default_delivery_radius_km', '5', 'Fallback max delivery radius (km) from a restaurant, when no area-specific pricing rule exists.'),
('default_delivery_rate_per_km', '8', 'Fallback ₹-per-km component of the delivery fee formula, used when no area-specific pricing rule exists.'),
('default_min_order_amount', '0', 'Fallback minimum cart value required to checkout, when no area/restaurant-specific rule exists.'),
('default_upi_allowed', '1', 'Fallback: whether UPI/online payment is allowed for an area/restaurant with no specific rule.'),
('delivery_charge_flat', '25', 'Flat delivery fee used by calculate_delivery_fee() only as its own last-resort fallback, when a distance-based fee genuinely cannot be computed (e.g. missing lat/lng).'),
('fcm_service_account_json', '', 'Firebase Cloud Messaging service-account JSON credentials, used to send push notifications. Set via admin/fcm-settings.php.'),
('google_directions_api_key', '', 'Google Directions API key, used for route-line/live-tracking distance+ETA calculations. Set via admin/directions-settings.php.'),
('gst_percent', '18', 'GST rate used for restaurant/tax-relevant reporting (kept separate from the customer-facing tax_percent below).'),
('home_promo_enabled', '0', 'Whether the promotional banner shows on the customer app home screen.'),
('home_promo_image_url', '', 'Image URL for the customer app home-screen promo banner, when enabled.'),
('home_promo_subtitle', '', 'Subtitle text for the customer app home-screen promo banner.'),
('home_promo_title', '', 'Title text for the customer app home-screen promo banner.'),
('legal_content_policy_url', '', 'URL to the content/community policy shown in-app.'),
('legal_privacy_url', '', 'URL to the privacy policy shown in-app.'),
('legal_terms_url', '', 'URL to the terms of service shown in-app.'),
('order_cancel_window_minutes', '5', 'Minutes after placing an order that a customer can still self-cancel without contacting support.'),
('otp_expiry_minutes', '10', 'Minutes an OTP (login/signup/delivery) stays valid after being sent.'),
('otp_length', '4', 'Digit-length of generated OTPs. NOTE: two different call sites in this codebase default to 4 and 6 respectively — flagged for the app owner to confirm which is intended; whichever has an existing app_settings row wins today, this migration does not overwrite it, only fills the gap if truly missing.'),
('otp_max_attempts', '3', 'Max wrong-OTP attempts allowed before the OTP is locked and a fresh one must be requested.'),
('otp_request_cooldown_seconds', '60', 'Minimum seconds a user must wait between two OTP-send requests to the same mobile/email.'),
('otp_required_for_cod', '0', 'Whether an extra OTP verification step is required specifically for COD orders.'),
('packing_charge_flat', '0', 'Flat packing charge added to every order''s bill breakdown.'),
('platform_fee_flat', '5', 'Flat platform fee added to every order''s bill breakdown (admin revenue, separate from commission).'),
('refund_expected_days', '5', 'Days shown to customers as the expected refund turnaround time.'),
('restaurant_due_limit', '2000', 'Max ₹ a restaurant''s current_due can reach before the restaurant app blocks it from accepting new orders (mirrors rider_cod_settlement_limit''s pattern on the rider side).'),
('rider_assignment_timeout_seconds', '180', 'Seconds an offered delivery stays open before it expires and moves to the next eligible rider.'),
('rider_cod_settlement_limit', '2000', 'Max ₹ COD cash a rider can hold before being blocked from new COD-order assignment until they settle with admin.'),
('rider_dispatch_radius_km', '8', 'Radius (km) around a restaurant searched for an eligible rider when dispatching a new order.'),
('rider_earning_minimum', '20', 'Minimum ₹ a rider earns per delivery, floors the percentage-of-delivery-charge calculation below it.'),
('rider_earning_share_percent', '80', 'Percent of an order''s delivery_charge paid to the rider as their earning for that delivery.'),
('rider_location_freshness_seconds', '300', 'Seconds after which a rider''s last-known location is considered stale for dispatch/tracking purposes.'),
('rider_payout_min_amount', '100', 'Minimum ₹ earnings balance a rider must have to request a payout.'),
('rider_route_deviation_sustain_seconds', '60', 'How long a rider must continuously deviate from the planned route before a route recalculation triggers.'),
('rider_route_deviation_threshold_m', '70', 'Meters a rider must be off the planned route before it counts as a deviation.'),
('rider_route_max_recalc_interval_seconds', '90', 'Minimum seconds between two automatic route recalculations for the same active delivery.'),
('signup_rate_limit_max_attempts', '5', 'Max signup attempts allowed from the same source within the rate-limit window below.'),
('signup_rate_limit_window_minutes', '60', 'Minutes the signup rate-limit window covers.'),
('splash_banner_image_url', '', 'Image URL shown on the app splash/login screen.'),
('tax_percent', '5', 'Customer-facing tax percentage applied to the order bill breakdown.'),
('wallet_withdrawal_min_amount', '100', 'Minimum ₹ wallet balance a customer must have to request a withdrawal.')
ON DUPLICATE KEY UPDATE `key` = `key`;
