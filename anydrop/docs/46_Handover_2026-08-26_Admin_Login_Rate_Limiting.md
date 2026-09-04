# Handover — Admin Login Rate Limiting (2026-08-26)

Picked up `docs/AnyDrop_Admin_Management_Plan.md` §27 P1.2 (Login Rate
Limiting) — a small, self-contained security gap: admin login had no
brute-force protection at all (grepped `backend/admin/login.php` and
`backend/lib/admin_auth.php` first — confirmed no attempt counter, no
lockout, no throttling anywhere before starting).

## What was built

- **`backend/sql/51_migration_admin_login_rate_limit.sql`** (new) —
  `admins.failed_login_attempts` + `admins.locked_until` (per-account
  lockout), plus a new `admin_login_attempts` table (one row per
  attempt, indexed on `(ip_address, created_at)`) for per-IP
  throttling via a rolling-window `COUNT(*)` query rather than a
  separate counter/reset job. Same idempotent CONTINUE-HANDLER pattern
  as migration 29 — safe to re-run.
- **`backend/lib/admin_login_throttle.php`** (new) — the two layers:
  - Per-IP: `admin_login_ip_is_throttled()` — 20 failed attempts
    (any username) from one IP in a 15-minute rolling window blocks
    further attempts from that IP, checked *before* the `admins`
    table is even queried.
  - Per-account: `admin_login_register_failure()` — 5 consecutive
    wrong-password attempts against one username locks that account
    for 15 minutes (`locked_until`), resets the counter to 0 so the
    account doesn't re-lock on its first mistake after the lockout
    expires. `admin_login_register_success()` clears both fields.
  - Thresholds are code constants, not admin-configurable settings —
    this is a security control, not a business rule like delivery
    charge/OTP expiry.
- **`backend/admin/login.php`** — wired both layers in. Order: IP
  throttle check → account lockout check → deactivated check →
  password check → success. Every failure path (throttled, locked,
  wrong password, unknown username) now writes to
  `admin_login_attempts` for the IP-throttle counter, and every
  meaningfully-different outcome gets its own `write_audit_log()`
  action (`admin_login_blocked_ip_throttle`,
  `admin_login_blocked_account_locked`,
  `admin_login_blocked_inactive`, `admin_login_failed`,
  `admin_login_failed_unknown_username`, `admin_login`) — same
  "every sensitive action writes to the audit log" convention
  `audit.php`'s own docblock states.
- **Deliberately kept the generic "Invalid username or password."**
  message for wrong-password/unknown-username cases (unchanged from
  before this session) — only the lockout/throttle messages name what
  happened, since by that point the account being targeted is already
  the visible signal from the attacker's own behavior, so naming it
  doesn't leak anything new. A username-enumeration-safe error message
  was already the existing behavior; this session didn't weaken it.

## Not touched / out of scope

- **P1.1 (2FA), P1.4 (session hardening), P1.5 (remove seed script)**
  — separate P1 items in the same doc section, not part of this pick.
- **No cleanup job for old `admin_login_attempts` rows** — noted in
  the migration's own comment as a fine future follow-up (rows are
  only ever queried within the last 15-60 minutes, so an unbounded
  table doesn't affect correctness, only eventual storage size).
- **No UI change** — the login page's error `<div>` already renders
  whatever `$error` is; no new markup needed for the new message
  strings.

## Needs a real machine, not this sandbox

Same standing limitation as every session before this one — no PHP
CLI, no live DB, no browser here. Balance-checked braces/parens on
both touched/new PHP files programmatically; that's not a substitute
for `php -l` or a real login attempt.

1. Run migration 51 against the real DB, confirm
   `SHOW COLUMNS FROM admins` includes `failed_login_attempts` /
   `locked_until`, and `admin_login_attempts` exists.
2. `php -l` on `login.php` and `admin_login_throttle.php`.
3. Manually trigger 5 wrong-password attempts against one real admin
   username, confirm the 6th attempt (even with the correct password)
   shows the lockout message; wait out 15 minutes (or shorten the
   constant temporarily) and confirm login works again.
4. Confirm a correct login clears `failed_login_attempts`/
   `locked_until` on that admin's row.
5. Spot-check `audit_logs` after a few failed/blocked attempts —
   confirm the new action names appear with the right `actor_id`
   (`null` for unknown-username / IP-throttle blocks, the real admin
   id otherwise) and `ip_address`.

## Suggested next session

Resume doc 45's own suggested order — the rest of doc 45's
verification checklist, or the next P1/P0 item from
`AnyDrop_Admin_Management_Plan.md` §26–27 (P0.2 Server-Side Scope
Enforcement, P1.4 Session Hardening, or P1.5 removing the web-based
seed script are all similarly small, self-contained picks).
