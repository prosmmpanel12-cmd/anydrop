# Restaurant App (Anydrop For Restaurant) — Status

Full scope/priority reference: `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`.
This file tracks what's actually been *built* against that plan — updated
each session, newest at the top.

---

## 2026-08-14 — Signup/Login entry flow (this session)

**Decision:** app owner chose to start with the Signup/Login entry point
(not Menu Management, which doc 18's own recommended order lists first) —
because Signup didn't exist at all yet, only a bare Login screen.

### ✅ Done
- **Splash screen** (`ui/splash/SplashActivity.kt`) — new launcher Activity.
  Animated logo (scale + overshoot) and fade-up title/tagline, same
  animation files the Customer app's splash already uses
  (`res/anim/splash_logo_in.xml`, `splash_text_in.xml` — copied as-is so
  both apps share one brand entrance). Routes to Dashboard (already
  logged in) or Login after ~0.9s.
- **Login screen redesign** (`ui/login/LoginActivity.kt` +
  `activity_login.xml`) — same fields as before (email/password), now
  with a cascading fade-up entrance for each field
  (`res/anim/form_field_in.xml`, staggered via `startOffset`) and a
  "New restaurant partner? Sign up" link.
- **Signup flow — full 3-step flow, new this session:**
  1. `ui/signup/SignupActivity.kt` — restaurant name, owner name, owner
     mobile, owner email, password, confirm password, address (optional).
     Client-side validation, then requests an email OTP.
  2. `ui/signup/OtpVerifyActivity.kt` — 6 individual auto-advancing digit
     boxes (Zomato/Swiggy-style OTP input), 30s resend countdown, shake
     animation on wrong code. On success, submits the account.
  3. `ui/signup/SignupSuccessActivity.kt` — "Application submitted, under
     review" screen with a pop-in checkmark, routes back to a clean Login.
- **Backend — 3 new endpoints** (`backend/api/v1/auth/`):
  - `restaurant-request-otp.php` — sends OTP (mirrors
    `customer-request-otp.php`'s cooldown/debug_otp pattern, reuses the
    same `email_otps` table).
  - `restaurant-verify-otp.php` — verifies only, does **not** create an
    account (unlike the customer flow) since the restaurant form needs
    more fields collected first.
  - `restaurant-signup.php` — creates the `restaurants` row
    (`status='pending'`) only after confirming a just-verified OTP exists
    for that email. No schema changes needed — every column
    (`name`, `owner_name`, `owner_mobile`, `owner_email`, `password_hash`,
    `address`, `status` default `'pending'`) already existed.
- **New animation resources** (`restaurant/app/.../res/anim/`):
  `form_field_in`, `slide_in_right/left`, `slide_out_left/right`,
  `shake_error`, `success_pop_in` (+ copies of the Customer app's
  `splash_logo_in`/`splash_text_in`).

### 🟡 Known gaps / not done this session
- **No real email delivery** — same limitation as the Customer app's OTP
  flow (`docs/19` §7 Email OTP multi-provider is planning-only). OTP is
  logged server-side only; visible in the app response solely when
  `debug_otp_enabled` app_setting is `'1'` on a dev/staging DB.
- **Logo upload during signup** — not included; restaurant logo/cover
  photo upload is separately scoped under Tier 1 "Restaurant Management"
  in doc 18 and wasn't pulled into this flow to keep the signup form
  short. Can be added post-approval, in the (not-yet-built) profile screen.
- **No build/compile verification** — same standing limitation as every
  other session per `Status.md` (no Android SDK in this environment).
  First thing next session on this: build the restaurant app, fix
  whatever the compiler catches, smoke-test signup → OTP → pending →
  (admin approves, not built yet) → login on an emulator.
- **Admin approval screen doesn't exist yet** — a restaurant can now
  self-signup into `status='pending'`, but nothing in the Admin Panel can
  approve it yet (that's doc 19 §3, planning-only). Until that's built,
  a pending signup has no way to reach `approved` except a manual
  `UPDATE restaurants SET status='approved'` on the DB.

### ⏭️ Next (per doc 18's own recommended order, resuming after this
detour)
1. Menu Management (Tier 1) — biggest remaining functional gap.
2. Order Management small additions (loud sound, prep-time select,
   cancel reason).
3. Restaurant Management profile screen (name/address/hours/logo, temp
   closure) — natural next stop after Signup, since a newly-approved
   restaurant needs this to actually set itself up.
4. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last).

Admin-side "Approve/Reject pending restaurants" screen should also move
up in priority now that self-signup exists and can actually produce
pending rows to approve — flag this to the app owner alongside item 1.

---

## 2026-08-14 — QA test restaurant account (later, same day)

Added `backend/sql/21_seed_test_restaurant_account.sql` — one
pre-approved (`status='approved'`) restaurant row so the new
signup/login flow can be tested end-to-end without the (not-yet-built)
admin approval screen blocking it.

- **Login:** `test@anydrop.com` / `test`
- Run this SQL file against the DB (phpMyAdmin on KS Web, or wherever
  `backend/sql/*.sql` files normally get run from — same as every other
  numbered migration in this folder) — it's idempotent, safe to re-run.
- This is a QA-only seed, not something to ship in production data —
  worth deleting (or at least rotating the password) before a real launch.
