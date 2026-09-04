# Rider App — Phase 3 (R3): Delivery Assignment Engine Built
Session date: 04 Sep 2026

Implements Rider_Deep_Plan.md sections 4-8, on top of R2 (doc 83/84).

## ✅ Done this session

**Backend**
- `backend/sql/72_migration_rider_assignment_engine.sql` (new) —
  `rider_order_assignments` table + 3 `app_settings`
  (`rider_assignment_timeout_seconds`=40, `rider_dispatch_radius_km`=8,
  `rider_location_freshness_seconds`=300).
- `backend/lib/dispatch.php` (new) — the engine itself:
  `find_eligible_riders()` (approved + online + fresh location + within
  radius + no conflicting active order + COD-limit check, distance-sorted),
  `dispatch_next_candidate()` (creates one offer + push notification),
  `expire_stale_offers()` (opportunistic sweep, no cron needed — runs at
  the top of every rider dispatch-adjacent endpoint).
- `backend/api/v1/restaurant/orders-status.php` — marking an order
  `ready` now calls `dispatch_next_candidate()`.
- `backend/api/v1/rider/orders-available.php` (new, GET) — rider's one
  open offer, if any.
- `backend/api/v1/rider/orders-current.php` (new, GET) — rider's active
  delivery (`rider_assigned`/`picked_up`/`out_for_delivery`), if any.
- `backend/api/v1/rider/orders-accept.php` (new, POST `?id=`) —
  race-safe via two conditional UPDATEs (no `FOR UPDATE` locking, matches
  this codebase's existing transaction style).
- `backend/api/v1/rider/orders-reject.php` (new, POST `?id=`) — rejects
  + immediately dispatches next candidate.

**Android**
- `RiderDashboardActivity` — now polls every 5s (while online with no
  active delivery) for an offer or an active order, and shows exactly
  one of three cards: no-active-delivery / incoming offer with a live
  countdown + Accept/Reject / active delivery (read-only).
- `activity_rider_dashboard.xml` — added `offerCard` + `currentOrderCard`,
  gave the existing placeholder an id so all three can be visibility-toggled.
- `ApiService.kt` / `Models.kt` — `getAvailableOffer()`, `getCurrentOrder()`,
  `acceptOrder()`, `rejectOrder()` + matching data classes.
- `strings.xml` — offer/current-order strings added.

## Deliberate V1 simplifications (see dispatch.php's own header for the full reasoning)

- **Sequential single-candidate offering**, not broadcast-to-all — matches
  deep-plan §6's state diagram exactly, and sidesteps the double-accept
  race by construction rather than needing row-locking.
- **Straight-line radius from the restaurant** (`rider_dispatch_radius_km`)
  instead of matching the rider's signup `service_area_id` against the
  restaurant's area hierarchy — the plan doesn't specify that matching
  rule and a radius check is simpler + consistent with how
  `restaurants/list.php` already filters for customers.
- **Plain distance-ascending ranking** — no freshness-penalty/
  active-work-penalty/fairness scoring yet (deep-plan explicitly says
  "start simple, don't build ML dispatch in V1").
- **No cron/worker** — `expire_stale_offers()` runs opportunistically on
  every rider dispatch-adjacent call instead.

## Not built this session (deliberately out of scope)

- **Pickup/drop-off flow** (deep-plan §9-16) — advance-status buttons
  (picked up → out for delivery → delivered), delivery OTP entry. The
  current-order card is read-only; a rider can accept a delivery but the
  app has no way yet to move it past `rider_assigned`.
- **Live location tracking during delivery** — `rider_locations` audit
  table is untouched; only the online-toggle's `riders.last_lat/lng` is
  written (R2's location.php, unchanged).
- **COD collection / settlement, earnings, payouts** — sections 17-21.
- **Admin visibility into assignments** — no admin screen shows
  offered/rejected/expired assignment history yet; it's all queryable in
  `rider_order_assignments` directly if needed for support in the meantime.

## Not tested against a live backend / device

Same caveat as every prior session — no Android SDK/Gradle/emulator or
PHP interpreter in this sandbox. Every touched/new PHP file was manually
brace/paren-balance checked; Kotlin files were hand cross-checked against
their PHP counterparts' request/response shapes, not compiled.

## 🔴 Still open — next steps, in order

1. **Run migration 72** on the live DB.
2. **Smoke test the full loop** manually: mark a restaurant order `ready`
   → confirm a `rider_order_assignments` row appears with `status=offered`
   → confirm the target rider's device sees the offer within 5s → accept
   → confirm `orders.status` flips to `rider_assigned` and `rider_id` is
   set → confirm a second rider (if any were also eligible) never saw an
   offer for the same order.
3. **Test reject + timeout paths** — reject an offer, confirm the next
   eligible rider gets offered within the next poll cycle; let an offer
   sit unanswered past 40s, confirm the same on the next `orders-available`
   call from any rider (that's what triggers the sweep).
4. **Device-test the dashboard's three card states** end to end, including
   the offer countdown timer and what happens if the app is backgrounded
   mid-offer (countdown/poller correctly stop in `onPause`, resume in `onResume`).
5. **Next real feature slice**: pickup/drop-off flow (§9-16) — this is
   what actually lets a rider complete a delivery past `rider_assigned`,
   and is the natural next thing since accept now works but nothing
   after it does yet.
