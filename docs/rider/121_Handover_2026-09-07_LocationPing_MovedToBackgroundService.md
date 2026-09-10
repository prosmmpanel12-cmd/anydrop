# Handover — 2026-09-07 — Rider Location Pings Now Survive Backgrounding (moved into RiderOrderPollingService)

**The gap:** identified while auditing what's genuinely still open in
the Rider App (as opposed to the stale `recall.md`/`PENDING.md`/
`Rider_Deep_Plan.md` trackers, none of which reflect this session's or
recent sessions' actual state). `HomeFragment`'s periodic location ping
was still exactly the bug class doc 112 already fixed for order-offer
polling: a `Handler`/`Runnable` loop tied to `onResume()`/`onPause()`.
The instant a rider locked their screen or switched apps mid-delivery —
completely normal between-tasks behavior — `onPause()` killed the
location loop immediately. The rider's position on the admin's Live
Rider Map and the customer's delivery-tracking screen would freeze at
whatever it was the moment the screen turned off, with nothing updating
again until the rider manually reopened the app. Order-offer polling
already survives this exact scenario (doc 112); location pinging never
got the same treatment until now.

## The fix

**Moved the periodic location ping into `RiderOrderPollingService`** —
the foreground `Service` doc 112 already built for order-offer polling,
independent of any Activity/Fragment lifecycle. It now runs a SECOND,
separate coroutine loop alongside the existing order-poll loop:

- Same adaptive cadence as before: 30s while idle, 7s while an active
  delivery is in progress (`LOCATION_POLL_INTERVAL_MS`/
  `LOCATION_POLL_INTERVAL_ACTIVE_MS`, values unchanged, just relocated).
- Kept as a genuinely separate loop/job from `pollForOffer()`'s 15s
  fixed cadence rather than merged into one — merging them would mean
  either polling orders too slowly or pinging location too often.
- The two loops share one field (`activeOrderIdForLocation`) instead of
  each independently calling `/rider/orders-current` on its own
  schedule — `pollForOffer()`'s existing call every 15s already tells
  the location loop whether there's an active order (for its interval
  choice) and what its id is (for `LocationBody.orderId`), so the
  location loop just reads that rather than re-fetching.
- Same `FusedLocationProviderClient.getCurrentLocation()` call and
  `POST /rider/location` request shape as before — genuinely just
  relocated, not redesigned. The one implementation difference: this
  runs inside a plain suspend function in the service's own coroutine
  scope rather than a Fragment's `lifecycleScope`, so the callback-based
  Play Services `Task` is bridged via `suspendCancellableCoroutine`
  (this module has no `kotlinx-coroutines-play-services` dependency for
  `.await()`, and adding one just for this wasn't worth it).

**`HomeFragment` keeps only its one-shot `sendLocationThenGoOnline()`**
(needed synchronously before the "go online" API call can succeed) —
the periodic `locationPoller`/`locationPollRunnable` Handler loop,
`sendLocationPing()`/`sendLocationPingInternal()`, and the two interval
constants are all removed from that class entirely. Running both the
service's loop and a duplicate foreground-only one in the fragment at
the same time would just double the GPS reads and API calls with zero
freshness benefit — unlike order-offer polling, where a faster in-focus
cadence genuinely improves the UX of seeing a new offer appear sooner.

## Manifest + notification changes this required

- **New permission:** `FOREGROUND_SERVICE_LOCATION` — required
  alongside the existing `FOREGROUND_SERVICE_DATA_SYNC` on API 34+ for
  a foreground service that does both jobs now.
- **`RiderOrderPollingService`'s `android:foregroundServiceType`**
  widened from `"dataSync"` to `"dataSync|location"`.
- **The persistent "online" notification text updated** —
  "Watching for new delivery offers" → "Watching for delivery offers
  and sharing your location". A location-type foreground service's
  notification should honestly say so, both for the rider's own
  transparency about being tracked while online, and because Google
  Play's foreground-service policy expects the notification to reflect
  what's actually running, not just half of it.

## Not done / caveats

- **No live device test** — same standing sandbox limitation as every
  session in this project (no Android SDK/Gradle/device here).
  Verified via brace/paren balance on all three touched Kotlin files
  and manifest well-formedness; the `suspendCancellableCoroutine`
  bridge pattern is standard but wasn't Gradle-compiled to confirm.
- There's a small, accepted staleness window: `activeOrderIdForLocation`
  only updates on `pollForOffer()`'s 15s cadence, so right after a
  rider accepts a new order, the location loop can stay on the slower
  30s interval for up to ~15s before switching to the faster 7s one.
  Not worth cross-signaling between the accept-flow and this service
  just to close a 15-second window — matches this codebase's general
  "next poll cycle catches up" tolerance elsewhere.
- This is the one gap flagged from this session's own "what's actually
  still open" audit — the older `PENDING.md` item 37 (Wallet
  Withdrawal, code-complete, needs live testing) remains separately
  open and untouched by this session.
