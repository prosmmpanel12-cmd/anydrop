Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"Order Management small additions: prep-time select + loud sound on new
order" (2026-08-17, at the very top of the file).

## Just finished this session — Order Management small additions (doc 18 item #2)

Prep-time select (10/15/20/30 min, `PrepTimeDialog`) wired into both
Accept paths (`OrderAdapter`, `OrderDetailActivity`); loud alarm-tone +
vibration alert (`NewOrderAlertSound`) fires from `OrdersFragment`'s
existing 10s poll when a genuinely new pending order shows up. No backend
changes needed — `orders-accept.php`/`orders-reject.php` already had the
fields. New `VIBRATE` permission added to the manifest.

## Next feature work

Admin approve/reject is now done too (see `docs/Status.md`'s newest
entry, 2026-08-18 — `backend/admin/`, session-auth per doc 02 §6).
Resume doc 18/19's build order from there: **Coupon system** next, then
notification bell, reviews reply, settings, payments, analytics, staff,
then Rider App last.


## Standing risk — build verification

**Still not build-verified — now SEVENTEEN+ sessions running, no Android
SDK, no PHP CLI, no network access in this sandbox.** Do the moment a
real toolchain is available. Priority order for what to eyeball first,
updated for this session's changes:
1. **The crop screen end to end** (item #2) — pick a photo in each of the
   3 wired pickers (logo, dish photo, category photo), confirm the ratio
   chips behave, confirm EXIF-rotated photos crop right-side-up, confirm
   the cropped result actually uploads correctly.
2. **BannerManagerActivity end to end** (item #3, owner side) — add a
   banner, confirm it appears in the grid immediately, delete one,
   confirm the 10-banner cap's error surfaces sensibly.
3. **RestaurantBannerCarouselView end to end** (item #3, customer side) —
   open a restaurant with 0/1/2+ banners uploaded, confirm each of the
   three display modes looks right and the 2+ case actually auto-advances
   and stops when you navigate away.
4. Everything from prior sessions' checklists (Edit Profile screen,
   location picker, Menu tab drag-to-reorder + photo upload UI, backend
   endpoints, Orders tab) — unchanged, still pending.

Test login: demo@anydrop.test / Demo@1234 (via
backend/scripts/seed-test-data.php?key=SEED_ME if not already seeded).
QA account: test@anydrop.com / test.

CI: GitHub Actions workflow at .github/workflows/build-apks.yml builds
both APKs on push to `master` (not `main`).
