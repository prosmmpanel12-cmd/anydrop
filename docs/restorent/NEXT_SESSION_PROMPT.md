Restaurant app — continue from here.

**2026-08-20 (latest) update:** Notification bell (Type 1) built for the
Restaurant App's Android UI — mirrors the Customer App side (already
done, see `docs/Status.md`) using the already-existing backend endpoint.
Read `docs/restorent/00_Status.md`'s newest entry in full first.
**Not built/compiled, not tested on-device** — same standing sandbox
limitation as every other session here (no Android SDK/Gradle
available). Static verification (brace/paren balance, every
`binding.X` reference cross-checked against its layout's actual IDs,
XML well-formedness, ViewBinding class-name generation, dependency
availability) all came back clean — see that entry for the full list of
what was checked — but none of that substitutes for a real compile.

**This now stacks on top of the already-unconfirmed build surface below**
— Phase 3 (bottom sheets), Phase 4 (toggle redesign — note: the toggle
was further changed again on 2026-08-19, from the `MaterialButtonToggleGroup`
Phase 4 built to a plain `SwitchMaterial`; `activity_main.xml`'s own
comments document this as a deliberate app-owner-requested revert, not a
regression — worth confirming this doesn't conflict with anything in
Phase 4's own unconfirmed state), and now this session's bell UI.

## Do this first — confirm the build

**This has been the top priority for at least two sessions running and
still hasn't happened.** Every session keeps adding more unverified
surface on top instead. Before writing any more code:

1. Get a fresh Actions log. Check `:app:compileDebugKotlin` for the
   Restaurant App job. Several things need confirming, not just one:
   - The 2026-08-19(c) import fix (missing `GridLayoutManager` /
     `DialogCategoryIconPickerBinding` imports in `MenuFragment.kt`) —
     still unconfirmed.
   - Phase 3's `BottomSheetDialog` usage in `MenuFragment.kt` and
     `CouponManagerActivity.kt` — manually verified only.
   - Phase 4's status-toggle changes in `MainActivity.kt`/
     `activity_main.xml` (built as `MaterialButtonToggleGroup`, then
     reverted to `SwitchMaterial` per app-owner preference on
     2026-08-19) — manually verified only.
   - **New this session:** the notification bell additions to
     `network/Models.kt`, `network/ApiService.kt`,
     `ui/notifications/NotificationAdapter.kt`,
     `ui/notifications/NotificationListActivity.kt`, `MainActivity.kt`,
     `activity_main.xml` (also got a newly-added `xmlns:tools`
     namespace it didn't have before), and 3 new layout/drawable sets —
     manually verified only, see `docs/restorent/00_Status.md`'s newest
     entry for the specific checks done.
2. If it fails, check whether it's a genuinely new error before assuming
   so — rule out a stale-zip/workflow-cheatsheet slip first (see
   `docs/14_Update_Workflow_Cheatsheet.md`).
3. If it succeeds: this build now covers 4 sessions' worth of stacked
   unverified changes at once. Call it out to the app owner, then
   actually run the manual test lists below now that things can be
   installed on a device.

## Manual verification lesson, still applies
Grep every referenced class/type against each file's own `import` list,
and every `binding.X` call against the actual layout's `android:id`
values — don't just confirm the class/ID exists somewhere in the
project. Done this session for all new notification-bell files (see
00_Status.md entry for detail); done in prior sessions for
`BottomSheetDialog` and the toggle changes.

## One thing to sanity-check with the app owner (carried from a prior session)
`submitNewCoupon()`/`submitCouponEdit()` now keep the add/edit-coupon
bottom sheet **open** on a validation error instead of auto-dismissing
(the old `AlertDialog` behavior silently closed the dialog even on
invalid input). Judged a clear improvement, made without asking — worth
a quick nod from the app owner once it can be tested on a device.

## Two standing asks from the app owner

1. **Gradle build** — see "Do this first" above, now with four
   unconfirmed surfaces stacked instead of one.
2. **Run the still-pending migrations against the live DB**, three deep:
   `26_migration_address_delete_fk_fix.sql`,
   `27_migration_coupon_archive.sql`, `28_migration_category_icon_key.sql`.
   All verified correct/idempotent by inspection, none confirmed run.
   Still also unconfirmed: whether `23_migration_restaurant_banners.sql`
   and `24_migration_default_radius_setting.sql` ever ran.

## Next feature work (once the build is confirmed)

1. ~~Notification bell~~ ← **Both apps now built** (Customer App +
   Restaurant App, Android UI + backend). **Nothing left on this item
   except build/device verification** — see "Do this first" above.
2. Reviews reply
3. Settings (GST/FSSAI/language/dark mode)
4. Payments/settlement
5. Analytics
6. Staff management
7. Rider App last

Phases 4–6 of the UI/UX overhaul (toggle redesign — now further revised
past Phase 4, per the note above — update+maintenance check for both
apps, final consistency pass) are still open too; confirm with the app
owner whether to prioritize those or continue doc 18's feature queue
above.

## Standing risk — build verification

1. Full Gradle build for the Restaurant app — one real attempt so far,
   revealed 4 errors, fixed by inspection, **still awaiting a second
   build to confirm**, and now has three more sessions' worth of
   unverified surface stacked on top (Phase 3, Phase 4 + its later
   revert, and this session's notification bell).
2. Category icon picker end to end (once build confirmed): add a
   category, tap "Choose icon", confirm the grid renders and picking one
   updates the preview and clears any staged photo; edit an existing
   icon-only category, confirm it pre-fills correctly; upload a photo
   over an existing icon-only category, confirm `icon_key` goes NULL
   server-side; confirm the category list row shows the bundled icon.
3. Add-coupon and add-menu-item bottom sheets end to end — confirm the
   sheet opens/dismisses correctly, Save/Cancel both work,
   validation-failure-keeps-sheet-open behaves as intended, and the
   drag-handle renders as a neutral gray bar (not red).
4. Coupon system end to end (is_public/date-time-picker/archive) — same
   checklist as the 2026-08-18 entry, still unconfirmed.
5. The crop screen, BannerManagerActivity, RestaurantBannerCarouselView
   — flagged for several sessions running, still unconfirmed.
6. **New this session:** notification bell end to end — place a test
   order, confirm the bell badge appears on the shared top bar with the
   right unread count, open the list, confirm rows render with correct
   icon/read-state styling (order icon is `ic_store` here, not
   `ic_restaurant` like the Customer App), tap a row and confirm it
   marks read + opens `OrderDetailActivity` for the right order, tap
   "mark all read" and confirm the badge clears.
7. Everything from every prior session's checklist (Edit Profile screen,
   location picker, Menu tab drag-to-reorder + photo upload UI, backend
   endpoints, Orders tab) — unchanged, still pending.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test.

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`).
