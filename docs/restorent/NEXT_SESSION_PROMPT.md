Restaurant app — continue from here.

**Read `docs/restorent/00_Status.md`'s newest entry in full first** —
"Item #3 finished: Customer-app banner carousel built + wired"
(2026-08-17, at the very top of the file), and the entry right below it
for the fuller item #2/#3 build detail.

## App-owner's 4 real-device-feedback items — ALL CODE-COMPLETE this session

1. **Dish images should always be the owner's actual uploaded photo** —
   DONE. Fixed the WhatsApp-Status-style dish carousel reading from a
   never-synced `restaurant_gallery_photos` seed table; now reads live
   from `menu_items` in both `restaurants/list.php` and `search/search.php`.

2. **Crop option on photo/logo upload, with a visible ratio** — DONE.
   `ui/common/CropImageView.kt` + `CropActivity.kt` (Restaurant app),
   wired into the logo, dish-photo, and category-photo pickers.

3. **Restaurant banners — multiple with a transition, single = fixed** —
   DONE, both halves. Backend (`23_migration_restaurant_banners.sql` +
   3 endpoints + `menu.php` response field), Restaurant app's
   `BannerManagerActivity` (owner uploads/deletes, Account tab →
   "Restaurant Banners"), and now the Customer app's
   `RestaurantBannerCarouselView` wired into
   `RestaurantDetailActivity`'s header (0 banners = existing cover_url
   fallback, 1 = static, 2+ = auto-advancing carousel with dots).

4. **Live GPS + choose location on map** — Confirmed already fully built
   in an earlier session (`LocationPickerActivity.kt`). Still needs a
   real Google Maps API key before it renders anything but a blank/grey
   map.

**Nothing left to build for this round of feedback.** What's left is
deploy/ops (below), then resuming the project's normal backlog.

## Before any of this session's new features work on a live device

1. **Run `backend/sql/23_migration_restaurant_banners.sql`** against the
   live DB — new table, not an ALTER; `banner-upload.php`/
   `banners-list.php` will 500 without it.
2. Confirm `uploads/restaurant_banners/` gets created on the live server
   (banner-upload.php `mkdir`s it itself on first upload, so this should
   be self-healing, but worth a first-upload sanity check — same
   unconfirmed-on-InfinityFree caveat prior sessions noted for
   `restaurant_logos/`/`restaurant_dish_photos/`).

## Next feature work, once the above is confirmed live

Resume doc 18 §"Recommended build order" from where it left off before
this session's bug-fix/feedback detour: Admin-side "Approve/Reject
pending restaurants" screen (still overdue — self-signup produces
`status='pending'` rows with no approval path except a manual DB
UPDATE), then coupons, notification bell, reviews reply, settings,
payments, analytics, staff, then Rider App last.

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
