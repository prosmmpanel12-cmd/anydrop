# Rider App — Phase 2 (Android UI) — Signup/Login/Status flow COMPLETE
Session date: 01 Sep 2026 (continued 04 Sep 2026)

> ✅ **This zip should now compile.** All five activities referenced in
> AndroidManifest.xml (Splash, Login, OtpVerify, Signup,
> ApplicationStatus) have real Kotlin classes + layouts. A real launcher
> icon (derived from the AnyDrop rider logo PNG) is included. **Not yet
> tested against a live backend** — see "Still open" below.

## Context

This continues the mid-session checkpoint from earlier today (see the
"✅ Done this session" section below). Backend (Phase 1) is unchanged:
migrations 69/70/71 + rider-request-otp.php / rider-verify-otp.php /
rider-signup.php / service-areas.php / admin/riders.php, docs 79-82 in
the main project zip.

## ✅ Done — Phase 1 (Backend, unchanged)

- Migration 69: `riders` table with self-signup + status approval flow
- Migration 70: signup rate limiting per IP
- Migration 71: `riders.username`/`password_hash` made nullable
- `rider-request-otp.php` — email OTP send
- `rider-verify-otp.php` — OTP verify, doubles as login for existing riders
- `rider-signup.php` — Step 3 signup, issues token immediately
- `system/service-areas.php` — flat area list for cascading dropdown
- `admin/riders.php` — admin panel rider management

## ✅ Done — Phase 2 (Android, this session)

1. **`SplashActivity`** — checks token → routes to Login or Status
2. **`LoginActivity`** — email input, OTP send
   - **Fix (04 Sep):** `btnGoSignup` now reuses the same OTP flow
     instead of opening SignupActivity directly (which crashed
     immediately because EXTRA_EMAIL was never passed). If email is
     already typed + valid → OTP sends immediately. If empty → field
     focused with a hint toast. OtpVerifyActivity then routes to
     SignupActivity automatically on `account_exists=false`.
3. **`OtpVerifyActivity`** — 6-digit OTP verify, branches on `account_exists`
4. **`SignupActivity`** — name, mobile, GPS + cascading area dropdown
5. **`ApplicationStatusActivity`** — shows pending/approved/rejected/suspended
   - **Fix (04 Sep):** "Refresh Status" now calls `GET /api/v1/rider/me`
     instead of forcing a full logout + OTP re-login. Updates
     TokenManager in-place and re-renders UI. If `account_suspended`
     is returned (new suspension since login), session is cleared and
     rider is sent to login.
6. **`rider/me.php`** (new backend endpoint) — `GET /api/v1/rider/me`
   - Requires `Authorization: Bearer <token>`
   - Returns current `status`, `rejection_reason`, rider profile,
     and joined `service_area_name`
   - `require_auth('rider')` already blocks suspended/deleted riders
     with 403, so this endpoint never needs to special-case that
7. **`TokenManager.updateStatus(status, rejectionReason?)`** — now
   persists rejection reason alongside status (previously only status
   was updated, reason was left stale)

## Files changed this session (04 Sep)

```
rider/
└── app/src/main/java/com/anydrop/rider/
    ├── data/TokenManager.kt                        (updateStatus now takes rejectionReason)
    ├── network/ApiService.kt                       (added getMe())
    ├── network/Models.kt                           (added RiderMeResult, RiderMeProfile)
    ├── ui/login/LoginActivity.kt                   (btnGoSignup fix)
    ├── ui/pending/ApplicationStatusActivity.kt     (Refresh now calls rider/me.php)
    └── res/values/strings.xml                      (added signup_enter_email_hint, status_refreshed)

backend/
└── api/v1/rider/
    └── me.php                                      (NEW — GET /api/v1/rider/me)
```

## 🔴 Still open — exact next steps, in order

1. **`BASE_URL` in `ApiClient.kt`** — still `localhost:8080` placeholder.
   Update to your real backend URL before testing.
2. **Run migration 69/70/71 + smoke-test all backend endpoints for real**
   — the Android UI is code-complete for signup/login/status/refresh
   but has never run against a live backend (no Android SDK/Gradle/emulator
   in this sandbox — cross-checked by hand, not an actual Gradle build).
3. **`gradlew`/`gradlew.bat` wrapper** — not included, same as Restaurant app.
   Run `gradle wrapper` locally or let CI generate it.
4. **Phase 3: Rider delivery dashboard** — once approved riders exist,
   the real scope is Maps SDK, FCM push, live order assignment, earnings.
   Nothing in this signup/login/status pass touches that.
5. **`rider-me.php` — device test** — verify the token header is attached
   correctly (ApiClient's authInterceptor handles this, but confirm on
   a real device/Postman first).

Next session: upload this zip, confirm BASE_URL, run on device/emulator
against a live backend, then start Phase 3 planning.
