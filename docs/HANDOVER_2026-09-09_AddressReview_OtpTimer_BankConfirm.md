# Handover — 2026-09-09 (session 2) — Restaurant Address Review wiring + Customer OTP Timer + Bank/OTP Confirm

Continuation of the same-day handover ("Restaurant Address Admin-Review +
(planned) Bank/OTP Confirm + Customer OTP Resend Timer"). That doc's
"Suggested order for whoever continues" was followed exactly:

1. Finish `EditProfileActivity.kt` wiring → **DONE, item fully closed.**
2. Customer OTP timer → **DONE, item fully closed.**
3. Bank/UPI save confirmation → **backend done for both apps; Android
   done for restaurant; rider Android NOT done — bigger gap found than
   expected, see below.**

---

## ✅ DONE — Restaurant Address → Admin Review (now fully closed out)

Only piece left per the prior handover was `EditProfileActivity.kt`'s
`populate()` not setting `addressReviewNotice`'s text/visibility. Done:

- `restaurant/app/.../ui/account/EditProfileActivity.kt` — `populate()`
  now reads `profile.addressReviewStatus` / `profile.addressReviewRemarks`
  and shows/hides `addressReviewNotice` (pending / rejected / none),
  exactly the logic the prior handover had already drafted.
- `restaurant/app/src/main/res/values/strings.xml` — added
  `address_update_pending_review` and `address_update_rejected_reason`
  (`%1$s` placeholder for the rejection reason).

No further work needed here. **Reminder carried over: migration 79
(`backend/sql/79_migration_restaurant_address_review.sql`) still has not
been run against any database** — run it before testing.

---

## ✅ DONE — Customer OTP resend cooldown (30s, now fully closed out)

- `customer/app/.../ui/login/LoginActivity.kt`:
  - New `resendOtpTimer: CountDownTimer?` field + `resendOtpCooldownMillis = 30_000L`.
  - New `startResendOtpCooldown()`: cancels any prior timer, disables
    `btnResendOtp`, ticks `"Resend in Ns"` every second
    (`otp_resend_countdown` string), and on finish re-enables the button
    with the original `btn_resend_otp` label.
  - Called from `onSendOtp()`'s success branch — fires for both the
    first send and every resend tap.
  - `onDestroy()` override added to cancel the timer, avoiding a late
    tick against a dead Activity/binding.
- `customer/app/src/main/res/values/strings.xml` — added
  `otp_resend_countdown` (`"Resend in %1$ds"`).

No further work needed here.

---

## ◐ PARTIALLY DONE — Bank/UPI save confirmation (restaurant: password, rider: OTP)

### Restaurant (password) — backend + Android both done

- `backend/api/v1/restaurant/bank-details-save.php`:
  - Now requires `password` in the request body.
  - Looks up `restaurants.password_hash` for the authenticated owner and
    `password_verify()`s the submitted password **before** touching
    `restaurant_bank_details` — same check `restaurant-login.php` uses.
  - Wrong/missing password → `invalid_password` (401), nothing written.
  - A failed attempt writes a `bank_details_save_password_failed` audit
    log row (visibility into repeated bad attempts) but does not touch
    the bank details themselves.
- `restaurant/app/.../network/Models.kt` — `BankDetailsSaveBody` gained a
  required `password: String` field.
- `restaurant/app/.../ui/account/BankDetailsActivity.kt`:
  - All existing client-side field validation is unchanged and still
    runs first.
  - On passing validation, `promptForPasswordThenSave()` shows a
    `MaterialAlertDialogBuilder` dialog with a programmatically-built
    `TextInputLayout`/`TextInputEditText` (password-masked, visibility
    toggle) asking the owner to re-enter their login password — no new
    dialog layout XML was added since this is the only place in the app
    that needs this one field.
  - On confirm, `performSave(...)` sends the password along with the
    bank fields. A `401` response shows a distinct "Incorrect password"
    toast (`error_incorrect_password`) instead of the generic bank-save
    failure message, so the owner knows what to fix.
  - New strings: `hint_confirm_password`, `dialog_confirm_password_title`,
    `dialog_confirm_password_bank_message`, `error_password_required`,
    `error_incorrect_password`. (`btn_save`/`btn_cancel` already existed
    and were reused.)

**This half is complete and ready to test** once migration 79 is run and
a build is available (password check has no schema dependency, so it can
actually be tested independently of that migration).

### Rider (OTP) — backend done; Android NOT done, bigger gap than expected

Backend:

- New endpoint `backend/api/v1/rider/payout-bank-details-request-otp.php`:
  - Auth: rider token. Takes no body — looks up the authenticated
    rider's own `riders.email`, applies the exact same
    `otp_request_cooldown_seconds` / `otp_length` / `otp_expiry_minutes`
    settings and `email_otps` insert pattern as
    `rider-request-otp.php` (the login flow), and sends via the same
    `EmailOtpService`, logged under a new purpose string
    `rider_payout_confirm` (purpose is just a free-text log field, not a
    constrained enum, so this didn't need a schema change).
  - Returns `debug_otp` when `debug_otp_enabled` is on, same convention
    as the login OTP endpoints.
  - Known, accepted tradeoff: `email_otps` has no "purpose" column on
    the row itself (only in the `email_otp_logs` table) — so a rider
    who is mid-login-OTP and mid-payout-confirm at the same moment would
    share one cooldown window / one latest-row. Reusing the existing
    table as-is rather than adding a purpose column for this narrow
    case.
- `backend/api/v1/rider/payout-bank-details-save.php`:
  - Now requires `otp` in the request body.
  - Looks up the rider's own email (never trusts anything in the
    request body for *whose* OTP this is), then checks it against
    `email_otps` with the same expiry/max-attempts/is_used rules
    `rider-verify-otp.php` uses. Marks the row `is_used = 1` on success.
  - Wrong/expired/exhausted OTP → `otp_not_found` / `otp_expired` /
    `otp_max_attempts_exceeded` / `invalid_otp` (401, with
    `attempts_remaining`), nothing written to
    `rider_payout_bank_details` in any of those cases.
  - On success, also now writes a `payout_bank_details_saved` audit log
    row (this endpoint had no audit logging at all before).

Android — **not done, and the scope turned out to be bigger than the
prior handover assumed.** Investigating `RequestPayoutActivity.kt` (the
only rider-app file that touches
`getRiderBankDetails()`/`saveRiderBankDetails()`) found:

- It only ever **reads** bank details (`api.getRiderBankDetails()`) to
  pre-fill the payout-request form.
- It **never calls `api.saveRiderBankDetails()`** — that Retrofit method
  and its `SaveRiderBankDetailsBody` model exist in the network layer,
  but nothing in the rider app's UI layer invokes them anywhere.
- There is no `BankDetailsActivity`-equivalent screen on the rider side
  at all (searched the whole `rider/app/src/main/java` tree for
  `SaveRiderBankDetailsBody` usage — only the two network-layer
  declarations, no callers).

So "add an OTP step to the rider's existing bank-details save screen"
isn't actually possible yet — **that screen doesn't exist.** Whoever
picks this up needs to:

1. Build a rider bank-details save screen (or add save capability to
   `RequestPayoutActivity` / wherever the app owner wants it — needs a
   decision, this wasn't specified anywhere in the docs I could find).
2. Wire it to call the new `payout-bank-details-request-otp.php` first
   (e.g. on tapping "Save" — this endpoint is intentionally
   parameterless besides auth, so it just needs to be called once
   right before showing an OTP-entry step).
3. Show an OTP-entry UI (dialog or inline field) and call
   `payout-bank-details-save.php` with the entered `otp` alongside the
   existing bank/UPI fields (`SaveRiderBankDetailsBody` needs an `otp`
   field added — not yet added, since the shape of the screen that will
   consume it isn't decided).
4. Handle the resend case — same 30s-cooldown pattern as the customer
   OTP screen would fit here (`otp_request_cooldown_seconds` is a
   backend setting, currently defaults to 60s per
   `rider-request-otp.php`'s own default — worth confirming with the
   app owner whether that's the desired cooldown for this flow too, or
   30s to match the customer ask).

## Suggested order for whoever continues

1. Run migration 79, smoke-test the restaurant address-review flow
   (admin approve/reject → Android notice) and the restaurant
   password-confirm bank save — both are code-complete now.
2. Decide where the rider's bank-details save screen should live (new
   screen vs. folded into `RequestPayoutActivity`) — needs an app-owner
   call, not just an engineering one.
3. Build that screen + wire the two-step OTP confirm using the backend
   endpoints already built above.
