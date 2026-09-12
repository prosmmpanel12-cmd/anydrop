# Handover — Track Live Button, Status-Change Confirm, Vertical Animated Stepper, Restaurant Order Tracking

Session date: 11 Sep 2026. Implements sections 5, 2, 3, and 1 of plan doc
127
(`127_Plan_RestaurantTracking_StatusConfirm_VerticalStepper_CancelRetention_TrackLiveButton_2026-09-11.md`),
in the order that doc's "Suggested build order" recommended. Section 4
(cancel retention) is **not** built this session — flagged in doc 127 as
having real open product questions to confirm with the app owner first
(the exact 4-5 retention options, especially whether address-change is
in scope) — cheaper to confirm than to build and redo, per that doc's
own recommendation.

---

## §5 — "Track Live" button (customer app)

Map no longer auto-shows the instant a rider position is available; a
"Track Live" card shows in its place, tapping it reveals the map.

**Files changed:**
- `customer/app/.../ui/orderstatus/OrderStatusActivity.kt` — new
  `mapUserRequested` flag; `isMapDataAvailable()` split out from the old
  `shouldShowMap()` (now `isMapDataAvailable() && mapUserRequested`);
  `updateMap()` shows the new card vs. the map based on that combined
  state; `btnTrackLive` click sets the flag and replays immediately off
  `lastTrack` instead of waiting for the next poll.
- `customer/app/src/main/res/layout/activity_order_status.xml` — new
  `trackLiveCard` (message + button), sitting in the map's old slot.
- `customer/app/src/main/res/values/strings.xml` — `track_live_message`,
  `btn_track_live`.

`startRouteRecalcLoop()` needed **no changes** — it already gates on
`shouldShowMap()`, which now transparently includes the new condition,
exactly as doc 127 §5 predicted. Kept the reveal inline (not a separate
full-screen map Activity) and one-way (no dismiss/hide-again affordance
for v1), both per that section's recommendations — flagged there as
open questions if the app owner wants either changed later.

## §2 — Status-change confirm (restaurant app)

"Mark Preparing" and "Mark Ready" now open a confirm dialog before
calling `updateStatus()`. Accept and Reject are unchanged — Accept
already has `PrepTimeDialog` as a real confirm step, Reject already
requires typing a reason + a separate "Confirm Reject" tap.

**Files changed:**
- `restaurant/app/src/main/res/layout/dialog_confirm_status_change.xml`
  (NEW) — modeled directly on `dialog_logout_confirm.xml`'s structure,
  reusing `ic_check_circle` rather than commissioning new art.
- `restaurant/app/.../ui/common/StatusChangeConfirmDialog.kt` (NEW) —
  generic `show(context, title, message, onConfirmed)`, mirrors
  `PrepTimeDialog`'s existing object-style pattern; one dialog class
  reused for both transitions.
- `restaurant/app/.../ui/orderdetail/OrderDetailActivity.kt` — wired
  into `configureActions()`'s `"accepted"`/`"preparing"` cases.
- `restaurant/app/src/main/res/values/strings.xml` — `btn_confirm`,
  `dialog_confirm_preparing_title/message`,
  `dialog_confirm_ready_title/message`.

Open question carried over from doc 127 §2, not resolved this session:
should Accept also get this dialog on top of `PrepTimeDialog`? Left as-is
(not gated) per that section's recommendation — one-line change in
`promptAcceptPrepTime()`'s callback if the app owner wants it added.

## §3 — Vertical animated stepper (customer app)

`OrderStatusStepperView` rebuilt from a horizontal row-of-dots to a
vertical timeline (dot+connector column, label to the right of each
dot), with three animations on step transitions: the newly-completed
dot pulses once (scale, ~300ms) as its color swaps; the connector below
it fills top-to-bottom (~350ms) instead of snapping; the new
current-step dot pulses gently on a loop until it's overtaken.

**File changed (only one):**
- `customer/app/.../ui/orderstatus/OrderStatusStepperView.kt` — full
  rewrite. `setStatus()` now builds the view tree once and mutates it
  in place on every later call (`buildViews()` / `updateViews()`)
  instead of `removeAllViews()` + rebuild every poll tick — required
  for any animation to be possible at all, since there's nothing to
  animate *from* once old views are torn down. Animation only runs
  when `currentStep` actually differs from the previous call
  (`lastStep`), and is skipped entirely on the first call
  (`lastStep == -1`) so opening the screen mid-delivery jumps straight
  to the correct state with no animation — same principle
  `OrderStatusActivity.updateMap()` already applies to its own
  first-marker-placement vs. animated-move split.

No layout XML or backend changes — this view is built entirely
programmatically, as before.

## §1 — Restaurant order tracking (restaurant app)

Restaurants can now see their order's rider on a live map, same as the
customer app already could — **marker-only** (no route polyline), per
doc 127 §1's own recommendation to ship the smaller version first and
only add a route line as a fast-follow if asked for.

**Files changed:**
- `backend/api/v1/restaurant/orders-track.php` (NEW) — near-direct port
  of `orders/track.php`, restaurant-auth'd. Reuses `orders-detail.php`'s
  exact ownership check. Excludes any OTP field and payment info.
  `rider` is gated server-side to only populate for
  `rider_assigned`/`picked_up`/`out_for_delivery`, so the Android side
  can just check `rider != null`.
- `restaurant/app/.../network/Models.kt` — `RestaurantTrackRider`/
  `RestaurantTrackRestaurant`/`RestaurantTrackDelivery`/
  `RestaurantTrackResult`, mirroring the customer app's `OrderTrackResult`
  family minus `otp`.
- `restaurant/app/.../network/ApiService.kt` — `trackOrder(orderId)`.
- `restaurant/app/.../ui/ordertrack/RiderTrackActivity.kt` (NEW) — 5s
  poll, restaurant/delivery/rider markers, rider position animated
  (lerped) between polls same as the customer app's map, one-time
  camera-fit on first rider position. Deliberately **not** a port of
  `OrderStatusActivity`'s full map logic — no route line, no
  progress-trim, no deviation-triggered recalc; stops polling once the
  order leaves the trackable status set (the screen has no further use
  after that).
- `restaurant/app/src/main/res/layout/activity_rider_track.xml` (NEW) —
  header + full-screen `MapView`, same structural pattern as this app's
  other map screen (`activity_location_picker.xml`); a "waiting for
  rider location" overlay shows until the first position lands.
- `restaurant/app/.../ui/orderdetail/OrderDetailActivity.kt` — new
  `trackOrderCard` (message + "Track Order" button), visible for the
  same `rider_assigned`/`picked_up`/`out_for_delivery` status set the
  new endpoint actually returns a rider for — a different set than
  pickup OTP's (`rider_assigned` only), intentionally, since the
  restaurant may want to confirm delivery happened through
  `out_for_delivery`.
- `restaurant/app/src/main/res/values/strings.xml` — `track_order_message`,
  `btn_track_order`, `rider_track_title`, `rider_track_waiting`.
- `restaurant/app/src/main/AndroidManifest.xml` — new `RiderTrackActivity`
  entry.

Open question carried over from doc 127 §1, not resolved this session:
does the restaurant actually need a drawn/recalculated route polyline,
or is marker-only enough? Left as marker-only per that section's
recommendation.

---

## Not done this session (see doc 127 for full detail)

- **§4 — Cancel-order retention flow.** New
  `CancelOrderOptionsBottomSheet` + at least one new backend endpoint
  (`customer/order-edit-instructions.php`, small) and possibly a second
  (`customer/order-change-address.php`, the single biggest unknown in
  the whole plan — needs `orders/create.php` traced for exactly which
  area/COD/delivery-fee checks depend on delivery location before this
  can be scoped precisely). Confirm the exact 4-5 options with the app
  owner before starting.
