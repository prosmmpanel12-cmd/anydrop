# Anydrop — Phase-by-Phase Build Plan

> **⚠️ PLAN CHANGED (2026-08-11):** The osmdroid-based map plan referenced
> in this file (Phase 3.5-ish live map screen) is superseded — **Google
> Maps is now the planned provider**. See
> `docs/12_Handover_H6_Map_PinDrop_Photo.md` → "Google Maps SDK migration
> plan" for details. Migration is blocked on app name/package finalization
> and Google Cloud billing setup — don't build the map screen below
> against osmdroid until that plan doc says otherwise.

**Rule:** No phase begins until you've tested the previous phase's deliverable and explicitly said to continue (e.g. "Phase 2 confirmed, start Phase 3"). If something's broken, we fix it before moving on — we don't build on top of a broken foundation.

---

## Phase 0 — Foundation & Build Pipeline
**Goal:** Prove the "no-PC" workflow works before writing any real feature.

**Tasks:**
1. GitHub repo structure created (`anydrop-customer`, `anydrop-restaurant`, `anydrop-rider` as separate Android projects, or one mono-repo with 3 modules — decided in Phase 0 based on your GitHub comfort)
2. Minimal Android Studio (Kotlin) project — one blank screen, correct package name, correct `build.gradle`
3. GitHub Actions workflow file (`.github/workflows/build.yml`) that runs `gradle assembleDebug` on every push
4. First APK successfully appears in the Actions run artifacts

**You test:** Download the APK from GitHub Actions, install it on your phone, see a blank "Anydrop" screen open successfully.

**Checkpoint:** ✅ Confirms the entire no-PC build loop works before any real feature is built on top of it.

---

## Phase 1 — Backend Foundation + Database
**Goal:** A working PHP+MySQL backend, live on InfinityFree, with the core schema and first real APIs.

**Tasks:**
1. InfinityFree account + database created, connection tested
2. Full schema from `01_Database_Schema.md` created (SQL script provided)
3. Lightweight PHP router set up (no framework — a small `index.php` dispatcher, per your comfort with plain PHP)
4. `app_settings` table seeded with default values (commission %, due limit, OTP rules, etc.)
5. APIs built: restaurant login, customer email-OTP login, `GET /restaurants`, `GET /restaurants/{id}/menu`
6. Basic admin seed script (create first admin user)

**You test:** Hit these API URLs directly in a browser or Postman/Insomnia (works fine from phone browser too) and see correct JSON responses. Insert one test restaurant + menu manually via phpMyAdmin (InfinityFree provides this) to test against.

**Checkpoint:** ✅ Confirms database + backend are reachable and correct before any app tries to talk to them.

---

## Phase 2 — Customer App: Browse & Cart
**Goal:** Installable Customer App APK that can log in and browse.

**Tasks:**
1. Splash → version check → login screen (Google + Email OTP)
2. Home screen: restaurant list from live API, basic filters
3. Restaurant detail + menu screen
4. Cart (add/remove/quantity, local state only — no order placement yet)
5. Retrofit (or plain HttpURLConnection, decided based on final complexity comfort) networking layer connected to Phase 1 APIs

**You test:** Install APK, log in with your email, see real restaurant(s) you created via phpMyAdmin, add items to cart.

**Checkpoint:** ✅ Confirms full read-path (DB → API → App) works end-to-end.

---

## Phase 3 — Ordering + Restaurant App
**Goal:** A real order can be placed and managed.

**Tasks:**
1. Checkout screen, `POST /orders` wired up
2. Order status screen (polling-based)
3. Restaurant App: login, dashboard, incoming order screen, accept/reject/preparing/ready actions
4. Order status history + notifications table wired (in-app only for now, FCM push comes Phase 6)

**You test:** Place an order from Customer App on your phone → open Restaurant App (can be same phone, different app, or a second device/emulator) → see the order appear → accept it → status updates back on Customer App.

**Checkpoint:** ✅ Confirms the two-way order lifecycle works.

---

## Phase 4 — Rider App + Live Tracking + OTP Delivery
**Goal:** Full order journey including live GPS.

**Tasks:**
1. Rider App: login (restaurant-created accounts only), online/offline toggle, order accept/reject
2. Background location service with adaptive ping interval (per `03_Live_Tracking.md`)
3. `POST /rider/location` + `GET /orders/{id}/track` wired
4. Customer App: live map screen (osmdroid) with animated marker + OSRM route line
5. Delivery OTP generation, display (app + email), rider verify-OTP screen, wrong/expired/max-attempts handling

**You test:** Full journey — place order, restaurant accepts, assign a rider (can use a second phone or your own phone switching apps), watch the rider marker move on the map as you physically walk/drive with the rider phone, complete delivery via OTP.

**Checkpoint:** ✅ This is the single most complex phase — confirms the platform's core differentiator works.

---

## Phase 5 — Admin Panel (Web)
**Goal:** Full administrative control, browser-based.

**Tasks:**
1. Admin login (session-based)
2. Dashboard (live stats)
3. Restaurant approval/suspension workflow
4. Due ledger view + payment verification screen
5. Settings page — direct editor for `app_settings` table (commission, due limit, OTP rules, etc.)
6. Basic reports (CSV export)

**You test:** Open the admin URL in any browser (phone or otherwise), approve a restaurant, adjust a setting, verify it reflects in the apps.

**Checkpoint:** ✅ Confirms the "nothing hardcoded" principle is real and controllable.

---

## Phase 6 — Notifications, Reviews, Polish
**Goal:** Feature-complete v1.

**Tasks:**
1. FCM push notifications wired for order status changes (all 3 apps)
2. Ratings & reviews (customer submits, restaurant replies)
3. Coupons/offers functional in checkout
4. Empty states, error states, offline handling across all screens
5. Restaurant due-limit auto-suspend cron endpoint + external pinger (cron-job.org) configured

**You test:** Full walkthrough of all edge cases — no internet, empty cart, restaurant over due limit disappearing from customer app, etc.

**Checkpoint:** ✅ Confirms edge-case robustness.

---

## Phase 7 — Hardening & Launch Readiness
**Goal:** Production-ready.

**Tasks:**
1. Security pass: rate limiting, input validation audit, password policies, audit log review
2. Performance pass: query optimization, caching headers review, image compression
3. Deployment checklist finalized, backup strategy documented
4. Decision point: stay on InfinityFree or move to a paid VPS (documented migration path, no rewrite needed)

**You test:** Load-test with a few real friends/family ordering simultaneously if possible.

**Checkpoint:** ✅ Ready for real restaurants.

---

## Status Tracking

After every phase, `Status.md` gets updated with:
- What was built
- What's confirmed working
- Known limitations / things deferred to a later phase
- Exact next step

This file always tells you (or me, in a future conversation) exactly where the project stands without re-reading everything.
