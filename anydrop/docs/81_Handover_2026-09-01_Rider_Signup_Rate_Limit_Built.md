# Rider App — Phase 1 wrap-up: Signup Rate-Limit Guard Handover
Session date: 01 Sep 2026 (follow-on to docs 79/80 in the same day's work)

> **⚠️ Not built/tested this pass.** Same standing sandbox limitation
> as docs 79/80: no PHP interpreter, no MySQL, no network access here.
> Per `done.md`'s rule, treat this as `🟡 IMPLEMENTED — TEST PENDING`
> until migration 70 has run and the endpoint has actually been hit
> past the threshold on a live server.

## Context

Doc 79's known-gaps list, item 3: *"No rate-limit/abuse guard on
`rider-signup.php` beyond the existing OTP cooldown — same gap
`restaurant-signup.php` already has, not new to this session, just
noting it's still open."* App owner picked this item to close out
next.

The existing `otp_request_cooldown_seconds` guard on
`rider-request-otp.php` only throttles repeat requests for the *same
email* — it does nothing to stop one IP cycling through many
*different* emails to spam signup, each of which triggers a real
OTP-email send (a cost/abuse concern on the email-provider side, not
just a fake-account concern).

## ✅ Built this session

### 1. `backend/sql/70_migration_signup_rate_limit.sql`
New `signup_attempts` table — one row per attempt (not a running
counter), same shape as migration 51's `admin_login_attempts`:
`endpoint`, `ip_address`, `email` (nullable, logged for audit only),
`was_successful`, `created_at`, indexed on `(endpoint, ip_address,
created_at)` for the rolling-window COUNT(*) query. Brand-new table,
so no `information_schema`-avoidance CONTINUE HANDLER needed — `CREATE
TABLE IF NOT EXISTS` is already idempotent on its own.

Named generically (`endpoint` column, not a rider-only table)
specifically so `restaurant-signup.php` — which doc 79 confirmed has
the identical gap — can log against the same table later with a
different `$endpoint` string, no new migration required.

### 2. `backend/lib/rate_limit.php` (new)
Two functions, both generic (not rider-specific):
- `rate_limit_check_signup(string $endpoint)` — counts attempts for
  the caller's IP + endpoint within the configured rolling window;
  responds `429 signup_rate_limited` with `retry_after_seconds` (same
  response shape as the existing `otp_request_cooldown` error) and
  exits if over the threshold. Rate limiting is fully disabled if the
  configured max is `0` or less (an explicit escape hatch, not an
  accidental one).
- `rate_limit_log_signup(string $endpoint, ?string $email, bool $wasSuccessful)`
  — logs one attempt row. Deliberately **not** called when the
  rate-limit check itself rejects a request — only genuine attempts
  against the real signup logic count, so a blocked IP's window still
  ages out on schedule instead of being pushed forward forever by its
  own blocked retries.

Configurable via `app_settings` (both optional — `get_setting()`
already falls back to the given default when no row exists, so
**no seed migration needed** for this to work out of the box):
- `signup_rate_limit_max_attempts` (default `5`)
- `signup_rate_limit_window_minutes` (default `60`)

### 3. `backend/api/v1/auth/rider-signup.php` (modified)
- `rate_limit_check_signup('rider_signup')` called first, before body
  parsing — an already-over-limit request doesn't even get as far as
  logging or touching the DB for anything else.
- `rate_limit_log_signup('rider_signup', $email, false)` added at
  every existing failure exit point past the rate-limit check itself:
  invalid email format, invalid mobile format, name too short,
  `email_already_registered`, `email_not_verified`.
- `rate_limit_log_signup('rider_signup', $email, true)` added right
  before the success response.
- The one exit point deliberately **not** logged:
  `require_fields()`'s missing-required-field rejection (fires before
  `$email` is even parsed out of the body). Low-value attack surface
  (doesn't touch the riders/email_otps tables or send an email) —
  flagging this as a known minor gap rather than silently claiming
  full coverage: a request that omits fields entirely doesn't count
  toward the same-IP threshold. Acceptable trade-off, not fixed this
  session.

## 🟡 Known gaps / next-session TODO

1. **Run migration 70 + smoke-test this for real** — hit
   `rider-signup.php` 6 times in under an hour from the same IP,
   confirm the 6th gets `429 signup_rate_limited` with a sane
   `retry_after_seconds`, confirm it clears once the window passes.
2. `restaurant-signup.php` still has the identical gap, entirely
   unchanged by this session — reusing `lib/rate_limit.php` there
   (`rate_limit_check_signup('restaurant_signup')` /
   `rate_limit_log_signup('restaurant_signup', ...)`) is now a small,
   low-risk follow-up whenever the app owner wants it, since the
   table/lib are already generic.
3. Doc 79's remaining items (2: username/password_hash nullable
   cleanup, 5: EmailOtpService `rider_auth` template check) are still
   open, untouched by this session.
4. No admin-facing view of `signup_attempts` yet (e.g. to see who's
   being throttled) — not asked for, not built. Would be a small
   addition to an existing admin page if ever wanted.

## Files in this delivery

```
backend/sql/70_migration_signup_rate_limit.sql   (new)
backend/lib/rate_limit.php                        (new)
backend/api/v1/auth/rider-signup.php              (modified — rate-limit check + logging calls added; existing logic untouched)
```

Run migration 70 before deploying the other two files.
