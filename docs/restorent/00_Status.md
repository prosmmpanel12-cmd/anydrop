## 2026-08-18 (latest) — Build fix: `OrdersFragment.kt` compile error from the prep-time/loud-sound session

First-ever real GitHub Actions Gradle build run came back for both apps.
**Customer app: `BUILD SUCCESSFUL`, 0 errors** (first confirmed-working
build in the project's history). **Restaurant app: `BUILD FAILED`, 1
Kotlin compile error**, introduced by the immediately-prior session's
"loud sound on new order" addition:

```
e: OrdersFragment.kt:183:36 Type mismatch: inferred type is Set<Int> but MutableSet<Int>? was expected
```

`knownNewOrderIds` was declared `MutableSet<Int>?` but every write to it
is a full reassignment (`knownNewOrderIds = currentIds`, where
`currentIds` is `.toSet()` — an immutable `Set`), never an in-place
`.add()`/`.remove()` mutation — so `MutableSet` was simply the wrong
type. **Fixed** by retyping the field to `Set<Int>?`; no behavior change,
pure type fix. This was the only error in the whole log.

**Not yet re-verified** — only confirmed by reading the failing log, not
by re-running Gradle (still no Android SDK in this sandbox). Next
real-device/CI pass should confirm this specific fix is enough before
trusting the rest of that session's `NewOrderAlertSound`/`PrepTimeDialog`
work is otherwise fine (the rest of that log had no other errors, so
there's no reason to suspect more, but it's still unconfirmed).

---

## 2026-08-17 — Order Management small additions: prep-time select + loud sound on new order

Closes the item #2 gap from `docs/18`'s recommended build order (§"Order
Management") that every session since kept deferring in favor of other
asks — genuinely un-started until now (only `estimated_prep_minutes`/
`cancellation_reason` model fields and backend support existed; no UI).

### ✅ Preparation-time select (10/15/20/30 min) on Accept
- New `ui/common/PrepTimeDialog.kt` — shared single-choice `AlertDialog`
  (no new layout/ChipGroup, matches this app's existing quick-dialog
  pattern), defaults to 20 min (matches `orders-accept.php`'s own
  fallback when nothing is sent).
- Wired into **both** accept paths so neither one silently skips it:
  `OrderAdapter`'s inline Accept button (Orders tab → New section) and
  `OrderDetailActivity`'s full-screen Accept button. `OrderAdapter.onAccept`
  changed from `(Order) -> Unit` to `(Order, Int) -> Unit` to carry the
  chosen minutes through to `OrdersFragment.acceptOrder()`, which now
  sends `AcceptBody(estimatedPrepMinutes = ...)` instead of the old
  always-empty `AcceptBody()`. No backend changes needed —
  `orders-accept.php` already accepted and stored this field.

### ✅ Loud sound on new order
- New `ui/common/NewOrderAlertSound.kt` — plays the device's default
  **alarm** tone (not notification — alarm tones are conventionally
  louder/longer and use a stream users don't routinely leave silenced)
  looped for 8s, plus a vibration pattern, when `OrdersFragment`'s 10s
  poll (`loadNew()`) sees a pending-order id it hadn't seen on the
  previous poll. First load never fires it (would false-trigger for
  every already-pending order the instant the app opens) — only a
  genuinely *new* id after that baseline counts. No bundled custom audio
  asset — this sandbox has no network to source one and no Android SDK
  to confirm one plays correctly; swap in a real branded `res/raw` file
  later once that's possible.
- Stops immediately on accept/reject (staff acknowledged it), on
  `onPause`/`onDestroyView` (don't keep ringing off-screen), and
  auto-stops after 8s either way.
- New `VIBRATE` permission added to `AndroidManifest.xml`.

### What's left from doc 18's Order Management section
- "Ready for Pickup" reachability — already fine, no change needed
  (`preparing → ready` was already a working button in both
  `OrderAdapter` and `OrderDetailActivity`).
- OTP Verification (delivery boy) — intentionally Rider-App/Phase K
  scope, not restaurant-app scope, per the doc's own note.

### 🟡 Not build-verified
Same standing sandbox limitation as every entry above (no Android SDK).
Priority check next real-device pass: (a) the alarm tone actually plays
and is audible over the notification stream, (b) the prep-time dialog
shows on both accept paths and the chosen value actually lands in
`orders.estimated_prep_minutes`, (c) the alert doesn't double-ring when
two new orders land in the same 10s poll window.

### ⏭️ Next
Per doc 18 §"Recommended build order" / `NEXT_SESSION_PROMPT.md`:
Admin-side "Approve/Reject pending restaurants" screen (still overdue,
self-signup produces `status='pending'` rows with no approval UI), then
Coupon system, Notification bell, Reviews reply, Settings
(GST/FSSAI/language/dark mode), Payments/Settlement, Analytics, Staff
Management, Rider App last.

---

## 2026-08-17 — Admin-configurable default radius + "Use current location" split into its own GPS-only row

Two more app-owner asks, both done:

### ✅ Admin-configurable default delivery/search radius
Ask: "5km default, baaki ka admin panel se distance aayega — 5km ke
under koi restaurant ho to show karo, warna Not Available."

- `restaurants/list.php` already filtered restaurants beyond
  `delivery_radius_km` (per-restaurant, defaults to 5.0) — that part
  existed since an earlier session. What was hardcoded was the **5.0
  fallback itself**; now reads `app_settings['default_delivery_radius_km']`
  via the existing `lib/settings.php` helper (`get_setting()`), seeded to
  `5` by `backend/sql/24_migration_default_radius_setting.sql`. No admin
  UI to edit this yet (the Admin Panel itself doesn't exist — see
  NEXT_SESSION_PROMPT.md's standing backlog item), so for now it's a
  direct `UPDATE app_settings SET value = '<km>' WHERE \`key\` =
  'default_delivery_radius_km'` — same manual-SQL stopgap every other
  app_settings value this project has needs until the Admin Panel ships.
- The "Not Available" empty state the second half of this ask wants
  **already existed** (`HomeActivity.setServiceAreaUnavailable()`,
  `service_area_unavailable_title`/`_message` strings — "We're not
  available in your area yet") — built in an earlier session, nothing new
  needed there. It already fires whenever the plain unfiltered Home feed
  comes back with 0 restaurants, which is exactly what happens once
  nothing is within radius.
- Also added `out_of_range_count` to `list.php`'s response `meta` (how
  many restaurants were excluded specifically by the radius check, as
  opposed to other filters) and a matching nullable field on the
  Customer app's `PageMeta` — not consumed anywhere yet, but available
  for a future "N restaurants nearby but outside your area" messaging
  if ever wanted; today's binary Not-Available state doesn't need it.

### ✅ Restaurant location split into 2 separate entry points
Ask: "usko 2 bhag mein dalo — ek GPS se lat/long nikalega, ek choose on
map wala."

Previously Edit Profile had one "Set restaurant location on map" row
that always opened `LocationPickerActivity` (the full Google-Maps-SDK
screen) — even for the common "I'm standing in my restaurant right now,
just use my GPS" case. Split into two rows in `activity_edit_profile.xml`:
- **"Use current location (GPS)"** — new code, entirely inside
  `EditProfileActivity.kt`, plain `LocationManager` + permission request,
  **no Maps SDK involved at all**. Sets `pickedLat`/`pickedLng` directly
  and shows a success toast. Notably this means the GPS half of location-
  setting now works even before a real `google_maps_key` is configured
  (see LocationPickerActivity's own kdoc for that standing caveat) — it
  never touches `GoogleMap`.
- **"Choose on map"** — unchanged, still opens `LocationPickerActivity`
  for manually dragging a pin (still needs the real Maps key).
- Both rows write into the same `pickedLat`/`pickedLng` staged fields
  (only one location per restaurant, saved the same way either way);
  a new `pickedLocationSource` enum (GPS/MAP, UI-only, never sent to the
  backend) drives each row's own label text so re-opening the screen
  shows which one was actually used most recently.

### 🟡 Not build-verified
Same standing sandbox limitation as every entry above.

### 🔴 Before deploying
Run `backend/sql/24_migration_default_radius_setting.sql` against the
live DB (new app_settings row — INSERT ... ON DUPLICATE KEY UPDATE, safe
to re-run, but still needs to run once).

---

## 2026-08-17 (very latest) — Item #3 finished: Customer-app banner carousel built + wired

Completed the piece flagged as "not started" in the previous entry.

- `customer/.../ui/common/RestaurantBannerCarouselView.kt` +
  `view_restaurant_banner_carousel.xml` — near-copy of
  `DishPhotoCarouselView`'s "0 = fallback image, 1 = static, 2+ =
  auto-advance" shape and attach/detach lifecycle, simplified: plain dot
  indicators (reusing the existing `dot_carousel_selected/unselected`
  drawables from another carousel already in this app) instead of
  Stories-style progress segments, no dish-name/price overlay.
- `activity_restaurant_detail.xml` — the header's static `coverImage`
  ImageView replaced with `<RestaurantBannerCarouselView
  android:id="@+id/bannerCarousel">`.
- `RestaurantDetailActivity.kt` — both places that used to
  `binding.coverImage.load(...)` (the instant intent-extra placeholder in
  `onCreate()`, and the real data in `populate()`) now call
  `binding.bannerCarousel.setBanners(...)`; `onCreate()`'s call passes an
  empty banner list (so it falls back to the intent-extra `coverUrl`
  exactly as before), `populate()`'s call passes the real
  `restaurant.banners.orEmpty()` from the menu.php response.
- `network/Models.kt` (Customer app) — `RestaurantDetail` gained a
  `banners: List<String>? = null` field.

**Item #3 (restaurant banners) is now fully built end to end** — owner
uploads via Restaurant app's Account → Restaurant Banners, customers see
them auto-carousel (or static, or fall back to cover_url) at the top of
the restaurant detail page. All 4 of this session's app-owner feedback
items are now code-complete. What's left is entirely deploy/ops, listed
below — nothing left to *build* for this round of feedback.

### 🔴 Before this can work on a live device
1. Run `backend/sql/23_migration_restaurant_banners.sql` — new table,
   `banner-upload.php`/`banners-list.php` will 500 without it.
2. Confirm `uploads/restaurant_banners/` exists (or gets auto-created —
   `banner-upload.php` does `mkdir` itself) on the live server.

### 🟡 Not build-verified
Same standing sandbox limitation as every entry above.

---

Continuing from app-owner's 4 real-device-feedback items this session
(#1 dish-carousel bug — fixed and logged separately above; #4 GPS/map
picker — confirmed already fully built, no changes needed). This entry
covers #2 and #3.

### ✅ #2 — Crop tool on every photo upload (Restaurant app) — DONE
Built from scratch with plain Canvas/Matrix, **no new third-party crop
library** — deliberate, since this sandbox has no network access to
resolve a new Maven dependency and no way to build-verify one either.
- `ui/common/CropImageView.kt` — pan (drag) + pinch-zoom a bitmap inside a
  fixed-aspect-ratio window, rule-of-thirds grid, dimmed outside-window
  overlay via saveLayer+CLEAR punch-out. `getCroppedBitmap()` maps the
  window back into full source-resolution bitmap space via the inverted
  matrix, so crop quality doesn't depend on on-screen window size.
- `ui/common/CropActivity.kt` — hosts the view, downsamples the source
  image (max 2048px side, OOM safety), corrects EXIF rotation
  (`androidx.exifinterface:exifinterface:1.3.7`, newly added to
  `app/build.gradle`), shows ratio chips per "slot": `SLOT_SQUARE_ONLY`
  (logo, category icon — always square everywhere they're displayed),
  `SLOT_DISH_PHOTO` (square/4:3/4:5, owner's choice), `SLOT_BANNER`
  (16:9/4:3, for #3 below). Returns a cropped JPEG as a plain `file://`
  Uri (not FileProvider — never leaves this app process) via
  `CropActivity.start()`/`getResultUri()`.
- Wired into all three existing pickers: `EditProfileActivity`'s logo
  picker, `MenuFragment`'s dish-photo and category-photo pickers. Each
  picker's `GetContent()` callback now launches `CropActivity` instead of
  staging the raw picked Uri directly; the crop result becomes the staged
  Uri exactly where the raw pick used to go, so the rest of each upload
  flow (stage-locally, upload-on-Save) is unchanged.
- `activity_crop.xml` (dark full-bleed, Cancel/Done header, ratio chip
  row, hint text) + new strings (`crop_*`).

### 🟡 #3 — Restaurant banners — backend + owner-side UI done, Customer-app display NOT built yet
Ask: after opening a restaurant's page, show the restaurant's own
uploaded banner(s) as a carousel — auto-transition if 2+, stay static/
fixed if exactly 1.

**Done:**
- `backend/sql/23_migration_restaurant_banners.sql` — new
  `restaurant_banners` table (id, restaurant_id, image_url, sort_order,
  created_at). Own table, not a reuse of `restaurant_gallery_photos`
  (that table was just retired from the dish-carousel this same session
  for the exact "never synced" reason it should not be reused for
  banners either — banners are a restaurant-curated upload, not derived
  from menu_items).
- Backend endpoints (`backend/api/v1/restaurant/`):
  `banner-upload.php` (writes straight to DB, unlike logo — no separate
  Save step for a standalone add/remove list; 5MB cap, 10-banner-per-
  restaurant soft cap), `banners-list.php` (owner's own banners, for the
  manager screen), `banner-delete.php` (restaurant-scoped DELETE, same
  ownership-guard pattern as menu-items-delete.php).
- `backend/api/v1/restaurants/menu.php` — now also returns
  `restaurant.banners: string[]` (ordered by sort_order), the same
  response the Customer app's `RestaurantDetailActivity` already calls.
  **This is the one piece the Customer-app carousel (not built yet, see
  below) will consume.**
- Restaurant app (owner-facing manager, fully wired):
  `ApiService.kt`/`Models.kt` (`Banner`, `BannersListResult`,
  `BannerUploadResult`, `BannerDeleteBody`, 3 new endpoint methods),
  `ui/account/BannerAdapter.kt` (grid adapter), `ui/account/
  BannerManagerActivity.kt` (list/add-via-crop/delete, each an immediate
  network call — no form-Save step, see banner-upload.php's kdoc),
  `activity_banner_manager.xml` + `item_restaurant_banner.xml` layouts,
  new AccountFragment row ("Restaurant Banners" → opens
  BannerManagerActivity), manifest registration, new strings
  (`banner_*`, `account_row_banners`).

**🔴 Not started — next session's first task:**
1. **Customer app `RestaurantBannerCarouselView`** — the actual "app-
   owner sees banners on the restaurant page" half of this ask. Nothing
   has been built here yet; `RestaurantDetailActivity`'s header still
   shows only the single static `cover_url` image (`binding.coverImage`).
   Suggested approach (mirrors `DishPhotoCarouselView.kt`, which already
   solves "2+ = auto-transition, else static" for the *dish* carousel —
   reuse that same shape, simplified: no per-photo dish-name/price
   overlay, no Stories-style progress segments needed unless the app
   owner specifically wants that look for banners too, just a plain
   crossfade + dot indicators is a reasonable default): read
   `restaurant.banners` (already in the menu.php response, see above) →
   0 banners = keep showing `cover_url` as today; 1 banner = static,
   shown full-width in place of/instead of cover_url; 2+ = auto-advancing
   crossfade carousel with dot indicators, same `onAttachedToWindow`/
   `onDetachedFromWindow` start/stop lifecycle pattern.
2. Add the new `<ImageView>`/custom-view slot to
   `activity_restaurant_detail.xml`'s header area (replacing or sitting
   above the existing `coverImage`) and bind it from
   `RestaurantDetailActivity.kt`'s `populate()` (same method that already
   binds `restaurant.coverUrl`).
3. Run `backend/sql/23_migration_restaurant_banners.sql` against the live
   DB (new table, needed before `banner-upload.php`/`banners-list.php`
   will work at all — same standing "run new migrations before testing"
   note every session's had since item 4's `22_migration_category_image.sql`).
4. Confirm `uploads/restaurant_banners/` gets created on the live server
   (same standing item as `restaurant_logos/`/`restaurant_dish_photos/`
   from prior sessions — none of these confirmed to exist on InfinityFree
   yet).

### 🟡 Not build-verified
Same standing sandbox limitation as every session — no Android SDK, no
PHP CLI, no network. Everything above is a static/logical
implementation (grep-verified id/binding matches between new XML and
Kotlin, verified against this project's existing patterns for
upload/crop/list/delete flows), not compiled or run.

---

App owner reported the auto-advancing "WhatsApp Status"-style dish-photo
carousel on restaurant cards (`DishPhotoCarouselView`, §2.7) was showing
wrong/old images with the wrong dish name+price, not whatever the owner
had actually just uploaded through the Menu tab.

**Root cause:** `restaurants/list.php` and `search/search.php` both
sourced this carousel's photos from `restaurant_gallery_photos` — a
table that is **only ever populated by a one-time SQL seed**
(`12_seed_gallery_from_menu_items.sql` / `10_seed_restaurant_gallery_photos.sql`).
Neither `menu-items-create.php`, `menu-items-update.php`, nor
`menu-item-photo-upload.php` ever write to that table. So any dish photo
an owner uploaded or changed after the one-time seed ran had zero effect
on the carousel — it just kept showing whatever was seeded at that
moment (including, for a fresh test restaurant, whatever stray image
happened to be in `menu_items.image_url` at seed time).

**Fix:** both endpoints now build the carousel's photo list by querying
`menu_items` directly (`image_url`/`name`/`price`, `deleted_at IS NULL`,
`is_available = 1`, `image_url` non-empty), ordered
`is_bestseller DESC, is_recommended DESC, updated_at DESC` and capped to
`MAX_GALLERY_PHOTOS = 6` per restaurant in PHP (no SQL window function,
same constraint the `_no_window` seed variant was written for). No table
to keep in sync anymore — the carousel now always reflects whatever the
owner currently has uploaded. `restaurant_gallery_photos` and its two
seed scripts are no longer read by anything; safe to drop in a future
cleanup pass, left in place for now in case anything else references it.

### 🟡 Not build-verified
Same standing sandbox limitation — logical/grep-verified fix (checked
`menu_items` schema columns exist, checked both call sites), not run
against a live DB.

---

## 2026-08-17 (later still) — Full audit of remaining `.load()` sites: 7 more bugs fixed, 4 confirmed NOT bugs

Followed up on the previous entry's "audit the rest" flag. Checked all
~12 remaining `.load()` call sites in the Customer app, one by one,
against their actual backend source column — some are relative upload
paths (need the prefix), some are admin-curated **absolute** URLs
(flaticon/Unsplash CDN links seeded directly in SQL) that would actually
**break** if prefixed. Confirmed each with the backend query/seed data
before touching anything, per the previous entry's own warning not to
assume.

### ✅ Fixed — same missing-prefix bug, confirmed relative paths from `menu_items`/`restaurants`
- `DishPhotoCarouselView.kt` — fixed once in `loadImage()`, which
  automatically covers both its callers: the Home screen's restaurant
  card gallery-photo carousel *and* its cover-image fallback
  (`RestaurantAdapter.kt` didn't need its own change).
- `SavedDishAdapter.kt`, `SearchResultsAdapter.kt`,
  `PopularItemsAdapter.kt`, `ItemDetailBottomSheetFragment.kt` — all load
  `menu_items.image_url`, same as the already-fixed `MenuAdapter`.
- `OrderHistoryAdapter.kt` — `restaurants.cover_url`.
- `SavedRestaurantAdapter.kt` — `restaurants.cover_url` / `logo_url`.

### ✔️ Confirmed NOT a bug — already absolute URLs, do not prefix
- `FoodCategoryAdapter.kt` (`category.iconUrl`) — `food_categories.icon_url`
  is seeded with full `flaticon.com` CDN links
  (`05_migration_categories_and_tags.sql`), not restaurant uploads.
- `PromoBannerAdapter.kt` (`banner.imageUrl`) — `promo_banners.image_url`
  seeded with full Unsplash URLs (`06_migration_phase36.sql`).
- `HomeActivity.kt`'s promo banner (`config.homePromoImageUrl`) and
  `BannerCarousel.kt`'s splash-screen fallback (`fallbackUrl`) — both
  come from the `home_promo_image_url`/`splash_banner_image_url`
  `app_settings` keys, also seeded as full Unsplash URLs
  (`03_migration_splash_login_settings.sql`), with the seed comment
  itself saying "replace with your own **hosted** image" — i.e. this
  field is meant to hold whatever absolute URL an admin pastes in, by
  design, not an upload-server path.
- `InAppNotifier.kt`'s `imageUrl` param — not a bug either way, just
  unused: grepped for callers, nothing in the app currently passes
  `imageUrl` to `InAppNotifier.show()`. Left alone; whoever wires this up
  first should decide its convention then (relative upload vs. admin-
  pasted URL) based on where the image is actually meant to come from.

**Every reachable dish-photo, restaurant-logo, restaurant-cover, and
category-icon image field driven by a restaurant/admin upload now
resolves through `ApiClient.baseUrlForStaticFiles()` somewhere in the
chain.** The only fields still holding raw values are the ones that are
supposed to (admin-curated absolute CDN URLs).

### 🟡 Still not build-verified
Same standing sandbox limitation. All of the above are static/logical
fixes and backend-seed-data cross-checks, not compiled or run.

---

## 2026-08-17 (later) — Customer app: dish photos, category icons, restaurant logo not rendering (bug report + fix)

App owner reported that item 4's photo uploads (dish + category, plus
the Restaurant app's Account-tab logo upload) weren't showing up
anywhere on the **Customer app**'s restaurant detail screen — screenshot
showed Butter Chicken/Dal Makhani with no thumbnails at all. Root cause
was **three separate, unrelated gaps**, not one bug:

1. **Dish photos** — `MenuAdapter.kt`'s `ItemVH.bind()` called
   `binding.itemImage.load(item.imageUrl)` with the raw relative path
   straight from `restaurants/menu.php`, not prefixed with
   `ApiClient.baseUrlForStaticFiles(context)`. Exact same bug class as the
   Restaurant app's `MenuItemAdapter` fix from item 4 — just never made
   it to this app's own equivalent code. Fixed.
2. **Category icons** — didn't exist on the customer side *at all*.
   `restaurants/menu.php`'s category SQL never selected `image_url` and
   never included it in the response, even though
   `22_migration_category_image.sql` added the column and the Restaurant
   app has been able to set it since item 4. Added `image_url` to the
   category SELECT + response in `menu.php`, added `imageUrl` to the
   Customer app's `MenuCategory` model, and gave
   `item_menu_category_header.xml` an actual icon slot (was a bare
   `TextView` before — now a `LinearLayout` with a 28dp rounded icon +
   title, tinted `ic_menu_list` placeholder when unset, same
   pattern as `MenuItemAdapter`'s no-photo state).
3. **Restaurant logo** — also didn't exist anywhere in the Customer app's
   UI. `RestaurantDetail.logoUrl` was already modeled (unused) since the
   bug-1.1 fix session. Added a 40dp circular `detailLogoCard` next to
   the restaurant name in `activity_restaurant_detail.xml`
   (`layout_constraintStart_toEndOf` chained so the name's start
   position collapses back to normal when the card is `gone`), bound
   from `restaurant.logoUrl` in `RestaurantDetailActivity.kt`. Hidden
   entirely (not a placeholder) when a restaurant hasn't set one yet.

**Also fixed while in this code:** the restaurant *cover* image had the
identical unprefixed-URL bug in two places in
`RestaurantDetailActivity.kt` — the initial intent-extra load (before
`menu.php` responds) and the re-bind once `menu.php`'s own
`restaurant.coverUrl` comes back. Neither was reported, but it's the same
root cause and cheap to fix in the same pass.

### 🟡 Known gaps / not done this session
- **No build verification** — same standing sandbox limitation as every
  other session. These are static/logical fixes (grep-verified id
  matches between XML and Kotlin, verified the SQL column exists per
  `22_migration_category_image.sql`), not compiled or run.
- **Did not audit every other `.load()` call in the Customer app** for
  the same unprefixed-path pattern — a `grep -rn "\.load(" | grep -v
  baseUrlForStaticFiles` this session turned up ~14 call sites total
  (`SavedDishAdapter`, `OrderHistoryAdapter`, `SearchResultsAdapter`,
  `PopularItemsAdapter`, `ItemDetailBottomSheetFragment`,
  `FoodCategoryAdapter`, `PromoBannerAdapter`, `HomeActivity`'s promo
  banner, `DishPhotoCarouselView`, `InAppNotifier`, `BannerCarousel`,
  `SavedRestaurantAdapter`). Only the three the app owner actually
  reported (+ the cover-image sibling bug) were fixed here. Some of these
  may be legitimately fine (e.g. admin-configured banner URLs that are
  already absolute), but each one should be checked the same way before
  assuming it's safe — don't assume "same file pattern elsewhere" means
  "already fixed."

### ⏭️ Next
Audit the remaining `.load()` call sites listed above one by one (same
question each time: is this field a relative path from the backend, or
already an absolute URL?) before the next round of on-device testing —
cheap to do now, expensive to rediscover one broken image at a time
during manual QA.

---

## 2026-08-17 — Item 4 client half: verified already complete, no code changes needed (this session)

Opened this session expecting to do `NEXT_SESSION_PROMPT.md`'s checklist
(Models.kt → ApiService.kt → dialog XMLs → MenuFragment.kt → MenuItemAdapter
bug fix → category thumbnail). **Read every file on the checklist before
writing anything, and all six items were already fully implemented and
correctly wired** — `imageUrl`/upload-result classes in `Models.kt`,
`uploadMenuItemPhoto`/`uploadCategoryPhoto` in `ApiService.kt`, both photo-
picker rows in `dialog_add_menu_item.xml`/`dialog_add_category.xml`,
the full staged-Uri-upload-on-Save flow in `MenuFragment.kt` (including
edit-prefill loading the existing photo via `baseUrlForStaticFiles`), the
`baseUrlForStaticFiles` prefix fix in `MenuItemAdapter.kt`, and the
`categoryThumb` ImageView + load logic in `item_menu_category.xml`/
`CategoryAdapter.kt`. All required strings (`btn_add_photo`,
`btn_change_photo`, `photo_upload_failed`) exist too.

This means the prior "not started" status in `00_Status.md`'s 2026-08-16
entry and `NEXT_SESSION_PROMPT.md` was stale by the time of this upload —
the client work must have been done in a session whose Status.md entry
either wasn't written or didn't make it into this zip export. **Lesson
for next time:** always read the actual files the checklist points at
before assuming the doc's "not done" status is current, same caution
`NEXT_SESSION_PROMPT.md` already flags for zip-export gaps in the other
direction (missing files) — this is the mirror case (files present, doc
just outdated).

**All four app-owner real-device-feedback items are now fully done,
backend + client, no remaining gaps.**

### 🟡 Still open (unchanged, no sandbox tooling to act on these)
- **No build/compile verification** — still no Android SDK, PHP CLI, or
  network in this sandbox. Fourteen-plus sessions of code written and
  never actually compiled/run. This is the single biggest risk in the
  project and should be the very next real-world action once a toolchain
  is available — see the priority list in `NEXT_SESSION_PROMPT.md`.
- Three upload directories (`restaurant_logos/`, `restaurant_dish_photos/`,
  `category_photos/`) still unconfirmed to exist on the live InfinityFree
  server.
- The logo-upload `BASE_URL`-points-at-`localhost` question is still open.

### ⏭️ Next
Per doc 18's recommended build order, now that item 4 is confirmed fully
done: **Admin-side "Approve/Reject pending restaurants" screen** — still
overdue, self-signup produces pending rows with no approval path except a
manual DB `UPDATE`. After that, resume doc 18 §"Recommended build order"
(coupons, notification bell, reviews reply, settings, payments, analytics,
staff, then Rider App last). Build verification (above) should happen
alongside/before this, the moment a real toolchain is available — it
isn't gated on any of this feature work.

---

## 2026-08-16 — Logo-upload bug: root cause found and fixed (this session, resolves the open investigation above)

App owner tested against **localhost** (KS Web on-device), not a stale/
missing InfinityFree URL — the previous entry's top suspect is ruled out
by this session's direct evidence: upload succeeded (3 files visible in
`uploads/restaurant_logos/` via file manager), and opening the uploaded
file's URL directly in the phone's browser (`http://localhost:8080/anydrop/uploads/restaurant_logos/<file>.png`)
rendered the correct orange AnyDrop logo fine. So serving, upload, and
URL-building were never the problem.

**Actual root cause:** `fragment_account.xml`'s `profileLogoThumb` and
`activity_edit_profile.xml`'s `logoPreview` both set
`app:tint="@color/text_secondary"` in XML, intended only to mute the
`ic_store` placeholder icon's color. An ImageView's tint applies to
*whatever drawable is currently set*, not just the placeholder — so once
Coil loaded the real logo bitmap on top, it got tinted grey too, making
a legitimate orange-logo photo render as a flat grey square. Looked
exactly like "upload works, display doesn't."

**Fix — `AccountFragment.kt` / `EditProfileActivity.kt` (3 call sites):**
`profileLogoThumb`'s profile-load, `logoPreview`'s profile-load, and
`logoPreview`'s picked-Uri preview-on-pick all now clear
`imageTintList = null` via Coil's `listener(onSuccess = ...)` once a real
image loads successfully, and restore the `text_secondary` tint via
`onError` if the load fails back to the `ic_store` placeholder. No XML
changes — the static `app:tint` still gives the placeholder its intended
muted look at rest.

**Also fixed same session:** `profile-update.php` now deletes the
previous `logo_url` file from `uploads/restaurant_logos/` (realpath-
guarded against path traversal) whenever a new logo overwrites it or a
logo is cleared — previously every re-upload left the old file orphaned
on disk forever.

**Not yet build-verified** — same standing risk as always (no Android
SDK in this sandbox). Next session opening Account/Edit Profile screens
for the first time should confirm the logo now renders in its real
colors, not tinted.

---

## 2026-08-16 — Dish + category photo upload: backend only, partial (this session, closes app-owner item 4 of 4's SMALLER half + DB half only — client/UI not started)

Fourth and last of the four app-owner real-device-feedback items
(NEXT_SESSION_PROMPT.md). **Backend + migration done this session; the
Kotlin client (models, upload calls, dialog UI, adapter thumbnails) is
NOT done — this session ran out before reaching it.** Anyone picking this
up next should read this entry in full before touching client code, since
the backend contract is now fixed and the client needs to match it exactly.

### ✅ Done
**Migration:**
- `backend/sql/22_migration_category_image.sql` — new, adds
  `menu_categories.image_url VARCHAR(255) NULL` (idempotent conditional-
  ALTER, same pattern as `16_migration_address_photo.sql`). **Must be run
  before `categories-create.php`/`categories-update.php` are hit** —
  those two now unconditionally reference the `image_url` column in their
  INSERT/UPDATE, so category creation will hard-fail with a SQL error on
  any DB this migration hasn't been run against yet. `menu_items.image_url`
  needed no migration — it already existed in `01_schema.sql`.
- `docs/01_Database_Schema.md` updated to document the new column.

**Backend — two new upload endpoints**, same shape/pattern as
`logo-upload.php` (5MB cap, jpg/png/webp mime-sniff, upload-then-save
split — endpoint only uploads and returns a path, doesn't write the DB):
- `menu-item-photo-upload.php` — field name `photo`, saves to
  `uploads/restaurant_dish_photos/`, returns `{ image_url: "..." }`.
- `category-photo-upload.php` — field name `photo`, saves to
  `uploads/category_photos/`, returns `{ image_url: "..." }`.
  **Neither `restaurant_dish_photos/` nor `category_photos/` exists yet
  in this repo** — same as `restaurant_logos/`'s situation from the
  logo-upload bug investigation, `mkdir()`'s return isn't checked here
  either, consistent with the existing pattern rather than a new gap.

**Backend — existing endpoints extended to accept/return `image_url`:**
- `menu-items-create.php` — now inserts the client-sent `image_url`
  instead of hardcoding `NULL`.
- `menu-items-update.php` — partial-update now accepts `image_url` (same
  null-skip convention as its other fields — see the file's own kdoc for
  why an explicit clear isn't reachable from this app's default Gson
  setup anyway).
- `categories-create.php` — now inserts/returns `image_url`.
- `categories-update.php` — partial-update now accepts `image_url`, fetch
  query now selects it.
- `categories-list.php` — fetch query + response mapping now include
  `image_url`.
- All six touched/new PHP files brace-balance-checked by hand (still no
  PHP CLI in this sandbox to actually lint them).

### 🔴 Not done this session — the entire client half
Nothing on the Kotlin side has been touched yet. Needed, in roughly this
order:
1. **`Models.kt`** — add `imageUrl`/`image_url` to `MenuCategory`,
   `CategoryCreateBody`, `CategoryUpdateBody`, `MenuItemCreateBody`,
   `MenuItemUpdateBody` (`MenuItem`/`MenuItemsListResult` already have
   `imageUrl` from before — no change needed there). New result classes
   for the two upload responses (mirror `LogoUploadResult`).
2. **`ApiService.kt`** — two new `@Multipart @POST` calls,
   `uploadMenuItemPhoto`/`uploadCategoryPhoto`, mirroring `uploadLogo`'s
   signature (field name `photo`, not `logo`).
3. **`dialog_add_menu_item.xml`** / **`dialog_add_category.xml`** — add a
   photo-picker row to each, same visual pattern as
   `activity_edit_profile.xml`'s `logoPickerRow` block (circular/rounded
   preview + "Add/Change photo" label, tap launches
   `ActivityResultContracts.GetContent()`).
4. **`MenuFragment.kt`** — stage picked Uris the same way
   `EditProfileActivity` stages `pickedLogoUri` (upload only fires on
   dialog Save, not on pick — same cancel-safety reasoning as the logo).
   `showItemDialog()`/`saveItem()` and `showCategoryDialog()`/
   `saveCategory()` all need updating to carry the staged Uri through to
   an upload call before the create/update API call, same two-step flow
   as `EditProfileActivity.save()`.
5. **`MenuItemAdapter.kt`** — **found a pre-existing bug while reviewing
   this for the photo work**: `ItemViewHolder.bind()` calls
   `binding.itemThumb.load(item.imageUrl)` with the raw relative path
   from the API, not prefixed with
   `ApiClient.baseUrlForStaticFiles(context)` the way
   `EditProfileActivity`'s logo preview does it. This was harmless before
   (image_url was always null, so this code path never actually ran) but
   will load broken images the moment real `image_url` values start
   coming back from `menu-items-create/update.php`. **Fix this in the
   same pass as wiring up the picker**, don't ship photo upload without it.
6. **`item_menu_category.xml`** + **`CategoryAdapter.kt`** — category
   rows currently have zero image slot (checked this session — only
   `categoryNameText`/`categoryItemCountText`/edit/delete icons). Needs a
   new thumbnail `ImageView`, same 44dp rounded-square pattern as
   `item_menu_food.xml`'s `itemThumb` (`bg_skeleton_thumb` background,
   `ic_food_placeholder`-style tinted-icon fallback — no
   category-specific placeholder icon exists yet, reusing
   `ic_food_placeholder` is the pragmatic choice unless the app owner
   wants a distinct one).

### 🟡 Known gaps / not done this session
- No build/PHP-lint verification, same standing sandbox limitation.
- Upload directories (`restaurant_dish_photos/`, `category_photos/`) not
  confirmed created on the live server — same open question as
  `restaurant_logos/` from the logo-upload bug investigation, compounding
  it: there are now three restaurant-app upload directories whose
  existence on InfinityFree is unconfirmed, not just one.
- The still-unresolved `BASE_URL`-points-at-`localhost` question from the
  logo-upload bug entry applies here too — untested against a live
  backend either way.

### ⏭️ Next
Finish the client half in the order listed above (Models → ApiService →
dialog XML → MenuFragment wiring → MenuItemAdapter bug fix →
CategoryAdapter thumbnail), then this is genuinely done and all four
app-owner items are closed. After that: resume build verification
(NEXT_SESSION_PROMPT.md's standing priority list) — this is now the
single largest chunk of never-build-tested code in the project.

---



Third of the four app-owner real-device-feedback items. Reuses the
Customer app's H6 pin-drop pattern end to end, per the app owner's
explicit ask, trimmed down for a single-restaurant-address use case (no
photo picker, no receiver-details form, no saved-addresses list).

### ✅ Done
**Backend:**
- `profile-update.php` — accepts `latitude`/`longitude` together (rejects
  a lone coordinate as malformed rather than silently half-applying),
  range-validated (-90..90 / -180..180), can be explicitly cleared with
  nulls. `restaurants` table already had these columns
  (`01_Database_Schema.md`) — no migration needed.
- `profile-get.php` — no change needed, already `SELECT *`s the row.
- `Models.kt` — `latitude`/`longitude` added to `RestaurantProfileDetail`
  and `ProfileUpdateBody`. Confirmed the default (non-`serializeNulls()`)
  Gson instance `ApiClient.kt` uses omits null fields from the JSON body
  entirely when neither is set, matching `array_key_exists`'s
  both-or-neither check on the PHP side — no mismatch between "never
  picked" and "explicitly cleared."

**Client:**
- `build.gradle` — added `play-services-maps:19.1.0` (same version as
  Customer app).
- `AndroidManifest.xml` — `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION`
  permissions, `com.google.android.geo.API_KEY` meta-data (placeholder —
  see caveat below), registered `LocationPickerActivity`.
- `strings.xml` — `google_maps_key` placeholder + all location-picker/row
  strings.
- Ported drawables from the Customer app: `ic_map_center_pin.xml`,
  `ic_target.xml`, `bg_dialog_rounded_top.xml`.
- New `activity_location_picker.xml` — trimmed copy of the Customer
  app's `activity_map_pin_drop.xml`: fixed center pin over a pannable
  map, reverse-geocoded address line, single confirm button.
- New `LocationPickerActivity.kt` — trimmed copy of
  `MapPinDropActivity.kt`'s GPS-fetch/reverse-geocode/camera-idle-debounce
  logic. Returns the picked lat/lng/address line via activity result
  instead of saving directly; opens centered on the restaurant's
  *existing* saved location if one is already set (via
  `EXTRA_EXISTING_LAT`/`EXTRA_EXISTING_LNG`), rather than always
  defaulting to device GPS or the hardcoded Osian/Jodhpur fallback.
- `activity_edit_profile.xml` — new "Set restaurant location on map" row
  below the address field, styled like the existing opening/closing-time
  rows.
- `EditProfileActivity.kt` — launches the picker via
  `registerForActivityResult`, stages the result in `pickedLat`/
  `pickedLng` (same "only applied on Save" pattern as the logo picker —
  cancelling out of Edit Profile without saving never touches the
  server), pre-fills from the loaded profile so re-saving the form
  without touching the location row doesn't clear a location that was
  already there, includes both in the `ProfileUpdateBody` sent on Save,
  and swaps the row's label between "Set…"/"…set ✓" based on staged
  state.
- All new/edited XML validated as well-formed; all edited/new Kotlin
  files brace-balance-checked; `profile-update.php` brace-balance-checked
  (no PHP CLI in this sandbox to actually lint it — standing limitation).
  Cross-checked every layout ID against its Kotlin `binding.` reference
  by hand — all match.

### 🟡 Known gaps / not done this session
- **No build/visual verification** — same standing sandbox limitation as
  every prior session. This is the first screen in the Restaurant app
  that touches Google Maps at all, so it's also the first real test of
  whether `play-services-maps` actually resolves/renders correctly in
  this app's Gradle setup — worth extra attention on first real build.
- **`google_maps_key` is still a placeholder** (`YOUR_ANDROID_RESTRICTED_MAPS_KEY_HERE`,
  same as the Customer app's own unresolved placeholder) — the map area
  will render blank/grey until a real Android-restricted Maps SDK key is
  provisioned and swapped in for both apps. This isn't new to this
  session — the Customer app's H6 doc already flagged its own copy of
  this same key as not yet real — but it now blocks two screens instead
  of one.
- No distance-sanity-check (e.g. "this pin looks far from the address you
  typed") — the Customer app doesn't have an equivalent for restaurants
  to reuse (its `DistanceUtil` is delivery-address-specific, comparing
  against the customer's current location, which isn't the right
  comparison here), and it wasn't asked for. Worth a follow-up if the app
  owner wants it.

### ⏭️ Next
Last remaining app-owner item: dish/category photo upload (dish photos:
DB-ready, just needs an upload endpoint + UI, similar shape to
logo-upload.php; categories: needs a new migration first, bigger lift).

---

## 2026-08-16 — Logo-upload bug: investigation (this session, docs-only — no code changed, still not reproducible)

Second of the four app-owner real-device-feedback items (item 2 of 4 in
the "App owner feedback from real-device testing" entry further down).
Still **not reproducible in this sandbox** — no PHP runtime, no network,
no live server access — but re-read `logo-upload.php`, `EditProfileActivity.kt`,
`address-photo.php` (the working reference), and both apps' `ApiClient.kt`
end to end, and one finding changes the ranking of suspects from the
original bug-report entry.

### 🔴 New, higher-priority suspect: `BASE_URL` is still `localhost:8080`, not InfinityFree
The original bug report entry listed "confirm the app points at the live
InfinityFree URL, not a stale/local BASE_URL" as its **least likely**
suspect. Checked it directly this session — and it isn't stale, it looks
like it was **never set in the first place**:
- `restaurant/app/.../network/ApiClient.kt` line 19:
  `BASE_URL = "http://localhost:8080/anydrop/api/v1/"`. The file's own
  kdoc says *"Only this constant needs to change when the backend moves
  to InfinityFree"* — phrased as a future step, not a completed one.
- `customer/app/.../network/ApiClient.kt` has the identical value and an
  identical "when the backend moves to InfinityFree" comment.
- Grepped the **entire repo** for any InfinityFree-style hostname
  (`.infinityfreeapp.com`, `.epizy.com`, `.rf.gd`, etc.) — zero real
  domains found anywhere, only placeholder examples in
  `backend/scripts/seed-*.php`'s doc-comments (`yourdomain.infinityfreeapp.com`).
- `docs/Status.md`'s Phase-3 entry states plainly: *"Backend currently
  runs locally on-device (KS Web) — must migrate to InfinityFree before
  real use. Only `config/config.php` and each app's `ApiClient.kt` base
  URL need to change."* Nothing in this repo snapshot shows that swap
  ever happened for either app.

**Why this matters more than a "logo upload specifically is broken" bug:**
if `BASE_URL` really is still `localhost:8080` in whatever APK build the
app owner tested, that URL is only reachable from the same device the
backend is running on (or over a LAN, not from a phone hitting it as
"the internet"). That would make it look like *everything* fails, not
just logo upload — unless the app owner's phone/backend setup has some
other way of making `localhost:8080` resolve to something real for them
(e.g. testing PHP locally via KS Web **on the same device**, per
`docs/Status.md`'s "runs locally on-device (KS Web)" wording — this is
plausible for earlier testing, worth asking directly rather than
assuming). **Needs a direct question to the app owner**: what URL is the
tested build's `BASE_URL` actually pointing at, and is a real
InfinityFree domain provisioned yet? If yes and this was just missed in
this zip export, the rest of this entry's original suspects (upload-dir
permissions, ini limits) become the live ones again, in the original
order.

### Also checked this session, ruled out
- **Client-side multipart flow** — `uploadLogo()` in
  `EditProfileActivity.kt` copies the picked content-`Uri` to a cache
  file, builds `MultipartBody.Part.createFormData("logo", ...)`, matches
  `logo-upload.php`'s expected `$_FILES['logo']` field name exactly. No
  bug found here, consistent with the original bug-report entry.
- **PHP logic vs. the known-working reference** — diffed
  `logo-upload.php` against the Customer app's working
  `address-photo.php` line by line: identical validation/mkdir/
  move_uploaded_file structure, only directory name and filename prefix
  differ. Rules out a restaurant-specific server-side logic bug; if the
  live backend is reachable at all, this endpoint should behave exactly
  like the working one.
- **Upload-directory precedent in the repo** — `backend/uploads/address_photos/`
  exists in this repo snapshot (created manually per
  `12_Handover_H6_Map_PinDrop_Photo.md`'s deployment checklist, "created
  empty, `.gitkeep` only" — though no `.gitkeep` survived into this zip
  export specifically). `backend/uploads/restaurant_logos/` does **not**
  exist anywhere in this repo. Since backend deployment to InfinityFree
  is a **manual FTP/cPanel folder copy** (confirmed via the H6 doc's
  deployment checklist — there's no CI/CD step for the backend, only for
  APK builds per `05_Build_Pipeline.md`), whether `restaurant_logos/`
  exists on the live server depends entirely on whether it was in
  whatever `backend/` copy was last uploaded there, or on `mkdir()`
  succeeding on first use (unchecked return value — still the top
  suspect if BASE_URL turns out fine).

### ⏭️ Next
This needs one direct question answered before further investigation is
useful: **what does the tested build's `BASE_URL` actually point at, and
does a live InfinityFree domain exist yet?** Once that's answered:
- If BASE_URL is the problem: needs a real InfinityFree domain, then
  `ApiClient.kt` updated in both apps, a rebuild, and a retest — likely
  fixes far more than just logo upload if so.
- If BASE_URL is fine (e.g. real domain already swapped in on the app
  owner's actual tested build, and this repo zip just doesn't reflect
  it): fall back to the original ranked list — `restaurant_logos/`
  directory permissions/creation first, then PHP ini upload limits —
  with real PHP error-log access to confirm which.
Remaining two of the four app-owner items after this: live location for
restaurant profile, then dish/category photo upload.

---

## 2026-08-16 — Palette revert: back to orange + white (this session, closes doc 19 §8.1.1 item 1 of 4)

First of the four app-owner real-device-feedback items (see the entry
directly below this one) tackled, per app owner's own ranking of it as
lowest-risk/do-first. Full revert of the 2026-08-16 "Exotic Orange +
Midnight Blue ink" palette refresh and the "Pre-login/detail screens ink
pass" that followed it — both entries are further down this file, kept
for history.

### ✅ Done
- **`colors.xml`** — `anydrop_primary` back to `#E64A19`, `anydrop_primary_dark`
  back to `#B23C14`, `anydrop_primary_container` back to `#FFE0D3`; the
  `anydrop_ink`/`anydrop_ink_light`/`text_on_ink`/`text_on_ink_muted`
  tokens removed entirely (grepped first to confirm nothing else in
  `res/` referenced them before deleting).
- **`activity_main.xml`** — shared top bar and `BottomNavigationView`
  backgrounds both back to `@color/surface` (white); `restaurantNameText`
  back to `@color/text_primary`.
- **`themes.xml`** — `statusBarColor` back to `@color/anydrop_primary`;
  `windowLightStatusBar` left `false` (orange is still dark enough to
  need light status-bar icons, same as the pre-refresh app).
- **`bottom_nav_item_color.xml`** — unselected state back to
  `@color/text_secondary`; checked state (`anydrop_primary`) unchanged.
- **`bg_hero_curved.xml`** — gradient back to
  `anydrop_primary`/`anydrop_primary_dark` (was `anydrop_ink`/
  `anydrop_ink_light`), used by both `activity_login.xml` and
  `activity_signup.xml`'s hero panels.
- **`activity_splash.xml`** — background back to flat `anydrop_primary`;
  app name back to `@color/white`, tagline back to
  `@color/anydrop_primary_container`.
- **`activity_otp_verify.xml`** — `btnBack` moved back out of a header
  bar to floating unstyled on the plain background (its pre-ink-pass
  structure) — this one was a structural revert, not just a token swap,
  since the ink pass had introduced a whole new header `LinearLayout`
  that didn't exist before it.
- **`activity_order_detail.xml`** — header background back to
  `@color/surface`; back icon + title back to `@color/text_primary`.
- **`activity_edit_profile.xml`** — same header revert as order detail.
- **`activity_signup_success.xml`** — removed the `FrameLayout`/
  `bg_icon_circle_ink` backdrop entirely, back to a plain `ImageView` for
  the success icon (its pre-ink-pass state, since the ink pass had
  *added* this backdrop rather than recoloring an existing one).
  `bg_icon_circle_ink.xml` deleted as an orphaned drawable — confirmed
  nothing else referenced it first.
- Confirmed clean: grepped the whole `restaurant/` module afterward for
  `anydrop_ink`/`text_on_ink` — zero remaining references anywhere.
- Updated `19_Restaurant_App_UI_Plan.md` §8.1.1 to ✅ with a summary.

### 🟡 Known gaps / not done this session
- **No build/visual verification** — same standing sandbox limitation as
  every prior palette change. All 10 edited XML files were validated as
  well-formed (parsed cleanly), but that only catches syntax errors, not
  visual regressions — a real toolchain should eyeball all 10
  screens/files before shipping, same priority order as the original
  refresh (global chrome first, then the 6 pre-login/detail screens).
- Three of the four app-owner feedback items remain: the logo-upload bug,
  live location for restaurant profile, and dish/category photo upload —
  app owner asked to go through them one at a time, this was just the
  first.

### ⏭️ Next
App owner's remaining three real-device-feedback items, one at a time,
per their original numbering: bug report (logo upload), then live
location, then photo upload. Build verification remains the standing
biggest risk project-wide.

---

## 2026-08-16 — App owner feedback from real-device testing (this session, docs-only — no code changed)

App owner tested the build on a real device (first real-device test this
project has had). Four items came back — recorded here in full so
nothing gets lost, **not fixed/built this session per app owner's own
instruction** ("mention karo, baad mai kar lenge" — document now, build
later). Each item below is a candidate for a future session.

### 1. Palette: revert to orange + white — see doc 19 §8.1.1
App owner's verdict after seeing the ink chrome live: the original
orange + white combo (pre-2026-08-16 palette refresh) looked better than
orange + Midnight Blue ink. Decision recorded in
`19_Restaurant_App_UI_Plan.md` §8.1.1 — revert is deferred to its own
session, not done here. When that session happens, it needs to undo
**both** the original palette-refresh entry (further down this file)
**and** the "Pre-login/detail screens ink pass" entry directly below
this one — the ink pass extended ink into 7 more files that didn't exist
when the original refresh shipped, so a revert has to cover all of them,
not just `colors.xml`/`activity_main.xml`.

### 2. 🔴 Bug report: Logo upload not working on live device
> **Update:** re-investigated in the 2026-08-16 "Logo-upload bug:
> investigation" entry above this one — that entry reprioritizes the list
> below, promoting the BASE_URL check from "less likely" to the top
> suspect. Read that entry first; this one is kept for history.

App owner reports the Edit Profile logo picker/upload doesn't work
against the live (InfinityFree) backend. **Not reproducible in this
sandbox** — no PHP runtime, no network, no way to hit the live server or
see its error log, so this is a bug report to investigate with a real
toolchain, not a diagnosed-and-fixed issue. Reading `logo-upload.php` and
`EditProfileActivity.kt` end to end (this session) didn't turn up an
obvious client-side logic bug — the multipart flow (copy content-Uri to
a cache file, `MultipartBody.Part.createFormData("logo", ...)`, same
pattern as the Customer app's working `address-photo.php` upload)
matches the backend's expected `$_FILES['logo']` field name. Most likely
suspects for whoever debugs this next, roughly in order of likelihood on
InfinityFree specifically:
- **`uploads/restaurant_logos/` directory permissions/creation** —
  `logo-upload.php`'s `mkdir($uploadDir, 0755, true)` return value isn't
  checked, and `move_uploaded_file()`'s failure just returns a generic
  `upload_failed` 500 with no detail on *why*. If InfinityFree's
  `open_basedir` or file-ownership rules block `mkdir`/`move_uploaded_file`
  outside an existing folder, this fails silently from the client's
  point of view — worth creating `uploads/restaurant_logos/` manually via
  cPanel/FTP first and re-testing, and/or temporarily logging
  `error_get_last()` after a failed `move_uploaded_file()` call.
- **PHP `upload_max_filesize`/`post_max_size` ini limits** — InfinityFree
  free tier has historically set these lower than typical (sometimes
  2MB), which would silently truncate/reject an upload before it even
  reaches `logo-upload.php`'s own 5MB check (`$_FILES['logo']['error']`
  would come back as `UPLOAD_ERR_INI_SIZE`, which the current check
  `!== UPLOAD_ERR_OK` does catch, but it reports as a generic
  `validation_error` rather than anything actionable, and the file may
  be well under 5MB and still get rejected).
- Less likely but worth a quick check: confirm the Restaurant app's
  network config actually points at the live InfinityFree URL and not a
  stale/local `BASE_URL` in `ApiClient.kt` for this build.
- **Next step**: once someone has real access to the live PHP error log,
  a single failed upload attempt should immediately show which of the
  above it is — flagging so the next session doesn't have to re-derive
  this list from scratch.

### 3. 🆕 Feature request: live location for restaurant profile
> **Update:** built in the 2026-08-16 "Live location picker for
> restaurant profile" entry above — that entry has the full
> implementation summary. Read that entry first; this one is kept for
> history.

App owner wants a "use my current location" option when updating the
restaurant's address, instead of (or alongside) typing it in as free
text. This is **not new scope** — `profile-update.php`'s own kdoc
already flags `latitude`/`longitude` as deliberately excluded from that
endpoint, with the comment *"needs its own map-picker flow, out of scope
this pass"* (written when that endpoint was first built). The Customer
app already has exactly this pattern built and working —
`ui/address/LocationPickerActivity.kt` (GPS "use current location"
button) and `ui/address/MapPinDropActivity.kt` (drag-a-pin-on-a-map
confirm step) — so the Restaurant app's version should be able to reuse
that same approach rather than designing one from scratch. Needs, for a
future session:
- Backend: a restaurant-specific endpoint (or extend `profile-update.php`)
  to accept/store `latitude`/`longitude` on the `restaurants` row —
  currently only settable via direct DB access.
- Client: a location-picker entry point from `EditProfileActivity`
  (probably next to the address field), reusing/adapting the Customer
  app's `LocationPickerActivity`/`MapPinDropActivity` Kotlin.

### 4. 🆕 Feature request: photo upload for dishes (menu items) and categories
App owner wants to be able to upload a photo per dish and per category
from the Restaurant Management app's Menu tab (currently: no image field
in either the Add/Edit Menu Item dialog or the Add Category dialog —
checked `dialog_add_menu_item.xml`/`dialog_add_category.xml`, neither
has any image UI). Where things currently stand, checked this session:
- **Menu items (dishes): DB is already ready.** `menu_items.image_url`
  has existed in the schema since `01_schema.sql` (used to render the
  Customer app's dish photos, `MenuAdapter.kt` etc.) — but
  `menu-items-create.php` hardcodes `'image_url' => null` on create, and
  neither create nor update endpoints currently accept an image upload.
  So this is "wire up the existing column," not "add a new column" —
  should be the smaller of the two halves. Suggested approach: same
  split as the logo (`logo-upload.php` uploads-and-returns-a-path,
  `profile-update.php` writes the path) — a new
  `menu-item-photo-upload.php` (or extend `menu-items-update.php` to
  accept multipart) plus an image-picker row in
  `dialog_add_menu_item.xml`/`MenuFragment.kt`.
- **Categories: DB is NOT ready.** `menu_categories` has no `image_url`
  (or any image) column at all — needs a new migration
  (`backend/sql/2X_migration_category_image.sql`, following the numbering
  convention of the existing `sql/` migrations) before any category-photo
  UI work can start. This is the larger of the two halves.
- Both would need their own upload endpoint (mirroring
  `logo-upload.php`'s 5MB-cap/mime-sniff pattern) and their own picker UI
  in the Menu tab's add/edit dialogs, plus rendering the photo in
  `MenuItemAdapter`/`CategoryAdapter`'s existing rows.

### ⏭️ Next
App owner explicitly deferred all four of the above — no code changed
this session, docs only. Still standing ahead of all four: build
verification (see `NEXT_SESSION_PROMPT.md`) and the Admin approve/reject
screen, unless the app owner reprioritizes one of today's four items
above them.

---

## 2026-08-16 — Pre-login/detail screens ink pass (this session, closes doc 19 §10 item 7)

Continuing from the uploaded `account-tab-fixed` zip, which already had
both items from the top of `NEXT_SESSION_PROMPT.md` applied (confirmed
by reading the code, not just the doc): `AccountFragment.kt` matches
`fragment_account.xml`'s current IDs and no longer crashes, and
`MainActivity.kt`'s `loadOperationalStatus()` already reads
`isOpen = summary.operationalStatus == "open"`. Nothing to redo there —
moved straight to the next queued item, doc 19 §10 item 7.

### ✅ Done this session
- **`bg_hero_curved.xml`** (shared by `activity_login.xml` +
  `activity_signup.xml`) — gradient switched from
  `anydrop_primary`/`anydrop_primary_dark` (orange) to
  `anydrop_ink`/`anydrop_ink_light`, so both screens' hero panels match
  the app's main dark chrome instead of clashing with it. White hero
  text and the orange form-card accents underneath are unchanged.
- **`activity_splash.xml`** — background moved from flat
  `anydrop_primary` to `anydrop_ink`; app name recolored to
  `text_on_ink`, tagline to `text_on_ink_muted` (was
  `anydrop_primary_container`, tuned for an orange background).
- **`activity_otp_verify.xml`** — `btnBack` moved out of the plain
  background into its own `anydrop_ink` header bar (same pattern as
  order detail's header), tinted `text_on_ink`. `otpTitle`/
  `otpSubtitle` keep their existing IDs/positions — `OtpVerifyActivity.kt`
  needed no changes.
- **`activity_order_detail.xml`** — header background `@color/surface`
  (white) → `@color/anydrop_ink`; back icon + title recolored to
  `text_on_ink`.
- **`activity_edit_profile.xml`** — same header treatment as order
  detail, applied here too even though it wasn't in doc 19's original
  six-screen list (it didn't exist yet when that item was written) —
  its own header comment explicitly said "the dark ink top bar ... is a
  separate, not-yet-done pass," which is this pass. Comment updated to
  reflect that it's now done.
- **`activity_signup_success.xml`** — new `bg_icon_circle_ink.xml`
  drawable (a translucent ~10%-alpha ink circle, not solid) placed
  behind the existing green check-circle icon. Deliberately did **not**
  ink the whole screen background here — the success green icon/copy
  reads best on the light surface, so this screen's ink touch is scoped
  to the icon backdrop rather than a full-screen change, per the plan
  doc's own note that this pass "needs its own layout pass per screen,
  not a token swap."
- Updated `docs/restorent/19_Restaurant_App_UI_Plan.md` §10 item 7 to
  ✅ with a summary of what was actually done, and pointed its "next up"
  line at the Admin approve/reject screen.

### 🟡 Known gaps / not done this session
- **No build/visual verification** — same standing sandbox limitation,
  called out again because this change touches 7 layout files across
  the whole pre-login/detail-screen surface; a real toolchain should
  eyeball all of them (contrast of white/text-on-ink text against the
  ink header bars especially) before shipping.
- Didn't re-touch typography/spacing on any of these screens, only
  color, consistent with the rest of the palette-refresh work.
- The translucent-ink circle behind the signup-success icon is a new
  pattern not used anywhere else in the app (everywhere else ink is
  either full-opacity chrome or not used at all) — flagging as a
  judgment call in case the app owner prefers a different treatment
  there.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md`'s standing order: Admin-side "Approve/
Reject pending restaurants" screen next, then the rest of doc 18's
recommended build order (coupons, notification bell, reviews reply,
settings, payments, analytics, staff, Rider App last). Build
verification with a real toolchain remains the single biggest
outstanding risk project-wide — now an even larger unverified surface
after this session.

---

## 2026-08-16 — Account tab / Edit Profile UI, PARTIAL (later session — continues the entry below)

Continues the "backend + models only" entry directly below this one.
**Still not finished — paused again partway through the UI half.**
`EditProfileActivity` (the form) is done; `AccountFragment` (the tab
itself) is not.

### ✅ Done this session
- **`EditProfileActivity.kt` + `activity_edit_profile.xml`** — the full
  form: circular logo picker (tap opens gallery via
  `ActivityResultContracts.GetContent()`, same copy-to-cache-file +
  multipart approach as the Customer app's `MapPinDropActivity`
  address-photo upload), name/address/cuisine-tags/description fields
  (`TextInputLayout`, same outlined style as Signup), opening/closing
  time rows that open a plain `android.app.TimePickerDialog` (new
  `bg_input_outline.xml` background + `ic_clock.xml`, both copied/
  adapted from existing patterns), a working-days `ChipGroup` (Mon–Sun
  mapped to backend's 1–7 `date('N')` convention, `Chip.isCheckable`
  pattern copied from the Customer app's `SearchFiltersBottomSheet`
  cuisine chips), and Save.
  - Save order matches the backend's intended split (see
    `logo-upload.php`'s kdoc): if a new logo was picked, `uploadLogo()`
    fires first and its returned path is folded into the same
    `updateProfile()` call as the rest of the form — a single
    `ProfileUpdateBody`, one network round trip for the whole save.
  - The profile is passed in from the caller as a JSON string extra
    (`EditProfileActivity.EXTRA_PROFILE_JSON`, decoded with a plain
    `Gson().fromJson`) rather than re-fetched here, since the caller
    (`AccountFragment`) already has it from its own `getProfile()` load
    — `RestaurantProfileDetail` isn't `Parcelable`, so a JSON string
    extra was the lower-friction option over adding `@Parcelize` to a
    model shared with plain Retrofit/Gson elsewhere.
- Registered `EditProfileActivity` in `AndroidManifest.xml`
  (`windowSoftInputMode="adjustResize"`, same as `MainActivity`/
  `OtpVerifyActivity` — this screen has a scrolling form under a fixed
  header, needs the keyboard to resize rather than pan).
- **`fragment_account.xml` redesigned** — profile summary card (logo
  thumbnail, name, address, hours — `MaterialCardView`, tap-through to
  Edit Profile), a separate "Edit profile" row underneath, a
  "Temporarily closed" `SwitchMaterial` in its own card, a view-only
  payout card (UPI ID + outstanding balance rows), Logout unchanged at
  the bottom. Wrapped in `SwipeRefreshLayout` (dependency already in
  `build.gradle`, same as `fragment_orders.xml`) so a pull-to-refresh
  can re-run `getProfile()`.
- New shared resources: `bg_input_outline.xml` (outlined-box background
  for the two tappable time rows), `ic_clock.xml` (copied from the
  Customer app, wasn't in this app yet).
- New strings for both screens added to `strings.xml` under "Account
  tab" / "Edit Profile screen" headers.

### 🔴 Not done yet — genuinely incomplete, not just unverified
- **`AccountFragment.kt` itself was NOT rewritten this session** — the
  new `fragment_account.xml` layout exists but nothing populates it
  yet. The Kotlin file is still the old placeholder version (loads only
  `restaurantNameText`/`btnLogout`, which no longer exist as IDs in the
  new layout — **the account tab will crash on open (`NullPointerException`
  from view-binding) until `AccountFragment.kt` is rewritten to match
  the new layout's IDs**). This is the single most important thing to
  fix next, before anything else.
  - Needs: `getProfile()` call in `onViewCreated`/`onResume` (or via the
    `SwipeRefreshLayout` listener), populating
    `profileNameText`/`profileAddressText`/`profileHoursText`/
    `profileLogoThumb` (Coil `.load()`, same pattern as
    `EditProfileActivity`'s logo preview), `upiIdText`/`currentDueText`
    from `RestaurantProfileDetail.upiId`/`currentDue`.
  - `switchTempClosed` — set `isChecked` from
    `operationalStatus == "temp_closed"` **without** firing its own
    listener on that initial programmatic set (guard flag, or set
    `isChecked` before attaching the listener), then on user toggle call
    the existing `updateOperationalStatus()` with `"temp_closed"`/`"open"`,
    with revert-on-failure same as `MainActivity.setOperationalStatus()`.
  - `profileSummaryCard`/`btnEditProfileRow` — launch
    `EditProfileActivity` via `registerForActivityResult` (not plain
    `startActivity`), passing `Gson().toJson(profile)` as
    `EXTRA_PROFILE_JSON`, and re-run `getProfile()` on a non-cancelled
    result so a saved name/logo/hours change reflects immediately
    without waiting for the next tab switch.
  - `btnLogout` — unchanged logic from the old file, just needs porting
    over (clear token, go to `LoginActivity`).
- **`MainActivity.kt`'s flagged correctness fix — still NOT applied**:
  `loadOperationalStatus()` still has
  `isOpen = summary.operationalStatus != "busy"`. Needs to become
  `isOpen = summary.operationalStatus == "open"` — otherwise once
  `AccountFragment`'s temp-closed switch ships and can actually send
  `"temp_closed"`, the top bar's pill will wrongly show green/open for
  a temp-closed restaurant. Flagged in the entry below, still flagging
  again here since it's still outstanding.
- No build/compile verification, same standing project-wide limitation
  — this session raises that risk further: `AccountFragment.kt`
  currently doesn't even match its own layout's view-binding IDs, which
  a real compile would catch immediately (view-binding-generated
  property misses) but this sandbox cannot.

### ⏭️ Next
1. **Rewrite `AccountFragment.kt`** per the gaps above — this is not
   optional polish, the tab is currently broken (see above).
2. Apply the `MainActivity.kt` one-line fix.
3. Then resume the rest of the standing queue per
   `NEXT_SESSION_PROMPT.md` (pre-login ink pass, Admin approve/reject
   screen, build verification queue).

---

## 2026-08-16 — Account tab / Restaurant Management profile (backend + models only, IN PROGRESS — this session)

Per `NEXT_SESSION_PROMPT.md` / doc 19 §7 (Account tab) and §10 item 5.
App owner picked this over the pre-login ink pass and the Admin
approve/reject screen. **Session paused partway through — backend and
Kotlin networking layer are done, but no screen UI exists yet.** This
entry will be replaced/extended once the UI half is built.

### ✅ Done
- **3 new backend endpoints** (`backend/api/v1/restaurant/`):
  - `profile-get.php` — GET, returns the full `restaurants` row (minus
    `password_hash`) for the logged-in restaurant. Needed because
    `restaurant-login.php` only returns this once at login time, and the
    30-day token can far outlive that single response.
  - `profile-update.php` — POST, partial update of a restaurant-safe
    column subset: `name`, `address`, `cuisine_tags`, `opening_time`,
    `closing_time`, `working_days`, `description`, `logo_url`,
    `cover_url`. Deliberately excludes `status`, `operational_status`,
    `current_due`, `commission_percent`, `latitude`/`longitude`,
    `owner_email`/password — same restraint `status-update.php` already
    uses. `opening_time`/`closing_time` are validated and normalized to
    `HH:MM:SS`; `working_days` is validated as 1–7, deduped, and
    canonically re-sorted before storing.
  - `logo-upload.php` — multipart image upload, exact same
    pattern/limits as H6's `address-photo.php` (5MB cap, mime-sniffed
    jpeg/png/webp, safe random filename under
    `backend/uploads/restaurant_logos/`). Deliberately only uploads and
    returns a path — does **not** write `logo_url` to the DB itself, so
    a user who picks a new logo then cancels out of Edit Profile without
    saving doesn't leave a half-applied change. The app is expected to
    pass the returned path to `profile-update.php` as an ordinary string
    field, same split as H6's photo-upload + address-save.
- **Kotlin networking layer** (Restaurant app):
  - `Models.kt` — new `RestaurantProfileDetail` (full profile, richer
    than the minimal `RestaurantProfile` used at login/signup),
    `ProfileResult`, `ProfileUpdateBody`, `LogoUploadResult`.
  - `ApiService.kt` — `getProfile()`, `updateProfile()`,
    `uploadLogo()` (multipart, mirrors the Customer app's
    `uploadAddressPhoto()`).
  - `ApiClient.kt` — added `baseUrlForStaticFiles()`, same helper/
    reasoning as the Customer app's, for turning `logo_url`'s relative
    path into a loadable image URL.
  - `TokenManager.kt` — added `updateRestaurantName()` so a rename in
    Edit Profile can refresh the top bar's cached name immediately
    without a fresh login.

### 🔴 Not done yet — this is genuinely incomplete, not just unverified
- **No UI built at all yet**: `EditProfileActivity.kt` +
  `activity_edit_profile.xml` (the actual form — logo picker, name/
  address/cuisine/description fields, opening/closing time pickers,
  working-days chip toggle, save) don't exist yet.
- **`AccountFragment.kt`/`fragment_account.xml` not redesigned yet** —
  still the old placeholder (name + "coming soon" text + Logout) from
  the bottom-nav-shell session. Planned redesign: profile summary card
  (logo thumbnail, name, address, "Edit profile" row), a "Temporarily
  closed" toggle (reusing `status-update.php`'s existing `temp_closed`
  value — the top bar's own OPEN/CLOSED pill only ever sends
  `open`/`busy`, so this is genuinely new client usage of a value the
  backend already accepted but nothing sent yet), a view-only payout
  card (UPI ID + outstanding `current_due`), Logout unchanged.
- **A known correctness fix identified but not yet applied**:
  `MainActivity.kt`'s pill currently computes
  `isOpen = operationalStatus != "busy"`, which would incorrectly show
  green/open for `temp_closed` once the Account tab can set that value.
  Needs to become `isOpen = operationalStatus == "open"` once the
  toggle above ships — flagging now so it isn't forgotten.
- **No notification-preferences toggle** — doc 19 §7 lists one, but
  there's no push/FCM infrastructure anywhere in the Restaurant app yet
  (grepped — nothing) for a toggle to actually control. Deliberately
  skipped rather than building a switch that does nothing; worth
  revisiting once push notifications are actually built.
- `AndroidManifest.xml` doesn't have `EditProfileActivity` registered
  yet (doesn't exist yet to register).
- No build/compile verification, same standing project-wide limitation.

### ⏭️ Next
Finish this session's UI half first (see gaps above), in one PR/zip so
Account tab isn't left in a half-wired state — `AccountFragment` should
not link to an Edit Profile screen that doesn't exist yet, and vice
versa. Then resume the rest of the standing queue (pre-login ink pass,
Admin approve/reject screen) per `NEXT_SESSION_PROMPT.md`.

---

## 2026-08-16 — Palette refresh: Exotic Orange + Midnight Blue "ink" chrome (this session)

App owner shared 8 color-pair reference images and asked for the best
one applied to the app, plus a general UI-quality pass, with the plan
doc (`19_Restaurant_App_UI_Plan.md`) kept in sync. See that doc's new
§8.1 for the full picked-palette rationale (short version: warm hues
are the deliberate, non-negotiable choice for a food app — established
appetite-suppressing effect of blue in F&B branding ruled out every
blue-led pair in the reference set as the primary color) and §8.2 for
the superseded original table.

### ✅ Done
- **Token-level palette swap** — `colors.xml`: primary `#E64A19` →
  `#F54F1B` ("Exotic Orange"), plus three new tokens for a dark "ink"
  surface family (`anydrop_ink` `#1E223D` "Midnight Blue",
  `anydrop_ink_light`, `text_on_ink`, `text_on_ink_muted`) that didn't
  exist before.
- **Ink applied to nav chrome** — `activity_main.xml`'s shared top bar
  and `BottomNavigationView` background both switched from plain white
  (`@color/surface`) to `@color/anydrop_ink`; `restaurantNameText`
  recolored to `text_on_ink`; `bottom_nav_item_color.xml`'s unselected
  state recolored from `text_secondary` (too low-contrast on dark navy)
  to the new `text_on_ink_muted`. `themes.xml`'s `statusBarColor` moved
  to `anydrop_ink` to match, with `windowLightStatusBar=false` (status
  bar icons need to be light-colored against a dark bar now — safe to
  set directly, `minSdk 24` is well above the API 23 floor for that
  attribute).
- **Confirmed this cascades app-wide with no other edits needed** —
  grepped for the old hex values (`#E64A19`/`#B23C14`/`#FFE0D3`)
  anywhere else in `res/`; none hardcoded outside `colors.xml` itself,
  so every button/switch/active-state pulling from `colorPrimary` or
  `@color/anydrop_primary` picked up the new orange automatically.

### 🟡 Known gaps / not done this session
- **No build/visual verification** — same standing sandbox limitation.
  This is a genuinely higher-risk change than most prior sessions'
  additive ones: it touches the app's global theme/status-bar color and
  a shared layout every screen sits inside, so a mistake here is
  visible everywhere rather than isolated to one screen. First real-
  toolchain step should be opening the app and eyeballing the top bar
  contrast, bottom nav legibility (checked vs. unchecked), and status
  bar icon color before anything else in this session's queue.
- **Pre-login/detail screens not touched** — `activity_login.xml`,
  `activity_signup.xml`, `activity_otp_verify.xml`,
  `activity_signup_success.xml`, `activity_splash.xml`,
  `activity_order_detail.xml` still use plain white full-screen
  backgrounds from before this refresh. Not an oversight — flagged
  explicitly as plan §10 item 7, a follow-up visual pass, since giving
  them a matching ink treatment needs a per-screen layout look rather
  than a token swap.
- Didn't touch typography/spacing, only color — per the plan doc, the
  bigger hierarchy issues (§4–§7) are structural, not color, and were
  already addressed in earlier sessions' Orders/Menu tab redesigns.

### ⏭️ Next
Same standing top priority as every recent entry — **build both APKs
with a real toolchain**, now with this session's theme/status-bar/nav
chrome change added to the unverified pile (see `NEXT_SESSION_PROMPT.md`
for the full running list). Then plan §10 item 7 (pre-login screens ink
pass) if there's appetite for more visual polish before moving to
Account tab (§10 item 5, unchanged from before).

---

## 2026-08-16 — Menu tab: category-tabs-strip + drag-to-reorder (this session)

Per `NEXT_SESSION_PROMPT.md`, closing out the last two open pieces of §10
item 4 — both were still stacked-cards-only / not-built as of the entry
below. Backend support for both already existed as of this session's
earlier "Backend: category/menu-item PHP endpoints" entry
(`categories-update.php` accepts `sort_order`).

### ✅ Done
- **Category tabs strip** (§5) — new `CategoryTabAdapter.kt` +
  `item_category_tab_chip.xml`, a horizontal `RecyclerView` of pill chips
  ("All" + one per active category) inserted into `fragment_menu.xml`
  between the search bar and the category list. `MenuFragment` shows it
  only once there are 5+ active categories (`TAB_STRIP_MIN_CATEGORIES`)
  and hides it while searching or reordering (see "mutually exclusive"
  design note below). Selecting a tab other than "All" filters
  `categoriesRecycler` down to that one category's card client-side — no
  new network call, reuses whatever `categories`/`items` are already
  loaded. New drawables `bg_chip_unselected.xml` (gray-100 pill) and
  `bg_menu_tab_selected.xml` (solid `anydrop_primary` pill, matching §8's
  color-token table which explicitly lists Primary for "active tab" —
  deliberately not reusing the existing `bg_chip_selected.xml`, since
  that one's default fill, `anydrop_primary_container`, is too light for
  white chip text; that drawable is only safe as used elsewhere, retinted
  per-status at runtime).
- **Drag-to-reorder** (§5) — `CategoryAdapter.kt` gained a `reorderMode`
  flag (default off) and a `moveItem()` used by a new `ItemTouchHelper`
  (`MenuFragment`, vertical drag only, `isLongPressDragEnabled = false` —
  the row's new ☰ drag-handle TextView starts the drag itself via
  `ACTION_DOWN` instead). `MenuFragment`'s "⇅ Reorder"/"Done" toggle
  (new header button) enters/exits reorder mode; entering it clears any
  active search/tab filter and shows every active category collapsed to
  just name + item count (items/edit/delete/add-item hidden — see
  `CategoryAdapter`'s class doc for why). "Done" diffs the on-screen order
  against each category's last-known `sortOrder`, calls
  `categories-update.php` once per category whose position actually
  changed (new sequential 0..n-1 values, not gapped), shows a
  success/failure toast either way, then reloads from the server
  regardless (so any partial-failure leaves the UI showing server truth,
  not a half-applied local reorder).
- **Design call, documented in both `MenuFragment`'s and
  `CategoryAdapter`'s class docs**: search, tab-strip filtering, and
  reorder mode are treated as mutually exclusive rather than combined.
  Starting a search or entering reorder mode clears the tab selection;
  entering reorder mode also clears any active search. Reordering only
  ever operates on the full active-category list, never a filtered
  subset — dragging within a filtered view wouldn't reflect real
  positions relative to hidden categories.
- Added a stale-selection guard in both `MenuFragment.applyDisplayFilter()`
  and `CategoryTabAdapter.submitCategories()`: if the tab-selected category
  gets deleted/deactivated elsewhere (e.g. the 🗑️ button) between loads,
  selection silently falls back to "All" instead of filtering the list
  down to nothing.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation, seven
  sessions running now. New unverified surface this session: the
  `ItemTouchHelper.SimpleCallback` wiring itself (never compiled or
  run in this project before), the nested-RecyclerView-inside-drag
  interaction (dragging a `CategoryViewHolder` whose `itemsRecycler` is
  set to a null adapter mid-drag), and `SwipeRefreshLayout.isEnabled`
  toggling around reorder mode.
- **Reordering only repositions active categories** — inactive
  (soft-disabled) categories keep whatever `sort_order` they had before;
  since they're invisible in every current UI surface this doesn't cause
  a visible bug, but their stored `sort_order` can end up interleaved
  with the newly-sequential active values. Worth a cleanup pass if/when
  category restore (re-activating a disabled category) gets built.
- **No "long-press anywhere on the row" drag start** — deliberately
  scoped to the ☰ handle only (`isLongPressDragEnabled = false`), so a
  future accidental long-press on the category name/count doesn't start
  a drag by surprise. If real-device testing finds the handle's hit area
  too small to grab reliably, widen its padding rather than falling back
  to whole-row long-press.

### ⏭️ Next
§10 item 4 is now feature-complete client + server side (photo thumbnail,
search, skeleton, tabs strip, drag-reorder). Per `NEXT_SESSION_PROMPT.md`'s
standing top priority: **build both APKs with a real toolchain** —
nothing in this project has been compiled since it started (now the
oldest and largest pile of unverified surface, seven sessions deep) —
before adding anything else. Then Account tab (§10 item 5).

---

## 2026-08-16 — Backend: category/menu-item PHP endpoints (this session)

Per `NEXT_SESSION_PROMPT.md`'s note that this upload was a partial project
export — the client side (`ApiService.kt`) already declared 8 endpoints
under `restaurant/` that had no backend file in this zip. Built all 8
now, following this codebase's existing conventions (`lib/response.php`,
`lib/auth.php`'s `require_auth('restaurant')`, `Database::get()`,
partial-update pattern from `status-update.php`/`orders-status.php`).

### ✅ Done — `backend/api/v1/restaurant/`
- **`categories-list.php`** (GET) — all of the restaurant's categories
  (active + inactive; `CategoryAdapter.kt` already filters to
  `is_active` client-side), with a live `item_count` subquery per row.
- **`categories-create.php`** (POST) — `sort_order` defaults to
  "append to end" when omitted (current client never sends it).
- **`categories-update.php`** (POST, `?id=`) — partial update
  (name / sort_order / is_active), ownership-checked.
- **`categories-delete.php`** (POST, `?id=`) — soft-disable
  (`is_active = 0`), **not** a hard delete: `menu_categories` has no
  `deleted_at` column per `01_Database_Schema.md` §2, and this avoids
  orphaning any `menu_items` still pointing at the category.
- **`menu-items-list.php`** (GET, `?category_id=&search=`) — both
  filters optional/combinable; `search` is a `name LIKE %q%` match.
  Includes out-of-stock (`is_available = 0`) items, same reasoning as
  the customer-facing `restaurants/menu.php`.
- **`menu-items-create.php`** (POST) — validates `category_id` belongs
  to the calling restaurant before inserting; `prep_time_minutes`
  defaults to 15 (schema default) when omitted.
- **`menu-items-update.php`** (POST, `?id=`) — partial update; doubles
  as the out-of-stock toggle's write path (`{"is_available": bool}`
  only) and the full edit-dialog save. Re-validates `category_id`
  ownership if that field is being changed.
- **`menu-items-delete.php`** (POST, `?id=`) — soft delete
  (`deleted_at = NOW()`), since `menu_items` (unlike categories) does
  have that column. Past orders are unaffected — `order_items` snapshots
  `item_name_snapshot`/`unit_price` at order time rather than joining
  live to `menu_items` (`01_Database_Schema.md` §"order_items").

No `.htaccess` changes needed — the Restaurant App calls these `.php`
files directly by path (`@GET("restaurant/categories-list.php")` etc.,
same as the already-working `orders-list.php` etc.), not through the
pretty-route rewrites those older endpoints also happen to have.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing PHP-side limitation:
  no PHP CLI in this sandbox to lint-check, let alone a real DB to run
  these against. Balanced braces/parens checked mechanically only.
  First real-toolchain step: hit each endpoint once (Postman/curl) against
  a seeded restaurant before trusting the Android client-side work from
  earlier today against it.
- **Client-side Menu tab work (photo thumbnail, search wiring, skeleton)
  not touched this session** — that was already done per the entry
  below; this session was backend-only, per the app owner's explicit
  "pehle backend sahi karo" instruction.
- `discount_percent`, `is_recommended`, `is_bestseller` aren't settable
  through `menu-items-create.php`/`menu-items-update.php` — no UI sends
  them yet (`MenuItemCreateBody`/`MenuItemUpdateBody` don't expose them
  either), so they're left at their schema defaults for every item
  created through this app so far.

### ⏭️ Next
Build/smoke-test both the backend (this session) and the client
(2026-08-16 Menu tab entry below, 2026-08-15 Orders tab entry further
below) together with a real toolchain — per `NEXT_SESSION_PROMPT.md`'s
standing top priority, oldest unverified risk first. Then close out §10
item 4 (category-tabs-strip + drag-to-reorder) before moving to Account
tab (§10 item 5).

---

## 2026-08-16 — Menu tab: photo thumbnail + search + skeleton (this session, partial)

Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 4, picking up Menu
Management after the Orders tab redesign. **This session only covers
part of item 4** — photo thumbnail slot, search bar wiring, and the
skeleton state. Category-tabs-strip (5+ categories) and drag-to-reorder
are still not done — see gaps below.

### ✅ Done
- **Photo thumbnail slot** (`item_menu_food.xml` + `MenuItemAdapter.kt`):
  44dp `ImageView` added before the veg dot, matching
  `skeleton_menu_item_row.xml`'s already-built proportions (the plan's
  §5 text says "60×60" but the skeleton built two sessions ago locked
  in 44dp first, so this follows that rather than the plan doc — worth
  reconciling if it's noticed). Loaded via Coil (`item.imageUrl`,
  crossfade); falls back to a new `ic_food_placeholder.xml` (tinted
  `text_secondary`, inset) when there's no photo yet or the load fails.
  `restaurant/app/build.gradle` — added `io.coil-kt:coil:2.6.0`, same
  version the Customer app already uses.
- **Search bar** (`fragment_menu.xml` + `MenuFragment.kt`): pill-style
  bar under the header (copied `bg_search_pill.xml`/`ic_search.xml`
  from the Customer app for visual consistency), 400ms-debounced
  `TextWatcher` calling `getMenuItems(search = query)` — the backend
  `?search=` param the plan doc noted as "already ready." Categories
  with zero matching items under an active search are filtered out of
  the list client-side (an empty category card under a search felt
  like noise); a `menu_search_no_results` string swaps in for the
  usual empty-state text while a query is active.
- **Skeleton state** (§9.2): new `skeleton_menu_category_card.xml`
  (title bar + 3× the existing `skeleton_menu_item_row.xml`), wrapped
  in `ShimmerFrameLayout` inside a `ScrollView`, same
  show-on-first-load/hide-after-response pattern as the Orders tab's
  per-section skeletons. Shown on true first load and on a fresh
  search (new result set); **not** shown on pull-to-refresh — that
  keeps `SwipeRefreshLayout`'s own small spinner per §9.1.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation, now
  six sessions running (no Android SDK, no network access in this
  sandbox). New unverified surface this session: the Coil dependency
  addition itself (never built with it in this project), `ImageView`
  padding/scaleType toggling in `MenuItemAdapter.bind()`, and
  `fragment_menu.xml`'s new `ScrollView`-wrapped skeleton stacked
  inside the existing `SwipeRefreshLayout`/`FrameLayout`.
- **Category-tabs-strip variant not built** — §5's "horizontal scroll
  strip once a restaurant has 5+ categories" is still the old stacked
  cards regardless of category count.
- **Drag-to-reorder not built** — `sort_order` exists backend-side per
  the plan doc, but no drag handle or reorder UI was added this
  session.
- **This zip fragment doesn't include the backend `restaurant/`
  category/menu-item PHP endpoints** — `ApiService.kt` already
  declared `getCategories()`/`getMenuItems(search=...)` from a prior
  session, so this session only had to wire the client side. Not a
  gap in the work itself, just a note that this upload was a partial
  project export.

### ⏭️ Next
Per doc 19 §10, still within item 4: category-tabs-strip (5+
categories) and drag-to-reorder, to close out Menu tab. Then, per
`NEXT_SESSION_PROMPT.md`'s standing top priority — build both APKs
with a real toolchain and smoke-test everything unverified so far
(Orders tab redesign from 2026-08-15, plus this session's Menu tab
changes) before moving to §10 item 5 (Account tab).

---

## 2026-08-15 — Orders tab redesign, OrderAdapter + OrdersFragment rebuild (earlier session)

Per `NEXT_SESSION_PROMPT.md`'s "Next work, in order" list — items 1–3,
continuing straight from the UI-groundwork entry directly below (same
day). All three of that list's items landed this session.

### ✅ Done
- **`OrderAdapter.kt` — rebuilt around a `CardMode` enum** (`NEW` /
  `IN_PROGRESS` / `COMPLETED`). One adapter class, but now takes `mode`
  in its constructor — callers create **one instance per section**
  rather than one shared instance for everything, per the plan's
  suggested approach.
  - `NEW`: `countdownChip` + `actionRow` (`btnReject`+`btnAccept`) both
    shown. `btnAccept` invokes an `onAccept(order)` callback; `btnReject`
    opens an inline `AlertDialog.Builder` with a plain `EditText` (no new
    dialog layout, per the plan's "keep it simple" note) collecting a
    reason before invoking `onReject(order, reason)` — empty reason
    blocks submission via `InAppNotifier`, same validation
    `OrderDetailActivity.confirmReject()` does.
  - `IN_PROGRESS`: `stepperRow` shown, dot state derived from
    `order.status` (`accepted`/`preparing` → step 1 filled only;
    `ready`+ → steps 1–2 filled; step 3 "Handed to rider" always empty —
    unreachable from this app). `actionRow` shows only `btnAccept`,
    relabeled via `btn_mark_next_step`, invoking `onMarkNextStep(order)`
    — row hidden entirely once `nextStatusFor(status)` returns null
    (status already `ready` or beyond), matching
    `OrderDetailActivity.configureActions()`'s `else` branch.
  - `COMPLETED`: all three optional rows hidden — identical to the
    pre-redesign card.
  - **Countdown ticker implemented here too** (folds in what was
    planned as a separate item 3): each `NEW`-mode `ViewHolder` runs its
    own `Handler.postDelayed` loop counting down from `order.createdAt`
    (reusing `ScheduledTimeFormatter`'s `"yyyy-MM-dd HH:mm:ss"` parsing
    approach) against a fixed 5-minute local window, formatted via
    `countdown_format`, switching to `countdown_expired` ("Accept now")
    once past it. `onViewRecycled()` stops the handler so a recycled row
    doesn't keep ticking into whatever gets bound next. Comments
    reiterate this is cosmetic-only — no backend deadline exists to
    imply.
- **`OrdersFragment.kt` + `fragment_orders.xml` — full rebuild**,
  replacing the New/Active/History tab filters with the actual §4
  layout:
  - Old `switchAcceptingOrders` + its listener, `toggleAcceptingOrders()`,
    `revertToggle()`, and the summary-text operational-status wiring are
    **deleted** — that's `MainActivity`'s job now (see the entry below),
    and nothing duplicate remains.
  - "Today" stat strip: 3 `bg_stat_chip` chips (orders count / earnings /
    avg prep), fed by the same `getDashboard()` call the old
    `summaryText` used. Avg prep falls back to `stat_placeholder` ("—")
    when `avgPrepMinutes` is null.
  - Three always-visible sections, each its own `RecyclerView` +
    `OrderAdapter` instance (`nestedScrollingEnabled="false"`, stacked
    inside a plain `ScrollView` — matching this project's existing
    `ScrollView` convention elsewhere rather than introducing
    `NestedScrollView`) wrapped in one `SwipeRefreshLayout`:
    - **New** (`status=pending`).
    - **In progress** (`status=accepted,preparing,ready,rider_assigned,
      picked_up,out_for_delivery` — the old `Tab.ACTIVE` filter, now
      always visible instead of behind a tab).
    - **Completed today** (`status=delivered,cancelled,rejected` — old
      `Tab.HISTORY`), collapsed by default behind a tappable header with
      a text-caret ("▸"/"▾", no new drawable) that flips on toggle;
      loads lazily on first expand rather than on every poll tick.
  - `ShimmerFrameLayout` + 2x `skeleton_order_card` wired into each
    section (shown whenever that section's adapter is currently empty
    going into a load, hidden once the response lands).
  - 10s polling loop and `onResume()` refresh-on-return-from-detail
    behavior both carried over as-is, now driving `loadAll()` (all three
    section calls + the stat strip) instead of one call.
  - Each section's mutation flow (accept/reject/mark-next-step) re-loads
    only the section(s) it affects rather than the whole screen — e.g.
    accepting a New order reloads New + In-progress, not Completed.

### 🟡 Known gaps / not done this session
- **Still no build/compile verification** — same standing limitation,
  now five sessions running. No Android SDK, no network access in this
  sandbox. This session's new unverified surface on top of the existing
  stack: `OrderAdapter`'s changed constructor signature (now requires
  `mode`), the `Handler`-based countdown ticker and its recycle-cleanup,
  and `fragment_orders.xml`'s `ScrollView`-wrapping-3-`RecyclerView`s
  layout (each `RecyclerView` relies on `wrap_content` height +
  `nestedScrollingEnabled="false"` to lay out correctly inside a
  `ScrollView` — a well-worn pattern, but unverified here).
- **No dedicated confirmation title string for the reject dialog** —
  reuses `R.string.btn_reject` ("Reject") as the `AlertDialog` title
  rather than a purpose-written "Reject this order?" string. Cosmetic;
  fine to leave or polish later.
- **Reject/accept/mark-next-step failures don't disable buttons
  mid-flight** — unlike `OrderDetailActivity`'s `setActionsEnabled()`
  pattern, a double-tap on `btnAccept`/`btnReject` before the first
  request resolves could fire twice. Low-risk (the backend's status
  transitions are idempotent/guarded either way) but worth tightening
  if it comes up.
- **Stat strip has no skeleton state** — only the three order sections
  got skeleton wiring; the stat chips just show blank text until
  `loadDashboardSummary()` resolves. Minor, since it's usually fast and
  non-blocking.

### ⏭️ Next
Per doc 18's own recommended order (Orders redesign was pulled forward
out of turn — see the 2026-08-14 entry below): resume with **Menu
Management** (Tier 1, biggest remaining functional gap) once this is
build-verified. Before that: build both APKs with a real toolchain and
smoke-test the whole Orders tab (accept/reject/mark-next-step, the
countdown, the Completed-today expand/collapse, skeletons) end-to-end —
this is the largest unverified surface added in one sitting so far.

---

## 2026-08-15 — Orders tab redesign, UI groundwork (earlier this session, partial)

Per doc 19 §10 item 3, continuing from the backend/model groundwork
entry directly below. This session started the actual UI build but did
**not** finish it — `OrderAdapter`/`OrdersFragment`/`fragment_orders.xml`
are still untouched. Everything below is new resources + the shared
top bar only; the Orders tab list itself still looks and behaves
exactly as it did before this session (New/Active/History tab filters,
switch inside the fragment removed — see known gap below).

### ✅ Done
- **New drawables** (`restaurant/app/src/main/res/drawable/`):
  - `bg_stepper_dot_filled.xml` / `bg_stepper_dot_empty.xml` — filled
    (completed/current) vs. outline (upcoming) dots for the §4
    horizontal status stepper.
  - `bg_countdown_chip.xml` — amber pill background for the New-section
    "Accept within 1:45" countdown chip.
  - `bg_stat_chip.xml` — gray-100 background for the "Today" snapshot
    strip chips (§8). **Not yet used anywhere** — waiting on the stat
    strip itself, which is still inside `fragment_orders.xml`'s
    unbuilt redesign.
  - `bg_pill_open.xml` / `bg_pill_closed.xml` — green/red pill
    backgrounds for the shared OPEN/CLOSED toggle.
  - `bg_dot_green.xml` / `bg_dot_red.xml` — small solid dots inside the
    pill (deliberately separate from the stepper dots above, which use
    `anydrop_primary` orange, not semantic green/red).
- **New strings** in `strings.xml`: section headers
  (`section_new_orders`, `section_in_progress`, `section_completed_today`),
  stat chip labels/formats, stepper step labels, countdown format
  strings, `btn_mark_next_step`, per-section empty-state strings, pill
  labels (`restaurant_open_label`/`restaurant_closed_label`), and the
  close-confirmation dialog strings.
- **`activity_main.xml`** — added the shared top bar above the bottom
  nav: restaurant name (left) + OPEN/CLOSED pill (right, tappable).
  Matches §3's "stays pinned in a top bar above the bottom nav on
  every tab."
- **`MainActivity.kt`** — now owns operational-status state and UI:
  - Fetches current status via `api.getDashboard()` on create and
    resume (`operationalStatus != "busy"` → pill shows Open).
  - Restaurant name pulled from `TokenManager.getRestaurantName()`
    (already cached at login — no new API call needed for this part).
  - Tapping the pill while Open shows an `AlertDialog.Builder`
    confirmation (§4: "tap to confirm before closing") before calling
    `updateOperationalStatus(busy)`; tapping while Closed re-opens
    immediately, no confirmation needed.
  - Same revert-on-failure pattern the old `OrdersFragment` switch
    used: `isOpen` only flips after a successful API response;
    `renderPill()` re-syncs the visible state either way.
- **`item_order_card.xml`** — rebuilt with three new optional sections,
  **all `visibility="gone"` by default** so the card looks identical to
  before until `OrderAdapter` is updated to show them:
  - `countdownChip` next to the existing `statusChip`.
  - `stepperRow` — 3 dots (Preparing → Ready → Handed to rider) with
    connecting lines. Note: "Handed to rider" is shown for visual
    completeness only — it's never reachable by tapping this app's
    "Mark next step" button, since `orders-status.php`'s allowed
    transitions are only `accepted→preparing` and `preparing→ready`
    (rider
    assignment is Phase 4, not built).
  - `actionRow` — `btnReject` + `btnAccept` for New cards; the same
    `btnAccept` slot is meant to be relabeled "Mark next step" and
    `btnReject` hidden for In-progress cards (adapter work, not done
    yet).

### 🟡 Known gaps / not done this session
- **`OrderAdapter.kt` untouched** — no `CardMode` enum yet, so none of
  `item_order_card.xml`'s new rows are ever shown or wired to a click
  handler. This is the next concrete step.
- **`OrdersFragment.kt` / `fragment_orders.xml` untouched** — still has
  the old New/Active/History tab-filter UI and its own
  `switchAcceptingOrders` + `summaryText`. This needs to become the
  three-section layout (§4) and **the operational-status switch/summary
  code in `OrdersFragment.kt` needs to be deleted**, not just
  left alongside the new `MainActivity` pill — right now there would be
  two places managing/displaying the same status if both were live
  (the fragment's old switch is still in `fragment_orders.xml` and
  still functional; it just hasn't been removed yet).
- **No countdown timer utility** — the "Accept within 1:45" ticking
  chip needs a small ticker (e.g. a `Handler`/coroutine loop) counting
  down from `order.createdAt` against a fixed local window (5 min
  suggested previously); not started. `Order.createdAt` is the same
  `"yyyy-MM-dd HH:mm:ss"` format `ScheduledTimeFormatter` already
  parses, so that utility's parsing approach can be reused.
- **No skeleton wiring** — `ShimmerFrameLayout` + `skeleton_order_card.xml`
  (built two sessions ago) are still not used anywhere.
- **Still no build/compile verification** — same standing limitation,
  now four sessions running. No Android SDK, no network access in this
  sandbox. `MainActivity.kt`'s new `AlertDialog` import and the
  `ContextCompat.getDrawable` calls in `renderPill()` are the new
  unverified risk this session adds on top of the still-unverified
  Fragment conversion from two sessions ago.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md`: finish §10 item 3 — `OrderAdapter`
`CardMode` wiring, `OrdersFragment`/`fragment_orders.xml` section
rebuild (and deleting its now-superseded switch/summary code), the
countdown ticker, and skeleton wiring. The resources/top-bar above are
there to be consumed, not re-derived.

---



Per doc 19 §10 item 3. This session did **not** get to the actual Orders
tab UI rework — only the small, safe, backend-first groundwork it depends
on. Nothing risky was touched; everything below is additive and backward
compatible with the app as it stood after the bottom-nav shell session.

### ✅ Done
- **`backend/api/v1/restaurant/dashboard.php`** — added a real
  `avg_prep_minutes` to the `today` object: `AVG(TIMESTAMPDIFF(MINUTE,
  accepted_at, ready_at))` for today's orders, restricted to orders that
  actually have both timestamps set (still-preparing orders are excluded,
  not counted as 0 min). Backs §4's "Today" snapshot strip third chip
  (Orders · Earnings · Avg prep time). `orders.accepted_at`/`ready_at`
  already existed in the schema and are already written by
  `orders-accept.php`/`orders-status.php` — this only adds a read.
- **`network/Models.kt`** — added `avgPrepMinutes: Int?` to
  `TodaySummary`, nullable (no orders reached "ready" yet today = no
  stat, not a misleading "0 min").
- **`colors.xml`** — added `warning_amber` / `warning_amber_bg` (§8's
  amber for the order countdown timer as it runs low) and
  `stat_chip_bg` (§8's neutral gray-100 for stat chips, to sit apart
  from white order cards). Not wired into any layout yet.

### 🟡 Known gaps / not done this session
- **The actual Orders tab redesign is not started** — no top-bar
  OPEN/CLOSED pill, no New/In-progress/Completed sections, no countdown
  timer, no status stepper, no matching skeleton wiring. This session
  only got as far as confirming what real data the backend has to work
  with before writing any Kotlin/XML for it.
- **Important scope note discovered this session, for whoever picks this
  up:** there is no accept-deadline/auto-reject concept anywhere in the
  backend (checked — no `accept_deadline`/`expires_at`/timeout column on
  `orders`, no cron/scheduled job for it). §4's "Accept within 1:45"
  countdown chip can only be a **client-side visual cue** (e.g. counting
  down from `created_at` against a fixed local window like 5 minutes),
  not something the backend enforces or would reject against. Build it
  that way and say so in the UI/code comments — don't imply a real
  deadline exists.
- **Still no build/compile verification** — same standing limitation,
  now three sessions running. No Android SDK and no network access in
  this sandbox (confirmed again this session — no `java`/Android SDK,
  bash_tool has network disabled). The Fragment-conversion risk flagged
  in the bottom-nav shell session is still unverified.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 3: actually build the
Orders tab redesign — shared top bar (restaurant name + OPEN/CLOSED
pill, §3) in `MainActivity`, then `OrdersFragment`/`fragment_orders.xml`
sections + countdown + stepper + skeleton. The three additions above
(avg_prep_minutes, colors) are there to be consumed, not re-derived.

---

# Restaurant App (Anydrop For Restaurant) — Status

Full scope/priority reference: `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`.
This file tracks what's actually been *built* against that plan — updated
each session, newest at the top.

---

## 2026-08-15 — Bottom nav shell (this session, after the shimmer block)

Per doc 19 §10's build order, item 2 — unblocks Insights/Account tabs
and the Orders/Menu tab redesigns that come next.

### ✅ Done
- **`ui/main/MainActivity.kt` + `activity_main.xml`** — new post-login
  entry point: a `FragmentContainerView` above a persistent
  `BottomNavigationView` with the 4 tabs from §3 (Orders / Menu /
  Insights / Account). `SplashActivity` and `LoginActivity` now route
  here instead of the old `DashboardActivity`.
- **`DashboardActivity` → `ui/orders/OrdersFragment.kt`** — same
  behavior (New/Active/History sub-tabs, 10s polling, accepting-orders
  switch), just re-hosted as a fragment. Polling is now tied to
  `viewLifecycleOwner.lifecycleScope`, so it's cancelled automatically
  on tab switch instead of needing manual onPause/onResume bookkeeping.
- **`MenuManagementActivity` → `ui/menu/MenuFragment.kt`** — same
  category/item CRUD behavior, minus the back-arrow/slide-transition
  (it's a tab now, not a pushed screen).
- **`ui/insights/InsightsFragment.kt`** — empty "coming soon" placeholder.
- **`ui/account/AccountFragment.kt`** — minimal placeholder: restaurant
  name + a Logout button. Logout moved here from the old Dashboard top
  bar (flagged as still-needed in docs/restorent/20 §3) — it's not the
  full §7 profile screen yet, just enough that logout has a home.
- Removed the now-superseded `DashboardActivity.kt`,
  `MenuManagementActivity.kt`, `activity_dashboard.xml`,
  `activity_menu_management.xml`, and their manifest entries. New
  manifest entry for `MainActivity` carries over the
  `adjustResize` flag Menu's add/edit dialogs needed.
- New icons: `ic_nav_orders`, `ic_nav_menu`, `ic_nav_insights` (Account
  reuses the existing `ic_person`); new `bottom_nav_item_color.xml`
  color selector (primary when selected, secondary otherwise).

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation.
  Fragment conversions are the riskiest kind of change to make blind;
  double-check view-binding class names (`FragmentOrdersBinding`,
  `FragmentMenuBinding`, `FragmentInsightsBinding`,
  `FragmentAccountBinding`) actually generate as expected and that
  `InAppNotifier.show(activity, ...)` (it takes a nullable `Activity`,
  not a `Context` — easy to get wrong when converting Activity code to
  Fragment code, caught once already this session) is called correctly
  everywhere before trusting this compiles clean.
- **Tabs reload on every switch** — `MainActivity` uses a plain
  fragment `replace()`, so Orders/Menu re-fetch from the network every
  time you switch back to them rather than keeping state in the
  background. Flagged in `MainActivity`'s own doc comment as an
  acceptable simplification for this shell step, not a final decision.
- **OPEN/CLOSED toggle not yet promoted to the shared top bar** — §3
  calls for it pinned above the bottom nav on every tab; it's still
  inside `OrdersFragment` only, same as before. That's Orders tab
  redesign work (§10 item 3), not this step.
- Insights/Account are placeholders only, as planned — no real content,
  no skeleton loading state (nothing to load yet).

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 3: Orders tab redesign
(sections + countdown timer + status stepper) with its skeleton loading
state wired in the same pass, using the `ShimmerFrameLayout` +
`skeleton_order_card.xml` built in the prior session.

---

## 2026-08-15 — Shared skeleton/shimmer building block (this session)

Per doc 19 §10's build order, item 1 — the piece everything else in the
UI plan (bottom nav shell, Orders/Menu/Insights/Account tabs) depends on.

### ✅ Done
- **`ui/common/ShimmerFrameLayout.kt`** — reusable container that sweeps
  a light-gray → white → light-gray gradient across whatever skeleton
  shapes it wraps, using a `ValueAnimator` + `LinearGradient` composited
  with `PorterDuff.SRC_IN` (so the sheen only shows over the opaque
  skeleton shapes, not the gaps between them). Starts automatically on
  attach, stops on detach; `startShimmer()`/`stopShimmer()` exposed for
  screens that want manual control (e.g. pausing while off-screen in a
  RecyclerView). Written once, per §9.3, so Orders/Menu/Insights/Account
  skeletons all reuse this instead of duplicating animation code.
- **Two reusable skeleton row shapes** (§9.2), built as plain layouts
  (no shimmer baked in — wrap them in `ShimmerFrameLayout` at the call
  site):
  - `layout/skeleton_order_card.xml` — mirrors `item_order_card.xml`'s
    exact margins/padding/proportions: order # bar, status-chip blob,
    item-summary bar, stepper blob, total/payment bars.
  - `layout/skeleton_menu_item_row.xml` — mirrors `item_menu_food.xml`
    plus the not-yet-built §5 thumbnail slot: 44dp rounded-square
    placeholder, stacked name/price bars, switch-area blob.
- **New drawables:** `bg_skeleton_bar.xml` (4dp-radius bar),
  `bg_skeleton_blob.xml` (20dp pill, for chips/steppers),
  `bg_skeleton_thumb.xml` (8dp rounded square, for the photo thumbnail).
- **New color tokens** in `colors.xml`: `skeleton_base` (`#E8E8E8`),
  `skeleton_shimmer_highlight` (`#F5F5F5`) — match §9.4's visual tokens
  table exactly.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation noted in
  every prior session (no Android SDK in this sandbox). First thing to
  do with a real toolchain: build the app, confirm the shimmer actually
  renders/animates on-device, tune `SHIMMER_DURATION_MS`/band width by
  eye if the sweep looks too fast/slow or too wide/narrow.
- **Not wired into any screen yet** — these are building blocks only.
  Next step (§10 item 2) is the bottom nav shell; the Orders tab
  skeleton (§10 item 3) is the first place these actually get used
  inside a real loading state.
- Only two row shapes built, per the plan's "a couple" — Insights'
  stat-chip-row and Account's form-skeleton shapes aren't built yet;
  those come with their own tabs per §9.5's "same PR as the real
  layout" rule.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10: bottom nav shell (item 2),
then Orders tab redesign with its skeleton wired up together (item 3).

---

## 2026-08-14 — Signup/Login entry flow (this session)

**Decision:** app owner chose to start with the Signup/Login entry point
(not Menu Management, which doc 18's own recommended order lists first) —
because Signup didn't exist at all yet, only a bare Login screen.

### ✅ Done
- **Splash screen** (`ui/splash/SplashActivity.kt`) — new launcher Activity.
  Animated logo (scale + overshoot) and fade-up title/tagline, same
  animation files the Customer app's splash already uses
  (`res/anim/splash_logo_in.xml`, `splash_text_in.xml` — copied as-is so
  both apps share one brand entrance). Routes to Dashboard (already
  logged in) or Login after ~0.9s.
- **Login screen redesign** (`ui/login/LoginActivity.kt` +
  `activity_login.xml`) — same fields as before (email/password), now
  with a cascading fade-up entrance for each field
  (`res/anim/form_field_in.xml`, staggered via `startOffset`) and a
  "New restaurant partner? Sign up" link.
- **Signup flow — full 3-step flow, new this session:**
  1. `ui/signup/SignupActivity.kt` — restaurant name, owner name, owner
     mobile, owner email, password, confirm password, address (optional).
     Client-side validation, then requests an email OTP.
  2. `ui/signup/OtpVerifyActivity.kt` — 6 individual auto-advancing digit
     boxes (Zomato/Swiggy-style OTP input), 30s resend countdown, shake
     animation on wrong code. On success, submits the account.
  3. `ui/signup/SignupSuccessActivity.kt` — "Application submitted, under
     review" screen with a pop-in checkmark, routes back to a clean Login.
- **Backend — 3 new endpoints** (`backend/api/v1/auth/`):
  - `restaurant-request-otp.php` — sends OTP (mirrors
    `customer-request-otp.php`'s cooldown/debug_otp pattern, reuses the
    same `email_otps` table).
  - `restaurant-verify-otp.php` — verifies only, does **not** create an
    account (unlike the customer flow) since the restaurant form needs
    more fields collected first.
  - `restaurant-signup.php` — creates the `restaurants` row
    (`status='pending'`) only after confirming a just-verified OTP exists
    for that email. No schema changes needed — every column
    (`name`, `owner_name`, `owner_mobile`, `owner_email`, `password_hash`,
    `address`, `status` default `'pending'`) already existed.
- **New animation resources** (`restaurant/app/.../res/anim/`):
  `form_field_in`, `slide_in_right/left`, `slide_out_left/right`,
  `shake_error`, `success_pop_in` (+ copies of the Customer app's
  `splash_logo_in`/`splash_text_in`).

### 🟡 Known gaps / not done this session
- **No real email delivery** — same limitation as the Customer app's OTP
  flow (`docs/19` §7 Email OTP multi-provider is planning-only). OTP is
  logged server-side only; visible in the app response solely when
  `debug_otp_enabled` app_setting is `'1'` on a dev/staging DB.
- **Logo upload during signup** — not included; restaurant logo/cover
  photo upload is separately scoped under Tier 1 "Restaurant Management"
  in doc 18 and wasn't pulled into this flow to keep the signup form
  short. Can be added post-approval, in the (not-yet-built) profile screen.
- **No build/compile verification** — same standing limitation as every
  other session per `Status.md` (no Android SDK in this environment).
  First thing next session on this: build the restaurant app, fix
  whatever the compiler catches, smoke-test signup → OTP → pending →
  (admin approves, not built yet) → login on an emulator.
- **Admin approval screen doesn't exist yet** — a restaurant can now
  self-signup into `status='pending'`, but nothing in the Admin Panel can
  approve it yet (that's doc 19 §3, planning-only). Until that's built,
  a pending signup has no way to reach `approved` except a manual
  `UPDATE restaurants SET status='approved'` on the DB.

### ⏭️ Next (per doc 18's own recommended order, resuming after this
detour)
1. Menu Management (Tier 1) — biggest remaining functional gap.
2. Order Management small additions (loud sound, prep-time select,
   cancel reason).
3. Restaurant Management profile screen (name/address/hours/logo, temp
   closure) — natural next stop after Signup, since a newly-approved
   restaurant needs this to actually set itself up.
4. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last).

Admin-side "Approve/Reject pending restaurants" screen should also move
up in priority now that self-signup exists and can actually produce
pending rows to approve — flag this to the app owner alongside item 1.

---

## 2026-08-14 — QA test restaurant account (later, same day)

Added `backend/sql/21_seed_test_restaurant_account.sql` — one
pre-approved (`status='approved'`) restaurant row so the new
signup/login flow can be tested end-to-end without the (not-yet-built)
admin approval screen blocking it.

- **Login:** `test@anydrop.com` / `test`
- Run this SQL file against the DB (phpMyAdmin on KS Web, or wherever
  `backend/sql/*.sql` files normally get run from — same as every other
  numbered migration in this folder) — it's idempotent, safe to re-run.
- This is a QA-only seed, not something to ship in production data —
  worth deleting (or at least rotating the password) before a real launch.
