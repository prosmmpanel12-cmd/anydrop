-- ============================================================
-- Anydrop — Migration 83: Pickup OTP (restaurant -> rider handoff)
--
-- App-owner ask, 2026-09-11: a rider marking an order "Picked Up" was
-- previously a single unauthenticated tap (orders-pickup.php just
-- checked rider_id + status, no code of any kind) — anyone holding
-- the rider's phone could advance the order. This adds a pickup-side
-- OTP, mirroring the existing delivery_otp/otp_attempts/otp_verified_at
-- columns (01_schema.sql) exactly, but as its own independent column
-- set so a pickup OTP retry/lockout never interacts with the delivery
-- OTP's own counter.
--
-- Unlike delivery_otp (only generated when otp_required is true — UPI
-- orders, or COD when otp_required_for_cod is on), pickup_otp is
-- ALWAYS generated for every order. The delivery OTP protects against
-- *payment* fraud (wrong person receiving a COD/UPI order); the
-- pickup OTP protects against *handoff* fraud (wrong rider collecting
-- the food from the restaurant) — a concern that exists regardless of
-- payment method, so there is no equivalent "otp_required" gate here.
-- ============================================================

-- pickup_otp_last_sent_at / delivery_otp_last_sent_at: cooldown timestamps
-- for the new "Resend OTP" buttons (restaurant app for pickup, customer
-- app for delivery) — same simple "read last-sent time off the row you
-- already have, compare to now()" cooldown shape as
-- payout-bank-details-request-otp.php uses against email_otps, just
-- kept on the order row itself since there's no separate OTP-request
-- table for these (the OTP already exists from order-creation time;
-- resend never generates a new code, it only re-delivers the existing
-- one via push + email).
ALTER TABLE orders
    ADD COLUMN pickup_otp VARCHAR(6) NULL AFTER delivery_otp,
    ADD COLUMN pickup_otp_verified_at TIMESTAMP NULL AFTER otp_verified_at,
    ADD COLUMN pickup_otp_attempts TINYINT NOT NULL DEFAULT 0 AFTER otp_attempts,
    ADD COLUMN pickup_otp_last_sent_at TIMESTAMP NULL AFTER pickup_otp_attempts,
    ADD COLUMN delivery_otp_last_sent_at TIMESTAMP NULL AFTER pickup_otp_last_sent_at;
