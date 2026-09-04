# Rider App — Phase 1 (Backend: DB + Signup/OTP) Handover
Session date: 01 Sep 2026

> **⚠️ Not built/tested this pass.** This sandbox has no PHP interpreter,
> no MySQL, and no network access, so nothing below has actually been
> run against a database or lint-checked with `php -l`. Every file was
> hand-written to closely mirror an existing, already-working file
> (`restaurant-signup.php` / `restaurant-request-otp.php` /
> `restaurant-verify-otp.php` / `restaurant-login.php`) and re-read
> carefully for syntax, but per `done.md`'s own rule ("No successful
> test = No DONE mark"), treat everything below as
> `🟡 IMPLEMENTED — TEST PENDING` until you've run the migration and
> hit each endpoint for real.

## Context

App owner wants a Rider (delivery partner) app. Full scope was planned
in-chat as 4 phases (signup → core delivery flow → earnings → polish).
This handover covers **Phase 1 only**: the backend foundation — DB
schema change + signup/OTP auth endpoints — that Phase 2 (the actual
Android rider app UI) will call.

Two things app owner specifically asked for in the signup screen:
- Fields: **email, mobile number, name, service area**
- Service area: **both** GPS auto-detect *and* a dropdown — not
  either/or.

## What already existed (found during audit, not built this session)

- `riders` table (01_schema.sql) — but it's **restaurant-scoped**
  (`restaurant_id NOT NULL`, username/password, created by a
  restaurant for its own delivery boy). Not the self-signup model
  needed here.
- `service_areas` hierarchy table (migration 30) — State → District →
  City/Village → Area, with `center_lat/center_lng/radius_km` on
  whichever level is deepest in a branch. Exactly what the dropdown +
  GPS match needed.
- `resolve_service_area()` (`lib/geo.php`) — nearest-within-radius
  lookup already used by `restaurant-signup.php`, customer addresses,
  and banner targeting. Reused as-is, not reimplemented.
- Email-OTP infra (`email_otps` table, `EmailOtpService` with
  6-provider failover, `otp_length`/`otp_expiry_minutes`/
  `otp_max_attempts`/`otp_request_cooldown_seconds`/`debug_otp_enabled`
  settings) — already powers customer login and restaurant signup.
  Riders now use the exact same table/service, just a different
  `purpose` string (`'rider_auth'`) passed to `EmailOtpService::send()`
  for template/logging purposes.
- `auth_tokens.owner_type` ENUM **already included `'rider'`** — so
  `create_auth_token('rider', $id)` needed zero schema change on that
  table.

## ✅ Built this session

### 1. Migration 69 — `backend/sql/69_migration_rider_self_signup.sql`
Extends the existing `riders` table rather than replacing it:
- `restaurant_id` made **nullable** — a platform rider (this feature)
  is inserted with `restaurant_id = NULL`. Existing restaurant-created
  rider rows are untouched and keep working exactly as before.
- New columns: `email` (nullable, unique), `service_area_id` (FK →
  `service_areas.id`), `latitude`/`longitude`, `status` ENUM
  (`pending`/`approved`/`rejected`/`suspended` — mirrors
  `restaurants.status` exactly, same admin-approval gate),
  `rejection_reason`, `vehicle_doc_url`, `id_doc_url` (for Phase 2's
  document-upload step, added now so the column exists ahead of time).
- **Backfill:** any existing restaurant-created rider row is set to
  `status = 'approved'` — a restaurant that added its own rider
  already vetted them, so this migration doesn't retroactively lock
  them out behind the new approval gate.
- Same idempotent `CONTINUE HANDLER FOR 1060` (duplicate column) /
  `1826`+`1005` (duplicate FK) pattern every other ALTER-TABLE
  migration in this project uses, since this environment's DB user
  can't read `information_schema` directly. Safe to run any number of
  times.

**Run this migration before anything else.** Nothing below works
without it.

### 2. `backend/api/v1/auth/rider-request-otp.php`
`POST /api/v1/auth/rider/request-otp` — `{ "email" }` →
`{ "message": "OTP sent" }`. Mirrors `restaurant-request-otp.php`
exactly (same cooldown, same `debug_otp_enabled` echo-back for
testing). **Difference:** does NOT block on "email already
registered" — this same endpoint is also how an *existing* rider
requests their login OTP, so a duplicate email must be allowed
through here. `rider-signup.php` is what blocks a duplicate email, at
actual account-creation time.

### 3. `backend/api/v1/auth/rider-verify-otp.php`
`POST /api/v1/auth/rider/verify-otp` — `{ "email", "otp" }`.
Does double duty as Step 2 of signup **and** the entirety of login
(riders are email-OTP-only, no password, same as customers):
- New email (no rider row yet) → `{ "verified": true, "account_exists": false }`.
  App sends them to the signup form to collect name/mobile/area.
- Existing rider's email → issues the auth token right here (same
  shape as `customer-verify-otp.php`'s pattern) and returns
  `{ "verified": true, "account_exists": true, "rider": {...}, "token": "...", "status": "..." }`.
  A `suspended`/`rejected` rider is blocked with `account_suspended`
  at this point, same as restaurant login blocks those statuses.

### 4. `backend/api/v1/auth/rider-signup.php`
`POST /api/v1/auth/rider/signup` — `{ "name", "email", "mobile",
"service_area_id"? , "latitude"?, "longitude"?, "vehicle_type"?,
"vehicle_number"? }`.
- Re-checks the same `is_used=1`-within-expiry `email_otps` row
  `restaurant-signup.php` checks — can't call this with an email that
  was never actually verified in step 3.
- **Area resolution — both GPS and dropdown, dropdown wins if both
  given:** if lat/lng are sent, `resolve_service_area()` runs first and
  its nearest match becomes the default. If the rider *also* picked a
  specific `service_area_id` from the dropdown, that explicit choice
  overrides the GPS guess (dropdown = editable confirmation of the GPS
  guess, not just a no-GPS fallback). If neither resolves, `area_id`
  stays NULL and the response says `area_resolved: false` — same "not
  an error, admin sorts it at approval" behaviour restaurant signup
  already has for an out-of-coverage applicant.
- New row: `status = 'pending'`, `restaurant_id = NULL`.
- `username`/`password_hash` (legacy, NOT NULL columns from the old
  restaurant-created-rider path) are filled with random placeholders —
  a platform rider never actually uses them, but leaving them NULL
  would risk breaking any existing restaurant-side rider-management
  screen that still reads `username`. **Flagging this as a follow-up
  cleanup candidate** — those two columns could become properly
  nullable in a later migration once it's confirmed nothing restaurant
  side still depends on them being non-null.
- **Issues a token immediately** (unlike restaurant signup, which
  doesn't) — since riders are passwordless, this lets the app take a
  freshly-signed-up rider straight into an authenticated "application
  pending" screen instead of a dead-end public page. `require_auth('rider')`
  still blocks it from doing anything order/earnings-related until an
  admin flips `status` to `approved` — endpoints for that check
  `$owner['status']` themselves (see `lib/auth.php` change below);
  this token only unlocks the pending-status screens.

### 5. `backend/api/v1/system/service-areas.php`
`GET /api/v1/system/service-areas` → `{ "areas": [{id, parent_id,
level, name}, ...] }`. Public, unauthenticated, flat list of every
`is_active = 1` `service_areas` row. Deliberately flat (not
pre-nested) — `admin/areas.php` already groups by `parent_id`
client-side rather than the backend doing it, so the rider app's
cascading State → District → City/Village → Area picker does the same
on-device. Not rider-specific — reusable by customer/restaurant apps
too if a similar picker is ever needed there.

### 6. `backend/lib/auth.php` — `require_auth()` extended
Added an `elseif ($expectedOwnerType === 'rider')` branch, same
re-check-status-on-every-request principle the `restaurant`/`customer`
branches already use. **Deliberately does NOT block `pending`/
`rejected` here** (unlike the restaurant branch, which does) — a
pending rider still needs to reach authenticated screens right after
signup (see point 4 above). Only `suspended` / `is_active = 0` / row
gone is blocked at this shared layer. Any endpoint that actually
requires full approval (going online, accepting orders, earnings —
all Phase 2/3) must check `$owner['status'] === 'approved'` itself;
this function now returns `status` in the owner array so those checks
are a one-line `if`.

## 🟡 Known gaps / next-session TODO (Phase 1 wrap-up)

1. **Run migration 69 + smoke-test all 4 endpoints for real** — this
   is the top priority before touching Android at all. No PHP/MySQL in
   this sandbox to do it here.
2. `username`/`password_hash` placeholder columns on `riders` — see
   note in point 4 above; consider making them properly nullable once
   confirmed safe.
3. No rate-limit / abuse guard on `rider-signup.php` beyond the
   existing OTP cooldown — same gap restaurant-signup.php already has,
   not new to this session, just noting it's still open.
4. Admin panel has **no "Riders" approval queue screen yet** — a
   self-signed-up rider will sit at `status='pending'` with no UI for
   an admin to approve/reject them. This needs a small admin page
   (`backend/admin/riders.php`?) mirroring the restaurant-approval
   queue's shape, before Phase 1 is genuinely end-to-end usable. Not
   built this session — flagging as the next piece of backend work,
   likely before or alongside Phase 2's Android signup screen.
5. `EmailOtpService::send()`'s `'rider_auth'` purpose string — check
   whether the email template/provider config needs anything
   purpose-specific added on the Admin → Email Providers screen, or
   whether it falls through to a generic template fine as-is.

## Files in this delivery

```
backend/sql/69_migration_rider_self_signup.sql
backend/api/v1/auth/rider-request-otp.php
backend/api/v1/auth/rider-verify-otp.php
backend/api/v1/auth/rider-signup.php
backend/api/v1/system/service-areas.php
backend/lib/auth.php   (modified — require_auth() rider branch added)
```

Apply the migration first, then drop the `api/` files into the same
relative paths in your live `backend/` tree, replacing `lib/auth.php`
with this version (it's additive — nothing existing in that file was
removed or changed, only the new `elseif` branch added).
