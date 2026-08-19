Restaurant app — continue from here.

**2026-08-19(e) update:** 6-phase UI/UX overhaul continues — Phase 1
(category icon/photo live search) and Phase 2 (Restaurant app nav icon
overhaul + app-wide emoji cleanup) are both done, both unverified. See
00_Status.md's newest entry for Phase 2's full writeup. Waiting on
app-owner confirmation before Phase 3 (dialogs UI pass — doc 22 item 2,
modernize all 7 dialog layouts across both apps). Everything below this
point is the pre-existing standing queue, still accurate.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"2026-08-19 (c) — FIRST REAL GRADLE BUILD RESULT for the Restaurant app:
BUILD FAILED, 2 missing imports, now fixed" (top of the file).

## What just happened — read this first

The app owner ran the real GitHub Actions build for the first time in
21+ sessions and uploaded the logs. **Customer App built successfully.
Restaurant App failed** — `:app:compileDebugKotlin`, 4 compiler errors,
all traced to 2 missing imports in `MenuFragment.kt`
(`GridLayoutManager`, `DialogCategoryIconPickerBinding`) left over from
the 2026-08-19(a) session's category-icon-picker work. Both imports have
been added this session (2026-08-19c) — **but this fix has not itself
been build-verified yet.** That's the first thing to confirm once a new
Actions log is available.

## Do this first — confirm the fix actually worked

1. If a new Actions log (post this session's zip) is available, check
   `:app:compileDebugKotlin` for the Restaurant App job specifically —
   confirm `BUILD SUCCESSFUL` and that the four specific errors listed
   in Status.md's newest entry are gone.
2. If the build is still failing, don't assume it's a new bug until
   you've confirmed it isn't a stale-zip issue (wrong zip pushed, cheat-
   sheet step skipped, etc. — see `docs/14_Update_Workflow_Cheatsheet.md`).
3. If it succeeds: this is worth calling out explicitly to the app owner
   as a milestone — first-ever green build for this app — and worth
   using as a moment to actually run through the "Category icon picker
   end to end" manual test list below, since the picker can finally be
   installed on a real device.

## Standing lesson from this session, apply going forward

Manual verification passes (brace/paren counting, XML parsing,
grep-based ID cross-checks) **do not catch missing imports** the way a
real compiler does — that's exactly what slipped through both the
2026-08-19(a) session (skipped verification entirely) and the
2026-08-19(b) session (did verify, but checked "does the filename map to
the right class name" without checking "is that class actually
imported in every file that references it"). When doing a manual pass on
Kotlin files going forward, explicitly grep each referenced class/type
against the file's own `import` list — don't just confirm the class
exists somewhere in the project.

## Two standing asks from the app owner

1. **Gradle build** — partially answered this cycle (see above), but
   confirm the fix lands clean before considering this closed. The DB
   migrations ask (below) is still fully open.
2. **Run the still-pending migrations against the live DB**, three deep:
   `26_migration_address_delete_fk_fix.sql`,
   `27_migration_coupon_archive.sql` (2026-08-18), and
   `28_migration_category_icon_key.sql` (2026-08-19a). All verified
   correct/idempotent by inspection (same CONTINUE-HANDLER/conditional-
   ALTER pattern as every prior migration), none confirmed run. Still
   also unconfirmed: whether `23_migration_restaurant_banners.sql` and
   `24_migration_default_radius_setting.sql` ever ran.

## Next feature work (once the build fix is confirmed)

Doc 22 is fully closed. Resume doc 18's recommended build order:
1. Notification bell ← next up, not started
2. Reviews reply
3. Settings (GST/FSSAI/language/dark mode)
4. Payments/settlement
5. Analytics
6. Staff management
7. Rider App last

## Standing risk — build verification

Down to one open sub-item now that the Restaurant App has had a real
build attempt:
1. ~~Full Gradle build for the Restaurant app~~ — done this cycle,
   revealed 4 real errors, now fixed by inspection, **awaiting a second
   build to confirm the fix.**
2. **Category icon picker end to end** (once build confirmed working):
   add a category, tap "Choose icon", confirm the grid renders and
   picking one updates the preview and clears any staged photo; edit an
   existing icon-only category, confirm it pre-fills the right icon
   selected in the grid; upload a photo over an existing icon-only
   category, confirm the icon clears server-side (`icon_key` should go
   NULL, not just get ignored); confirm the category list row itself
   shows the bundled icon.
3. Coupon system end to end (is_public/date-time-picker/archive) — same
   checklist as the 2026-08-18 entry, still unconfirmed.
4. The crop screen, BannerManagerActivity, RestaurantBannerCarouselView —
   flagged for several sessions running, still unconfirmed.
5. Everything from every prior session's checklist (Edit Profile screen,
   location picker, Menu tab drag-to-reorder + photo upload UI, backend
   endpoints, Orders tab) — unchanged, still pending.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test.

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`).
