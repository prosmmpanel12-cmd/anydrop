# Plan — Restaurant Order Tracking, Status-Change Confirm, Vertical
# Animated Stepper, Cancel-Order Retention Flow, "Track Live" Button

Session date: 11 Sep 2026 (planning only — no code written this
session, per explicit ask). Five separate asks from the app owner,
bundled into one deep-plan doc since they touch overlapping screens
(mainly `OrderStatusActivity.kt` on the customer side and
`OrderDetailActivity.kt` on the restaurant side). Each piece below is
independently buildable/shippable — see "Suggested build order" at the
end for the recommended sequence and why.

All five were investigated against the actual current code this
session (not assumed) — see each section's "Current state" for what
was actually read.

---

## 1. Restaurant app — track their own order's rider/parcel live

**Ask (verbatim intent):** "restorent apne parcel ko track kar shkta
hai track order" — the restaurant should be able to see where their
order's rider currently is, once one is assigned, the same way the
customer app already can.

**Current state:**
- Customer app already has this: `orders/track.php` (5s poll) +
  `orders/route.php` (route polyline, ~35-90s recalc) +
  `OrderStatusActivity`'s live map (rider/restaurant/delivery markers,
  animated movement, progress-trim + deviation-recalc route line per
  doc 91). All customer-auth'd (`require_auth('customer')` +
  `customer_id` ownership check).
- Restaurant app has **no equivalent**. `OrderDetailActivity.kt`
  fetches the order **once** via `getOrder(orderId)` on `onCreate()` —
  no polling loop exists at all in that Activity today. There is no
  `restaurant/orders-track.php` or any restaurant-auth'd endpoint that
  exposes rider lat/lng.
- The restaurant app **already depends on** `play-services-maps`
  (`build.gradle` line 108) and already has a Maps API key configured
  in its manifest — confirmed this session — so no new Gradle
  dependency is needed, just a new map-bearing screen/section, same as
  customer's.
- `restaurant/orders-detail.php` / `orders-list.php` already return
  `pickup_otp`/`pickup_otp_verified` (doc 122/125) but not rider
  lat/lng — that field simply isn't selected in this endpoint today.

**Plan:**

### Backend — new `restaurant/orders-track.php`
Near-direct port of `orders/track.php`, restaurant-auth'd instead of
customer-auth'd:
- `require_auth('restaurant')`, ownership check via
  `orders.restaurant_id === $owner['owner_id']` (mirrors
  `orders-detail.php`'s existing ownership check — reuse that same
  check, don't reinvent it).
- Returns `status`, `rider: {name, mobile, lat, lng} | null`,
  `restaurant: {lat, lng}` (the restaurant's own fixed pin — mostly
  useful as a route origin, arguably skippable since the restaurant
  already knows where it is, but keeps the shape consistent with the
  customer endpoint for a shared Android map-drawing helper if one
  gets extracted later), `delivery: {lat, lng} | null` (destination
  pin, same `customer_addresses` join `orders/track.php` already
  does), `eta_minutes`.
- **Deliberately excludes**: any OTP field (pickup OTP is a
  `orders-detail.php` concern already solved; delivery OTP is
  customer-only and none of the restaurant's business), and payment
  info.
- Gate the whole response's `rider` block server-side to only populate
  while `status` is `rider_assigned`/`picked_up`/`out_for_delivery` —
  same reasoning as customer's `shouldShowMap()`, so the Android side
  doesn't need to separately reason about which statuses are
  "trackable," it can just check `rider != null`.

**Open question to confirm before building:** does the restaurant
actually need `orders/route.php`-equivalent (a drawn polyline,
recalculated on drift), or is a live rider marker + static
restaurant/delivery pins enough for this audience? A restaurant owner
checking "where's my order" probably just wants confirmation the rider
is moving in the right direction, not turn-by-turn precision — a
polyline is meaningfully more backend/Android work (Directions API
calls, progress-trim math) than a marker-only map. **Recommendation:
ship marker-only first, add the route line as a fast-follow only if
the app owner asks for it** — this cuts the first version's scope
roughly in half.

### Android — restaurant app
- New `RiderTrackActivity.kt` (or a section within
  `OrderDetailActivity` — see "Track Live" pattern in section 5 below,
  which argues for a *separate* screen/expand-on-demand rather than an
  always-embedded map; the same reasoning applies here even more
  strongly since `OrderDetailActivity` is already a long scroll of
  order details + actions).
- Entry point: a "Track Order" button/card on `OrderDetailActivity`,
  shown only when `order.status` is
  `rider_assigned`/`picked_up`/`out_for_delivery` (same gate as
  `renderPickupOtp()`'s status check, different status set — pickup
  OTP only shows for `rider_assigned`, tracking should stay visible
  through `out_for_delivery` since the restaurant may want to confirm
  delivery happened).
- Reuses `MapView` + marker-drawing logic conceptually identical to
  customer's `updateMap()`, but restaurant-scoped (own map instance,
  own 5s poll against the new endpoint) — **do not** try to share a
  single Activity/Fragment between the two apps (they're separate
  Gradle modules/APKs), but a shared *pattern* (poll loop shape,
  marker icons) is fine to copy the way doc 125 copied the rider app's
  cooldown-timer pattern into the restaurant app.
- New `ApiService.kt` (restaurant) method: `trackOrder(orderId)`.
- New `Models.kt` (restaurant) data classes: `RestaurantTrackResult`
  (mirrors customer's `OrderTrackResult`, minus `otp`).

**Files to touch:**
```
backend/api/v1/restaurant/orders-track.php          (NEW)
restaurant/app/.../network/ApiService.kt             (+trackOrder)
restaurant/app/.../network/Models.kt                 (+RestaurantTrackResult)
restaurant/app/.../ui/ordertrack/RiderTrackActivity.kt  (NEW)
restaurant/app/.../ui/ordertrack/activity_rider_track.xml (NEW layout)
restaurant/app/.../ui/orderdetail/OrderDetailActivity.kt (+ "Track Order" entry button)
restaurant/app/src/main/res/values/strings.xml       (+ few strings)
restaurant/app/src/main/AndroidManifest.xml           (+ new Activity entry)
```

---

## 2. Restaurant app — confirm dialog before order status changes

**Ask:** "restorents order ka status change pe confirm mango" — when
the restaurant taps an action that changes the order's status, ask for
confirmation first instead of firing immediately.

**Current state (`OrderDetailActivity.kt`, read this session):**
- `pending → accepted`: already has a soft gate — tapping "Accept"
  opens `PrepTimeDialog` to collect prep minutes, which is a form of
  confirmation, but there's no explicit "Are you sure?" step; entering
  a prep time and tapping its own button immediately fires
  `acceptOrder()`.
- `pending → rejected`: already gated behind typing a reason into
  `rejectGroup` + tapping "Confirm Reject" (`confirmReject()`) — this
  one **already has** an explicit two-step confirm, arguably the
  closest thing to what's being asked for elsewhere.
- `accepted → preparing` (`btnPrimaryAction` = "Mark Preparing") and
  `preparing → ready` (`btnPrimaryAction` = "Mark Ready`): **zero
  confirmation** — `updateStatus(newStatus)` fires the instant the
  button is tapped. These are the two the ask is really about.
- Reference confirm-dialog pattern already exists in this app:
  `dialog_logout_confirm.xml` (illustration + title + message +
  side-by-side Cancel/Confirm buttons, `MaterialAlertDialogBuilder`) —
  found this session while investigating. This is the exact visual
  pattern to reuse, not invent something new.

**Plan:**
- New `dialog_confirm_status_change.xml`, modeled directly on
  `dialog_logout_confirm.xml`'s structure (illustration slot, title,
  message, Cancel/Confirm buttons) — reuse `ic_lock`-style icon
  substitution logic from doc 122/123's own precedent (if no
  status-specific illustration exists, pick the closest existing
  drawable rather than commissioning new art).
- New small helper, e.g. `StatusChangeConfirmDialog.show(activity,
  title, message) { onConfirmed() }` — generic over the message/title
  so it's one dialog class reused for both "Mark Preparing" and "Mark
  Ready" (and reusable again for any future status-change action)
  rather than two near-identical copies.
- Wire into `configureActions()`:
  - `"accepted"` case: `btnPrimaryAction`'s listener becomes
    `{ confirmThenUpdateStatus("preparing", ...) }` instead of calling
    `updateStatus("preparing")` directly.
  - `"preparing"` case: same for `"ready"`.
  - **`promptAcceptPrepTime()` (accept flow) — leave as-is.** It
    already has PrepTimeDialog acting as a meaningful confirm step
    (the restaurant has to actively choose a prep time, not just tap
    once), and stacking a second "are you sure" on top of that dialog
    would be redundant friction on the highest-frequency action in
    this screen. **Recommendation, confirm with app owner:** only add
    the new confirm step to Mark Preparing / Mark Ready, not Accept.
    If the app owner actually wants Accept gated too, that's a
    one-line addition to `promptAcceptPrepTime()`'s callback once the
    generic dialog helper exists.
- Reject flow: already confirmed (see above) — no change needed.

**Files to touch:**
```
restaurant/app/src/main/res/layout/dialog_confirm_status_change.xml  (NEW)
restaurant/app/.../ui/common/StatusChangeConfirmDialog.kt            (NEW)
restaurant/app/.../ui/orderdetail/OrderDetailActivity.kt             (wire into configureActions())
restaurant/app/src/main/res/values/strings.xml                       (+ titles/messages per transition)
```

---

## 3. Customer app — delivery status progress: horizontal → vertical, animated

**Ask:** "delivery status wala progres horizonatal se vertical karo
and achha animation" — the 5-step order tracker should become a
vertical timeline instead of the current horizontal row of dots, with
good animation.

**Current state (`OrderStatusStepperView.kt`, read in full this
session):**
- Built entirely programmatically (no layout XML), `LinearLayout`
  subclass. `setStatus(currentStep)` does `removeAllViews()` then
  rebuilds two rows from scratch: a horizontal `dotsRow` (dot →
  connector line → dot → ...) and a horizontal `labelsRow` underneath.
  Called from `render()` on **every 5s poll** (`OrderStatusActivity`
  line ~475), same cadence as everything else on that screen.
- **No animation at all today** — `removeAllViews()` + rebuild is a
  hard cut every time `setStatus()` runs, even when the step hasn't
  actually changed since the last poll (it's called unconditionally
  every render(), not just on step transitions).
- 5 steps, colors/typeface driven by `bg_step_dot_done` /
  `_current` / `_pending` drawables + `anydrop_primary` /
  `text_secondary` colors — these drawables/colors are reusable as-is
  for a vertical layout, only the arrangement changes.

**Plan:**

### Layout change: horizontal rows → vertical column
- `orientation = VERTICAL` on the outer container (already `VERTICAL`
  per `init {}` — that's the *outer* stepper view; the two *inner* rows
  are the ones currently `HORIZONTAL` and need to flip).
- New per-step row: `[dot+connector column]  [label]`, side by side,
  stacked vertically for each of the 5 steps — i.e. swap the current
  "row of dots above a row of labels" for "a column of (dot, label)
  pairs," with the connector line now drawn **vertically** between
  consecutive dots instead of horizontally.
- `buildConnectorLine()` needs a vertical variant: instead of
  `LayoutParams(0, lineHeightPx, 1f)` (fills horizontal space, fixed
  height), a vertical connector needs `LayoutParams(lineWidthPx, 0,
  1f)` inside a `VERTICAL` parent, OR (cleaner) fixed-height segments
  between dots since a vertical timeline doesn't need to "fill
  remaining space" the way a horizontal row's flexible-width connector
  does — recommend a fixed `connectorHeightPx` (e.g. `28 * density`)
  rather than weight-based, since vertical stepper items typically have
  more breathing room per row than horizontal ones need.
- Label placement: to the *right* of the dot column (standard vertical
  timeline UX — Swiggy/Zomato-style), not below it like today's
  horizontal version. `gravity = Gravity.CENTER_VERTICAL` on each row
  so the label vertically centers against its dot.

### Animation
"Achha animation" is vague — concrete proposal, confirm with app owner
before building:
1. **Step-transition animation** (the main one worth building): when
   `currentStep` actually *increases* from the last call (track this
   via a stored `private var lastStep: Int = -1` field, only animate
   when `currentStep != lastStep`), animate:
   - The newly-completed dot's background swap (pending/current →
     done) via a simple `ValueAnimator`-driven color/scale pulse
     (scale up ~1.3x then back to 1x over ~300ms) rather than an
     instant drawable swap — reuses the same `ValueAnimator` pattern
     already used elsewhere in this Activity (`riderMarkerAnimator`),
     so no new animation *technique* enters the codebase, just a new
     use of one already there.
   - The connector line between the just-completed step and the next
     filling in top-to-bottom (a simple height/alpha animate) rather
     than snapping to full "reached" color instantly.
   - The new current-step dot pulsing gently (repeating scale
     animation, low-key) to draw the eye to "you are here" — optional,
     lower priority than the above two.
2. **Skip animating on first render** (`lastStep == -1`, i.e. screen
   just opened) — jump straight to the correct state with no
   animation; animation is for *transitions the user watches happen*,
   not for initial paint (matches how `updateMap()` already
   distinguishes first-marker-placement from subsequent
   animated-moves, per that method's existing `existing == null` vs.
   `animateRiderMarker()` branch — same principle, reused).
3. Rebuild strategy change needed regardless of animation: today's
   `removeAllViews()`-every-call approach can't be animated (there's
   nothing to animate *from* once the old views are gone). Needs to
   change to **update existing views in place** (keep references to
   each dot/connector/label view, mutate their background/text on
   `setStatus()` calls after the first, instead of tearing down and
   rebuilding). This is the bulk of the actual engineering work here —
   the visual vertical-vs-horizontal flip is comparatively small.

**Files to touch:**
```
customer/app/.../ui/orderstatus/OrderStatusStepperView.kt   (rewrite: vertical layout + in-place updates + animation)
customer/app/src/main/res/drawable/                          (bg_step_dot_* likely reusable as-is; check corner-radius assumptions don't assume horizontal context)
```

---

## 4. Customer app — cancel order shouldn't be one tap; offer alternatives first

**Ask:** "cancel order ko itna easy mat rakho 4-5 option do address
change etc" — don't let cancel happen in one direct action; show 4-5
alternative options (e.g. change address) before actually cancelling.

**Current state (`OrderStatusActivity.kt` `cancelOrder()`, read this
session):**
- `btnCancelOrder` is shown whenever `track.status in
  CANCELLABLE_STATUSES` (`pending`, `accepted`) — tapping it disables
  the button and **immediately** calls `api.cancelOrder(orderId)`, no
  confirmation, no reason prompt, no alternatives. One tap = cancelled.
- Backend `orders/cancel.php` (read this session) **already accepts an
  optional `reason` in the request body** (defaults to `"Cancelled by
  customer"` if omitted) — the Android side just never sends one.
  `ApiService.kt`'s `cancelOrder()` signature has no body parameter at
  all today (`@POST suspend fun cancelOrder(@Query("id") orderId:
  Int)`), so this is a real gap, not just a UI polish item — the
  backend capability already exists and is unused.
- No "change delivery address" capability exists anywhere in the
  order-lifecycle flow today — `customer/addresses.php` manages the
  saved address *book* (add/edit/delete addresses for future orders),
  but there's no endpoint to change an *already-placed* order's
  delivery address. This would be genuinely new backend work, not a
  wiring gap.
- No click-to-call exists for rider/restaurant contact today —
  `riderMobileText` just displays the number as plain text
  (`OrderStatusActivity` render(), confirmed this session) — tapping it
  does nothing.

**Plan — a retention bottom sheet before the actual cancel:**

Tapping "Cancel Order" opens a new `CancelOrderOptionsBottomSheet`
instead of cancelling directly. Proposed 4-5 options (confirm exact
set/wording with app owner — these are reasonable defaults based on
what similar apps offer and what's realistically buildable):

1. **Change delivery address** — ⚠️ needs new backend support (see
   below). If the order hasn't been picked up yet
   (`pending`/`accepted`, which is also exactly `CANCELLABLE_STATUSES`
   — convenient overlap), swapping the address is plausible without
   re-routing a rider mid-delivery.
2. **Edit delivery instructions** — cheaper win: `orders` already has
   `delivery_instructions`; an edit endpoint here is much smaller scope
   than address change (no re-geocoding, no area/COD-eligibility
   re-check, no delivery-fee recalculation implications) — a fast
   first option to ship even if address-change lands later.
3. **Contact restaurant** — call `respond_ok`'d restaurant
   phone/support number for this order (check whether
   `orders-detail.php`/`OrderDetailResult` already surfaces a
   restaurant contact number to the customer app; if not, that's a
   small additive field). Wires up `Intent(Intent.ACTION_DIAL)` —
   genuinely new (no click-to-call exists anywhere in this app today,
   confirmed above).
4. **Chat / contact support** — reuse whatever the existing FAQ/support
   entry point is (`FaqsActivity` per `features.md`'s I5 section — that
   doc flags full AI chat support as "not started, build last," so this
   option should point at *today's* support surface, not block on I5).
5. **Still want to cancel** — the actual cancel action, now living at
   the bottom of this sheet instead of being the one and only tap
   target. **This step should now prompt for a reason** (free-text or
   a short reason-picker: "Ordered by mistake" / "Taking too long" /
   "Found a better option" / "Other") and send it via the
   `orders/cancel.php` `reason` field the backend already supports.

**Backend work needed (only for the genuinely-new options):**
- `customer/order-edit-instructions.php` (or extend an existing
  endpoint) — `PATCH`/`POST` to update `delivery_instructions` on an
  order still in `pending`/`accepted`. Small, no side effects.
- `customer/order-change-address.php` — bigger: needs to re-run
  whatever `orders/create.php` already does for
  area/COD-eligibility/delivery-fee checks against the *new* address,
  since all of those were computed against the *original* address at
  order-creation time (check `orders/create.php` for exactly which
  checks depend on delivery location before scoping this precisely —
  not fully traced this session, flagged as the highest-unknown item
  in this whole plan).
- Restaurant contact number — confirm whether `OrderDetailResult`
  already includes one; add if not (small).

**Files to touch (Android):**
```
customer/app/.../ui/orderstatus/CancelOrderOptionsBottomSheet.kt   (NEW)
customer/app/.../ui/orderstatus/bottom_sheet_cancel_options.xml    (NEW layout)
customer/app/.../ui/orderstatus/OrderStatusActivity.kt             (btnCancelOrder opens sheet instead of cancelOrder() directly)
customer/app/.../network/ApiService.kt                             (cancelOrder gains @Body reason; + editInstructions/changeAddress if scoped in)
customer/app/.../network/Models.kt                                 (+ CancelOrderBody{reason})
```

---

## 5. Customer app — don't auto-load the map on `out_for_delivery`; add a "Track Live" button instead

**Ask:** "out for delivery par directly map load mat karo track live
ka option rakho" — stop automatically showing the live map once status
reaches `out_for_delivery` (and implicitly `rider_assigned`/
`picked_up`, the other two statuses that currently trigger it); show a
"Track Live" button/entry point instead, map loads on demand.

**Current state (`OrderStatusActivity.kt`, read this session):**
- `MAP_ACTIVE_STATUSES = setOf("rider_assigned", "picked_up",
  "out_for_delivery")`. `shouldShowMap(track)` returns true whenever
  status is in that set **and** rider lat/lng are non-null.
- `updateMap()` (called from every `render()`, i.e. every 5s poll) sets
  `trackingMapView.visibility = VISIBLE` the moment `shouldShowMap()`
  is true — fully automatic, no user action involved. The map, once
  shown, stays mounted and polling/animating for the rest of the
  delivery.
- `startRouteRecalcLoop()` (independent ~5-90s cadence per doc 91) also
  runs continuously in the background for the Activity's full
  lifetime, gated on `shouldShowMap(track) && mapReady` internally —
  this loop **doesn't need to change**, it already no-ops when the map
  isn't meant to be shown; it just needs its gate condition extended
  (see below).

**Plan:**
- Add a new state: **map is available but not yet opened by the user**
  vs. **map is actively shown**. Introduce `private var
  mapUserRequested = false` (or similar), separate from the existing
  `mapEverShown` flag (which currently means "shown at least once,"
  repurpose or replace carefully — check every existing read of
  `mapEverShown` before reusing it, since it currently drives the
  first-time camera-refit logic in `updateMap()`).
- `shouldShowMap(track)` (or a new wrapper) becomes: map data is
  *available* (today's exact condition, unchanged) AND
  `mapUserRequested` is true. Everything downstream
  (`trackingMapView.visibility`, marker/route drawing, the recalc loop)
  keys off this combined condition instead of the availability check
  alone.
- When map data is available but not yet requested: show a compact
  **"Track Live" card/button** instead of the `MapView` — e.g. reusing
  the same visual slot the map currently occupies, with a short label
  ("Your rider is on the way — Track Live") and a button. Tapping it
  sets `mapUserRequested = true` and calls `updateMap(lastTrack)`
  immediately (don't wait for the next 5s poll — same "replay
  immediately" reasoning `onMapReady()` already uses for
  `lastTrack?.let { updateMap(it) }`).
- **Open question, confirm with app owner:** should "Track Live" open
  the map *inline* on this same screen (current behavior, just
  gated behind a tap instead of automatic), or push to a **separate
  full-screen map Activity**? A full-screen map gives more room and
  matches "Track Live" reading like a distinct mode/screen rather than
  an inline reveal — but it's meaningfully more work (new Activity,
  passing `lastTrack`/order state across, lifecycle duplication for the
  `MapView`). **Recommendation: keep it inline first** (flip a
  visibility flag, cheapest to build, matches today's actual layout
  structure) and only split to a separate screen later if the inline
  version feels cramped once built. This recommendation also keeps
  section 1's restaurant-side tracking screen and this customer-side
  change independent — they don't need to share a screen-architecture
  decision.
- Once map is dismissed (if a "hide map" affordance is added — not
  explicitly asked for, but worth flagging): decide whether re-showing
  requires tapping "Track Live" again or whether dismiss isn't offered
  at all (simplest: no dismiss button, map stays open once opened,
  matches "Track Live" reading as one-way reveal rather than a
  toggle). **Recommendation: no dismiss for v1** — simplest, and
  matches the ask's wording ("track live ka option" sounds like a
  reveal, not a toggle).

**Files to touch:**
```
customer/app/.../ui/orderstatus/OrderStatusActivity.kt   (mapUserRequested flag, gate updateMap()/shouldShowMap() callers, new "Track Live" card show/hide)
customer/app/src/main/res/layout/activity_order_status.xml  (new "Track Live" card view, sitting where/near the MapView)
customer/app/src/main/res/values/strings.xml              (+ btn_track_live, track_live_message or similar)
```

---

## Suggested build order

1. **Section 5 (Track Live button)** first — smallest, most contained
   change (one Activity, one new layout element, no backend work at
   all), and it's a **prerequisite pattern** for section 1's restaurant
   tracking screen (same "gate a map behind an explicit tap" idea) —
   building it first gives section 1 a working reference to copy
   instead of designing both from scratch in parallel.
2. **Section 2 (restaurant status-change confirm)** — small, backend-
   free, no dependencies on anything else here. Good second pick to
   keep momentum with a quick win.
3. **Section 3 (vertical animated stepper)** — self-contained to one
   file (`OrderStatusStepperView.kt`), no backend, no dependency on the
   other sections — can genuinely be done in any order, slotted in
   whenever.
4. **Section 1 (restaurant tracking)** — backend + Android, but now has
   section 5's pattern to reuse. Ship marker-only per that section's
   recommendation; skip the route-polyline question until asked.
5. **Section 4 (cancel retention flow)** last — by far the most
   backend-heavy (two new endpoints, one of them — address change —
   flagged as having real unknowns still to trace in
   `orders/create.php`) and has the most open product questions (exact
   option wording/count, whether address-change is even in scope for
   v1 vs. instructions-edit + contact + support only). Recommend
   confirming the exact 4-5 options with the app owner before writing
   any code here, since "4-5 option" in the ask is a range, not a
   spec — cheapest to nail down over chat than to build and redo.

## Open questions to confirm before coding starts (collected from above)

1. Restaurant tracking: marker-only, or does it need the same route-
   polyline treatment as customer's map? (§1)
2. Status-change confirm: should Accept also get an explicit "are you
   sure," or does `PrepTimeDialog` already count as confirmation
   enough? (§2)
3. Stepper animation: is the 3-part proposal (dot pulse, connector
   fill, current-step pulse) the right scope, or is a simpler single
   effect (e.g. just the connector fill) enough for "achha animation"?
   (§3)
4. Cancel retention: confirm the exact 4-5 options and their order —
   this doc proposes address-change / edit-instructions / contact
   restaurant / contact support / actually-cancel-with-reason, but
   address-change in particular is the single biggest unknown in this
   whole plan (§4) and may be worth deferring out of v1.
5. Track Live: inline reveal (recommended) vs. separate full-screen map
   Activity? (§5)
