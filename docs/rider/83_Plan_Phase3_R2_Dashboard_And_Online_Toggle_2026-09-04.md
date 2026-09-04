# Rider App — Phase 3 Kickoff: R2 Dashboard + Online Toggle
Date: 04 Sep 2026

> This is a **plan**, not a handover — nothing in this doc is built yet.
> Scope is deliberately cut down from the full `Rider_Deep_Plan.md` (R2+R3)
> to one buildable slice: turn the approved rider's landing screen into a
> real dashboard with an online/offline switch. The R3 assignment engine
> (offer/accept/reject, dispatch scoring, timeouts) is a separate, larger
> next step and is NOT in this slice — see "Explicitly out of scope" below.

## Why this slice first

`ApplicationStatusActivity` is currently the only screen an approved
rider ever sees — there's no dashboard, no way to go online, and no
order data flows to the app yet. Building the assignment engine (R3)
before a rider can even go online has nothing to attach to. This slice
makes "approved rider opens the app" end in a real home screen instead
of a static status card.

## What ships in this slice

### Backend

| File | Change |
|---|---|
| `backend/api/v1/rider/status.php` (new) | `POST` — body `{ "online": true\|false }`. Requires `require_auth('rider')` + `status === 'approved'` (pending/rejected/suspended riders get `403 not_approved`). Writes `riders.is_online`. Going online also requires a fresh `last_lat`/`last_lng` (see next row) — no location, no online. |
| `backend/api/v1/rider/location.php` (new) | `POST` — body `{ "lat": ..., "lng": ... }`. Updates `riders.last_lat/last_lng/last_location_at`. Called on app foreground + periodically while online (interval TBD, start with 30s). Does **not** write `rider_locations` (that audit table is for active-delivery tracking, later slice) — this is just "is this rider's online status still meaningful" freshness data. |
| `backend/api/v1/rider/me.php` (extend, don't rebuild) | Add `is_online`, `vehicle_type`, `vehicle_number` to the existing response. Already-shipped endpoint, additive change only. |

No new migration needed — `is_online`, `last_lat`, `last_lng`,
`last_location_at`, `vehicle_type`, `vehicle_number` all already exist
on `riders` from `01_schema.sql`.

### Android

| File | Change |
|---|---|
| `ui/dashboard/RiderDashboardActivity.kt` (new) | Replaces `ApplicationStatusActivity` as the landing screen **only when `status == "approved"`** (pending/rejected/suspended still land on the existing status screen — no change there). Shows: online/offline switch, "no active delivery" placeholder card, today's earnings placeholder (static 0 until R2.4/settlement wiring — see below), rider name/vehicle. |
| `SplashActivity.kt` | Routing update: `approved` → `RiderDashboardActivity`, everything else → `ApplicationStatusActivity` (unchanged). |
| `ApiService.kt` | Add `setOnlineStatus(body: OnlineStatusBody)`, `updateLocation(body: LocationBody)`. |
| `Models.kt` | Add `OnlineStatusBody`, `LocationBody`. Extend `RiderMeProfile` with the 3 new fields from `me.php`. |
| Location permission flow | Reuse the existing GPS permission code from `SignupActivity` (already handles the runtime permission request) rather than writing a second copy. |

### Explicitly out of scope for this slice

- Order offers / accept-reject (R3) — dashboard's "current delivery"
  card stays a static placeholder until R3 exists.
- Earnings numbers — placeholder `₹0` / `0 deliveries` until the
  settlement/ledger read is wired (separate small slice once R3 lands,
  since earnings only mean something after deliveries happen).
- Background location service / foreground service notification —
  first pass is foreground-only polling (app open + on-screen). A
  proper background tracking service is deferred to when R3 needs live
  rider-position-during-delivery, not needed just to flip online/offline.
- `rider_order_assignments` table, dispatch scoring, offer timeouts —
  all of R3, unchanged from `Rider_Deep_Plan.md` section 4-8.

## Order of work, next session

1. `status.php` + `location.php` (backend) — small, testable independently with Postman/curl before touching Android.
2. Extend `me.php` response (3 fields).
3. `RiderDashboardActivity` UI (layout + online switch, wired to the two new endpoints).
4. `SplashActivity` routing split.
5. Smoke test: approved rider → dashboard → toggle online → confirm `riders.is_online` flips in DB → toggle offline.

## Open question for app owner before starting

Location update frequency while online (affects battery + data usage) —
30s foreground polling is a reasonable default but worth confirming
before building it in, since changing it later just means changing one
constant.
