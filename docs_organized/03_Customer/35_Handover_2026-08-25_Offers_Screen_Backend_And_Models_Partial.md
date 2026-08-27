# Handover — continue from here (2026-08-25, session 11)

Continues docs/34. Picked up the one remaining item from docs/33's
original six-part ask: the "Offers" category chip + browse screen
(docs/33's own items 2/3). **This session got through the backend
endpoint, the Kotlin models/API wiring, and all three new layouts —
the adapter, the Activity itself, and hooking it into Home are NOT
built yet.** Owner asked to package up what exists so far, same
"stop and hand over cleanly" pattern docs/30/33 already used.

Same sandbox limitation as every prior session: no PHP CLI / MySQL /
Android SDK / Gradle here. The one PHP file below was manually brace/
paren/bracket-count balanced and re-read, not run. The three new XML
layouts were parsed with Python's `xml.dom.minidom` (confirms
well-formed, NOT that ids/attrs are valid Android resources) and
re-read against the existing layouts they mirror. No Kotlin file was
compiler-checked.

---

## ✅ Done this session

### Backend: `api/v1/home/offers-browse.php` (new)

Implements docs/33's own spec almost exactly as originally planned:

- `SELECT DISTINCT po.restaurant_id FROM promo_offers ... WHERE
  status='active' AND deleted_at IS NULL AND date-range-ok AND
  offer_type != 'free_delivery'` (free_delivery excluded up front —
  same reasoning `get_browsable_offers_for_restaurant()`'s own kdoc
  already documents: it's a checkout perk, not a per-item badge, no
  item to show here) joined against `restaurants.status='approved'`.
- For each restaurant: `get_browsable_offers_for_restaurant()` +
  `pick_item_badge_offer()` + `offer_badge_label()` — the same trio
  every other offer-tag surface in this codebase already uses, so a
  badge on this screen always matches what the customer would see on
  the restaurant's actual menu. Only items that get a non-null badge
  are included; restaurants where every item badge comes back null
  (or with zero available items) are dropped entirely, not shown with
  an empty item list.
- **Same flagged simplification as `popular-items.php`/`search.php`**
  (docs/33's own precedent): category-scoped offer matching is
  skipped (`pick_item_badge_offer(..., null)`) since this endpoint
  spans every restaurant with a live offer and resolving each item's
  `food_category_id` in bulk isn't already an available query here.
  Item-scoped and restaurant-wide offers still badge correctly.
- Response shape matches docs/33's spec exactly: `{ restaurants: [{
  id, name, logo_url, rating_avg, distance_km, offer_titles: [...],
  items: [{ id, name, image_url, price, is_veg, offer_tag }] }] }`.
- `backend/.htaccess` — clean-URL rule added (`home/offers-browse` →
  `offers-browse.php`), matching every other `home/*` endpoint's
  convention. Not load-bearing for the app itself (see below).

### Customer App — network layer

- `network/Models.kt` — `OfferBrowseRestaurant`, `OfferBrowseItem`,
  `OffersBrowseResult` (mirror the PHP response field-for-field), plus
  `OfferBrowseItem.toMenuItem()` — same converter pattern
  `PopularItem`/`SearchItem` already have, needed so an item tapped on
  this screen can flow into `ItemDetailBottomSheetFragment`/
  `CartAddHelper` like every other item card in the app.
- `network/ApiService.kt` — `getOffersBrowse(lat, lng):
  Response<ApiResponse<OffersBrowseResult>>`, hitting
  `home/offers-browse.php` directly (the app calls `.php` paths
  directly everywhere already, same as `getPopularItems()` — the
  `.htaccess` clean-URL rule above is parity/consistency, not
  something the app itself needs).

### Customer App — layouts (new, unwired)

- `res/layout/item_offer_browse_restaurant_header.xml` — logo, name,
  ★ rating, distance, and every live offer title on that restaurant
  bullet-joined (`headerOfferTitles`). Clickable row (no listener
  attached yet — that's the adapter's job, not built this session).
- `res/layout/item_offer_browse_dish.xml` — same card body/ADD/qty-
  stepper shape as `item_search_dish.xml`, **minus** the restaurant-
  tag chip (redundant here — items are already grouped under their
  restaurant's own header row above them). `dishOfferTag` pill always
  visible (no `visibility="gone"` toggle needed — every item on this
  screen has one by construction, unlike Home/Search where most
  don't).
- `res/layout/activity_offer_screen.xml` — same toolbar/empty-state/
  `SwipeRefreshLayout` shape as `activity_saved.xml`, one flat
  `offersList` RecyclerView (no ids referenced by any Kotlin file
  yet).

---

## 🔴 Not built this session (flagged, not forgotten)

Everything below is still exactly docs/33's original plan — re-read
that doc's "Not done" #2 for the full original spec if any of this
needs re-deriving. Nothing about the plan changed, only the backend/
models/layouts got built ahead of it.

1. **`OfferBrowseAdapter.kt`** (new, not created) — flat RecyclerView
   adapter over a sealed row type, same shape as
   `SearchResultsAdapter.SearchRow`:
   ```kotlin
   sealed class OfferBrowseRow {
       data class RestaurantHeader(val restaurant: OfferBrowseRestaurant) : OfferBrowseRow()
       data class DishRow(val restaurant: OfferBrowseRestaurant, val item: OfferBrowseItem) : OfferBrowseRow()
   }
   ```
   Build the row list by flattening `OffersBrowseResult.restaurants`
   (one `RestaurantHeader` + N `DishRow`s per restaurant, in order).
   `RestaurantHeaderVH` binds `item_offer_browse_restaurant_header.xml`
   (logo via `ApiClient.baseUrlForStaticFiles(...)` prefix — same
   pattern every other image load in the app needs, `MenuAdapter`'s own
   comment documents why; rating `"%.1f".format(...)`; distance hidden
   when null; offer titles joined `" • "`) and fires `onRestaurantClick`.
   `DishVH` binds `item_offer_browse_dish.xml` — same
   image/veg-dot/price/offerTag binding as
   `SearchResultsAdapter.DishVH.bind()` minus the restaurant-tag line
   (that field doesn't exist on this layout), same
   `CartAddHelper.add(...)`/`QtyStepperTransition` ADD-button and qty-
   stepper wiring, fires `onDishClick` when the card body (not the
   stepper/ADD button) is tapped — copy that split-listener pattern
   exactly, it's what bug 1.6 fixed.

2. **`OfferScreenActivity.kt`** (new, not created) — `onCreate()`:
   `btnBack.setOnClickListener { finish() }`, build the adapter with
   `onRestaurantClick` → `Intent(RestaurantDetailActivity::class.java)`
   with `EXTRA_RESTAURANT_ID`/`EXTRA_RESTAURANT_NAME` (mirror
   `HomeActivity.openRestaurant()`'s exact extras), `onDishClick` →
   open `ItemDetailBottomSheetFragment.newInstance(restaurantId,
   restaurantName, item.toMenuItem(), ...)` (mirror
   `HomeActivity.openItemDetailSheet()`), `onCartChanged` → nothing
   needed unless this screen also shows a cart badge (check
   `activity_offer_screen.xml` doesn't currently have one — add only
   if wanted). `loadOffers()`: `ActiveAddressManager.get(this)` for
   lat/lng (same pattern `HomeActivity.loadPromoBanners()` already
   uses), call `api.getOffersBrowse(...)`, `adapter.submit(...)`,
   toggle `offersEmptyState` when `restaurants` is empty, wire
   `swipeRefresh.setOnRefreshListener { loadOffers() }`.

3. **Register in `AndroidManifest.xml`** — one `<activity
   android:name=".ui.offers.OfferScreenActivity"
   android:exported="false" />` entry, same block as
   `CheckoutActivity`/`SavedActivity`/etc.

4. **`HomeActivity.kt` wiring** (docs/33's own plan, unchanged):
   - `loadCategories()` — prepend a synthetic
     `FoodCategory(id = -1, name = "Offers", slug = "__offers__",
     iconUrl = null)` to the list before `categoryAdapter.submit(...)`.
   - `onCategoryTapped()` — early branch: `if (category.slug ==
     "__offers__") { startActivity(Intent(this,
     OfferScreenActivity::class.java)); return }` — before the
     existing toggle-active-category logic, so this chip never becomes
     the "active" filter chip and never calls `loadCategoryItems()`.

5. **`FoodCategoryAdapter.kt`** — icon fallback special case: when
   `category.slug == "__offers__"`, show `ic_offer_tag` (already
   exists — same icon this session's `activity_offer_screen.xml`
   toolbar and `item_offer_browse_restaurant_header.xml`'s own offer-
   fg tint already use) instead of the generic `ic_restaurant`
   fallback, regardless of `iconUrl` (always null for this synthetic
   entry, so the existing `if (!iconUrl.isNullOrBlank())` branch would
   otherwise fall through to `ic_restaurant`).

6. **Strings** — `offers_screen_title` ("Offers") and
   `offers_screen_empty` ("No offers running right now — check back
   soon") referenced by `activity_offer_screen.xml` but not yet added
   to `strings.xml`. **This will fail a real build as-is** — flagging
   clearly since it's the one loose end in this session's own new
   layout, not a pre-existing gap.

---

## Needs a real machine, not this sandbox

1. Everything docs/33/34 already listed (migration 48, `php -l` on the
   backend files, Gradle build, device tests) — still open, this
   session added one more file to the `php -l` list:
   - `backend/api/v1/home/offers-browse.php`
2. Once items 1–6 above are built: Gradle build to catch the missing
   `offers_screen_title`/`offers_screen_empty` strings (§6) and hand-
   matched view-binding ids across all three new layouts (§1) against
   `OfferBrowseAdapter`/`OfferScreenActivity` — none of that exists
   yet, so nothing has been checked against real bindings at all this
   session.
3. Device test once built: tap "Offers" chip → confirm it opens
   `OfferScreenActivity` instead of filtering the normal list, shows
   restaurant sections with badged items, tapping an item opens the
   detail sheet, tapping a restaurant header opens
   `RestaurantDetailActivity`, and tapping the already-selected
   category-chip's own toggle-off `×` badge doesn't apply here (this
   chip should never render as "selected" — confirm
   `FoodCategoryAdapter`'s selection ring/badge logic doesn't fire for
   `slug == "__offers__"` either, not just the icon fallback in §5).

## Suggested order for next session

1. Add the two missing strings (§6 above) — 30 seconds, unblocks a
   real build the moment everything else lands.
2. `OfferBrowseAdapter.kt` (§1) — needed before the Activity can bind
   anything.
3. `OfferScreenActivity.kt` (§2) + manifest registration (§3).
4. `HomeActivity`/`FoodCategoryAdapter` wiring (§4/§5) — last, since
   it's what actually surfaces the screen; everything above it can be
   built and reasoned about independently first.
5. Then: Restaurant App `OfferManagerActivity`/`dialog_add_offer.xml`
   (docs/30's still-not-started item, `allow_coupon_stacking`'s own
   toggle switch UI) — the other standing open item from docs/33,
   untouched this session.
6. Migration 47 + 48, `php -l` everything, live click-through, real
   Gradle build — same standing checklist every session accumulates.
