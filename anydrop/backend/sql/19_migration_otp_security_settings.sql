-- Migration 19 — bugs.md #2.2 and #2.1 fixes (same file, same session,
-- kept in one migration since both touch customer-request-otp.php).
--
-- #2.2 — seeds `debug_otp_enabled`, defaulting to '0' (off).
-- customer-request-otp.php now only includes `debug_otp` in its response
-- when this is explicitly '1' — flip it on a dev/staging DB only, never
-- on production, since '1' means anyone with API access can log in as
-- any email with zero possession-of-inbox proof (exactly the hole this
-- bug tracked).
--
-- #2.1 — seeds `otp_request_cooldown_seconds` (default 60), the per-email
-- cooldown customer-request-otp.php now enforces before inserting a new
-- OTP row, closing the open email-bombing / DB-row-spam vector.
--
-- Safe to re-run — ON DUPLICATE KEY UPDATE is a no-op, same pattern as 04.
INSERT INTO app_settings (`key`, `value`, description) VALUES
('debug_otp_enabled', '0', 'DEV/STAGING ONLY — when "1", customer-request-otp.php returns debug_otp in its response so login can be tested without real SMTP. Must stay "0" on production (bugs.md #2.2).'),
('otp_request_cooldown_seconds', '60', 'Minimum seconds a customer must wait between OTP requests for the same email (bugs.md #2.1).')
ON DUPLICATE KEY UPDATE `key` = `key`;
