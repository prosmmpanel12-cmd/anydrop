Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"2026-08-19 (b) — verification pass + doc 22's two loose ends closed"
(top of the file). This was a clean, scoped session (not a checkpoint) —
it closed out both loose ends the prior 2026-08-19 (a) session left
behind. **Doc 22 is now fully done, all 7 dialogs modernized, category
icon rendering wired everywhere.**

## Where things stand

**Doc 22 (`docs/22_UI_UX_Overhaul_Feedback_2026-08-18.md`) — all 5 items
done.** Nothing outstanding from that doc. Verification pass on the
prior session's changes came back clean (brace/paren balance, XML
well-formedness on all new layouts + 14 icon drawables, no duplicate
IDs, view-binding class names cross-checked, `saveCategory()` call site
confirmed, all referenced colors/strings/drawables confirmed present).

One real bug was caught and fixed in the process: `MenuFragment.kt` had
three unqualified `MaterialAlertDialogBuilder(...)` calls with no import
— would not have compiled. Fixed (import added, confirmed against
`CouponManagerActivity.kt`'s working usage + `build.gradle`'s
`material:1.11.0` dependency). This is exactly the kind of bug a real
Gradle build would catch in seconds and manual inspection can miss —
another data point for why the standing "run a real build" ask matters.

## Two standing asks from the app owner — still need a real toolchain

Unchanged, still the top blocker:

1. **Run a real Gradle build for the Restaurant app.** FIVE sessions of
   entirely unverified-by-compiler surface now stacked: the notification-
   service rewrite, the coupon-edit-dialog session, the doc-22 coupon
   slice (2026-08-18), the category-icon-picker + dialog-modernization
   session (2026-08-19a), and this session's fixes on top (2026-08-19b).
   This session did catch and fix one real compile-breaking bug by
   inspection, which is a good sign the manual process works — but it's
   not a substitute for an actual build.
2. **Run the still-pending migrations against the live DB**, three deep:
   `26_migration_address_delete_fk_fix.sql`,
   `27_migration_coupon_archive.sql` (2026-08-18), and
   `28_migration_category_icon_key.sql` (2026-08-19a). All verified
   correct/idempotent by inspection (same CONTINUE-HANDLER/conditional-
   ALTER pattern as every prior migration), none confirmed run. Still
   also unconfirmed: whether `23_migration_restaurant_banners.sql` and
   `24_migration_default_radius_setting.sql` ever ran — five-plus
   sessions old now.

## Next feature work

With doc 22 closed, resume doc 18's recommended build order:
1. Notification bell
2. Reviews reply
3. Settings (GST/FSSAI/language/dark mode)
4. Payments/settlement
5. Analytics
6. Staff management
7. Rider App last

No item in this list has been started yet — pick up at #1 (notification
bell) unless the app owner has a different priority.

## Standing risk — build verification

**Still not build-verified — 21+ sessions running, no Android SDK, no
PHP CLI, no network access in this sandbox.** Do the moment a real
toolchain is available. Priority order, unchanged:
1. **Full Gradle build for the Restaurant app** — see "two standing
   asks" above.
2. **Category icon picker end to end**: add a category, tap "Choose
   icon", confirm the grid renders and picking one updates the preview
   and clears any staged photo; edit an existing icon-only category,
   confirm it pre-fills the right icon selected in the grid; upload a
   photo over an existing icon-only category, confirm the icon clears
   server-side (`icon_key` should go NULL, not just get ignored); confirm
   the category list row itself now shows the bundled icon (new this
   session — `CategoryAdapter.kt`'s render branch was untested by a real
   renderer).
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
