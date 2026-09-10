# Handover — 2026-09-09 — Restaurant Address Admin-Review + (planned) Bank/OTP Confirm + Customer OTP Resend Timer

## App owner's ask (verbatim, Hinglish)

1. Restaurant address change karne par wapis admin review mai jaye, jab tak
   admin approve na kare purana address hi dikhe, saath mein "Update
   pending review" badge.
2. Restaurant app mein bank/UPI details fields sahi karo (rider app jaisa),
   aur save karte time confirmation mango save se pehle — **restaurant mein
   login password re-enter**, **rider mein OTP** (rider ka login hi OTP-based
   hai, restaurant ka email+password based hai — dono apne apne login-style
   se confirm karenge).
3. Customer app mein OTP resend "in X seconds" cooldown timer dalo — **30
   seconds** confirmed.

All three confirmed with the app owner via the options prompt earlier in
this session. This doc covers what's DONE vs NOT DONE as of this handover.

---

## ✅ DONE — Restaurant Address → Admin Review (backend + admin panel + Android model/layout)

### Schema (migration 79)
`backend/sql/79_migration_restaurant_address_review.sql` — adds to
`restaurants`:
- `pending_address` TEXT NULL
- `pending_latitude` DECIMAL(10,8) NULL
- `pending_longitude` DECIMAL(11,8) NULL
- `address_review_status` ENUM('none','pending','rejected') DEFAULT 'none'
- `address_review_remarks` VARCHAR(255) NULL
- `address_reviewed_by_admin_id` BIGINT UNSIGNED NULL (FK → admins.id)
- `address_reviewed_at` TIMESTAMP NULL

Same "self-submitted change starts pending" shape as migration 59's
`restaurant_bank_details` verification workflow. **Not yet run against
any live/dev database** — run this migration before testing.

### Backend endpoint: `backend/api/v1/restaurant/profile-update.php`
- `address` / `latitude` / `longitude` in the request body no longer
  write to the live columns. They're validated exactly as before, then
  written into `pending_address` / `pending_latitude` / `pending_longitude`
  with `address_review_status = 'pending'` (and any prior
  `address_review_remarks` / reviewer fields cleared — a re-submission
  replaces the old pending draft, doesn't queue a second one).
- Live `restaurants.address` / `latitude` / `longitude` are **only** ever
  changed by the new admin-panel approve action (below) — never by this
  endpoint anymore.
- Writes an `address_change_submitted` audit log row (new
  `require_once .../lib/audit.php` added to this file).
- Response is unchanged shape (`{ "restaurant": {...} }`, full row) — the
  new pending_*/address_review_* columns just ride along in the same
  `SELECT *`, nothing else to wire server-side.

### Admin panel: `backend/admin/restaurants.php`
- New POST action `review_address` (gated by `restaurants_approve`
  permission, same as approve/reject/suspend):
  - `address_action=approve` → copies `pending_address`/`pending_latitude`/
    `pending_longitude` into the live columns, clears all pending_*/
    review_* fields, stamps `address_reviewed_by_admin_id`/`_at`.
  - `address_action=reject` (reason required) → leaves the live address
    untouched, sets `address_review_status='rejected'` +
    `address_review_remarks` so the restaurant sees why.
  - Both write an audit log row (`restaurant_address_approved` /
    `restaurant_address_rejected`).
- Table row: small "Address update pending review" badge shown next to
  the status badge when `address_review_status === 'pending'`.
- Manage modal: new section showing current vs requested address (+ pin
  coords if changed) with Approve/Reject buttons when pending; a
  "Last address change was rejected: <reason>" note when rejected.
- Both the main list query and the "Unassigned" filter's query
  (`admin_unassigned_restaurants_with_detected_area()`) were updated to
  SELECT the new columns, and the inline row-lookup used by the POST
  handler too — all three needed the extra columns for this to work.

### Restaurant Android app (partial — model + layout only)
- `restaurant/app/.../network/Models.kt` → `RestaurantProfileDetail` gained
  `pendingAddress`, `addressReviewStatus`, `addressReviewRemarks`.
- `activity_edit_profile.xml` → new `addressReviewNotice` TextView right
  under the address field (`gone` by default) + new drawable
  `bg_status_notice.xml` (uses existing `status_pending_bg` color). Added
  `xmlns:tools` to the layout root (wasn't declared before) since the
  notice uses `tools:text`/`tools:visibility` for the preview.

## ❌ NOT DONE YET — Restaurant Android wiring
`EditProfileActivity.kt`'s `populate()` does **not yet** set
`addressReviewNotice`'s text/visibility from the new model fields. This is
the one remaining piece for the address-review feature to be visibly
complete end-to-end on the restaurant app. Needed logic (straightforward):

```kotlin
// in populate(profile: RestaurantProfileDetail), after existing address/location code
when (profile.addressReviewStatus) {
    "pending" -> {
        binding.addressReviewNotice.visibility = View.VISIBLE
        binding.addressReviewNotice.text = getString(R.string.address_update_pending_review)
        // consider also disabling/graying inputAddress + the location rows
        // here so the owner doesn't stack a second pending edit on top of
        // an unreviewed one — not decided/built yet, flagging as an open
        // question for whoever picks this up.
    }
    "rejected" -> {
        binding.addressReviewNotice.visibility = View.VISIBLE
        binding.addressReviewNotice.text = getString(
            R.string.address_update_rejected_reason,
            profile.addressReviewRemarks ?: "—"
        )
    }
    else -> binding.addressReviewNotice.visibility = View.GONE
}
```
Also needs two new strings in `restaurant/app/src/main/res/values/strings.xml`
(`address_update_pending_review`, `address_update_rejected_reason` with a
`%1$s` placeholder) — not yet added.

---

## ❌ NOT DONE — Bank/UPI save confirmation (restaurant: password, rider: OTP)

Investigated but not built. Findings, for whoever continues:

- Restaurant's bank fields (`bank_name`, `account_number`, `ifsc_code`,
  `upi_id` in `restaurant_bank_details`, via
  `backend/api/v1/restaurant/bank-details-save.php` +
  `backend/lib/restaurant_bank.php`) are **already structurally correct**
  and already close to what rider has — no field-shape fix was actually
  needed there, contrary to the initial ask's premise. Confirmed by
  reading both `bank-details-save.php` (restaurant) and
  `payout-bank-details-save.php` (rider) side by side.
- Neither restaurant's nor rider's bank-save endpoint has ANY re-auth
  step today. This is a new feature for both, not a copy-from-rider fix.
- Restaurant login is email+password (`restaurant-login.php`) — the plan
  is to require the restaurant's current password in the
  `bank-details-save.php` request body and `password_verify()` it against
  `restaurants.password_hash` before writing anything, same pattern
  `restaurant-login.php` already uses. `manage_bank_details` is
  owner-only per `backend/lib/permissions.php` (confirmed), so checking
  against the restaurant row's own password_hash — not a staff member's —
  is correct and doesn't need extra staff-password plumbing.
- Rider login is OTP-based (`rider-request-otp.php` /
  `rider-verify-otp.php`) — the plan is to add a request-OTP step before
  `payout-bank-details-save.php` (new endpoint, e.g.
  `payout-bank-details-request-otp.php`, reusing whatever OTP-storage
  helper the login flow already uses) and require that OTP in the actual
  save call.
- Nothing written yet for either — no new endpoint code, no Android UI
  (no "re-enter password" dialog on `BankDetailsActivity.kt`, no OTP-entry
  step on the rider side).

## ❌ NOT DONE — Customer OTP resend cooldown (30s)

Investigated, not built. Findings:

- `customer/app/.../ui/login/LoginActivity.kt` already has a
  `btnResendOtp` button wired to `onSendOtp(isResend = true)` — but it's
  currently tappable with **no cooldown at all** (can spam-resend).
- No existing `CountDownTimer` pattern anywhere in the customer app to
  reuse — would be a fresh implementation.
- Plan: on entering the OTP step (both first send and every resend),
  start a 30-second `CountDownTimer`; disable `btnResendOtp` and show
  "Resend in Ns" (new string, `%1$d` placeholder) during the countdown;
  re-enable with the original "Resend code" label
  (`R.string.btn_resend_otp`) when it hits 0. Needs a `CountDownTimer`
  field on the Activity, started in `onSendOtp()`'s success branch, and
  cancelled in `onDestroy()` to avoid a leak/late callback after the
  Activity is gone.
- Nothing written yet — no new field, no timer start call, no string
  resources added.

---

## Suggested order for whoever continues

1. Finish `EditProfileActivity.kt` wiring (small, self-contained, backend
   already done) → closes out item 1 completely.
2. Customer OTP timer (self-contained, one file + one layout string) →
   closes out item 3 completely.
3. Bank/UPI confirmation — biggest remaining piece, touches 2 backend
   endpoints (or +2 new ones) and 2 Android screens (restaurant + rider).
   Restaurant (password) is simpler than rider (OTP, needs a request-OTP
   round trip) — do restaurant first.

## Migration reminder

`backend/sql/79_migration_restaurant_address_review.sql` has **not been
run** against any database yet — run it before testing any of the address-
review backend/admin-panel code above.
