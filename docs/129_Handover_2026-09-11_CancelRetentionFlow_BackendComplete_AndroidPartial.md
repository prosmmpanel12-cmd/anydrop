# Handover — Cancel-Order Retention Flow (§4), Backend Complete, Android Partial

Session date: 11 Sep 2026. Continues plan doc 127 / handover doc 128,
picking up the one section that doc 128 explicitly left undone: **§4 —
cancel-order retention flow.**

Doc 127 flagged this section as needing the app owner's confirmation
before coding, since "4-5 options" was a range, not a spec, and the
address-change option was called out as the single biggest unknown in
the whole plan. Confirmed this session before writing any code:

- **Scope is narrower than doc 127's original 5-option proposal.**
  Only two retention paths are in v1: **change delivery address** and
  **still cancel, now with a reason** (picker + optional free-text).
  Edit-delivery-instructions, contact-restaurant (click-to-call), and
  contact/chat-support — all proposed in doc 127 §4 — are **not** in
  scope for this build. If the app owner wants any of those later,
  doc 127 §4 still has the original analysis for edit-instructions and
  contact-restaurant to build from.
- Reason capture: **picker + optional free-text**, not free-text-only
  or picker-only — both are captured and combined server-side isn't
  needed, since Android concatenates them into a single `reason` string
  before sending (see below).

**Session stopped mid-Android-build (explicit ask: "jaha tak kaam hua
waha tak complete karo" — wrap up cleanly at whatever point work had
reached, not push to finish everything).** Backend is fully built and
should be usable as-is. Android has the network layer, both new
layouts, and all new strings in place and building cleanly, but the
Fragment class that actually drives the sheet (`CancelOrderOptionsBottomSheet.kt`)
was **not** written this session, and `OrderStatusActivity.kt`'s
`btnCancelOrder` listener still calls the old direct `cancelOrder()` —
**nothing user-facing changed yet**. See "Not done" at the bottom for
exactly what's left and roughly how to finish it.

---

## Backend — complete

### `backend/api/v1/customer/order-change-address.php` (NEW)

Lets a customer swap an already-placed order's delivery address.
Traced `orders/create.php` + `lib/orders.php`'s `price_cart()` +
`lib/delivery_pricing.php` + `lib/cod_rules.php` +
`lib/payment_restrictions.php` this session (doc 127's flagged unknown)
to find every location-dependent check a fresh order runs, then
re-ran the same three against the **new** address on an **existing**
order:

1. `get_effective_payment_restrictions()` + `is_payment_method_allowed_in_area()`
   — is the order's existing `payment_method` even usable from the new
   address's area?
2. `get_effective_cod_rule()` + `evaluate_cod_eligibility()` — COD
   orders only, checked against the **recomputed** grand_total (a new
   address can change the delivery fee enough to trip an area's
   `max_cod_order_amount` cap).
3. `calculate_delivery_fee()` — delivery_charge is recalculated for the
   new restaurant→address distance, never carried over from the
   original address.

**Deliberately not re-run:** the cart-pricing / min-order / coupon /
restaurant-offers-engine logic in `price_cart()`. The cart itself
hasn't changed, only the destination — re-running the full offers
engine against an order that already has committed
`coupon_usages`/`offer_usages` rows risks double-counting or silently
picking a different "best offer" than what the customer actually saw
at checkout. `item_total`/`discount_amount`/`offer_discount_amount`/
`tax_amount` are left untouched. A `free_delivery_discount_amount` (if
the order used a free-delivery offer) is simply re-capped at the new
`delivery_charge` — same cap `select_best_free_delivery_offer()` itself
enforces at order-creation time — rather than re-selecting a fresh
"best" offer.

**Gating (conservative on purpose, matches doc 127's flag that this
needed real scoping):**
- Only while `order.status` is `pending`/`accepted` — same set as
  `orders/cancel.php`'s own gate and the Android app's
  `CANCELLABLE_STATUSES`. No rider assigned yet.
- Only while `order.payment_status` is `pending` — a `paid` order
  (wallet, debited synchronously at creation; or UPI, already
  confirmed) has already settled a specific `grand_total`; changing
  `delivery_charge` after that has no refund/top-up flow to reconcile
  it, so it's blocked with `order_already_paid` rather than silently
  drifting. **In practice this only ever blocks wallet orders and
  already-confirmed UPI orders — COD (the common case) is never
  blocked**, since a COD order's `payment_status` stays `pending` for
  its whole lifecycle (no `delivered` flip exists yet, per
  `lib/orders.php`'s own note).

Full error set: `not_found` (404), `forbidden` (403),
`order_not_eligible_for_address_change` (409), `order_already_paid`
(409), `validation_error` (422, bad/foreign `delivery_address_id`),
`payment_method_not_allowed` (422), `cod_not_eligible` (422). Writes an
`order_status_history` row ("Delivery address changed") on success, so
the change is visible in the order timeline the same way a status
change is.

### `backend/lib/orders.php` — `format_order()`

Added `delivery_address_id` to the returned order shape (wasn't exposed
before — nothing previously needed a client-visible read of an
already-placed order's address id). The Android address-picker needs
this to highlight/exclude the order's current address in its list.

No migration needed — every column this endpoint touches
(`delivery_address_id`, `delivery_charge`, `free_delivery_discount_amount`,
`grand_total`) already existed (migration 47 added the offers columns;
the rest are in the base schema).

---

## Android — network layer + layouts done, screen wiring not done

**Files changed:**
- `customer/app/.../network/ApiService.kt` —
  - `cancelOrder()` now takes `@Body body: CancelOrderBody = CancelOrderBody()`
    instead of no body at all. Default value keeps the existing call
    site in `OrderStatusActivity.kt` compiling unchanged; the backend
    (`orders/cancel.php`) already accepted an optional `reason` in its
    body long before this session, so this is purely an Android-side
    gap being closed, not a new backend capability.
  - New `changeOrderAddress(orderId, ChangeOrderAddressBody)` →
    `POST customer/order-change-address.php`.
- `customer/app/.../network/Models.kt` —
  - `CancelOrderBody(reason: String? = null)` (new).
  - `ChangeOrderAddressBody(deliveryAddressId: Int)` (new).
  - `Order.deliveryAddressId: Int? = null` (new field, mirrors the
    backend addition above).
- `customer/app/src/main/res/layout/item_cancel_address_row.xml` (NEW)
  — lightweight tappable address row (label + full address + a
  "Current" badge + a select checkmark). Deliberately much lighter
  than Address Book's `item_address_card.xml` (which has Edit/Delete/Set
  Default actions this sheet has no use for).
- `customer/app/src/main/res/layout/bottom_sheet_cancel_options.xml`
  (NEW) — the sheet itself. Same drag-handle/title/close-icon shell as
  `fragment_schedule_time.xml`. Structure, top to bottom:
  - "Change delivery address" row (`changeAddressRow`) that expands
    `addressListContainer` (empty `LinearLayout`, populated at runtime)
    — lazy-load-on-expand, same "don't fetch until asked for" idea
    Track Live already established for the map.
  - Reason prompt + a `ChipGroup` (`cancelReasonChipGroup`,
    single-selection, **not required** — `selectionRequired="false"`,
    since the free-text field below can stand alone) with the four
    chips: Ordered by mistake / Taking too long / Found a better option
    / Other.
  - An always-visible optional free-text field
    (`cancelReasonDetailsInput`) — **not** gated behind picking "Other"
    first, per the app owner's "picker + optional free-text" choice
    (a customer might want to add detail on top of any picked reason,
    not only when no chip fits).
  - `btnConfirmCancel` (outlined, primary-colored — the actual cancel,
    now living at the bottom of this sheet instead of being the one and
    only tap target) and `btnKeepOrder` (text button, dismisses).
- `customer/app/src/main/res/values/strings.xml` — all
  `cancel_sheet_*` / `cancel_option_*` / `cancel_reason_*` /
  `cancel_confirm_button` / `cancel_keep_order_button` /
  `cancel_address_*` strings the layout above references.

**Confirmed building cleanly at this point** — every string the new
layouts reference now exists, and the `ApiService`/`Models` changes are
additive (`Order.deliveryAddressId` defaults to null,
`cancelOrder()`'s new body param has a default). Neither new layout is
inflated by any Kotlin code yet, so they're inert until wired up —
nothing user-facing has changed.

---

## Not done this session — exactly what's left

1. **`CancelOrderOptionsBottomSheet.kt`** (NEW,
   `customer/app/.../ui/orderstatus/`) — the `BottomSheetDialogFragment`
   that actually drives `bottom_sheet_cancel_options.xml`. Shape to
   follow (closest existing pattern: `ScheduleTimeSlotBottomSheet.kt` —
   same private-constructor + `newInstance()` + `onSelected` callback
   style):
   - `newInstance(orderId: Int, currentAddressId: Int?)`.
   - `changeAddressRow` click → toggle `addressListContainer`
     visibility; first time it opens, show `addressListLoading`, call
     `api.getAddresses()`, inflate one `item_cancel_address_row.xml`
     per address (skip or badge the one matching `currentAddressId`),
     show `addressListEmptyText` if the list is empty/only the current
     address. Tapping a row calls
     `api.changeOrderAddress(orderId, ChangeOrderAddressBody(addr.id))`;
     on success, `dismiss()` and let the caller (`OrderStatusActivity`)
     know to re-fetch the order (a plain `onAddressChanged: (() -> Unit)?`
     callback, same shape as `ScheduleTimeSlotBottomSheet.onSelected`,
     is enough — no need to pass the updated order back through the
     sheet). On the specific error codes this endpoint can return
     (`order_already_paid`, `cod_not_eligible`,
     `payment_method_not_allowed`, `order_not_eligible_for_address_change`),
     show the reason via `InAppNotifier` rather than a generic "couldn't
     update" — the reason strings aren't user-facing-friendly as raw
     codes yet, so this needs a small code→message map (same shape
     `statusLabel()` in `OrderStatusActivity.kt` already uses for order
     statuses).
   - `btnConfirmCancel` click → build the `reason` string from
     whichever chip is checked in `cancelReasonChipGroup` (if any) plus
     `cancelReasonDetailsInput`'s text (if non-blank), joined as e.g.
     `"$chipLabel — $freeText"` when both are present, just the chip
     label or just the free text when only one is, or `null` (falls
     back to the backend's own "Cancelled by customer" default) when
     neither is. Call `api.cancelOrder(orderId, CancelOrderBody(reason))`;
     on success `dismiss()` + let the caller know (another simple
     `onCancelled: (() -> Unit)?` callback).
   - `btnKeepOrder` / `btnCloseCancelSheet` → just `dismiss()`.

2. **`OrderStatusActivity.kt`** — change
   `binding.btnCancelOrder.setOnClickListener { cancelOrder() }` (line
   197) to open the new sheet instead:
   ```kotlin
   binding.btnCancelOrder.setOnClickListener {
       val sheet = CancelOrderOptionsBottomSheet.newInstance(orderId, lastTrack?.let { /* no address id on track; use loaded Order instead */ })
       // ...
   }
   ```
   Needs `currentAddressId` from the full `Order` (via `getOrder()`,
   already fetched elsewhere in this Activity for `renderRefund()`),
   not from `OrderTrackResult` (`track.php` doesn't return it — only
   `orders-detail.php`'s `format_order()` does, per the backend section
   above). The existing private `cancelOrder()` method (line 827) can
   likely be deleted entirely once the sheet's `onCancelled` callback
   does the same success-path work it currently does inline
   (status text update, hide the button, re-fetch refund) — or kept as
   a small shared helper the callback invokes, whichever reads cleaner
   once written.

3. Not scoped this session (carried over from doc 127 if ever revisited):
   edit-delivery-instructions, contact-restaurant click-to-call,
   contact/chat-support entry point — all explicitly out of this v1 per
   the scope confirmed at the top of this doc.
