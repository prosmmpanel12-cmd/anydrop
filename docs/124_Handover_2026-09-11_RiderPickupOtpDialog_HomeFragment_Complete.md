# Handover — 2026-09-11 (Part 3): Rider App Pickup OTP Dialog — HomeFragment done

Direct continuation of
`123_Handover_2026-09-11_RiderPickupOtpDialog_DialogRestyle_Part2.md`
— read that doc first (and `122_...` before it for the backend
picture). This doc closes out item 1 from doc 123's "NOT done yet"
list. Items 2 and 3 (Restaurant app pickup OTP display + Resend,
Customer app delivery OTP Resend) are still untouched — see below.

## What's DONE this part

### Rider app — `HomeFragment.kt`
Brought fully in line with `RiderDashboardActivity.kt`'s copy from
Part 2, confirming doc 123's suspicion was correct: this fragment had
its own independent, still-old-style copy of everything.

- `btnMarkPickedUp`'s click listener now opens `showPickupOtpDialog()`
  instead of calling the old no-OTP `markPickedUp(order)` directly.
- `markPickedUp()` rewritten to take `(order, otp, onInvalidOtp,
  onDone)` and call `api.pickupOrder(order.id, PickupOrderBody(otp))`
  — same `invalid_otp` / `otp_max_attempts_exceeded` / `invalid_state`
  handling as the Activity and as `deliverOrder()` below it, same
  onInvalidOtp-keeps-dialog-open / onDone-dismisses-dialog split.
- New `showPickupOtpDialog()` added — inflates the existing
  `DialogPickupOtpBinding` (no new XML needed, per doc 123's
  prediction), wires `btnPickupOtpCancel`/`btnPickupOtpConfirm`
  directly (no `.setPositiveButton()`/`BUTTON_POSITIVE` involved at
  all, since those views are real buttons in the restyled layout).
- `showDeliveryOtpDialog()` restyled the same way: old
  `AlertDialog.Builder().setTitle().setPositiveButton().setNegativeButton()`
  + `dialog.getButton(AlertDialog.BUTTON_POSITIVE)` plumbing replaced
  with `MaterialAlertDialogBuilder(requireContext()).setView(...).create()`
  + `dialogBinding.btnDeliveryOtpCancel`/`btnDeliveryOtpConfirm` click
  listeners. `deliverOrder()` itself untouched — same as Part 2's
  Activity-side change, only the dialog wiring moved.
- Removed the now-unused `androidx.appcompat.app.AlertDialog` import.
  Added `DialogPickupOtpBinding` and `PickupOrderBody` imports.
  `MaterialAlertDialogBuilder` referenced fully-qualified inline
  (`com.google.android.material.dialog.MaterialAlertDialogBuilder`),
  matching the Activity's own style exactly rather than adding a new
  import line.
- Confirmed via grep across the whole rider module that
  `HomeFragment.kt` and `RiderDashboardActivity.kt` were the only two
  files referencing `showDeliveryOtpDialog`/`markPickedUp`/
  `api.pickupOrder`/`BUTTON_POSITIVE` — no other Activity/Fragment has
  a third copy of this flow hiding somewhere.
- Confirmed `dialog_pickup_otp.xml` and `dialog_delivery_otp.xml`
  already carry the exact view IDs this code binds to
  (`inputPickupOtp`/`pickupOtpError`/`btnPickupOtpCancel`/
  `btnPickupOtpConfirm` and `inputDeliveryOtp`/`deliveryOtpError`/
  `btnDeliveryOtpCancel`/`btnDeliveryOtpConfirm`) and that every
  string resource referenced (`pickup_confirmed`,
  `error_pickup_otp_locked`, `error_pickup_otp_empty`,
  `error_pickup_otp_invalid_format`, `error_pickup_otp_invalid`, etc.)
  already exists in `strings.xml` from Part 2 — no new resources
  needed for this file.
- Brace/paren balance-checked with a quick script (145/145 curly,
  363/363 paren) — no compiler available in this sandbox, same
  standing limitation as Part 2.

Doc 123's specific worry — "if `HomeFragment.kt` relied on
`dialog.getButton(AlertDialog.BUTTON_POSITIVE)` anywhere it will now
break" — turned out to be exactly what the old code did (via
`dialog.setOnShowListener { ... dialog.getButton(...) ... }`), and is
now removed entirely along with the rest of the AlertDialog-builder
pattern, so there's nothing left that could break on that front.

## What's NOT done yet — pick up here next session

Unchanged from doc 123, items 2 and 3:

1. **Restaurant app — show Pickup OTP + Resend button.** Nothing done.
   API already returns `pickup_otp` / `pickup_otp_verified` from
   `orders-detail.php`/`orders-list.php` (see doc 122). Locate the
   restaurant app's active-order screen, display the code, wire a
   "Resend" button to `restaurant/pickup-otp-resend.php`.

2. **Customer app — Delivery OTP Resend button.** Nothing done.
   Locate wherever the customer app shows the existing delivery OTP
   (reads it from `orders/track.php`), add a "Resend" button calling
   `customer/delivery-otp-resend.php`.

## NOT verified — same standing sandbox limitation

No Gradle/Android SDK/PHP CLI/live DB in this sandbox (repeated from
docs 122/123 — nothing has changed on that front). Specifically for
this part's change, still needs:

1. Full Gradle build of the rider module — confirm
   `DialogPickupOtpBinding`/`DialogDeliveryOtpBinding` view-binding
   classes resolve correctly from `HomeFragment.kt`'s new imports, and
   that nothing else in the module referenced the old
   `AlertDialog`-based `showDeliveryOtpDialog`/`markPickedUp`
   signatures in this file (e.g. via reflection or a test) that this
   session's greps wouldn't catch.
2. Tap through the pickup flow specifically via the bottom-nav Home
   tab (`HomeFragment`), not just `RiderDashboardActivity` — confirm
   the dialog shows/dismisses correctly and the fragment's
   `_binding`-nullable pattern doesn't cause a crash if the fragment's
   view is destroyed while `markPickedUp`'s coroutine is still in
   flight (this file already null-safes every `_binding?.` access
   elsewhere; the new code doesn't touch `_binding` inside the
   dialog/coroutine at all, only `dialogBinding` and `activity`, which
   should be safe, but wasn't exercised on a device).
