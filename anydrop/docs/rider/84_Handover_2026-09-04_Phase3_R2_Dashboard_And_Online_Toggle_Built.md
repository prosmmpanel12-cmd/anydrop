# Rider App — Phase 3 (R2): Dashboard + Online Toggle Built
Session date: 04 Sep 2026

Plan this implements: `docs/rider/83_Plan_Phase3_R2_Dashboard_And_Online_Toggle_2026-09-04.md`

## ✅ Done this session

**Backend**
- `backend/api/v1/rider/status.php` (new) — `POST`, toggles `riders.is_online`.
  Requires `status === 'approved'`. Going online requires `last_lat`/`last_lng`
  already on file → `422 location_required` otherwise. Going offline always allowed.
- `backend/api/v1/rider/location.php` (new) — `POST { lat, lng }`, writes
  `riders.last_lat/last_lng/last_location_at`. No approval gate.
- `backend/api/v1/rider/me.php` — extended (additive) with `is_online`,
  `vehicle_type`, `vehicle_number`.

**Android**
- `ui/dashboard/RiderDashboardActivity.kt` (new) — online/offline `MaterialSwitch`,
  static "no active delivery" + "₹0 earnings" placeholder cards. Switch-on flow:
  request `ACCESS_FINE_LOCATION` if not granted → fetch one location →
  `POST /rider/location` → `POST /rider/status {online:true}`. While online,
  foreground-only 30s location poll (`Handler.postDelayed`, started `onResume`,
  stopped `onPause`).
- `res/layout/activity_rider_dashboard.xml` (new).
- `ui/pending/ApplicationStatusActivity.kt` — now the single redirect choke
  point: `onCreate` and a successful Refresh both send `status == "approved"`
  straight to `RiderDashboardActivity` instead of rendering the (now-stale)
  "You're Approved!" card. Chose this over editing Splash/Login/Signup/OtpVerify
  individually since all four already funnel through this screen.
- `network/ApiService.kt` — `setOnlineStatus()`, `updateLocation()`.
- `network/Models.kt` — `OnlineStatusBody/Result`, `LocationBody`, `OkResult`;
  `RiderMeProfile` extended with the 3 new fields.
- `data/TokenManager.kt` — `getIsOnline()`/`setIsOnline()` (cache only, not
  source of truth — dashboard re-syncs from `/rider/me` on every open).
- `AndroidManifest.xml` — registered `RiderDashboardActivity`.
- `res/values/strings.xml` — dashboard strings added; stale "delivery app is
  coming soon" copy on the approved-status string removed (unreachable now,
  kept as harmless fallback text only).

## Files changed this session

```
backend/api/v1/rider/
├── status.php                                  (NEW)
├── location.php                                (NEW)
└── me.php                                       (+is_online, vehicle_type, vehicle_number)

rider/app/src/main/
├── java/com/anydrop/rider/
│   ├── ui/dashboard/RiderDashboardActivity.kt  (NEW)
│   ├── ui/pending/ApplicationStatusActivity.kt (redirect choke point for approved)
│   ├── ui/splash/SplashActivity.kt             (kdoc only, no logic change)
│   ├── network/ApiService.kt                   (+setOnlineStatus, +updateLocation)
│   ├── network/Models.kt                       (+4 new classes, RiderMeProfile extended)
│   └── data/TokenManager.kt                    (+getIsOnline/setIsOnline)
├── res/layout/activity_rider_dashboard.xml     (NEW)
├── res/values/strings.xml                      (+18 strings)
└── AndroidManifest.xml                         (registered new activity)
```

No new SQL migration — every column used (`is_online`, `last_lat`, `last_lng`,
`last_location_at`, `vehicle_type`, `vehicle_number`) already exists from
`01_schema.sql`.

## Not tested against a live backend / device

Same caveat as every prior Rider App session — no Android SDK/Gradle/emulator
in this sandbox. Code-complete, cross-checked by hand (request/response shapes
matched line-by-line between PHP and Kotlin), not gradle-built or run.

## 🔴 Still open — next steps, in order

1. **Smoke test `status.php` + `location.php`** directly (Postman/curl) before
   testing through the app — confirms `is_online`/`last_lat`/`last_lng` actually
   flip in the DB and `location_required` fires correctly when no location exists yet.
2. **Device test the dashboard** — build, log in as an approved rider (or approve
   one via `backend/admin/riders.php`), confirm: switch reflects server state on
   open, going online prompts for location permission then succeeds, going offline
   works with no permission prompt, app backgrounding stops the location poller
   (check logs/network calls stop in `onPause`).
3. **`gradlew`/`gradlew.bat` wrapper** — still not included (same open item as
   every prior Rider App handover).
4. **Phase 3 R2 remainder** — real earnings numbers (currently static ₹0/0)
   once there's a settlement/ledger read to back them; that's a small follow-up,
   not gated on anything else in this slice.
5. **Phase 3 R3** — the actual assignment engine (offer/accept/reject orders,
   dispatch scoring) is unstarted. `Rider_Deep_Plan.md` sections 4-8 cover it in
   full; doc 83 explicitly scoped it out of this slice on purpose.

Next session: run migrations (if any pending from earlier docs) + smoke-test
`status.php`/`location.php` on the live backend, then device-test the dashboard
end to end before starting R3 planning.
