# QrPay Rebuild — Status

**Last updated:** After Phase 5.5 (Auth Overhaul + Free Plan Conversion)

---

## 📋 For a new session (read this first)

This is a phased rebuild of an old PHP UPI payment gateway ("UPI PE") into
"QrPay". Reference material for the OLD system lives in `UpiPe_Reference/`
(read-only, do not edit or copy from it directly into `QrPay/` — it has
known issues, e.g. `CURLOPT_SSL_VERIFYPEER => false`, hardcoded DB creds,
API-key-only login. It's kept for comparison, not reuse).

New build lives in `QrPay/`. Full plan: `QrPay-Rebuild-Blueprint.md`
(identical to `QrPay-Build-Plan.md`).

**Current state:** Phases 1–5 done, plus a Phase 5.5 auth/billing overhaul
(see below, done at the user's request before Phase 6 UI work started).
**Next: Phase 6.**

**Known open items — do not re-litigate these, just carry them forward:**
- `admin_settings.owner_upi_id` in the DB is still the seed placeholder
  `CHANGE-ME@upi`. This is fine and expected — **now actually needed
  soon**, since Phase 5 (subscription purchases) reads it directly.
- `phpmailer/phpmailer` still needs to be added via Composer before any
  auth email (2FA code, verification link, password reset link) can
  actually send — not yet installed (code is written against it; see
  Phase 2 note).
- `APP_URL` env var (added in Phase 5.5) must be set to the real deployed
  base URL before verification/reset emails go out, or the links inside
  them will be broken (`config/env.example` has the placeholder).
- The archive also contains a large real DB export
  (`UpiPe_Reference/if0_42149143_upi.sql`) — treat as live/sensitive data,
  do not open, quote from, or build against it.
- Dashboard session identity (`developer_id`, set in `core/session.php`)
  is completely separate from the `apikey` used on `/api/*`. Phase 4's
  API endpoints authenticate via apikey lookup, NOT `qrpay_require_login()`.
  Phase 5's `subscribe.php`/`coupon_validate.php` are the first `/api/*`
  endpoints that go the OTHER way — session-authenticated via the new
  `qrpay_require_login_json()` (JSON 401 instead of a redirect), since a
  developer buying a QrPay plan for themselves is a dashboard action, not
  a server-to-server call. `verify_payment.php` itself stays apikey-only
  for both order types — the dashboard already has the developer's own
  apikey to poll with (see `subscribe.php`'s response).
- `lib/paytm_status.php` is a DELIBERATELY TRIMMED copy of the old
  `encdec_paytm.php` — status-check functions only, SSL verify forced on,
  merchant key passed per-call. Refund functions were NOT ported (legacy
  Paytm format, needs its own phase — see roadmap's "Open Items" at the
  bottom of the blueprint). Don't add refund logic here without reading
  that note first.
- No PHP syntax checker is available in this environment (`php` binary
  not installed, network disabled so it can't be apt-installed either) —
  only a brace/paren balance check was run on every new/changed file
  across Phase 5 and 5.5. Recommend `php -l` on everything as a first
  step of a real review before Phase 6, and ideally before any deploy.

---

## ✅ Done — Phase 5.5 (Auth Overhaul + Free Plan Conversion)

Done after Phase 5, before Phase 6 UI work, at the user's request. Two
changes, both touching the same files:

**1. Signup/login switched from email+OTP-only to email+password, with
per-user 2FA and admin-controlled email verification:**
- `developers` table: added `name`, `mobile_number`, `password_hash`,
  `two_fa_enabled` (per-user toggle, default off).
- `admin_settings` table: added `email_verification_enabled` (system-wide
  toggle, admin-controlled — contrast with `two_fa_enabled`, which is
  per-user).
- `auth/signup.php` (new) — `name` + `email` + `mobile_number` +
  `password` + `confirm_password`. Hashes the password
  (`password_hash()`/`PASSWORD_DEFAULT`), creates the developer, and
  auto-subscribes them to the free plan (see below). If admin email
  verification is on, sends a link and does NOT start a session yet;
  otherwise logs the developer straight in.
- `auth/login.php` (new) — email + password. Blocks unverified accounts
  when admin verification is required. If the account has 2FA on, emails
  an OTP and sets `$_SESSION['pending_2fa_email']` (a lightweight
  not-yet-authenticated marker) instead of granting a session; otherwise
  grants the full session immediately.
- `auth/verify_otp.php` (rewritten) — now ONLY the 2FA second step.
  Requires `$_SESSION['pending_2fa_email']` to match the email being
  verified (i.e. password must have been checked first in this same
  browser session) before it will even look at the OTP. No longer
  creates accounts — that's `auth/signup.php`'s job now.
- `auth/request_otp.php` (repurposed) — now a "resend 2FA code" endpoint,
  same pending-session guard as `verify_otp.php`.
- `auth/verify_email.php` (new) — consumes the emailed verification link
  (`email_verification_tokens` table, hashed token, single-use, timed
  expiry via `EMAIL_VERIFY_EXPIRY_MINUTES`).
- `auth/resend_verification.php` (new) — resend the verification link;
  deliberately generic response regardless of account state, to avoid
  leaking account existence.
- `auth/forgot_password.php` (new) — always returns the same generic
  message regardless of whether the email has an account; only actually
  emails a reset link (`password_reset_tokens` table, same hashed/
  single-use/timed-expiry pattern) if it does.
- `auth/reset_password.php` (new) — token + password + confirm_password.
  Token IS the identity proof (no email field needed). Burns every other
  unconsumed reset token for that developer on success, not just the one
  used.
- `panel/reset_password.php` (new) — the actual standalone HTML page for
  the reset link to land on (dark-themed inline CSS/JS, no dependency on
  not-yet-built Phase 6 dashboard assets). Posts to
  `auth/reset_password.php` via fetch, redirects to `panel/login.php`
  (not built yet — Phase 6) on success.
- `core/helpers.php` — added `hashPassword()`/`verifyPassword()`
  (wrapping `password_hash`/`password_verify`), `isPasswordStrongEnough()`
  (length-only, 8 char minimum — deliberately no forced-composition rules),
  `generateSecureToken()`/`hashSecureToken()` (shared by both the email
  verification and password reset flows), `isValidMobileNumber()`.
- `core/mailer.php` — `send_otp_email()` re-scoped to "2FA code" wording
  only; added `send_verification_email()` and `send_password_reset_email()`
  sharing a new `qrpay_send_mail()` low-level sender.
- `otp_codes.purpose` enum narrowed to `'2fa_login'` only (was
  `'login_or_signup'`) — OTP is exclusively the 2FA step now.

**2. Free trial converted into an actual Free plan with daily+monthly
credits (was: a separate one-time lifetime allotment):**
- Removed the standalone `free_trial` table entirely.
- `plans` table: added `plan_type` (`'free'|'paid'`) and
  `daily_credit_limit` columns. Seeded a `free` plan row: ₹0,
  `payment_limit = 300` (monthly cap, same mechanism paid plans already
  used), `daily_credit_limit = 10` (new, free-plan-only cap).
- New `daily_usage_counters` table — one row per developer per calendar
  day, lazily created (no cron needed; resets naturally at midnight since
  it's keyed by date, per the user's explicit choice of calendar-day
  reset over a rolling 24h window).
- Every developer now gets a REAL `subscriptions` row for the free plan
  at signup (100-year expiry, `status='active'`) instead of a separate
  trial mechanism — this means `core/plan_limits.php` has ONE code path
  for "find the active subscription + check its usage" regardless of
  whether that subscription is free or paid, instead of two.
- `core/plan_limits.php` — fully rewritten. `get_plan_status()` now
  returns `plan` (with `plan_type`/`payment_limit`/`daily_credit_limit`),
  `subscription`, `usage` (monthly, same as before), `daily_usage`
  (free-plan-only), `is_free_plan`, `can_accept_payment`, `reason`.
  `can_accept_payment()` unchanged signature. `is_next_order_trial_covered()`
  renamed to `is_next_order_free_plan()`.
- `payment_orders.covered_by_trial` renamed to `is_free_plan_order`
  (clearer meaning now that it's "was this covered by free-plan credits"
  rather than "was this covered by the old lifetime trial").
- `api/create_order.php` — updated to the new field names, otherwise
  unchanged (still hard-blocks with 403 and creates no order row if
  `can_accept_payment()` is false).
- `api/verify_payment.php` — PAID transition simplified: the
  `customer_payment` branch is now ONE code path (bump monthly
  `usage_counters` for whatever subscription is active, free or paid),
  with an extra `daily_usage_counters` bump layered on ONLY if
  `is_free_plan_order = 1`. The old three-way branch
  (trial / paid-sub / neither) is gone.
- `api/subscribe.php` — added a guard rejecting attempts to "purchase"
  the free plan (`plan_type = 'free'`) with a 400 — it's assigned
  automatically at signup, not something to buy.
- `core/billing.php` — `get_active_plans()` / `get_active_plan_by_id()`
  now also select/return `plan_type` and `daily_credit_limit`, so the
  free plan shows up correctly (as ₹0, not purchasable) if `plans_list.php`
  is rendered in Phase 6.
- `config/db.php` — `qrpay_admin_settings()` now also returns
  `email_verification_enabled`; added `qrpay_email_verification_required()`
  convenience wrapper.
- `config/migrate_from_old_schema.sql` — updated for the new schema:
  migrated (pre-Phase-3) developer rows get an unusable random
  placeholder `password_hash` (forces them through "Forgot password" to
  set a real one — **must be communicated to them separately**, they
  cannot self-serve any other way) and a real `subscriptions` row on the
  free plan instead of a `free_trial` row.
- `cron/expire_subscriptions.php` — logic unchanged (the free plan's
  100-year expiry means this query naturally never touches it), only
  updated a stale comment.



## ✅ Done

### Phase 1 — Database Schema
- `QrPay/config/schema.sql` — full fresh schema: `developers`, `otp_codes`,
  `plans`, `coupons`, `subscriptions`, `usage_counters`, `free_trial`,
  `admin_settings`, `user_settings`, `payment_orders` (QR-only, no deep-link
  fields). Seeded starter Basic/Pro/Premium plans.
- `QrPay/config/migrate_from_old_schema.sql` — path from the old UPI PE
  data (apikey-only accounts) into the new developer/email-based schema.
  Old accounts get placeholder emails and must set a real email before
  OTP login works for them.

**Note on `admin_settings`:** the `CHANGE-ME@upi` seed placeholder is left
as-is by design — this is QrPay's own payout UPI ID/MID (used only when a
developer pays *QrPay* for a plan), managed entirely through the DB /
admin panel (Phase 7), never through env vars or hardcoded PHP. It doesn't
block Phases 2–4; it only needs a real value before Phase 5 (subscription
purchases) goes live.

### Phase 2 — Core Config & Helpers
- `config/db.php` — PDO connection, all credentials from env vars
  (`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`/`DB_PORT`), fails loudly with a
  clear 500 if any are missing rather than falling back to a default.
  Also exposes `qrpay_admin_settings($pdo)` — the one shared place that
  reads QrPay's own UPI ID/MID from the `admin_settings` table, so later
  phases don't duplicate that SQL.
- `config/env.example` — template for every required env var (DB, SMTP,
  session secret, admin allowlist, OTP settings). Admin UPI ID/MID
  deliberately **not** listed here — it's DB-only, see note above.
- `core/helpers.php` — `success()`/`fail()` JSON envelopes, `httpGet()`/
  `httpPost()` with `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` forced **on**
  (the old reference code had these off — fixed here), plus OTP
  generate/hash/verify helpers for Phase 3.
- `core/plan_limits.php` — `get_plan_status()` (free trial + active
  subscription + current-cycle usage) and the single `can_accept_payment()`
  boolean gate. Only reads counters — incrementing on PAID happens in
  `verify_payment.php` (Phase 4), never here.
- `core/mailer.php` — PHPMailer/SMTP OTP sender, creds from env only,
  returns `false` on failure rather than throwing (so auth endpoints can
  fail safely instead of leaking the OTP or crashing).

**Dependency added:** `phpmailer/phpmailer` via Composer — run
`composer require phpmailer/phpmailer` before Phase 3 auth endpoints
can send real emails.

### Phase 3 — Developer Auth (Email + OTP, no password)
> ⚠️ **SUPERSEDED by Phase 5.5 above.** Signup/login is now email+password
> (with per-user 2FA and admin-controlled email verification), not
> email+OTP-only. Kept below for history only — don't build against this
> description, read the Phase 5.5 section instead.

- `core/session.php` — new shared helper: `qrpay_session_start()` (hardened
  cookie: `HttpOnly`, `Secure` when on HTTPS, `SameSite=Strict`, custom
  session name) and `qrpay_require_login()` (redirects to login if no
  `developer_id` in session). Every panel/auth file uses this instead of
  a bare `session_start()`, so the flags can't drift between files.
- `auth/request_otp.php` — email in → generates 6-digit OTP, stores only
  the **hash**, 5-min expiry (`OTP_EXPIRY_MINUTES`), rate-limited per
  **email** (`OTP_MAX_REQUESTS_PER_HOUR`, not per IP), emails it via
  `core/mailer.php`. Invalidates any previous unconsumed OTP for that
  email first. Suspended accounts get a deliberately vague error (doesn't
  reveal account state to a prober).
- `auth/verify_otp.php` — email + otp in → validates hash (`hash_equals`),
  caps wrong attempts at 5 per OTP, checks expiry. On first-ever match for
  an email: creates the `developers` row (email_verified=1), generates a
  random `apikey` (shown read-only in dashboard later, used only for
  `/api/*` Authorization — never for panel login), creates the
  `free_trial` row (15 lifetime, from `FREE_TRIAL_MAX_COUNT`), and an
  empty `user_settings` row. Existing email → just logs in. Runs
  `session_regenerate_id(true)` on login to prevent session fixation.
  Also checks `ADMIN_EMAIL_ALLOWLIST` and flips `is_admin` for Phase 7.
- `panel/logout.php` — destroys session + expires the cookie explicitly
  (not just server-side `session_destroy()`). Does not touch `apikey`.
- `panel/auth_check.php` — thin guard for panel pages; include at the top
  of any `panel/*.php` (except `login.php`), exposes
  `$QRPAY_DEVELOPER_ID` / `$QRPAY_DEVELOPER_EMAIL` / `$QRPAY_IS_ADMIN`.

**Not built yet in Phase 3:** the actual `panel/login.php` HTML page
(email + OTP two-step form) — Phase 6 owns all dashboard UI per the
blueprint, this phase only built the auth *endpoints* or `login.php`
would need to be half-rebuilt again in Phase 6. `auth/request_otp.php`
and `auth/verify_otp.php` are plain JSON endpoints any frontend (Phase 6
or otherwise) can call.

### Phase 4 — Order Creation & Verification (QR-only, limit-enforced)
> ⚠️ **Free trial reference below is SUPERSEDED by Phase 5.5** — the
> `free_trial` table and `covered_by_trial` column no longer exist; it's
> `is_free_plan_order` reading from the free plan's own subscription now.
> The apikey auth, QR-only response shape, and Paytm verify logic
> described below are all still accurate.

- `lib/paytm_status.php` — new, trimmed copy of the old `encdec_paytm.php`:
  only the checksum/crypto primitives + `getTxnStatusNew()`/
  `callNewAPI()` that `verify_payment.php` needs. SSL verification forced
  ON (old file had `VERIFYPEER`/`VERIFYHOST` set to 0 in every call —
  fixed here). `PAYTM_MERCHANT_KEY` is no longer a global constant; it's
  passed into `getTxnStatusNew()` per call, sourced from `user_settings`.
  Refund functions **deliberately not ported** — see open items above.
- `api/create_order.php` — authenticates via **apikey → developers**
  lookup (not the dashboard session). Calls
  `get_plan_status($pdo, $developerId)` from `core/plan_limits.php`
  BEFORE creating any order row; hard-blocks with `fail(..., 403)` and
  creates nothing if the limit's hit. Tags the order
  `covered_by_trial = 1` if free trial still has room at creation time.
  Response is QR-only — no `deep_links` block, just `upi_id`,
  `upi_link`, `qr_url`.
- `api/verify_payment.php` — same auto (Paytm) + manual UTR (5-min
  fallback) flow as the old file, confirmed working logic kept intact.
  Also apikey-authenticated, orders scoped by `developer_id`. On
  transition to `PAID`: increments `free_trial.used_count` if the order
  was trial-covered, OR finds/lazily-creates the current cycle's
  `usage_counters` row and increments `verified_count` — decided purely
  by the `covered_by_trial` flag set at order-creation time, never
  re-derived at verify-time. Sets `txn_id` from Paytm's response
  (needed later for the refund phase). If `mid` is set but no
  `paytm_merchant_key` is configured, degrades to manual/UTR flow
  instead of erroring.

### Phase 5 — Plans, Coupons & Self-Referential Subscription Purchase
- `core/billing.php` — new shared helper, same pattern as `plan_limits.php`:
  `get_active_plans()` / `get_active_plan_by_id()` (excludes deactivated
  plans everywhere, even by direct id lookup), `base_price_for_plan()`,
  `validate_coupon()` (checks active/date-window/max_uses/applicable_plans,
  computes flat-or-percent discount, floors final price at ₹1 so a QR can
  always be generated, does **not** consume a use — that only happens on
  actual PAID), and `upsert_subscription_on_payment()` (called from inside
  `verify_payment.php`'s existing transaction).
- `core/session.php` — added `qrpay_require_login_json()` alongside the
  existing `qrpay_require_login()`: same session check, but exits with a
  JSON 401 instead of a redirect, for JSON endpoints called from dashboard
  JS rather than rendered panel pages.
- `api/plans_list.php` — public, unauthenticated: active plans with
  monthly/yearly price, yearly discount %, and payment_limit.
- `api/coupon_validate.php` — session-authenticated (dashboard). Takes
  `plan_id` + `billing_cycle` + `code`, returns base/discount/final price
  or a specific rejection reason. Pure preview, doesn't touch `used_count`.
- `api/subscribe.php` — session-authenticated. Takes `plan_id` +
  `billing_cycle` (+ optional `coupon_code`, **re-validated server-side,
  never trusts a client-computed price**). Creates a `payment_orders` row
  with `order_purpose = 'subscription_purchase'`, `customer_id` = the
  developer's own id, raised against **`admin_settings`** (QrPay's own
  UPI ID/MID via `qrpay_admin_settings($pdo)`) — NOT the developer's own
  `user_settings`. Hard-blocks with 503 if `owner_upi_id` is still the
  `CHANGE-ME@upi` seed placeholder — **this is the trigger to finally
  replace it** before Phase 5 can go live for real. Response includes the
  developer's own `apikey` so dashboard JS can immediately start polling
  `verify_payment.php` with it, same QR-only shape as `create_order.php`.
- `api/verify_payment.php` — added the `order_purpose` branch inside the
  existing PAID transaction: `subscription_purchase` orders call
  `upsert_subscription_on_payment()` (extends `expires_at` from the
  current active subscription's expiry if one exists — so renewing or
  switching plans early doesn't lose remaining paid time — otherwise
  inserts a fresh `subscriptions` row from `NOW()`; bumps
  `coupons.used_count` if a coupon was applied) and explicitly does
  **not** fall into the free_trial/usage_counters branches, which stay
  exactly as Phase 4 left them for `customer_payment` orders.
- `cron/expire_subscriptions.php` — new, CLI-only (refuses to run over
  HTTP). Daily: flips `subscriptions.status` to `expired` where
  `expires_at <= NOW()`. Doesn't touch anything else — `get_plan_status()`
  (Phase 4) already stops finding an active subscription for that
  developer the moment this runs, so `create_order.php` naturally starts
  hard-blocking them on their next request without any extra code.

**Not built yet:** no PHP syntax checker was available in this
environment (`php` binary not installed) — only a brace/paren balance
check was run on every new/changed file. Recommend `php -l` on all
Phase 5 files as a first step of a real review before Phase 6.

---

## 🔜 Next — Phase 6: New Dashboard UI

- Login (email + password, with a 2FA step for accounts that have it
  enabled) — the actual `panel/login.php` HTML, still not built. Should
  call `auth/login.php` then, if `two_fa_required: true` comes back,
  `auth/verify_otp.php`.
- Signup page — calls `auth/signup.php` (name/email/mobile/password/
  confirm). `panel/reset_password.php` (Phase 5.5) is already built as a
  standalone page; a "forgot password" entry point calling
  `auth/forgot_password.php` still needs a small form.
- Panel settings should expose the per-user 2FA toggle
  (`developers.two_fa_enabled`) — no endpoint for toggling it exists yet,
  only the login-time behavior once it's on. Add a small
  `panel/toggle_2fa.php`-style endpoint in Phase 6 or 7.
- Overview, Settings, Orders (manual-approve UI over `manual_action.php`),
  Billing (plan cards from `plans_list.php` — remember the `free` plan
  row will be in that list now, display it as included/non-purchasable
  rather than hiding it; coupon input via `coupon_validate.php`, "Pay
  with QR" via `subscribe.php`, polls `verify_payment.php` until `PAID`),
  Billing History.
- Server-rendered PHP matching the rest of the stack, no legacy dashboard
  code reused. See blueprint for full screen list.

---

## Full Phase Roadmap (see `QrPay-Rebuild-Blueprint.md` for details)

- [x] Phase 1 — Database Schema
- [x] Phase 2 — Core Config & Helpers
- [x] Phase 3 — Developer Auth (Email + OTP) — superseded by Phase 5.5
- [x] Phase 4 — Order Creation & Verification (QR-only, limit-enforced)
- [x] Phase 5 — Plans, Coupons & Subscription Purchase
- [x] Phase 5.5 — Auth Overhaul (email+password/2FA/email verification) +
      Free Plan Conversion (daily/monthly credits, no more separate trial)
- [ ] Phase 6 — New Dashboard UI
- [ ] Phase 7 — Admin Panel
- [ ] Phase 8 — Cleanup & Hardening Pass
- [ ] (Later, separate) — Refund API rebuild against current Paytm format

---

## Notes / Reminders carried forward
- No secrets in committed code — DB password, SMTP creds via environment
  variables. QrPay's own payout UPI ID/MID is DB-only (`admin_settings`
  table), never env, never hardcoded — see note above.
- SSL verification stays ON for every outbound cURL call, including
  Paytm status/refund calls.
- Only `PAID` orders ever increment usage counters.
- Rate limiting on `/api/*` is per-**apikey**, not per-IP (developer server
  traffic shares IPs).
- Free plan: 10 credits/day, 300 credits/month, calendar-day reset
  (midnight) — replaces the old one-time 15-lifetime free trial as of
  Phase 5.5. Every developer always has an active subscription (free or
  paid), never zero.
- Limit hit → `create_order.php` hard-blocks (no order row created at all).
- Coupon `used_count` only ever increments on an actual PAID subscription
  order (inside `verify_payment.php`'s transaction) — never on a
  `coupon_validate.php` preview call, and never inside `subscribe.php`
  itself (that only creates the pending order). The free plan can't be
  "purchased" at all — `subscribe.php` rejects it with a 400.
- `admin_settings.owner_upi_id` must be a real value before subscribe.php
  will create orders — it currently hard-fails with a 503 while it's
  still `CHANGE-ME@upi`. Update it via direct DB write until Phase 7's
  admin panel exists.
- Email verification (`admin_settings.email_verification_enabled`) is
  OFF by default (`0`) — signups log straight in until an admin flips it
  on via direct DB write (no Phase 7 admin panel yet).
- 2FA (`developers.two_fa_enabled`) is OFF by default per developer, and
  there's no UI to turn it on yet (Phase 6/7) — only direct DB write for
  now, same as the email verification toggle above.
