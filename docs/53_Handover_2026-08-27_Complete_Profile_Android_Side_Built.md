# Handover — 2026-08-27 (session 14): Complete-Profile Android Side Built

## What was asked

Continue the highest-priority item left open in `PENDING.md` /
`docs/52_Handover_...md` — the Android side of "Complete Profile After
OTP Login" (name + mobile), whose backend was already built and
documented in session 13.

## What was built

All 7 remaining steps listed in doc 52's handover section, in the
customer app (`customer/app/src/main/java/com/anydrop/food/`):

1. **`network/Models.kt`** — added `mobile: String?` to `Customer`;
   added `CompleteProfileBody(name, mobile)` and
   `CompleteProfileResult(customer: Customer?)`, matching the backend's
   `{ "customer": {...} }` response shape exactly.
2. **`network/ApiService.kt`** — added
   `completeProfile(@Body body: CompleteProfileBody)` posting to
   `customer/complete-profile.php`, next to `verifyOtp`.
3. **`ui/login/CompleteProfileActivity.kt`** (new) — name + mobile form.
   Client-side validation mirrors the backend (`name` non-empty,
   `mobile` exactly 10 digits) so bad input never reaches the network
   call. On success, calls `tokenManager.setProfileComplete(...)` and
   routes to `HomeActivity`. Error handling follows the same
   `ApiErrorParser` pattern `LoginActivity` already uses (doc references
   the parser's own kdoc on why `response.body()` is null on non-2xx),
   with a specific message for the `mobile_already_in_use` 409.
4. **`res/layout/activity_complete_profile.xml`** (new) — same visual
   language as `activity_login.xml` (no banner, per doc 52's
   suggestion): title/subtitle, two `TextInputLayout` fields
   (`ic_person` / `ic_phone` start icons), one save button, a
   `ProgressBar` for the loading state.
5. **`res/drawable/ic_phone.xml`** (new) — standard Material phone-handset
   glyph, same 24dp/`colorControlNormal`-tint convention as
   `ic_mail.xml`/`ic_person.xml`/`ic_lock.xml`.
6. **`AndroidManifest.xml`** — registered
   `.ui.login.CompleteProfileActivity` (`exported="false"`, same as
   `LoginActivity`/`HomeActivity`), placed directly between the two.
7. **`ui/login/LoginActivity.kt`** — `onVerifyOtp()`'s success branch now
   checks `customer?.name.isNullOrBlank() || customer?.mobile.isNullOrBlank()`
   and routes to `CompleteProfileActivity` instead of `HomeActivity`
   when either is missing (i.e. every brand-new signup). A returning
   customer who already completed this step gets both fields populated
   by `verify-otp.php` and skips straight to Home, unchanged from
   before.

### Also added (small, not in doc 52's original list but needed for symmetry)

- **`data/TokenManager.kt`** — added `setProfileComplete(name, mobile)`,
  `getName()`, `getMobile()` with two new `SharedPreferences` keys. This
  is a local cache only; the actual source of truth for "does this
  customer still need the screen" is the `customer.name`/`.mobile`
  nullability check in `LoginActivity`, same as doc 52 specified — the
  TokenManager fields just mean the rest of the app (e.g. a future
  Account/Settings screen) doesn't have to re-fetch the customer row to
  show the name/mobile it already has.

### Strings

Added to `res/values/strings.xml` right after the OTP strings:
`complete_profile_title`, `complete_profile_subtitle`,
`hint_full_name`, `hint_mobile_number`, `btn_save_continue`.

## Not done / still open

- **Not device/build-verified** — same standing sandbox limitation
  every session in this project has noted (no Android SDK/emulator
  here). Needs an actual Android Studio build + a real OTP login run
  (both the brand-new-signup path into `CompleteProfileActivity`, and
  the returning-customer path that skips it) before this is
  production-ready, per `PENDING.md`'s completion rule (§34).
- No `Customer(...)` call sites elsewhere in the app construct the data
  class manually (checked — Gson deserializes it from JSON only), so
  adding the `mobile` field is safe and required no other file changes.
- The mobile-uniqueness check on the backend is explicitly a soft
  application-level guard (no DB `UNIQUE` index on `customers.mobile`)
  — doc 52 already flagged this as a possible future hardening item,
  unchanged here.

## Files touched this session

- `customer/app/src/main/java/com/anydrop/food/network/Models.kt`
- `customer/app/src/main/java/com/anydrop/food/network/ApiService.kt`
- `customer/app/src/main/java/com/anydrop/food/data/TokenManager.kt`
- `customer/app/src/main/java/com/anydrop/food/ui/login/LoginActivity.kt`
- `customer/app/src/main/java/com/anydrop/food/ui/login/CompleteProfileActivity.kt` (new)
- `customer/app/src/main/res/layout/activity_complete_profile.xml` (new)
- `customer/app/src/main/res/drawable/ic_phone.xml` (new)
- `customer/app/src/main/res/values/strings.xml`
- `customer/app/src/main/AndroidManifest.xml`
- `PENDING.md` (item 11b checklist updated)
- `docs/53_Handover_2026-08-27_Complete_Profile_Android_Side_Built.md` (this file)
