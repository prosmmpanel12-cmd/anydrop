# Rider App — Phase 2 (Android UI) — Signup/Login/Status flow COMPLETE
Session date: 01 Sep 2026 (continued same-day)

> ✅ **This zip should now compile.** All five activities referenced in
> AndroidManifest.xml (Splash, Login, OtpVerify, Signup,
> ApplicationStatus) have real Kotlin classes + layouts. A placeholder
> launcher icon is included so resource linking won't fail. **Not yet
> tested against a live backend** — see "Still open" below.

## Context

This continues the mid-session checkpoint from earlier today (see the
"✅ Done this session" section below, renamed from that checkpoint's
"Not built yet" list — everything in that list is now done). Backend
(Phase 1) is unchanged: migrations 69/70/71 + rider-request-otp.php /
rider-verify-otp.php / rider-signup.php / service-areas.php /
admin/riders.php, docs 79-82 in the main project zip.

## ✅ Done this session (continuation)

1. **`OtpVerifyActivity.kt`** — verifies the OTP via
   `rider-verify-otp.php`, then branches on `account_exists`:
   - `true` → that same call already returned `token`/`rider`/`status`.
     Session is saved via `TokenManager.saveSession()` and the rider
     goes straight to `ApplicationStatusActivity` (task stack cleared —
     back button shouldn't return to login/OTP once authenticated).
   - `false` → hands the verified email to `SignupActivity` via
     `EXTRA_EMAIL`. The signup screen never re-collects/re-verifies it.
   - **Edge case handled**: a `rejected`/`suspended` rider hitting the
     login path gets `account_suspended` back as an *error* (no token
     issued at all, per `rider-verify-otp.php`'s own contract) — there's
     no session to save, so this just surfaces the reason/status via
     the toast notifier and leaves the rider on the OTP screen.

2. **`SignupActivity.kt` + `activity_signup.xml`** — name + mobile
   fields, plus **both** service-area inputs per the original scope
   decision:
   - GPS auto-detect button using `FusedLocationProviderClient`
     (`play-services-location`, already in `app/build.gradle`) —
     requests `ACCESS_FINE_LOCATION` via
     `ActivityResultContracts.RequestPermission`, captures lat/lng only
     (no on-device reverse-geocode-to-service-area — that only happens
     server-side in `resolve_service_area()` at signup time).
   - Cascading **State → District → City/Village → Area** dropdown
     built from `service-areas.php`'s flat list, grouped client-side by
     `parent_id` (same approach `admin/areas.php` uses). Each spinner
     selection resets and repopulates the next level down.
   - Both `service_area_id` (dropdown) and `latitude`/`longitude` (GPS)
     are sent to `rider-signup.php` together — the backend's own
     dropdown-wins-over-GPS precedence resolves it, so the app doesn't
     duplicate that logic.
   - On success: saves the session (token issued immediately per that
     endpoint's kdoc), shows a "Detected: <area>" / "couldn't detect"
     notice **only if GPS coords were actually given** (mirrors the
     Restaurant app's own `areaNotCovered` reasoning — skipping location
     entirely isn't the same as "not covered"), then goes to
     `ApplicationStatusActivity`.

3. **`ApplicationStatusActivity.kt` + `activity_application_status.xml`**
   — reads `TokenManager.getStatus()` (still no dashboard, so every
   authenticated rider lands here regardless of status), renders one of
   4 states using the pre-built status pill colors, shows
   `rejectionReason` when present (see TokenManager change below), and
   has:
   - **Refresh Status** — there's still no dedicated re-check endpoint
     (flagged as an open backend item last checkpoint too). Closest live
     equivalent is re-running `rider-verify-otp.php`'s login branch,
     which needs a fresh OTP — so this button logs the rider out and
     sends them back through email-OTP login, with a toast explaining
     why. A real `rider-me.php` "who am I" endpoint would make this
     instant instead; that's still backend work for a future session.
   - **Log out** — `TokenManager.clear()` → back to `LoginActivity`.

4. **Placeholder launcher icon** — vector-only (no image-generation
   tooling was available this session), so it's a simple deep-green
   circle backdrop + white droplet glyph, NOT the real AnyDrop rider
   logo. Two layers:
   - `mipmap-anydpi-v26/ic_launcher(_round).xml` — proper
     `<adaptive-icon>` for API 26+, layering
     `drawable/ic_launcher_background.xml` +
     `drawable/ic_launcher_foreground.xml` (foreground kept inside the
     108dp canvas's ~66dp safe zone so it isn't clipped on
     circular/rounded-square masks).
   - `mipmap/ic_launcher(_round).xml` — a flattened fallback vector,
     because `<adaptive-icon>` only resolves on API 26+ and this app's
     `minSdk` is 24. **Swap all four files for the real icon** once
     you're ready to derive proper mipmap assets from the AnyDrop rider
     logo you shared — the placeholder is clearly commented as such in
     each file.

### Bonus fix — error-body parsing bug, caught before it shipped
Ported `network/ErrorParsing.kt` from the Restaurant app: Retrofit only
populates `Response.body()` on 2xx responses — for anything else
(401/403/409/422/...) the real `{error, data}` payload only lives in
`Response.errorBody()`. `LoginActivity` (built last checkpoint) was
reading `response.body()?.error` on its failure branch, which is always
null on a non-2xx response — silently collapsing every specific error
(`otp_request_cooldown`, `validation_error`, etc.) into the generic
fallback message. Fixed `LoginActivity` and used the correct pattern
from the start in `OtpVerifyActivity`/`SignupActivity`. This is the
exact same bug the Restaurant app's own `ErrorParsing.kt` kdoc documents
finding and fixing — see that file for the fuller writeup.

`TokenManager.saveSession()` also gained an optional `rejectionReason`
parameter (persisted alongside token/riderId/name/status) so
`ApplicationStatusActivity` can actually show *why* a rider was
rejected/suspended, sourced from `RiderProfile.rejectionReason` in the
verify-otp / signup responses.

## Files in this delivery

```
rider/
├── build.gradle
├── settings.gradle
├── gradle.properties
├── docs/00_HANDOVER_Rider_App_Phase2_Android_InProgress.md   (this file)
└── app/
    ├── build.gradle
    └── src/main/
        ├── AndroidManifest.xml
        ├── java/com/anydrop/rider/
        │   ├── data/TokenManager.kt
        │   ├── network/{ApiClient,ApiService,ErrorParsing,Models}.kt
        │   └── ui/
        │       ├── common/InAppNotifier.kt
        │       ├── splash/SplashActivity.kt
        │       ├── login/{LoginActivity,OtpVerifyActivity}.kt
        │       ├── signup/SignupActivity.kt
        │       └── pending/ApplicationStatusActivity.kt
        └── res/
            ├── anim/  (8 files — slide/shake/success/splash animations)
            ├── drawable/  (16 files — backgrounds, vector icons,
            │                launcher foreground/background)
            ├── layout/  (activity_splash, activity_login,
            │              activity_otp_verify, activity_signup,
            │              activity_application_status, toast_custom)
            ├── mipmap-anydpi-v26/  (adaptive icon, API 26+)
            ├── mipmap/  (flattened fallback icon, API 24-25)
            └── values/  (colors.xml, themes.xml, strings.xml)
```

## ✅ Done this session (continuation 2 — 04 Sep 2026)

5. **Real launcher icon** — replaced all 4 placeholder files with assets
   derived from the app-owner-supplied AnyDrop rider logo PNG
   (dark-green rounded-square badge, neon-green glow border, white
   "ANY Drop" wordmark + smiley-arrow + delivery-scooter-rider glyph).
   - Isolated the white glyph from the source raster via grayscale +
     brightness thresholding, then a morphological open (erode→dilate)
     to strip the thin anti-aliased glow-border line that the naive
     threshold also picked up — verified visually against a dark-green
     preview composite before use.
   - `drawable/ic_launcher_foreground.png` (new, 864×864, transparent) —
     the cleaned glyph, cropped to its tight bounding box and scaled to
     a ~62dp footprint centered in the 108dp adaptive-icon canvas, i.e.
     inside the ~66dp safe zone (checked against a simulated circular
     mask — no clipping of any letter or the rider glyph).
   - `drawable/ic_launcher_foreground.xml` (old vector placeholder) —
     deleted; same resource name now resolves to the PNG above.
   - `drawable/ic_launcher_background.xml` — kept as-is (full-bleed
     black canvas + `#0E3B22` circle inset) since that green is already
     a close match to the logo's own body color; no placeholder text
     left to clean up here.
   - `mipmap-{mdpi,hdpi,xhdpi,xxhdpi,xxxhdpi}/ic_launcher.png` (new) —
     the full source badge (background + glyph + glow, already
     flattened in the supplied artwork) resized to 48/72/96/144/192px.
   - `mipmap-{same densities}/ic_launcher_round.png` (new) — same
     source, circle-cropped for launchers that request the round
     variant explicitly.
   - `mipmap/ic_launcher.xml` + `ic_launcher_round.xml` (old vector
     placeholders, default/no-qualifier bucket) — deleted; replaced
     with `mipmap/ic_launcher.png` + `ic_launcher_round.png` (96px,
     same derivation) as the fallback for any density bucket not
     covered by the five above.
   - `mipmap-anydpi-v26/ic_launcher.xml` / `ic_launcher_round.xml`
     (API 26+ adaptive-icon definitions) — **untouched**, they already
     referenced `@drawable/ic_launcher_background` /
     `@drawable/ic_launcher_foreground` by name, which now resolve to
     the new files automatically.
   - **Not build/device-verified** — same standing sandbox constraint
     (no Android SDK/Gradle/emulator here). Please install and check
     the home-screen icon on your actual launcher (circle, squircle,
     and rounded-square shapes if you can test more than one), plus
     Settings → Apps and the recent-apps switcher.

## 🔴 Still open — exact next steps, in order

1. ~~**Real launcher icon**~~ — ✅ done this session, see above. Build/
   device-test still pending (no Android SDK in this sandbox).
2. **`gradlew`/`gradlew.bat` wrapper** — still not included, same as the
   Restaurant app (built via CI, which generates its own). Run
   `gradle wrapper` locally or let CI handle it.
3. **`BASE_URL` in `ApiClient.kt`** — still the `localhost:8080`
   placeholder, needs updating before running against a real backend.
4. **Run migration 69/70/71 + smoke-test the 4 backend endpoints for
   real** — this was the top-priority open item from doc 82 last
   checkpoint too, and nothing here changes that. The Android UI is now
   code-complete for signup/login/status but has not been run against a
   live, migrated backend at all this session (no Android build
   environment available here — this was written and cross-checked by
   hand: every view ID and `R.string`/`R.color` reference used in the
   new Kotlin was grepped against the XML resources and confirmed to
   resolve, but that is not a substitute for an actual Gradle build).
5. **`rider-me.php`** (optional, nice-to-have) — a lightweight
   "who am I" endpoint so `ApplicationStatusActivity`'s Refresh Status
   button doesn't have to force a full logout + fresh OTP just to
   re-check status.
6. Once approved riders exist: Phase 2's real delivery-flow dashboard
   (Maps SDK, FCM, live order assignment) is the next actual scope —
   nothing in this signup/login/status pass touches that.

Next session: re-upload this zip alongside the main project zip (for
backend reference). Priority is #4 above — get a real backend running
and actually exercise all 4 endpoints from a device/emulator before
adding anything else.
