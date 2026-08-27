Anydrop project zip attached. Unzip it, read `PENDING.md` first (the
source-checked pending list), then
`docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`
for exactly what the last session did and why.

## Kiya hua hai (what's done, as of 2026-08-27 session 13)

- **Admin Panel — Customer Feedback view. Fully built.** Migration 55
  (`feedback_view` permission) + `admin/customer-feedback.php`
  (read-only list of `feedback` table rows — customer name/email/
  mobile, star rating, message, timestamp — with star-rating filter
  chips and a message search box) + a new sidebar nav entry under
  Operations. This closes the TODO that had been sitting in
  `api/v1/customer/feedback.php`'s own kdoc since Phase 3.6
  ("Reviewable directly in the `feedback` table, or a future Admin
  Panel screen, Phase 5").
- **Customer App — Complete Profile after OTP login. Backend built,
  Android NOT started.** New `api/v1/customer/complete-profile.php`
  (auth'd, validates `name` + 10-digit `mobile`, rejects a mobile
  already used by another customer row, updates `customers.name`/
  `customers.mobile` — both nullable columns that email-OTP signup has
  always left blank). `.htaccess` route added too. **Nothing on the
  Android side has been touched yet** — see PENDING.md item 11b for
  the full remaining checklist (new `CompleteProfileActivity` +
  layout, `Models.kt`/`ApiService.kt` wiring, and the
  `LoginActivity.onVerifyOtp()` routing change to send a customer with
  no `name`/`mobile` to the new screen instead of straight to
  `HomeActivity`).
- **Nothing in this project has ever been build/device-verified.**
  No PHP CLI, Android SDK/Gradle, live DB, or physical device exists
  in the sandbox any session has run in so far, including this one.

## Kiya pending hai (what's still pending — see PENDING.md for the full list)

Top of the list, roughly in priority order:

1. **PENDING.md item 11b — Customer Complete-Profile, Android side.**
   This is the very next thing to build: a single self-contained
   screen (`CompleteProfileActivity` + layout, same visual language as
   `activity_login.xml`) plus one wiring change in
   `LoginActivity.onVerifyOtp()`. The backend contract is already
   fixed and documented in doc 52 — this is pure Android work, no new
   migration, no backend changes needed.
2. **PENDING.md item 11a — run migration 55 + browser-verify the new
   Customer Feedback admin page** once a live DB/browser is available.
3. **Restaurant Self Delivery** (PENDING.md item 5) — admin control
   for Anydrop-delivery vs. restaurant-self-delivery eligibility, not
   started. Next unstarted P1 feature in PENDING.md's own list order,
   if the owner wants a bigger feature pick instead of #1 above.
4. **Payment/Refund Reconciliation** (PENDING.md item 24) — foundation
   exists, final reconciliation layer (provider transaction matching,
   mismatch detection, admin mismatch queue) is not built.
5. **Email OTP Provider Failover** (PENDING.md item 25) — confirmed via
   grep in an earlier session that no `email_otp_providers`-shaped
   table/file exists anywhere; genuinely not started.
6. **Security Hardening** (PENDING.md item 26) — final audit across
   OTP rate limiting, coupon race conditions, server-side price/state
   validation, RBAC audit, secret management. Needs a real machine to
   actually test rate limits/race conditions, not just read code.
7. **Machine verification** — every "BUILT" item across this entire
   project is written but completely unverified. Whenever real PHP
   CLI/Android SDK/live DB/device access becomes available, running
   through the accumulated checklists (docs 42, 44, 48, 49, 50, 51, 52)
   is higher value than more feature work on an ever-growing
   unverified surface.
8. Everything else in PENDING.md's P1/P2 lists below these.

## Next session should start here

**Recommended: finish PENDING.md item 11b (Customer Complete-Profile,
Android side).** It's small, self-contained, and the backend is
already done and waiting — the fastest way to actually close out a
full feature this session instead of leaving another half-built item.

Steps:
1. Read `docs/52_Handover_2026-08-27_Admin_Feedback_View_And_Customer_Complete_Profile_Built.md`
   section 2 for the exact backend contract
   (`api/v1/customer/complete-profile.php`'s request/response shape)
   and the numbered Android to-do list already written out there.
2. Read `customer/app/src/main/java/com/anydrop/food/ui/login/LoginActivity.kt`
   and `customer/app/src/main/res/layout/activity_login.xml` first —
   don't guess at the existing patterns (TokenManager, ApiClient,
   InAppNotifier, view-binding usage), copy them.
3. Add the `mobile` field to `Customer` in `Models.kt`, add
   `CompleteProfileBody`/result models, add the `ApiService.kt` call.
4. Build `CompleteProfileActivity.kt` + `activity_complete_profile.xml`
   (name + mobile fields; a new `ic_phone.xml` vector drawable is
   needed — copy `ic_mail.xml`'s shape as a template).
5. Register the activity in `AndroidManifest.xml`.
6. Wire the routing change in `LoginActivity.onVerifyOtp()`.
7. Same standing rule as every session in this project: read the
   actual current code before assuming what's there, don't rely on
   older docs' framing alone.

If the app owner instead wants machine verification before more
feature work, docs 42/44/48/49/50/51/52's checklists are all ready and
waiting — see "Kiya pending hai" item 7 above.
