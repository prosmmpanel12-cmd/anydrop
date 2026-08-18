Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest two entries in full
first** — "Coupon system: edit-dialog + usage-limit fields DONE" and
"OrderPollingService/OrdersFragment question RESOLVED" (both
2026-08-18, top of the file).

## Just finished this session — Coupon system, edit-dialog + usage-limit fields

Closes the two remaining coupon items from the prior session. Backend
and Kotlin models needed no changes — this was UI-only:
`CouponManagerActivity.showEditCouponDialog()` (new), `CouponAdapter`'s
new `onEditClick` callback wired to a tappable row, and two new
optional numeric fields (`usage_limit_total`/`usage_limit_per_user`)
added to `dialog_add_coupon.xml` and wired into both create and edit
submit paths. Full reasoning and exact files touched in Status.md's
newest entry.

**Open question, not a code question:** whether the app owner wants a
real coupon delete/archive state, or whether "toggle off forever" (the
existing behavior) is an acceptable substitute for delete. Worth asking
before building anything further here.

## Also resolved this session (previous entry) — OrderPollingService/OrdersFragment

Read all the relevant files end to end and confirmed
`OrderPollingService`/`OrderNotificationHelper` is a complete,
deliberate, self-consistent replacement for the old
`OrdersFragment`/`NewOrderAlertSound` approach — not dead or
conflicting code. Every cross-reference the kdocs claim (start/stop
wiring in `MainActivity`/`AccountFragment`, `stopRingingLoop()` calls,
manifest registrations, the bundled `alarm_tone.wav` resource) was
independently verified present and correct. Full detail in that
Status.md entry. **This resolves the documentation/conflict question
only — not compilation.** Still needs a real Gradle build.

## Two asks from the app owner that still could NOT be completed — need a real toolchain

1. **Run a real Gradle build for the Restaurant app.** Now two sessions
   of entirely unverified-by-compiler surface stacked on top of each
   other: last session's `OrderPollingService`/`OrderNotificationHelper`
   rewrite (uses several API-level-gated branches —
   `VibrationEffect.createWaveform`, `canUseFullScreenIntent`,
   `foregroundServiceType` — that read correctly but are unverified),
   and this session's coupon edit-dialog UI (lower risk — mostly new
   TextViews/TextInputLayouts and one new adapter constructor param —
   but still untouched by a compiler). Do this first; it's the biggest
   backlog of unverified changes yet.
2. **Run `backend/sql/26_migration_address_delete_fk_fix.sql` against
   the live DB** (verified correct and idempotent, same CONTINUE-HANDLER
   pattern as 11c/25 — just needs a human with real DB access). While in
   there, **confirm whether `24_migration_default_radius_setting.sql`
   and `23_migration_restaurant_banners.sql` have actually been run
   yet** — several migrations have been queued "run before deploying"
   for a while now; worth checking which of them are actually applied
   to the live DB rather than assuming, per the standing ask from two
   sessions ago.

## Next feature work (after the two items above, and after asking about coupon delete/archive)

Resume doc 18 §"Recommended build order": notification bell, reviews
reply, settings (GST/FSSAI/language/dark mode), payments/settlement,
analytics, staff management, then Rider App last.

## Standing risk — build verification

**Still not build-verified — now NINETEEN+ sessions running, no Android
SDK, no PHP CLI, no network access in this sandbox.** Do the moment a
real toolchain is available. Priority order, updated for this session:
1. **Full Gradle build for the Restaurant app** — covers both the
   notification-service rewrite and this session's coupon-edit UI in
   one pass; nothing here is individually flagged as more suspect than
   anything else at this point, it's just cumulatively the largest
   unverified stack yet.
2. **Coupon system end to end**, now including edit: create a coupon,
   edit its terms (confirm the pre-fill + save round-trips correctly,
   especially clearing an optional field back to null/unlimited),
   toggle it off/on, confirm `/cart/validate` on the Customer app
   respects `is_active` for a restaurant-created code.
3. The crop screen, BannerManagerActivity, RestaurantBannerCarouselView —
   same three items flagged for several sessions running, still
   unconfirmed.
4. Everything from every prior session's checklist (Edit Profile screen,
   location picker, Menu tab drag-to-reorder + photo upload UI, backend
   endpoints, Orders tab) — unchanged, still pending.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test.

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`).
