# Handover — Rider App "Orders Not Received" Bug Fix + Multi-Screen Shell (Started)

Session date: 06 Sep 2026. Owner report (v24): "rider app mein orders
receive nahi ho rahe hai" (rider app isn't receiving orders) + request
to give the rider app separate screens the way the restaurant app has
(bottom-nav tabs), instead of one flat dashboard.

Note: a separate small delta zip was also handed over this same
session covering Live Location customer-side re-verification (its own
doc, numbered 112 in that delta's own tree — a different topic, ETA
display). That delta was NOT part of this project archive and is not
merged here; whichever doc number lands second should be renumbered on
merge to avoid a collision.

## Part 1 — "Orders not received" bug: root cause found and fixed

### What was traced

Read the full dispatch chain end to end against its own kdoc before
touching anything:

- `backend/lib/dispatch.php` — `find_eligible_riders()`,
  `dispatch_next_candidate()`, `expire_stale_offers()`.
- `backend/api/v1/rider/orders-available.php`,
  `orders-accept.php`, `orders-current.php` — the rider-facing side of
  the same flow.
- `backend/api/v1/rider/location.php` — hot-cache write riders rely on
  for dispatch eligibility.
- `backend/lib/auth.php`, `backend/lib/settings.php` — nothing
  order-flow-adjacent found wrong here either.
- `RiderDashboardActivity.kt` (Android) — `pollDashboardState()`,
  `setOnlineStatus()`, `refreshFromServer()`, the two Handler-based
  pollers.

**All of the above backend logic is correct and was left unchanged.**
No dispatch bug, no auth bug, no settings/config bug found.

### The actual bug

`RiderDashboardActivity`'s own kdoc already documented this as a
known, deliberate v1 simplification, but it had never actually been
revisited: **both of the dashboard's pollers
(`dashboardPollRunnable`/`locationPollRunnable`) only run between
`onResume()` and `onPause()`.** The moment a rider backgrounds the
app — locks the screen, switches to another app to wait, anything
short of staring at the dashboard the whole time — `onPause()` cancels
both immediately. No further call to `/rider/orders-available` happens
until the rider manually reopens the app. A new offer can sit
dispatched server-side (with its own `rider_assignment_timeout_seconds`
— default 40s — already ticking) with literally nothing on the
Android side checking for it. From the rider's side this reads exactly
as "order aata hi nahi" even though the backend created the offer
correctly and on time.

This is the same class of bug the Restaurant app hit first — see
`restaurant/app/.../service/OrderPollingService.kt`'s own kdoc, "real
fix for alert should work even when the app is closed" — and the
Customer app already has its own equivalent
(`OrderUpdatePollingService`). The Rider app was the one app in this
project that never got that treatment; `RiderFirebaseMessagingService`
even calls this out directly in its own kdoc ("No polling service
exists in this app for account-status changes... unlike the customer
app's `OrderUpdatePollingService`").

### The fix

New: `rider/app/.../service/RiderOrderPollingService.kt` — a
foreground `Service`, independent of any Activity's lifecycle, ported
from the restaurant app's `OrderPollingService` pattern:

- Runs a 15s loop calling `/rider/orders-current` (skip alerting if an
  active delivery already exists — dispatch.php's own eligibility
  query already excludes a rider with one, so no offer would come back
  anyway) then `/rider/orders-available`.
- Fires a real status-bar alert (`RiderNotificationHelper.showOrderNotification`,
  existing `CHANNEL_ORDER`) the moment a **new** `assignment_id`
  appears — tracked in SharedPreferences so a re-poll of the same
  still-open offer doesn't re-alert, but a fresh offer (rejected/expired
  → re-dispatched to someone else, or a new one after delivery
  completes) always does.
- `startForeground()` + a new low-importance `CHANNEL_MONITORING`
  channel/notification (`RiderNotificationHelper.buildMonitoringNotification()`,
  id `MONITORING_NOTIFICATION_ID`) — same "quiet, ongoing, required by
  Android for any foreground service" shape as the restaurant app's
  own monitoring notification. `START_STICKY` so the OS restarts it
  after a low-memory kill.
- Stops itself if the rider is no longer logged in or no longer online
  (checked at the top of every loop iteration) — never polls for a
  logged-out or offline rider.

Wired into `RiderDashboardActivity`:
- **Start**: `setOnlineStatus()`'s "went online" success branch, and
  `refreshFromServer()`'s "already online" branch (covers the app
  being reopened while still online from a previous session — without
  this second call site, the service would only ever start from a
  fresh toggle-on, never from discovering an already-online state on
  load).
- **Stop**: `setOnlineStatus()`'s "went offline" branch, and the
  logout button handler.

`RiderDashboardActivity`'s own foreground pollers are **unchanged** —
still the fast in-app cadence while the screen is actually open. This
service is additive, covering only the gap when nothing else is
watching.

Manifest: added `FOREGROUND_SERVICE` + `FOREGROUND_SERVICE_DATA_SYNC`
permissions and registered the service with
`foregroundServiceType="dataSync"` (required API 34+), same shape as
the restaurant app's own manifest entry for `OrderPollingService`.

### Not done this session (re: Part 1)

- Not run against a live device/backend — same standing sandbox
  limitation as every other handover in this project. Brace/paren
  balance re-checked on all three edited/new files (all matched).
- Recommended first live check: go online on a real device, background
  the app (press Home, don't force-stop), have a restaurant mark an
  order `ready` for a nearby test order, confirm the status-bar
  "New delivery available" notification appears within one 15s poll
  cycle without reopening the app, and confirm tapping it opens the
  dashboard with the offer card already showing (same `order_offer`
  screen routing `RiderNotificationHelper` already had).
- Not tested: behavior under aggressive OEM battery-management (MIUI/
  ColorOS/etc.) — same caveat the restaurant app's own service kdoc
  already documents; there's no way to fully guarantee delivery
  without switching this to a real FCM-push-triggered check, which is
  a larger change than this session's scope.

## Part 2 — "Alag alag screens jaise restaurant app" (bottom-nav shell)

Investigated the restaurant app's shell as the reference pattern:
`MainActivity` + `BottomNavigationView` + four Fragments (Orders/Menu/
Insights/Account), replacing what used to be a single dashboard
Activity — see that class's own kdoc.

Planned equivalent for the rider app, **not yet built this session**:
`RiderMainActivity` + `BottomNavigationView` with four tabs —

- **Home** — the existing dashboard content (online toggle, offer/
  current-delivery card, pickup/deliver actions), ported from
  `RiderDashboardActivity` into a `HomeFragment`.
- **Earnings** — ported from the existing `EarningsActivity` into an
  `EarningsFragment` (today/balance figures, COD cash-held card,
  ledger list, Request Payout entry point).
- **Notifications** — ported from the existing `NotificationListActivity`
  into a `NotificationsFragment` (paginated bell list, mark-all-read).
- **Account** (new) — profile info from `/rider/me`, documents-status
  entry point (currently a header alert on the dashboard, would move
  here), and logout. Doesn't exist as a screen anywhere in this app
  yet — same gap the Rider Documents handover already flagged
  ("No Account/Profile hub screen exists in this app yet").

`RiderOrderDetailActivity`, `SubmitDocumentsActivity`, and
`RequestPayoutActivity` would stay separate Activities reached by
navigation from within the relevant tab's Fragment — same "detail
screens stay Activities, only the top-level sections become tabs"
split the restaurant app itself uses (`OrderDetailActivity` is not a
fifth tab there either).

This was deliberately **not started this session** — converting
~900 lines of `RiderDashboardActivity` logic into a Fragment, plus two
more screen ports plus one new screen, plus repointing every existing
`RiderDashboardActivity::class.java` reference
(`ApplicationStatusActivity`'s approved-redirect,
`RiderNotificationHelper.contentIntentFor()`,
`NotificationListActivity`'s own deep-link routing, the manifest) is a
large enough change that doing it in the same pass as a live-flow bug
fix — without a real device/Gradle build available in this sandbox to
catch a binding-ID or navigation mistake — risked breaking the one
thing that was just fixed. Splitting it into its own session/build-
verified pass is safer.

## Next step, owner's choice

- Verify Part 1's fix on a real device first (see "recommended first
  live check" above) — it's the one actually blocking riders from
  working right now.
- Then a dedicated session for Part 2 (the bottom-nav shell), built
  and ideally build-checked before merging, given its size.
