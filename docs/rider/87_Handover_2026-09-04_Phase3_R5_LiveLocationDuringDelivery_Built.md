# Rider App — Phase 3 (R5): Live Location Tracking During Delivery

Session date: 04 Sep 2026 (continuation — next slice after doc 86)

Implements deep-plan §12-13 (customer-facing side, §14-15, was already
served for free by the existing hot-read cache — see below).

## Bug found and fixed first (pre-existing, from the R4 session)

Before starting this slice, cross-checked `RiderDashboardActivity.kt`
against `Models.kt` one more time and found `CurrentOrder` has no
`orderId` field — its id field is just `id`. Three call sites used
`order.orderId`/`activeOrder?.orderId` (the pickup handler, the deliver
handler, and this session's new location-ping code) — would not have
compiled. Fixed all three to `order.id` / `activeOrder?.id`.
`Offer.orderId` (used in `acceptOffer`/`rejectOffer`) is a different,
correctly-named field on a different class — left untouched.

## ✅ Done this session

**Backend**
- `backend/api/v1/rider/location.php` — extended in place (not a new
  endpoint) to accept optional `order_id`/`speed_kmh`. When `order_id`
  is present AND checks out (belongs to this rider, order status is
  `rider_assigned`/`picked_up`/`out_for_delivery`), also inserts into
  `rider_locations` — the audit table location.php's own kdoc had
  flagged since Phase 3 R2 as "a natural extension once R3 has an
  active-delivery concept". A bad/foreign/stale `order_id` is a silent
  no-op on the `rider_locations` half only — the `riders.last_lat/lng`
  cache update still always happens, matching deep-plan §13's "never
  trust the client" instruction without turning ordinary best-effort
  telemetry into a hard error.
- No new migration — `rider_locations` (rider_id, order_id nullable,
  latitude, longitude, speed_kmh nullable, recorded_at) already existed
  in the base schema (`01_schema.sql`), just never written to until now.
- Customer-facing `orders/track.php` needed **no changes** — it already
  reads `riders.last_lat/last_lng` (the same hot cache this endpoint has
  always written), so it starts reflecting live in-delivery positions
  automatically the moment the Android side below starts sending
  `order_id` on its pings. This is deep-plan §14's "hot-read cache, not
  the history table" design already in place from Phase 3 R2.

**Android**
- `Models.kt` — `LocationBody` gained optional `orderId`/`speedKmh`
  (both null by default, so the one-off ping in
  `sendLocationThenGoOnline()` — sent before any order exists — is
  unaffected and still compiles as a 2-arg call).
- `RiderDashboardActivity.kt`:
  - `locationPollRunnable` now reads `activeOrder` each cycle and
    self-adjusts its own repeat interval: 7s while `activeOrder != null`,
    30s otherwise (unchanged default). Deliberately a two-tier
    simplification of deep-plan §12's full 5-tier table (60s/10s/
    5-7s/20s/3-5s by more granular leg-of-trip state) — the full table
    needs `app_settings`-driven intervals per that section's own
    "should become Admin-configurable" note, which is future work, not
    this slice.
  - `sendLocationPingInternal()` now reads `activeOrder?.id` once up
    front (before the async location fetch starts, to avoid a race if
    the order completes mid-fetch) and passes it as `order_id`, plus
    `location.speed` (m/s, converted ×3.6 to km/h) as `speed_kmh` when
    the platform `Location` object has one.

## Not built this session (explicitly out of scope, per deep-plan)

- The full 5-tier interval table and its `app_settings` keys.
- A foreground `Service`/notification for location while backgrounded —
  deep-plan explicitly deferred this in R2's own doc (83) and nothing
  here changes that; the poller is still `Handler.postDelayed`, stops in
  `onPause()` exactly as before.
- Marker interpolation / route recalculation on the customer app
  (deep-plan §14 "Marker animation", §15's 30-45s route-recalc cadence)
  — that's customer-app Android work, not touched this session. **Built
  in the very next session — see doc 88.**
- Admin live map (deep-plan §25) — separate, later slice.

## Not tested against a live backend / device

Same standing caveat as every prior session. `location.php` was
manually brace/paren-balance checked (8/8 braces, 52/52 parens — no PHP
interpreter available). The Kotlin file was manually cross-referenced
field-by-field against `Models.kt`/`ApiService.kt` (139/139 braces,
350/350 parens) — this is what caught the `orderId`→`id` bug above
before it reached a real build.

## Still open — next steps, in order (as of doc 87; see doc 88 for what
## was actually picked up)

1. **Real Gradle build + php -l** — genuinely overdue at this point
   across R4+R5 both; recommend doing this before any further Android
   work on this screen, given two real compile-breaking mismatches have
   now been caught by hand-review in a row. **Still not done as of doc
   88 either — no PHP/Gradle/network available in the coding sandbox
   used for either session. This needs to happen on a real machine/CI
   before either R5's or R5-follow-up's Android/PHP changes ship.**
2. **Smoke test the tracking loop**: go online → accept an offer → mark
   picked up → confirm the poll interval visibly tightens (watch
   `rider_locations` rows arrive every ~7s instead of ~30s) → open the
   order on the customer app's tracking screen and confirm the rider
   marker/position reflects `last_lat/last_lng` moving.
3. **Confirm the silent-no-op path**: send a ping with a stale/foreign
   `order_id` (e.g. right after delivering) and confirm
   `riders.last_lat/lng` still updates but no new `rider_locations` row
   appears for that order.
4. **Next real feature slice**: customer-app marker interpolation +
   route recalculation (deep-plan §14-15) — **person chose this one,
   see doc 88** — or Rider Earnings (deep-plan §19, next major unbuilt
   phase per doc 86's own roadmap), still open for whichever session
   comes after doc 88.
