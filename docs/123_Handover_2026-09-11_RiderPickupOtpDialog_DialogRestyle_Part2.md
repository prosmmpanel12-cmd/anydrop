# Handover — 2026-09-11 (Part 2): Rider App Pickup OTP Dialog + Dialog Restyle (Dashboard done, HomeFragment + Restaurant/Customer apps pending)

Direct continuation of
`122_Handover_2026-09-11_AdminBootstrapFix_RestaurantBankUpiSplit_PickupOTP_Backend_Built_AndroidPending.md`
— read that doc first, it has the full backend picture (migration 83,
new resend endpoints, HTML email template). This doc covers only what
changed on the Android side since then.

## What's DONE this part

### Rider app — `RiderDashboardActivity.kt`
- `btnMarkPickedUp`'s click listener now opens `showPickupOtpDialog()`
  instead of calling the old no-OTP `markPickedUp()` directly.
- `markPickedUp()` rewritten to take `(order, otp, onInvalidOtp, onDone)`
  and call `api.pickupOrder(order.id, PickupOrderBody(otp))` — mirrors
  `deliverOrder()`'s exact shape (same `invalid_otp` /
  `otp_max_attempts_exceeded` / `invalid_state` handling, same
  onInvalidOtp-keeps-dialog-open / onDone-dismisses-dialog split).
- New `showPickupOtpDialog()` added, modeled 1:1 on
  `showDeliveryOtpDialog()`.
- **UI polish pass** (app-owner ask: "poora dialog ki overall UI improve
  karo just like restaurant app") — `showDeliveryOtpDialog()` also
  rewritten in the same pass: both dialogs now use
  `MaterialAlertDialogBuilder(this).setView(...).create()` with **no**
  `.setTitle()`/`.setPositiveButton()`/`.setNegativeButton()` calls —
  title and Cancel/Confirm buttons now live inside the layout XML
  itself as real `MaterialButton`s, matching
  `restaurant/dialog_logout_confirm.xml`'s shape exactly (see
  `AccountFragment.kt`'s `showBankOtpDialog`-equivalent wiring pattern,
  which this copies).
- Removed the now-unused `androidx.appcompat.app.AlertDialog` import.
  Added `DialogPickupOtpBinding` and `PickupOrderBody` imports.
- Brace/paren balance-checked (no compiler available in this sandbox —
  see "NOT verified" below).

### Rider app — `RequestPayoutActivity.kt`
- `showBankOtpDialog()` restyled the same way: old
  `AlertDialog.Builder().setTitle().setPositiveButton().setNegativeButton()`
  replaced with `MaterialAlertDialogBuilder().setView().create()` +
  `dialogBinding.btnBankOtpCancel` / `btnBankOtpConfirm` click listeners.
  All existing logic (OTP validation, resend cooldown timer, error
  states) kept exactly as it was — only the button/title wiring changed.
- Unused `AlertDialog` import removed.

### Layouts — all three rider OTP dialogs now share one visual style
- `dialog_delivery_otp.xml` — restyled: circular icon badge (`ic_lock`
  tinted `anydrop_green` on `bg_icon_circle`) above a centered bold
  title + centered gray subtitle, OTP field below, then a full-width
  Cancel/Confirm `MaterialButton` row (`btnDeliveryOtpCancel` /
  `btnDeliveryOtpConfirm`). No app-theme changes needed — reused
  `bg_icon_circle` and `ic_lock`, both already present in the rider
  app's drawables, per the previous handover's own suggestion to reuse
  existing assets rather than commission new illustration art.
- `dialog_pickup_otp.xml` — **new file**, generated from the restyled
  delivery dialog via a straight ID/string substitution (same
  structure, `inputPickupOtp` / `pickupOtpError` /
  `btnPickupOtpCancel` / `btnPickupOtpConfirm` / new
  `pickup_otp_dialog_title` / `pickup_otp_dialog_subtitle` /
  `hint_pickup_otp` strings).
- `dialog_bank_details_otp.xml` — same treatment, kept its existing
  `bankOtpResend` "Resend code" row (the one thing that differs from
  the other two — this OTP is requested via a separate endpoint), added
  `btnBankOtpCancel` / `btnBankOtpConfirm`.

### New strings (rider `strings.xml`)
`btn_mark_picked_up_confirm`, `pickup_otp_dialog_title`,
`pickup_otp_dialog_subtitle`, `hint_pickup_otp`,
`error_pickup_otp_invalid_format`, `error_pickup_otp_invalid`,
`error_pickup_otp_empty`, `error_pickup_otp_locked` — all direct
mirrors of the existing `delivery_otp`/`bank_otp` equivalents.

### `ApiService.kt` / `Models.kt`
- `pickupOrder()` signature changed from `(orderId: Int)` to
  `(orderId: Int, body: PickupOrderBody)` — **this is a breaking change**
  to the interface; every call site had to be updated (only one existed,
  in `RiderDashboardActivity.kt`, already done above).
- New `PickupOrderBody(val otp: String)` data class added next to the
  existing `PickupOrderResult`.

## What's NOT done yet — pick up here next session

In priority order:

1. **`HomeFragment.kt`** — has its own independent copy of the
   delivery-OTP dialog flow (`showDeliveryOtpDialog` at line ~580,
   confirmed via grep in the previous session) and very likely its own
   pickup button/handler too, separate from
   `RiderDashboardActivity.kt`'s copy. **Not touched this part at all.**
   Needs the exact same treatment: wire pickup to a new
   `showPickupOtpDialog` calling `api.pickupOrder(id, PickupOrderBody(otp))`,
   and restyle its own `showDeliveryOtpDialog` to match the dialogs
   above. Since `dialog_pickup_otp.xml` and the restyled
   `dialog_delivery_otp.xml` already exist as shared layout resources,
   this should mostly be Kotlin wiring, not new XML — but confirm
   `HomeFragment.kt` doesn't reference the *old* dialog IDs
   (`inputDeliveryOtp`/`deliveryOtpError` are unchanged, but the removed
   default AlertDialog title/buttons mean if `HomeFragment.kt` relied on
   `dialog.getButton(AlertDialog.BUTTON_POSITIVE)` anywhere it will now
   break — check this specifically before assuming it "just works").

2. **Restaurant app — show Pickup OTP + Resend button.** Still nothing
   done here. API already returns `pickup_otp` / `pickup_otp_verified`
   from `orders-detail.php`/`orders-list.php` (see doc 122). Locate the
   restaurant app's active-order screen, display the code, wire a
   "Resend" button to `restaurant/pickup-otp-resend.php`.

3. **Customer app — Delivery OTP Resend button.** Still nothing done.
   Locate wherever the customer app shows the existing delivery OTP
   (reads it from `orders/track.php`), add a "Resend" button calling
   `customer/delivery-otp-resend.php`.

## NOT verified — same standing sandbox limitation

No Gradle/Android SDK/PHP CLI/live DB in this sandbox (repeated from
doc 122 — nothing has changed on that front). In addition to doc 122's
own verification checklist, add:

1. Full Gradle build of the rider module — confirm
   `DialogPickupOtpBinding` view-binding class actually generates
   correctly from the new layout, and that removing the `AlertDialog`
   import didn't leave any other reference to it uncaught by this
   session's greps.
2. Manually exercise the restyled bank-OTP dialog's resend-cooldown
   timer end-to-end — the countdown text update logic
   (`startBankOtpResendCooldown`) was untouched, but its button's
   `isEnabled` toggling now interacts with a real `MaterialButton`
   instead of whatever button type it was rendering as through the old
   layout (should be identical, `bankOtpResend` was already a plain
   `TextView`, unchanged — but confirm visually).
3. Tap through the full pickup flow on a device: rider accepts an
   order, restaurant shows the pickup code (once restaurant-side UI
   exists — item 2 above), rider enters it correctly and incorrectly,
   confirm attempt-counting and the final
   `rider_assigned → picked_up → out_for_delivery` transition all still
   work exactly as before this session (the transition logic itself in
   `orders-pickup.php` is unchanged from doc 122 — only the OTP gate is
   new).
