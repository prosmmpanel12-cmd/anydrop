# Handover — I4: Scheduled Orders ("Schedule for later"), same-day only

**Status: ✅ Done, 2026-08-13.** Backend deployed, Android built and
confirmed working end-to-end by the app owner (schedule pick → checkout →
order placed successfully). Closing this handover.

**Scope, confirmed by the app owner:** same-day only. The picker never
shows a date — just "Deliver Now" or a same-day time slot, bounded to the
restaurant's remaining open hours today.

---

## Where things stand

### ✅ Backend — done, should be deployable as-is

- `backend/sql/17_migration_scheduled_orders.sql` — adds
  `orders.scheduled_for` (DATETIME NULL) and `customer_cart_items.scheduled_for`
  (mirrors it, same per-row pattern `coupon_code` already uses on that
  table). Idempotent conditional-ALTER, same style as migrations 06/16.
  **Not yet run against the DB.**
- `backend/api/v1/restaurants/menu.php` — response now includes the
  restaurant's raw `opening_time`/`closing_time` (straight off the
  `restaurants` row) so the client can bound its slot list.
- `backend/api/v1/customer/cart-sync.php` — GET and POST both carry
  `scheduled_for` per restaurant cart now, alongside `coupon_code`. Not
  validated at this layer on purpose (see inline comment) — a stale/bad
  value sitting in the saved cart is harmless, the real check happens once
  at order-create time.
- `backend/lib/orders.php`:
  - New `validate_scheduled_for(array $restaurant, $raw): array` — rejects
    anything not dated today (server time), anything under a **20-minute**
    minimum lead time, and anything outside the restaurant's open hours
    (skipped entirely if the restaurant has no hours configured). Returns
    `['error' => null, 'value' => 'Y-m-d H:i:s'|null]` on success.
  - `format_order()` now returns `scheduled_for` in every order response.
- `backend/api/v1/orders/create.php` — accepts optional `scheduled_for` in
  the request body, calls `validate_scheduled_for()` right after pricing
  (needs `$priced['restaurant']`'s hours), 422s with
  `scheduled_time_not_today` / `scheduled_time_too_soon` /
  `scheduled_time_outside_open_hours` / `invalid_scheduled_time` on a bad
  slot, otherwise stores it on the order.

**Known limitation** (not new — matches the rest of the codebase):
`validate_scheduled_for()` only handles same-day open/close windows (e.g.
09:00–23:00). A restaurant open past midnight isn't modelled, same as
`restaurants/list.php`'s `is_open_now`.

**Restaurant/rider apps don't surface `scheduled_for` anywhere yet.** The
field is just `null` on every order until Android starts sending it, so
nothing breaks — but once the customer flow is live, restaurant staff
currently have no way to tell a scheduled order apart from an ASAP one on
their order list/detail screens. Worth a look after Android ships.

### ✅ Android — done this session

- `network/Models.kt`:
  - `RestaurantDetail` — added `openingTime`/`closingTime` (`"HH:MM:SS"` or
    null).
  - `CartSyncRestaurant` — added `scheduledFor`.
  - `CreateOrderBody` — added `scheduledFor`.
  - `Order` — added `scheduledFor`.
- `data/CartManager.kt` — `RestaurantCart.scheduledFor: String?` field
  (same slot `appliedCouponCode` sits in). Plus:
  - `CartManager.getScheduledFor(restaurantId)` /
    `CartManager.setScheduledFor(restaurantId, value)` — these exist
    *outside* the per-restaurant `RestaurantCart` lookup because the
    "Schedule for later" row on restaurant-detail sits above the menu and
    can be tapped before the customer has added anything, i.e. before a
    `RestaurantCart` exists. A pick made then is held in a
    `pendingScheduledFor` map and gets applied onto the real
    `RestaurantCart` the moment `add()`/`setCustomized()` create one.
  - `removeCart()` and `clear()` both clean up `pendingScheduledFor` too,
    so a slot picked for an order that already got placed doesn't leak
    into the *next* cart started with that restaurant.
- `data/CartSyncManager.kt` — `scheduledFor` now flows both directions
  (push to server, restore on app start), same as `appliedCouponCode`.
- **New** `ui/common/ScheduleTimeSlotBottomSheet.kt` +
  `res/layout/fragment_schedule_time.xml` +
  `res/layout/item_schedule_slot_row.xml` — the actual picker. Shows
  "Deliver Now" pinned first, then half-hour slots for the rest of today
  (`SLOT_INTERVAL_MINUTES = 30`), starting at least `MIN_LEAD_MINUTES = 20`
  from now (**must stay in sync with the server's floor** in
  `validate_scheduled_for()` — if one changes, change the other), rounded
  up to a clean slot boundary, bounded to `openingTime`/`closingTime` if
  both are present. Shows `scheduleEmptyState` text instead of a list if
  the restaurant is already closed for the rest of today. Tapping any row
  selects it and dismisses immediately (no separate Apply step) via a
  plain `onSelected: ((String?) -> Unit)?` lambda the caller sets before
  `.show(...)` — same lifecycle tradeoff `MenuFiltersBottomSheet.onApply`
  already accepts (doesn't survive the caller's own config-change
  teardown; a lost callback just means re-tapping the still-open sheet).
- `ui/restaurant/RestaurantDetailActivity.kt` — the ETA row's old
  `InAppNotifier` "Coming soon" toast is gone. Tapping it now opens
  `ScheduleTimeSlotBottomSheet` (passing the restaurant's hours + whatever
  `CartManager.getScheduledFor()` currently returns), and on selection
  calls `CartManager.setScheduledFor()` + a `CartSyncManager.scheduleSync()`
  + re-renders the row text via the new private `renderEtaRowText()`
  helper: `"N mins · Schedule for later"` when nothing's picked, or
  `"Today, h:mm a"` (new string `detail_eta_scheduled_format`) once
  something is.
- `res/values/strings.xml` — new strings: `schedule_sheet_title`,
  `schedule_sheet_subtitle`, `schedule_deliver_now`,
  `schedule_no_slots_today`, `detail_eta_scheduled_format`.
- `res/layout/activity_checkout.xml` — new `rowDeliveryTime` row inserted
  between the address section and Payment method (lightning icon +
  `deliveryTimeText` + chevron, same visual language as the restaurant-
  detail ETA row). **Not wired to anything yet** — see below.

### ✅ Android — `CheckoutActivity.kt`, done this session

All six items from the previous handover's checklist are in:

1. `renderDeliveryTimeRow()` renders `binding.deliveryTimeText` off
   `CartManager.getCart(restaurantId)?.scheduledFor` — "Deliver Now" or
   "Today, h:mm a" (reuses `R.string.detail_eta_scheduled_format`), called
   from `onCreate()` immediately (before the hours call below necessarily
   resolves) and again after every pick. **Still copy-pasted** from
   `RestaurantDetailActivity.renderEtaRowText()` rather than factored into
   one shared helper — flagged again, still not done.
2. **Blocker resolved: went with option (a).** New `loadRestaurantHours()`
   calls `api.getMenu(restaurantId)` once in `onCreate()`, stores
   `opening_time`/`closing_time` into two new fields
   (`restaurantOpeningTime`/`restaurantClosingTime`), fails silently
   (sheet just falls back to unbounded slots if this hasn't resolved yet).
   `binding.rowDeliveryTime` opens `ScheduleTimeSlotBottomSheet` via new
   `openScheduleSheet()`, same usage pattern as restaurant-detail's.
3. On selection: sets `CartManager.getCart(restaurantId)?.scheduledFor`
   directly, re-renders, calls `CartSyncManager.scheduleSync(this)`.
4. `placeOrder()`'s `CreateOrderBody(...)` now sends
   `scheduledFor = CartManager.getCart(restaurantId)?.scheduledFor`.
5. New 422 codes (`scheduled_time_not_today`, `scheduled_time_too_soon`,
   `scheduled_time_outside_open_hours`, `invalid_scheduled_time`) map to
   one new generic string, `R.string.schedule_time_unavailable`
   ("That delivery time isn't available anymore. Pick another."), added
   to the same `when (errInfo.code)` block coupon/min-order errors already
   go through — went with the generic-message option, not per-code.
6. Verified: no separate reset needed. `CartManager.removeCart()` deletes
   the whole `RestaurantCart` object (and cleans up `pendingScheduledFor`),
   so a successful Place Order clears `scheduledFor` for free along with
   the rest of the cart — confirmed by reading `CartManager.kt`, not just
   assumed.

**Also not done, lower priority:**
- `OrderStatusActivity`/order-history screens don't show `scheduled_for`
  anywhere yet, even though `Order.scheduledFor` is already populated by
  the API. A "Scheduled for 8:30 PM" line somewhere on the order-status
  screen would close the loop for the customer after Place Order.
- **No Gradle build has been run against any of this work yet** — worth a
  compile pass before further UI work, same as H6's outstanding gap. This
  is now the actual next step for I4.
- Restaurant-app order list/detail surfacing `scheduled_for` (see backend
  section above).
- The `renderEtaRowText()` / `renderDeliveryTimeRow()` duplication between
  `RestaurantDetailActivity` and `CheckoutActivity` flagged in both
  sessions now — worth factoring into one shared formatter if a third
  place ever needs it.
- `backend/sql/17_migration_scheduled_orders.sql` still hasn't been run
  against the DB (see backend section above) — needed before any of this
  works end-to-end regardless of Android's state.

---

## Design decisions made this session (so a future session doesn't re-litigate)

- **Same-day only, no date picker** — explicit app owner instruction.
- **20-minute minimum lead time**, **30-minute slot interval** — not
  explicitly requested, chosen as reasonable defaults; flag if the app
  owner wants these changed (both live in exactly two places: the
  `MIN_LEAD_MINUTES`/`SLOT_INTERVAL_MINUTES` constants in
  `ScheduleTimeSlotBottomSheet.kt`, and the `20 * 60` literal in
  `validate_scheduled_for()` in `backend/lib/orders.php` — **keep them in
  sync if either changes**).
- **Tap-to-select-and-dismiss**, no separate "Apply" button on the sheet —
  matches how a single time slot is a complete, unambiguous choice (unlike
  MenuFiltersBottomSheet's multi-chip state, which does need an Apply
  step).
- **Client-side slot generation is a convenience only** — the server
  re-validates independently and is the actual source of truth. A
  disagreement between the two just surfaces as a 422 at Place Order, sent
  back to the picker.
- **Schedule pick lives on the cart** (`RestaurantCart.scheduledFor`), not
  as separate Activity/Intent state — consistent with how coupon codes
  already work, and means it naturally survives navigating
  restaurant-detail → cart sheet → checkout → back, same as everything
  else in the cart.
