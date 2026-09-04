# Customer App — Live Tracking Map: Marker Interpolation + Route Recalc

Session date: 04 Sep 2026 (continuation — next slice after doc 87)

Implements deep-plan §14-15 (person explicitly picked this over Rider
Earnings §19 when asked at the end of doc 87's own "still open" list).

This is customer-app work — the rider-side half of live tracking
(location pings, `rider_locations` audit table) was already finished in
doc 87; this session is purely "now draw it on a map."

## ✅ Done this session

**Backend**
- `backend/api/v1/orders/track.php` — extended (not replaced) to also
  return `restaurant: {name, lat, lng}` and `delivery: {lat, lng}`,
  joined from `restaurants` and `customer_addresses` respectively. Both
  are static per order, unlike `rider`, which still changes every poll
  — Android only needs to read these two once. `rider` itself is
  unchanged; this endpoint already read `riders.last_lat/last_lng`
  before this session (doc 87's own note that track.php "needed no
  changes" for the *rider* half still holds — this session only adds
  the two new static fields alongside it).
- New `backend/api/v1/orders/route.php` — GET endpoint, customer auth.
  Given the order's current status, picks a "leg":
  - `rider_assigned` → route from rider's last known position to the
    **restaurant** (`to_restaurant`)
  - `picked_up`/`out_for_delivery` → route from rider to the
    **delivery address** (`to_customer`)
  - anything else → no route, responds with everything `null`, not an
    error (same "absence is normal" convention `track.php`'s own
    `rider: null` already uses).

  Calls Google's Directions API server-side via hand-rolled curl —
  same convention `backend/lib/payment/PaytmStatusClient.php` and
  `backend/lib/fcm.php` already established for this codebase (no
  Composer/vendor dir exists, and no network access in the dev sandbox
  to add one). The key is read via `get_setting('google_directions_api_key', '')`
  (`lib/settings.php`, same DB-backed-config convention `fcm.php` uses
  for its service-account JSON) — **not** a `config.php` define.

  **No admin UI field wired up for this key yet.** `app-settings.php`'s
  `$fields` array is per-app-suffixed (`{key}_customer`/`_restaurant`/
  `_rider`), which doesn't fit a single shared platform-wide key
  cleanly, so extending that array wasn't the right move for this one
  setting. For now: set it directly, e.g.
  `set_setting('google_directions_api_key', '<real key>');` from a
  one-off script, or `INSERT INTO app_settings (\`key\`, \`value\`)
  VALUES ('google_directions_api_key', '<real key>')`. A small
  dedicated admin field (or a generic "Integrations" tab) is a
  reasonable fast-follow, not done this session.

  Missing key, failed curl call, `ZERO_RESULTS`/`REQUEST_DENIED`/etc.
  from Google, and no rider/destination coordinates yet all degrade to
  the same `{polyline: null, distance_km: null, duration_minutes: null,
  leg: <leg or null>}` response — never a hard error. The Android side
  treats a null polyline as "nothing to draw," not a failure.

**Android (customer app)**
- `Models.kt` — added `TrackRestaurant`, `TrackDelivery`; extended
  `OrderTrackResult` with `restaurant`/`delivery` fields; added
  `RouteResult` (`polyline`/`distanceKm`/`durationMinutes`/`leg`).
- `ApiService.kt` — added `getOrderRoute(orderId)` →
  `GET orders/route.php`.
- New `util/PolylineDecoder.kt` — hand-decodes Google's
  `overview_polyline.points` encoded-polyline format into
  `List<LatLng>`. Deliberately not pulling in the
  `play-services-maps-utils` dependency (which has this built in as
  `PolyUtil.decode()`) for one ~20-line, decades-old, fully-specified
  algorithm — same "don't add a dependency for one small well-known
  thing" judgment call as elsewhere in this codebase.
- `res/layout/activity_order_status.xml` — added a `MapView`
  (`trackingMapView`, 200dp fixed height, `gone` by default).
  **Flagged, not fixed:** this screen has no `ScrollView` anywhere
  (pre-existing, not introduced this session) — riderCard + this map +
  otpCard + refundCard all visible at once on a short screen could in
  principle run past the visible area. Worth revisiting if it actually
  shows up on a real device; deliberately not wrapping the whole
  screen in a ScrollView as an unasked-for side change in this same
  session.
- `OrderStatusActivity.kt`:
  - Implements `OnMapReadyCallback`; full `MapView` lifecycle
    forwarding (`onCreate`/`onStart`/`onResume`/`onPause`/`onStop`/
    `onSaveInstanceState`/`onLowMemory`/`onDestroy`) — same pattern
    `MapPinDropActivity` already established.
  - Map only shows while `track.status` is in `{rider_assigned,
    picked_up, out_for_delivery}` **and** the rider has a live lat/lng
    — this exact status set is shared with `route.php`'s leg-selection
    set and `rider/location.php`'s own active-delivery set (doc 87),
    kept as one named constant (`MAP_ACTIVE_STATUSES`) rather than
    three independently-maintained lists.
  - Restaurant/delivery markers added once, on first availability
    (static per order — no reason to re-add every 5s poll).
  - Rider marker: on the first sighting, just placed; on every
    subsequent 5s `track.php` poll, **animated** from its last position
    to the new one via `ValueAnimator` over ~`POLL_INTERVAL_MS` (linear
    lerp on lat/lng — accurate enough over one 5s hop at delivery
    speeds, not a great-circle interpolation). This is deep-plan §14's
    "Android interpolates marker between A and B."
  - Separate, independent loop (`startRouteRecalcLoop()`) re-fetches
    `route.php` and redraws the polyline + refits camera bounds every
    35s (inside the plan's 30-45s target), rather than tying it to the
    5s marker-update loop — kept as two independently-tunable cadences
    on purpose. Camera bounds are **not** refit on every 5s marker
    update (would fight the marker animation and feel jumpy); only on
    the map's first appearance and on each route-recalc cycle. Between
    refits the rider marker can drift toward/off the visible edge — a
    manual "recenter" button would be the natural fix, not built this
    session.
  - `lastTrack` field added so `onMapReady()` (async, can fire before
    or after the first `track.php` poll lands) can immediately draw
    whichever state already exists instead of waiting up to
    `POLL_INTERVAL_MS` for the next poll — no ordering assumption
    between the two async events.

## Not built this session (explicitly out of scope)

- Admin UI field for `google_directions_api_key` (see above — set
  directly via `set_setting()`/SQL for now).
- "Significant route deviation" detection — deep-plan §15 mentions this
  as an *alternative* trigger to the 30-45s timer; only the timer-based
  recalc is built. Detecting deviation would need comparing the rider's
  live position against the currently-drawn route's polyline, which is
  meaningfully more work and wasn't the minimum needed for this slice.
- A "recenter map" button (see camera-drift note above).
- Any ScrollView fix for `activity_order_status.xml` (flagged, not
  touched — pre-existing risk, not introduced this session).
- Admin live map (deep-plan §25) — unrelated, later slice.

## Not tested against a live backend / device

Same standing caveat as every prior session — **no PHP interpreter,
Gradle, or network access in this coding sandbox** (confirmed this
session: `php -v` → not found; `apt-get install php-cli` → blocked,
403 on every package, sandbox has no outbound network). Verification
done instead:
- `track.php`: brace/paren balance 13/13, 51/51 (was 13/13, 51/51
  before this session's edit too — recount confirms the edit didn't
  break anything already balanced).
- `route.php` (new file): brace/paren balance 17/17, 86/86. Manually
  traced every `respond_ok`/`respond_error` call against
  `lib/response.php`'s `respond()` — confirmed it calls `exit`, so
  there's no fallthrough risk from the multiple early-return branches
  in this file.
- `customer_addresses`/`restaurants` column names (`latitude`,
  `longitude`, `full_address`, etc.) checked directly against
  `01_schema.sql` rather than assumed.
- `OrderStatusActivity.kt`: brace/paren balance 102/102, 310/310.
  Manually traced: `LatLng`/`Marker`/`Polyline` imports all exist in
  `play-services-maps:19.1.0` (already a dependency, used by
  `MapPinDropActivity`); `ValueAnimator.ofFloat(...).animatedValue as
  Float` cast is the standard pattern; `PolylineDecoder.decode()`
  returns non-null `List<LatLng>` matching how it's consumed;
  `getColorCompat()` (used by `fetchAndDrawRoute()`) already existed
  in this file from the refund-card code, not newly introduced.

## Still open — next steps, in order

1. **Real Gradle build + `php -l`** — now three sessions in a row
   (doc 86/R4, doc 87/R5, this one) where this has been flagged as
   overdue and still not done, for the same reason each time (no
   PHP/Gradle/network in the sandbox). Strongly recommend this happens
   on a real machine before shipping any of R4/R5/this session's
   changes — the risk compounds the longer multiple unverified
   sessions stack on each other.
2. **Get a real Google Directions API key with billing enabled**,
   set it via `set_setting('google_directions_api_key', ...)`, and
   confirm `route.php` actually returns a usable `overview_polyline`
   for a real rider/restaurant/delivery-address combination — this
   endpoint has never been called against the real Google API, only
   manually reasoned about against their documented response shape.
3. **Smoke test the map screen end-to-end**: place an order → get it
   to `rider_assigned` → confirm the map appears with restaurant +
   rider markers → mark picked up → confirm the route redraws toward
   the delivery address within ~35s and the rider marker keeps
   animating smoothly off the 5s poll → deliver → confirm the map
   disappears (status leaves `MAP_ACTIVE_STATUSES`).
4. **Next real feature slice** — worth confirming with the person
   before starting: Rider Earnings (deep-plan §19, still the next
   unbuilt major phase per doc 86's roadmap), the admin field for
   `google_directions_api_key`, or something else.
