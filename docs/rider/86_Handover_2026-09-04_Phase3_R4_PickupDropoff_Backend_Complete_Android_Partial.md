# Rider App — Phase 3 (R4): Pickup/Drop-off Flow — Backend Complete, Android Partial
Session date: 04 Sep 2026

Implements Rider_Deep_Plan.md sections 9-16, on top of R3 (doc 85).

**This session ended mid-flight.** Backend is complete and self-consistent.
Android is partially wired — models/API/strings/layout are in, but
`RiderDashboardActivity.kt`'s button handlers and OTP dialog logic are
NOT yet written. See "Not built this session" below for the exact cutoff.

## ✅ Done this session

**Backend (complete)**
- `backend/api/v1/rider/orders-pickup.php` (new, POST `?id=`) — pickup
  confirmation. Follows deep-plan §11's V1 recommendation exactly: one
  API call advances `rider_assigned` straight to `out_for_delivery`
  (no lingering `picked_up` resting state on the wire), avoiding an
  unnecessary second tap. Still writes `picked_up_at` and BOTH status-
  history rows (`picked_up` then `out_for_delivery`) so the audit trail
  reflects both logical transitions even though the rider only tapped
  once. Race-safe via the same conditional-UPDATE convention as
  `orders-accept.php` (WHERE encodes `rider_assigned` + rider ownership).
- `backend/api/v1/rider/orders-deliver.php` (new, POST `?id=`, body
  `{otp}`) — delivery OTP verification + final transition to
  `delivered`. Reuses the existing shared `otp_max_attempts` app_setting
  (same one `rider-verify-otp.php`/`customer-verify-otp.php`/
  `restaurant-verify-otp.php` already use) rather than inventing a
  parallel delivery-specific knob. Attempts tracked on `orders.otp_attempts`
  (already in schema, migration 01 — no new column needed). Orders with
  no `delivery_otp` on file (e.g. COD placed while `otp_required_for_cod`
  is off) deliver immediately with no OTP check, mirroring the same
  `delivery_otp !== null` convention `orders/track.php` already
  established (bugs.md #1.2) as the one source of truth for "does this
  order need one".
  - **COD ledger wiring**: `lib/ledger.php`'s `record_cod_order_ledger_entry()`
    and `lib/rider_ledger.php`'s `record_rider_cod_collected()` were both
    written in earlier sessions and explicitly flagged in their own kdocs
    as "not called anywhere yet — call this once a real `delivered`
    transition exists". This endpoint is that call site. Both fire inside
    the same transaction as the status flip, COD orders only.
    `payment_status` also flips to `'paid'` for COD here (cash has now
    physically changed hands).
  - Rider earning entries are deliberately NOT written — no
    `rider_earnings` table or payout-rate model exists yet
    (`rider_ledger.php`'s own kdoc: rate model undecided as of
    2026-08-27), same explicit out-of-scope line the R3 handover (doc 85)
    already drew for sections 17-21.
- No new migration needed — every column/table required
  (`picked_up_at`, `delivered_at`, `delivery_otp`, `otp_attempts`,
  `rider_cod_ledger`) already existed from prior sessions.

**Android (partial — see cutoff below)**
- `ApiService.kt` — `pickupOrder()`, `deliverOrder()` added.
- `Models.kt` — `PickupOrderResult`, `DeliverOrderBody`, `DeliverOrderResult` added.
- `ErrorParsing.kt` — `ParsedApiError` extended with `attemptsRemaining`
  (parses `data.attempts_remaining` from `orders-deliver.php`'s
  `invalid_otp` error body).
- `strings.xml` — all new UI strings added (`btn_mark_picked_up`,
  `btn_mark_delivered`, delivery-OTP dialog strings, error strings).
- `activity_rider_dashboard.xml` — `currentOrderCard` now has
  `btnMarkPickedUp` and `btnMarkDelivered` buttons (both `gone` by
  default; toggling them is Android's job, not yet written — see below).
- `dialog_delivery_otp.xml` (new) — single-field OTP entry dialog layout
  (deliberately NOT the boxed 6-digit grid `activity_otp_verify.xml`
  uses — that's sized for the rider's own SMS-autofillable email OTP;
  this is the rider manually typing a code the customer reads aloud,
  usually 4 digits per the `otp_length` setting default).
- `RiderDashboardActivity.kt` — new imports added (`AlertDialog`,
  `DialogDeliveryOtpBinding`, `DeliverOrderBody`) and a new
  `activeOrder: CurrentOrder?` field added to hold the currently-shown
  active order (needed by the button handlers for order id +
  `deliveryOtpRequired`).

## 🔴 Not built this session — exact cutoff

`RiderDashboardActivity.kt` is NOT finished. Specifically still missing:

1. **`renderCurrentOrder()` doesn't set `activeOrder` yet** and doesn't
   toggle `btnMarkPickedUp`/`btnMarkDelivered` visibility based on
   `order.status` (`rider_assigned` → show pickup button;
   `out_for_delivery` → show deliver button; anything else → both gone).
2. **No click listeners wired** for either button (the `onCreate()`
   listener-setup block only has `btnAcceptOffer`/`btnRejectOffer` so far).
3. **No `markPickedUp()` function** — should call `api.pickupOrder(id)`,
   handle success (toast + `pollDashboardState()` to pick up the new
   `out_for_delivery` state) and `invalid_state` (409 — order moved on
   some other way, just re-poll and re-render rather than erroring loudly).
4. **No delivery-OTP dialog function** — should inflate
   `DialogDeliveryOtpBinding`, build an `AlertDialog` (Confirm/Cancel),
   call `api.deliverOrder(id, DeliverOrderBody(otp))` on Confirm, and
   handle three distinct outcomes distinctly:
   - success → dismiss, toast `delivery_confirmed`, `pollDashboardState()`
   - `invalid_otp` (401) → keep dialog open, show
     `error_delivery_otp_invalid_format` with `parsed.attemptsRemaining`
     in the dialog's `deliveryOtpError` TextView, let the rider retry
   - `otp_max_attempts_exceeded` (400) → dismiss dialog, show
     `error_delivery_otp_locked`, leave the card as-is (order stays
     `out_for_delivery` server-side — nothing to re-render)
   - `invalid_state` (409) or network error → dismiss, generic error,
     `pollDashboardState()` to resync
5. If `activeOrder?.deliveryOtpRequired == false`, `btnMarkDelivered`'s
   click should skip the dialog entirely and call
   `api.deliverOrder(id, DeliverOrderBody(""))` directly — no code sent
   because none is needed (see `orders-deliver.php`'s own kdoc).

None of backend was touched to accommodate this gap — the two endpoints
are already correct and complete as written; this is purely "Android
hasn't called them yet."

## Not tested against a live backend / device

Same caveat as every prior session — no Android SDK/Gradle/emulator or
PHP interpreter in this sandbox. The two new PHP files were manually
brace/paren-balance checked (no interpreter available); no Kotlin
compile check was possible either since the activity file is mid-edit.

## 🔴 Still open — next steps, in order

1. **Finish `RiderDashboardActivity.kt`** per the 5 points above — this
   is a continuation of an in-progress edit, not a fresh feature; the
   imports/field/layout/strings/API surface it needs already exist.
2. **Run both new endpoints against a live DB** — nothing in this slice
   has touched a real database or device yet.
3. **Smoke test the full loop**: accept an offer (R3) → tap Mark Picked
   Up → confirm `orders.status` flips to `out_for_delivery` and both
   status-history rows appear → tap Mark Delivered → enter the OTP
   `orders/track.php` shows the customer → confirm `delivered`,
   `delivered_at`, and (for a COD order) a `rider_cod_ledger` row +
   `riders.cod_cash_held` increase + a `restaurant_due_ledger`
   `commission_cod` row all appear together.
4. **Test the OTP wrong/lockout path** — wrong code decrements
   attempts correctly, locks after `otp_max_attempts`, and a locked
   order is left exactly at `out_for_delivery` for admin/support to
   resolve (no automatic unlock path exists, matching deep-plan §16:
   "never change order status" on a bad OTP).
5. **Next real feature slice after this one lands**: live location
   tracking during delivery (deep-plan §12-15) — `rider_locations` is
   still untouched (only the online-toggle's `last_lat/lng` gets
   written), and customer-facing live tracking has nothing real to
   show yet beyond the static OTP `orders/track.php` already returns.
