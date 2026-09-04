# Handover — 2026-08-27 (session 13): Admin Customer-Feedback View Built, Customer Complete-Profile Backend Built (Android Pending)

## What was asked

App owner asked (Hinglish, admin panel side work):
1. "customer feedback wala" — an admin panel screen to see customer
   feedback submissions.
2. "customer mail + otp se login ke baad uska name puche and number
   mange customer app mai" — after email+OTP login, ask the customer
   for their name and mobile number, inside the customer app.

## 1. Admin Panel — Customer Feedback View (fully built)

`api/v1/customer/feedback.php` (built in Phase 3.6 §2.7, Profile >
Feedback screen in the customer app) has been capture-and-store only
since it was written — its own kdoc says so explicitly: "Reviewable
directly in the `feedback` table (or a future Admin Panel screen, Phase
5)." Nothing had ever built that screen. This session did.

**Built:**
- `backend/sql/55_migration_customer_feedback_admin_view.sql` — new
  `feedback_view` admin permission (Super Admin gets it by default,
  same pattern as migration 54's `reviews_moderate`; extendable to
  other roles via `admin/roles.php` with no further migration).
- `backend/admin/customer-feedback.php` — new read-only page. Lists
  feedback newest-first (customer name/email/mobile via a LEFT JOIN,
  star rating if set, message, timestamp), with:
  - Star-rating filter chips (1★–5★) with live counts
  - A plain text search box over the message body
  - "All" chip showing total count
- `backend/admin/_layout_head.php` — added a "Customer Feedback" nav
  item under the Operations group, right after Review Moderation
  (same icon-set convention as every other nav entry), and updated the
  `$activeNav` doc-comment list to include the new key.

**Deliberately NOT built:** any mark-as-reviewed/reply/status
workflow. The `feedback` table has no status column and the endpoint
was never meant to be anything but capture-and-store — this page
matches that, same restraint `review-moderation.php` doesn't need
(reviews *do* have a moderation_status column, feedback doesn't). If
the app owner wants a reply/workflow loop later, that's a new
migration + column, not a UI-only change.

**Not device/build-verified** — same standing sandbox limitation as
every other session in this project (no PHP CLI/live MySQL/browser
here). Migration 55 needs to actually run against a live DB, and the
page needs an eyeball check in a browser, before this is called done.

## 2. Customer App — Complete Profile After OTP Login

`customers.name` and `customers.mobile` (`01_Database_Schema.md`) have
always been nullable columns — email-OTP signup
(`auth/customer-verify-otp.php`) creates a customer row with just an
email the first time someone logs in, name/mobile never collected.
The app owner asked for a "tell us your name + number" step right
after OTP verification succeeds, before the customer reaches Home.

**Built (backend only, this session):**
- `backend/api/v1/customer/complete-profile.php` — new authenticated
  (`require_auth('customer')`) endpoint. Takes `{name, mobile}`
  (both required — this is the one-time "complete your profile" step,
  not a general partial-update endpoint). Validates:
  - `name`: non-empty, ≤100 chars (matches the column's `VARCHAR(100)`)
  - `mobile`: strips non-digits, must be exactly 10 digits
  - Rejects if another customer row already has that mobile (mobile
    has no UNIQUE constraint at the DB level, unlike email — this is
    an application-level check, not a DB constraint, so it's a soft
    guard, not airtight against a race; acceptable for this feature's
    stakes, flag it if the owner wants a real UNIQUE index added later)
  Returns the full updated customer row on success.
- `backend/.htaccess` — added the matching clean-URL route
  (`customer/complete-profile` → `customer/complete-profile.php`),
  though note the Android app talks to the `.php` files directly per
  `ApiService.kt`'s own header comment convention, same as every other
  endpoint here — the clean route exists for completeness only.

**NOT built yet — this is the bulk of the remaining work:**
Nothing on the Android side has been touched. To finish this feature,
next session needs to:

1. `Models.kt` — add `mobile: String?` to the `Customer` data class;
   add `CompleteProfileBody(name: String, mobile: String)` and a
   result wrapper (`CompleteProfileResult(customer: Customer?)` or
   reuse a pattern like `AuthResult`).
2. `ApiService.kt` — add
   `@POST("customer/complete-profile.php") suspend fun completeProfile(@Body body: CompleteProfileBody): Response<ApiResponse<CompleteProfileResult>>`
   next to the existing `verifyOtp`/`requestOtp` entries.
3. New `CompleteProfileActivity.kt` (suggest package
   `ui.login`, next to `LoginActivity.kt`) — two `TextInputLayout`
   fields (name: `textPersonName`, mobile: `phone` inputType, 10-digit
   max length), one submit button, same visual language as
   `activity_login.xml` (banner-less is fine, this is a quick
   in-between screen). Needs a new `ic_phone.xml` vector drawable —
   `ic_mail.xml`/`ic_person.xml`/`ic_lock.xml` already exist as a
   pattern to copy.
4. `activity_complete_profile.xml` layout to match.
5. Register `CompleteProfileActivity` in `AndroidManifest.xml`
   (alongside `LoginActivity`/`HomeActivity`).
6. Wire it in: `LoginActivity.onVerifyOtp()` currently does, on
   success:
   ```kotlin
   tokenManager.saveSession(...)
   startActivity(Intent(this@LoginActivity, HomeActivity::class.java))
   finish()
   ```
   This needs to become: if `body.data.customer?.name.isNullOrBlank()`
   or `body.data.customer?.mobile.isNullOrBlank()`, go to
   `CompleteProfileActivity` instead (passing the saved token along —
   `TokenManager` already holds it once `saveSession()` runs, so the
   new activity just needs to call `completeProfile()` with the
   existing session, no extra plumbing). Only route straight to
   `HomeActivity` when both are already present (a returning customer
   who already completed this step on a previous login).
7. `CompleteProfileActivity`, on successful save, should `startActivity`
   `HomeActivity` and `finish()` itself (and ideally clear
   `LoginActivity` off the back stack too, same as it already does).

No new migration needed for the Android side — the backend is ready
and waiting.

## Next session should start here

Finish the Android side of item 2 above (steps 1–7) — it's a single
self-contained screen + one wiring change in `LoginActivity`, the
backend contract is already fixed and documented above. After that,
device/build-verify both this session's admin feedback page and the
full OTP → complete-profile → Home flow, per the checklists in
`PENDING.md` items 11a/11b.

## Files touched this session

- `backend/sql/55_migration_customer_feedback_admin_view.sql` (new)
- `backend/admin/customer-feedback.php` (new)
- `backend/admin/_layout_head.php` (nav entry added)
- `backend/api/v1/customer/complete-profile.php` (new)
- `backend/.htaccess` (route added)
- `PENDING.md` (items 11a, 11b added)
- `recall.md` (this session's entry added)
- `docs/Status.md` (this session's entry added)
- `docs/52_Handover_...md` (this file)
