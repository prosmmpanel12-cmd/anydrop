# Handover — 2026-09-09 (session 3) — Rider Bank/UPI OTP-Confirmed Save

Continuation of session 2's handover ("Bank/UPI save confirmation —
restaurant done, rider backend done, rider Android not done, bigger gap
found than expected"). This session closes that gap.

---

## ✅ DONE — Rider bank/UPI details can now be saved on their own, OTP-confirmed

Session 2 found `RequestPayoutActivity.kt` never actually called
`saveRiderBankDetails()` — the only rider screen touching bank fields
submits them straight into a payout request (`payout.php`'s POST), and
there was no standalone "just save my details" path or OTP step
anywhere. Rather than build a brand-new screen (option 1 from session
2's suggested order), this session went with option 2 from that same
list: folded the save capability into `RequestPayoutActivity` itself,
since it already owns the only bank/UPI form in the app and the backend
(`payout-bank-details-get/request-otp/save.php`) was already built and
waiting.

**What changed:**

- `rider/app/.../network/Models.kt` — `SaveRiderBankDetailsBody` gained
  a required `otp: String` field. New `RequestPayoutBankDetailsOtpResult`
  data class (`message` + optional `debug_otp`, matching
  `payout-bank-details-request-otp.php`'s response shape).
- `rider/app/.../network/ApiService.kt` — new
  `requestPayoutBankDetailsOtp()` (`POST rider/payout-bank-details-request-otp.php`,
  no body) sitting right before the existing `saveRiderBankDetails()`
  declaration, which now correctly requires an OTP per the backend kdoc.
- `rider/app/src/main/res/layout/activity_request_payout.xml` — new
  "Save Bank Details" outlined button + its own progress spinner,
  inserted between the bank/UPI fields and the existing "Request
  Payout" button (so the two actions are visually distinct: one saves
  details for later payouts, the other submits an actual payout now).
- New `rider/app/src/main/res/layout/dialog_bank_details_otp.xml` —
  single-field OTP entry dialog (mirrors `dialog_delivery_otp.xml`'s
  plain-field style, not the 6-box login grid) plus a "Resend code"
  TextView row that `dialog_delivery_otp.xml` doesn't have, since this
  OTP (unlike the delivery OTP) is requested via its own endpoint the
  rider may need to retrigger.
- `rider/app/.../ui/earnings/RequestPayoutActivity.kt`:
  - Field validation refactored out of `onSubmit()` into a shared
    `validateBankFields()` (holder name + method-specific fields only —
    amount stays local to `onSubmit()`), returning a private
    `BankFields` data holder both `onSubmit()` and the new
    `onSaveBankDetails()` use, so the two entry points never validate
    differently.
  - `onSaveBankDetails()`: validates the form, calls
    `requestPayoutBankDetailsOtp()`, opens the OTP dialog on success
    (surfaces `otp_request_cooldown` distinctly from a generic send
    failure).
  - `showBankOtpDialog()`: same `AlertDialog.Builder` +
    `setOnShowListener` override pattern doc 87 fixed
    `RiderDashboardActivity.showDeliveryOtpDialog()` to use — positive
    button's default dismiss is overridden so an `invalid_otp` response
    keeps the dialog open with `attempts_remaining` shown inline,
    instead of closing it. `otp_expired`/`otp_not_found`/
    `otp_max_attempts_exceeded` dismiss with a generic failure toast
    (retrying those requires a fresh OTP, not another attempt at the
    same code).
  - Resend button inside the dialog re-fires
    `requestPayoutBankDetailsOtp()` and restarts a 30s cooldown
    (`CountDownTimer`, same 30s window/pattern session 2 built for the
    customer app's login-OTP resend) — this cooldown is UX-only
    button-disable, the real enforcement is server-side
    (`otp_request_cooldown_seconds`, defaults 60s per
    `rider-request-otp.php`, applies equally here since it's the same
    `email_otps` table/settings). Timer is cancelled in both
    `onDismissListener` and the Activity's new `onDestroy()` override.
  - On successful save, dialog dismisses, success toast shows, and
    `loadSavedBankDetails()` re-runs to refresh what's pre-filled in
    the form (masked account number, same as it already did on initial
    screen load).

**New strings** (rider app `strings.xml`): `payout_save_bank_details_button`,
`payout_bank_details_saved`, `payout_bank_details_save_failed`,
`bank_otp_dialog_title`, `bank_otp_dialog_subtitle`, `hint_bank_otp`,
`btn_confirm_save`, `error_bank_otp_empty`, `error_bank_otp_invalid`,
`error_bank_otp_invalid_format`, `bank_otp_resend_countdown`,
`btn_resend_bank_otp`, `bank_otp_sent`, `bank_otp_send_failed`,
`bank_otp_cooldown_message`.

**No backend changes this session** — `payout-bank-details-get/request-otp/save.php`
were already complete from session 2 and needed no edits.

Checked, not touched: `RequestPayoutActivity`'s existing "Request
Payout" submit flow (`onSubmit()`) is unchanged in behavior — it still
submits bank/UPI fields straight into `payout.php`'s POST without an
OTP step, exactly as before. The OTP gate only applies to the new
standalone "Save Bank Details" button. This was a deliberate scope
choice (session 2's backend work only built an OTP-confirm path for the
*save* endpoint, not for `payout.php` itself) — flagging in case the
app owner actually wants OTP-confirmation on payout submission too,
which would be new backend scope, not just Android wiring.

Only brace/paren-balance and XML-well-formedness checks run (no Android
SDK/Kotlin compiler in this sandbox, same limitation as every other
session on this track). **Real Gradle build + device test still
needed** before this is trustworthy — this is the first time the OTP
dialog, the resend timer, and the two-button (Save / Request Payout)
layout all exist together.

## Suggested order for whoever continues

1. Real Gradle build of the rider module + device test: fill bank
   fields → Save Bank Details → check email for OTP (or `debug_otp` if
   `debug_otp_enabled` is on) → confirm → verify masked details persist
   on screen reload. Also test: wrong OTP (dialog stays open, attempts
   count down), resend (cooldown countdown UI + a fresh code actually
   arrives), and that dismissing the dialog mid-cooldown doesn't leak
   the CountDownTimer (onDismissListener should cancel it).
2. Decide with the app owner whether `payout.php`'s own POST (submitting
   an actual payout request) should also require OTP confirmation, or
   whether OTP-gating only the standalone bank-details save (as built)
   is the intended final scope.
3. Once confirmed working, this closes out session 2's "Bank/UPI save
   confirmation" item across all three surfaces (restaurant password,
   rider OTP save) — next unscoped work per the standing backlog:
   customer-app tracking-screen work, Rider Earnings (§19) polish, or
   Admin live map (§25) — to be confirmed with the person.
