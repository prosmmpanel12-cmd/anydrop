# Handover — Rider Order Detail screen built (deep-plan §9), full stack: backend endpoint + Android screen + dashboard wiring

Session date: 06 Sep 2026, continuing from doc 105's "owner's choice"
list. Picked deep-plan §9 (Rider Order Detail), the option doc 105
flagged as still fully unbuilt. Same standing caveat as every prior
handover: nothing this session was compiled, run, or checked against a
live backend/Gradle/real device (no PHP CLI, no Gradle, no Android SDK
in this sandbox). Manual brace/paren-balance checks and XML well-
formedness checks were run on every touched/created file — see "What
was verified" below for exact results.

## What was verified before writing anything

- Confirmed no Order Detail screen or endpoint existed anywhere in the
  codebase — grepped for `OrderDetail`/`orderdetail` across `rider/`
  and `backend/api/v1/rider/`, found nothing. This is a fresh build,
  not a resume of partial work.
- Re-read deep-plan §9 in full for the exact field list: order code,
  restaurant name/address/location, customer name/address/location,
  item count, order total, payment method, COD amount, delivery
  instructions, estimated distance, current status, delivery-OTP
  *state* (never the OTP itself) — and its explicit caution: "Do not
  return unnecessary customer profile data."
- Read `orders-current.php` (Phase 3 R3, doc 85) in full — it already
  backs the dashboard's compact card with a *subset* of §9's fields
  (no restaurant lat/lng, no phones, no customer name, no distance, no
  cost breakdown). Decided this needed a new dedicated endpoint rather
  than extending orders-current.php, since that endpoint is polled
  every 5s by the dashboard (`DASHBOARD_POLL_INTERVAL_MS`) and bloating
  every poll response with detail-screen-only fields (phones,
  breakdown) would be wasteful — a rider looks at the detail screen
  occasionally, not every 5 seconds.
- Read `backend/lib/geo.php`'s `haversine_km()` in full and confirmed
  it's already the shared distance calc `delivery_pricing.php` and
  `dispatch.php` both use — reused directly, no new distance math
  invented.
- Checked `customers`/`restaurants` schema (`backend/sql/01_schema.sql`)
  to confirm exact column names before writing the query:
  `customers.mobile`, `customers.name`, `restaurants.owner_mobile`,
  `restaurants.latitude`/`longitude`. No migration needed — every
  column this endpoint reads already exists.
- Read `orders-pickup.php` in full for the rider-ownership/auth
  pattern (`require_auth('rider')`, `WHERE ... rider_id = :rider_id`)
  before writing the new endpoint's WHERE clause.
- Read `RiderDashboardActivity.kt` in full (all 868 lines) to find
  exactly where the current-order card renders
  (`renderCurrentOrder()`, lines ~530-556) and where its action buttons
  are wired in `onCreate()`, so the new "View Details" button could be
  added in the same place other buttons on this card are, not
  bolted on separately.
- Checked `EarningsActivity.kt`/`activity_earnings.xml` as the only
  existing "sub-screen reached from the dashboard" precedent in this
  app (confirmed: same conclusion doc 90's own kdoc already
  reached — no shared Toolbar/AppBar convention exists, just a
  plain back-arrow+title header row) — this screen's header matches
  that pattern exactly rather than inventing a third convention.
- Checked `ApplicationStatusActivity.kt` for the status-pill
  programmatic-tint pattern (`backgroundTintList = ColorStateList
  .valueOf(getColor(...))` per status) and reused it for this screen's
  own status pill.
- Grepped all three apps (`rider/`, `restaurant/`, `customer/`) for any
  existing `ACTION_DIAL`/`ACTION_CALL`/`geo:`/`google.navigation`
  usage — found none. Navigate/Call are genuinely new plumbing in this
  codebase, not copy-adapted from a working screen, same caveat class
  as doc 66's FCM work or doc 65's FileProvider work.
- Confirmed `colors.xml` already has `ic_phone.xml`/`ic_location.xml`
  drawables; no existing "directions/navigate" icon, so a new
  `ic_navigate.xml` was added (simple arrow-in-box glyph, matches the
  existing vector-icon style/size of the other two).

## What was built this session

### `backend/api/v1/rider/orders-detail.php` (new)
`GET /api/v1/rider/orders-detail.php?id={order_id}`, rider-auth,
same active-delivery scope and ownership rule as `orders-current.php`
(`o.rider_id = this rider AND status IN
('rider_assigned','picked_up','out_for_delivery')`) but returns the
fuller §9 field set: `restaurant_lat`/`restaurant_lng`/
`restaurant_phone` (from `restaurants.owner_mobile`), `customer_name`/
`customer_phone` (from `customers.name`/`mobile`), `delivery_address`/
`delivery_lat`/`delivery_lng` (same `customer_addresses` join
`orders-current.php` already uses), `item_total`/`delivery_charge`/
`grand_total` as separate fields (not just `grand_total` alone),
`cod_amount` (explicit `grand_total` copy when `payment_method =
'cod'`, else `null` — a separate field rather than making Android
re-derive it), and `distance_km` (restaurant → delivery address, via
`haversine_km()`, rounded to 1 decimal, `null` if either side is
missing lat/lng). `delivery_otp` itself is never returned — only
`delivery_otp_required` — exactly matching `orders-current.php`'s own
convention. Per §9's "do not return unnecessary customer profile
data" line: only `customers.name`/`mobile` are read, nothing else on
that table (no email, no login_type, no profile_photo). A miss (wrong
rider, wrong status, doesn't exist) returns a plain `404 not_found` —
this is a read endpoint with no state to protect, so no need for
`orders-pickup.php`'s richer `invalid_state` contract.

### `rider/app/.../network/Models.kt` (edited)
New `OrderDetailResult`/`OrderDetail` data classes, deliberately
separate from `CurrentOrder` above them (not an extension) — matches
the new endpoint's response shape 1:1 rather than forcing one bloated
model to serve both the dashboard card and this screen.

### `rider/app/.../network/ApiService.kt` (edited)
New `getOrderDetail(orderId: Int)` → `GET rider/orders-detail.php`,
added directly below `getCurrentOrder()`.

### `rider/app/.../ui/orderdetail/RiderOrderDetailActivity.kt` (new)
New `ui/orderdetail/` package. Loads via `getOrderDetail()` on
`onCreate` (expects `EXTRA_ORDER_ID` intent extra) and again on
`onResume()` (same staleness reasoning `EarningsActivity.onResume()`
already documents — a rider could background this screen, advance the
order from a push-notification tap, then return here). Renders two
address cards (pickup/restaurant, deliver/customer) each with its own
Navigate + Call button row, a delivery-instructions card (hidden when
none), and a bill-summary card (item total / delivery charge / grand
total, plus a highlighted COD-to-collect row or a "Paid online" line
depending on `payment_method`). Call uses `ACTION_DIAL` (dialer
pre-filled, no `CALL_PHONE` permission needed — deliberate, see kdoc).
Navigate tries a `google.navigation:q=lat,lng` intent targeted at
`com.google.android.apps.maps` first, falls back to a plain `geo:`
query (device's own app chooser) if Maps isn't installed. Both
gracefully toast-and-no-op if the relevant phone/location field is
null rather than crashing on a missing value.

### `rider/app/.../res/drawable/ic_navigate.xml` (new)
Simple directions-arrow vector icon, matches `ic_phone.xml`/
`ic_location.xml`'s existing style (24dp, `?attr/colorControlNormal`
tint).

### `rider/app/.../res/layout/activity_order_detail.xml` (new)
Header (back arrow + title + status pill) matching
`activity_earnings.xml`'s established shape, then a scrollable body:
order-code/item-count meta line, pickup card, drop card, instructions
card (visibility-gone by default), bill-summary card.

### `rider/app/.../res/layout/activity_rider_dashboard.xml` (edited)
Added a new outlined "View Details" `MaterialButton`
(`btnViewOrderDetail`) inside `currentOrderCard`, positioned above the
existing `btnMarkPickedUp`/`btnMarkDelivered` pair (always visible
while the card is, unlike those two which are status-conditional) so
"see everything" and "advance the delivery" read as two distinct
actions rather than stacked options.

### `rider/app/.../ui/dashboard/RiderDashboardActivity.kt` (edited)
New `btnViewOrderDetail` click listener (added just above the existing
`btnMarkPickedUp` listener) launches `RiderOrderDetailActivity` with
`activeOrder.id` as `EXTRA_ORDER_ID`. New import added.

### `rider/app/.../AndroidManifest.xml` (edited)
New `RiderOrderDetailActivity` entry (`exported="false"`), placed
after `SubmitDocumentsActivity`, with a comment noting its one entry
point (the dashboard card's new button) and its required intent
extra.

### `rider/app/.../res/values/strings.xml` (edited)
New strings block for the screen: title, section headings, button
labels, content-description strings for the four icon buttons,
distance/item-count format strings, and three toast-message strings
(load-failed, no-phone, no-location) plus the dashboard's new
`dashboard_view_details` button label.

## Deliberately not built this session

- **Live map / route preview on the detail screen** — deep-plan §9
  lists only the data fields, not a map view; §12-15 (live location
  system) is a separate unbuilt section. Adding a map here would be
  scope creep on §9 specifically, and this app doesn't have a maps SDK
  wired into the rider module yet (the Google Maps intent used for
  Navigate is a hand-off to the external Maps app, not an in-app map
  view — no new SDK dependency was added).
- **A shared/reusable order-card component** — the dashboard's compact
  card (`renderCurrentOrder()`) and this new detail screen's rendering
  (`render()`) both format similar fields (status pill, order code,
  payment badge) independently rather than sharing a helper. Judged
  not worth the abstraction for two call sites with genuinely
  different layouts; can be revisited if a third screen needs the same
  formatting.
- **Distance/ETA to the *customer* from the restaurant leg the rider is
  currently on** (i.e. a live, rider-position-aware distance) — the
  `distance_km` this endpoint returns is a static restaurant→customer
  distance (same haversine calc dispatch.php already uses for offer
  distance), not a live rider→destination distance. A rider-position-
  aware version would need the rider's current lat/lng as an input,
  which this read-only detail endpoint doesn't currently take — could
  be added later by reusing `riders.last_lat/lng` if wanted.

## Still open

- **Nothing this session was compiled or device-tested.** Manual
  brace/paren-balance checks: `Models.kt`, `ApiService.kt`,
  `RiderOrderDetailActivity.kt`, `RiderDashboardActivity.kt` all
  balanced clean. XML well-formedness checks (Python's
  `xml.etree.ElementTree`): `activity_order_detail.xml`,
  `activity_rider_dashboard.xml`, `AndroidManifest.xml`,
  `strings.xml`, `ic_navigate.xml` all parsed clean. `orders-detail.php`
  balance-checked (braces/parens/brackets) since no PHP CLI is
  available in this sandbox — no `php -l` run.
- **Recommended first live check, backend**: call
  `orders-detail.php?id={id}` for a rider's own active order and
  confirm the full field set matches what the Android model expects
  (field names are a straight 1:1 match against `OrderDetail`'s
  `@SerializedName` annotations, but this has never round-tripped
  through a real HTTP call); also confirm the 404 path for someone
  else's order.
- **Recommended first live check, Android**: once a Gradle build is
  possible, tap "View Details" from a real active delivery and confirm
  (a) both cards render with real data, (b) Navigate actually launches
  Google Maps turn-by-turn (this device likely has Maps installed, so
  the `geo:` fallback path specifically has never been exercised),
  (c) Call opens the dialer pre-filled with the right number, (d) the
  COD/paid-online bill rows toggle correctly for both payment methods.
- Everything doc 105 already carried over unchanged and untouched this
  session: `php -l` still never run anywhere in this project,
  migrations 75/76 still never run, PENDING.md/recall.md still not
  re-audited (flagged stale since doc 97), COD settlement warning
  still uninvestigated, the two deliberately-unbuilt §23 sub-events
  ("Restaurant ready", "Customer cancellation") unchanged.

## Next step, owner's choice

Deep-plan §9 is now built end-to-end (backend + Android + dashboard
entry point) but entirely unverified. Same fork doc 105 left open:
a real Gradle build + device test (now covers both the notifications
work from doc 105 and this session's Order Detail screen together —
arguably the more valuable checkpoint now that two sessions' worth of
untested Android work has stacked up), or continuing to a different
still-unbuilt deep-plan section (Live Location System §12-15 Android
customer-side pieces, Delivery OTP §16 — mostly built already via the
pickup/deliver flow, worth double-checking against §16's full text —,
COD Settlement Limit UI §18, Admin Rider Command Center §25).

## Addendum (same day, immediately after) — address detail fields added

Person pointed out the first version of this screen showed only the
single concatenated `full_address` string for the delivery address —
no house/flat number, floor, landmark, receiver name/phone, or door
photo. Checked and confirmed all of these already exist as columns on
`customer_addresses` (migration 06 added `house_flat_no`/`floor`/
`landmark`/`receiver_name`/`receiver_phone`; migration 16 added
`photo_url` for H6's door-photo feature) — the customer app's own
`addresses.php` already reads/writes all six. Neither
`orders-current.php` nor this session's first `orders-detail.php`
version ever selected them; this was a real gap, not a deliberate
scope cut.

Fixed same-session:

- **`backend/api/v1/rider/orders-detail.php`** — query now also selects
  `house_flat_no`/`floor`/`landmark`/`receiver_name`/`receiver_phone`/
  `photo_url` (returned as `address_photo_url` on the wire, to avoid
  future confusion with a restaurant/customer profile photo). Still
  respects §9's "no unnecessary customer profile data" line — only
  `customers.name`/`mobile` are read off the customers table itself;
  everything new here comes from `customer_addresses`, which exists
  specifically to hold delivery-relevant detail (`receiver_name`/
  `receiver_phone` were built for exactly the "address belongs to
  someone other than the account holder" case).
- **`rider/.../network/Models.kt`** — `OrderDetail` extended with the
  6 new fields (`houseFlatNo`/`floor`/`landmark`/`receiverName`/
  `receiverPhone`/`addressPhotoUrl`).
- **`rider/.../network/ApiClient.kt`** — new `baseUrlForStaticFiles()`,
  matching the customer app's own helper of the same name exactly
  (strips the `api/v1/` suffix off `BASE_URL`) — needed because the
  backend returns `address_photo_url` as a relative path, same
  convention every other image field in this codebase already follows.
- **`rider/app/build.gradle`** — added Coil 2.6.0 (same version the
  customer app uses) — **this is the rider app's first-ever remote-
  image load of any kind**, genuinely new plumbing, not copy-adapted
  from a working screen in this app (the customer app's own Coil usage
  was the pattern copied from, not anything already in `rider/`).
- **`activity_order_detail.xml`** — drop card gained 4 new optional
  text lines (house/floor/landmark/receiver, each independently
  `visibility="gone"` when its field is null — a partially-filled
  address doesn't leave an awkward blank line) and a 96dp photo
  thumbnail (also gone-by-default; no tap-to-zoom this slice, it's a
  "confirm the right door" aid, not a viewer).
- **`RiderOrderDetailActivity.kt`** — new private `renderOptionalLine()`
  helper used for all 4 text lines; photo loaded via Coil's `.load()`
  when `addressPhotoUrl` is non-blank. Also changed Call-customer to
  prefer `receiverPhone` over `customerPhone` when a receiver is on
  file — the rider should call whoever's actually listed as receiving
  the order at that address, not necessarily the account holder.
- **`strings.xml`** — 5 new strings (4 format strings + 1 content
  description for the photo).

All 4 touched/created files in this addendum balance/XML-checked clean
(see exact counts in this session's own verification pass). Still
unverified for the same standing reason as everything else in this
project: no PHP CLI/Gradle/Android SDK in this sandbox — the
`geo:`/`google.navigation` fallback path, the receiver-phone-preference
logic, and this app's first-ever Coil image load are all genuinely
untested code paths, not just untested UI wiring.

