# Anydrop — Bug Tracker & New Feature Requests (Phase 3.7)

**Status:** Draft — logged from user's live-device screenshots + testing
session on 2026-08-06. **Nothing fixed yet.** Confirm/edit this doc, then
say "start" and we fix these **one by one**, in the order listed, same
discipline as `06_Phase_3.6_UI_Fixes_And_New_Features.md`.

**Source:** 8 screenshots from a real device test pass on the Customer App
(Home, Restaurant Detail, Cart sheet, Checkout).

---

## 0. How to read this doc
Each item has:
- **Reported:** what the user saw, in their words
- **Root cause:** confirmed by reading the actual code (marked ✅), or
  flagged as **needs repro** if it can't be confirmed from static code
  alone (e.g. a crash needs a logcat/stack trace)
- **Fix:** the plan (not yet done)

---

## 1. Bugs

### 1.1 Filter chips revert after ~1 second (flashes correctly, then reverts to unfiltered)
**Reported:** "koi bhi filter lagate hai 1 second ke liye filter apply hota
hai, phir wapas refresh karne par filter show hota hai."

**✅ ACTUALLY FIXED (this session) — real bug found, distinct from the
filter-chip guard added in 3.6.** The 3.6 fix only cancelled the pending
debounced search callback inside `setupFilterChips()`'s click listener. It
never covered the **category icon row** (Pizza/Rolls/Burger/...),
`onPromoBannerTapped()`'s `category` branch, or `onExploreTileTapped()`'s
`offers`/`top10` branches — every one of those calls
`binding.searchInput.setText("")` to reset the search box, which still
fires the `TextWatcher`'s `afterTextChanged` and schedules a debounced
`runSearchOrReload("")` 400ms later. That callback has no idea a
category/tile filter was just applied — it unconditionally resets the
section title, swaps back to `restaurantAdapter`, and calls the plain
`loadRestaurants()`, silently overwriting the just-applied filtered view
about 400ms after the tap. That's exactly the "flashes then reverts"
symptom, and exactly why a manual pull-to-refresh (which re-reads
`activeCategorySlug`/`activeFilter` via `reloadCurrentView()`) always
"fixed" it again.

**Fix (corrected — first attempt was incomplete):** the first pass added
`clearSearchInputProgrammatically()`, which cancelled the *previously
pending* `searchRunnable` before calling `searchInput.setText("")` — but
`setText("")` still fires the `TextWatcher`, which immediately schedules a
**brand-new** runnable 400ms out regardless of what was just cancelled, so
the revert still happened, just via a freshly-created callback instead of
the stale one. User re-tested and confirmed it was still reverting
("milliseconds ke liye filter lagta hai, phir old, phir refresh se theek").
**Real fix:** a `isProgrammaticSearchClear` boolean the `TextWatcher`
checks first — while true, `afterTextChanged` does nothing at all (no
reschedule), so a code-driven clear can never trigger a delayed reload.
**File changed:** `customer/app/src/main/java/com/anydrop/customer/ui/home/HomeActivity.kt`.

### 1.2 ✅ FIXED — Filter chip row needs a "clear/cross" option once a filter is active
**Reported:** filter icons ki UI achhi karo; filter select karne ke baad
uske paas ek cross (×) bhi hona chahiye.

**Fix:** the filter chips are plain styled `TextView`s (not Material
`Chip`s), so the doc's original `app:closeIconEnabled` plan didn't apply as
written. Implemented instead via `setCompoundDrawablesWithIntrinsicBounds()`
— the currently-active chip (excluding "All", which has nothing to clear)
now shows `ic_close` as a trailing icon. Tapping the already-active chip
again now also clears the filter back to "All" (previously only tapping
"All" itself could clear it) — the × icon is the visual affordance for this.
**File changed:** `ui/home/HomeActivity.kt` (`setupFilterChips()` rewritten
with an `applyChipSelectionUi()` helper, toggle-to-clear tap logic).

### 1.3 App crashes when using Search
**✅ FIXED — separate session, see chat history.** Root cause: `search.php`
never emitted a `tags` key in its restaurant results (unlike
`restaurants/list.php`, which does), so Gson deserialized
`Restaurant.tags` as a real `null` at runtime — Gson does not honor Kotlin
constructor default values (`= emptyList()`) since it builds objects via
reflection/Unsafe, not the constructor. `SearchResultsAdapter.kt`'s
`restaurant.tags.isNotEmpty()` then NPE'd. Fixed both sides: `search.php`
now fetches and emits `tags` (same pattern as `list.php`), and
`Restaurant.tags`/`RestaurantDetail.tags` in `Models.kt` were changed to
nullable with all 3 call sites (`SearchResultsAdapter`,
`RestaurantDetailActivity`, `RestaurantAdapter`) updated to use
`.orEmpty()` so this class of bug can't recur even if another endpoint
forgets the field. A follow-up deploy also hit and fixed a second bug in
the same new code: `$restaurantIds` in `search.php` was built via
`array_map()` over an associative array, producing non-sequential keys —
PDO's positional `?` placeholders require a sequential array, so
`execute()` threw `PDOException: Invalid parameter number` until wrapped
in `array_values()`. **Confirmed working on-device by user.**

### 1.4 ✅ FIXED — The small square "checkbox" next to dish names does nothing
**Reported:** "ye tick box kis liye hai, kaam nahi kar raha."

**Fix:** confirmed this was the veg/non-veg indicator missing its inner
dot (visual bug, not a missing feature — no click listener needed, it was
never supposed to be interactive). `bg_badge_veg.xml` and
`bg_badge_nonveg.xml` rebuilt as layer-lists: outer square outline
(unchanged) + a new inset filled circle (green dot for veg, red dot for
non-veg), matching the standard Zomato/Swiggy veg symbol.

### 1.5 ✅ FIXED — Notification permission popup reappears every app open, even after already granted
**Reported:** "har app open par notification permission ka popup aata hai,
allow karne ke baad bhi phir se aata hai."

**Fix:** two changes, both as planned. (1) `NotificationPermissionDialog`
now persists "user already answered this prompt" in SharedPreferences
(same `anydrop_prefs` pattern as `VegModeManager`) instead of a
process-lifetime static flag — the static field reset on every real-world
app open, which was the actual bug. (2) Added
`NotificationHelper.areNotificationsEnabled()` (wraps
`NotificationManagerCompat.areNotificationsEnabled()`) — `showOnce()` now
checks this first and skips the popup entirely if notifications are
already enabled, regardless of the stored flag. **Files changed:**
`notifications/NotificationHelper.kt` (new `areNotificationsEnabled()`),
`ui/common/NotificationPermissionDialog.kt` (rewritten).

### 1.6 ✅ FIXED — "Delivering to your location" bar doesn't do anything
**Reported:** "delivering to your location option nahi chal raha hai."

**Root cause:** ✅ confirmed — no click listener exists anywhere on that
view in `HomeActivity.kt`. This isn't a regression — it's the **Home
screen GPS location bar**, which `Status.md` already explicitly flagged as
**intentionally deferred to Phase 4** (built together with rider routing
so it's not bolted on twice). Right now Checkout's "Use current location"
button is the only working location entry point.
**Fix:** confirm whether you want a lightweight version now (tap → opens
the same address-editor bottom sheet with "Use current location" instead
of nothing) as a stop-gap, or whether it's fine to leave it inert until
Phase 4's full map-based picker. Recommend the lightweight stop-gap since
it's a ~30 minute wire-up using components that already exist
(`AddressEditorBottomSheet` + `CheckoutActivity`'s existing GPS/Geocoder
logic), rather than leaving a dead-looking button on the busiest screen.

**✅ FIXED — lightweight stop-gap implemented.** `HomeActivity` now
implements `AddressEditorBottomSheet.LocationRequester` (same interface
`CheckoutActivity` already used) and `deliveryLocationText` has a click
listener that opens `AddressEditorBottomSheet` in add-mode. The sheet's own
"Use current location" button already falls back to `activity as?
LocationRequester` when there's no parent Fragment, so no extra wiring was
needed there — just implementing the interface + adding GPS fetch/Geocoder
resolve logic (identical pattern to Checkout's, not duplicated by
reference). Full map-based picker is still Phase 4 scope; this replaces the
dead-looking bar with a working entry point in the meantime.
**Files changed:** `customer/app/src/main/java/com/anydrop/customer/ui/home/HomeActivity.kt`
(implements `LocationRequester`; added `pendingSheetForLocation`,
`locationPermissionLauncher`, `requestLocationForAddressEditor()`,
`fetchCurrentLocation()`, `onLocationResolved()`; click listener on
`deliveryLocationText`). No layout, manifest, or backend changes —
`ACCESS_FINE_LOCATION` was already declared.

### 1.7 ✅ FIXED — Cart badge / cart sheet shows wrong item count (3 items added, shows 1)
**Reported:** "cart par values show nahi ho rahi, 3 item dalne ke baad bhi
1 show kar raha hai."

**Root cause:** ✅ confirmed, and this is a real bug, not a display
glitch. `CartManager.add()` is **single-restaurant scoped by design**:
```kotlin
fun add(restaurantId: Int, item: MenuItem) {
    if (this.restaurantId != null && this.restaurantId != restaurantId) {
        clear()   // <-- wipes the whole cart
    }
    ...
}
```
This is correct behavior when adding items *inside one restaurant's menu*.
But the new **"Popular dishes near you"** row (Phase 3.6) shows dishes
from **different restaurants side by side** (in your screenshots:
Pavitras, Shahi Sabji Wala, The Rolls...). Tapping "Add" on 3 different
popular dishes from 3 different restaurants calls `CartManager.add()` 3
times with 3 different `restaurantId`s — each call **silently clears**
the previous restaurant's item before adding the new one. End result:
whatever you added *last* is the only thing left, so the badge shows 1.
**Fix:** this needs a product decision first — Anydrop's checkout flow
(single `restaurant_id` per order, per `02_API_Contract.md`) only
supports one restaurant per order, matching how the backend/order model
is built. Two options:
  - **(A)** Keep single-restaurant-cart as the real rule (matches
    Zomato/Swiggy — you can't checkout one order across two kitchens), but
    make it **visible and intentional** instead of silent: when tapping
    "Add" on a dish from a different restaurant than what's already in
    the cart, show a confirmation ("Your cart has items from Pavitras.
    Start a new cart with items from Shahi Sabji Wala?") before clearing
    — same pattern Zomato uses.
  - **(B)** Support multiple restaurant-scoped carts and let the user
    checkout each separately (bigger change — new cart-switcher UI, not
    a small fix).
  Recommend **(A)** — smallest change, matches the existing backend order
  model exactly, just replaces a silent data-loss with an explicit user
  choice.

**✅ FIXED — option (A) implemented.** `CartManager.add()` no longer clears
the cart as a silent side effect: if the tapped item belongs to a different
restaurant than what's already in the cart, it now returns
`AddResult.Conflict` and leaves the existing cart untouched. Every ADD/qty-
stepper button in the app (Popular row, Search/Category dish cards,
Restaurant Detail's menu) now routes through a new shared
`CartAddHelper.add()`, which shows the confirmation dialog ("Your cart has
items from X. Adding this item from Y will clear your current cart.
Continue?") and only calls the new `CartManager.clearAndAdd()` after the
user taps "Start New Cart". Cancelling leaves the cart exactly as it was.
**Files changed:** `customer/app/src/main/java/com/anydrop/customer/data/CartManager.kt`
(added `restaurantName`, `AddResult`, `wouldConflict()`, `clearAndAdd()`;
`add()` now returns `AddResult` instead of `Unit`), `customer/app/src/main/
java/com/anydrop/customer/ui/common/CartAddHelper.kt` (new), `ui/search/
SearchResultsAdapter.kt`, `ui/home/PopularItemsAdapter.kt`, `ui/restaurant/
MenuAdapter.kt` (gained a `restaurantName` constructor param), `ui/
restaurant/RestaurantDetailActivity.kt` (passes restaurant name to
`MenuAdapter`), `values/strings.xml` (4 new strings).

### 1.8 ✅ FIXED — "Popular dishes near you" row should disappear once a filter is applied
**Reported:** "filter apply ke baad popular dishes chali jani chahiye."

**Fix:** two changes. `isBrowsingDefaultHome()` (the check that gates the
row's visibility) now also requires `activeFilter == null`, not just no
search/category — this fixes it for the *next* `loadPopularItems()` call.
Also added an explicit `setPopularItemsVisible(...)` call directly inside
the filter-chip tap handler itself (same pattern already used in
`onCategoryTapped`/`onExploreTileTapped`), so the row hides **immediately**
on tap rather than waiting for popular items to reload again. Reappears
correctly when "All" is tapped (or the active chip is tapped again to
clear it — see 1.2). **File changed:** `ui/home/HomeActivity.kt`.

### 1.9 ✅ RESOLVED — Tapping a filtered/searched dish should open a real item-detail view (with restaurant name + related popular items), not just the restaurant page
**Reported:** "koi item filter mein open karne ke baad, uski details aur
upar restaurant ka naam aur uske popular products aane chahiye."

**Root cause:** ✅ confirmed — today, tapping the *body* of any dish card
(Popular row, Search results, Category-browse results) calls
`openRestaurantById()` — it jumps straight to the full restaurant menu
page, with no dedicated single-dish detail view at all. There's currently
no "item detail" screen anywhere in the Customer App.
**Fix:** this is a **new screen**, not a small fix — a dish-detail bottom
sheet/activity showing: the dish's own image/name/description/price, the
restaurant it's from (name, tappable to open the full restaurant), and a
"More from this restaurant" or "You might also like" row (reuse
`getPopularItems()` or a filtered `menu.php` call scoped to that
restaurant). Scoping this properly needs one clarification — see open
question in §3 below.

**Resolved as part of item 2.6 below** — `ItemDetailBottomSheetFragment`
(bottom sheet, not a separate Activity) now opens from `onDishClick` on
`MenuAdapter` (Restaurant Detail), `PopularItemsAdapter` (Home), and
`SearchResultsAdapter` (Search) — confirmed wired in code. Bookmark-sync,
cart-qty-sync, and per-dish share link polished in
`docs/10_Handover_Part12_ItemDetail_Sync_And_Share.md`. **Build verified
via Gradle and confirmed working on-device (2026-08-10).**

---

## 2. New feature requests (not bugs — net-new scope)

### 2.1 Restaurant-created coupons, with an on/off visibility toggle
**Requested:** "Restaurant coupon code bana sakti hai apne restaurant ke
liye aur uski visibility ko user ke paas on/off kar sakta hai."

**Current state:** `coupons` table already supports `restaurant_id`
(nullable — null means platform-wide) per `01_Database_Schema.md` §6, and
`/cart/validate` + `/orders` already accept and validate a coupon code.
**What's missing:** there is **no Restaurant App UI** for a restaurant
owner to create/edit/toggle their own coupons — coupon rows only exist
today via direct SQL/seed data. This is Restaurant App scope (new screen:
"My Coupons" — create code, discount type/value, min order, validity
dates, active/inactive toggle) + a couple of small backend endpoints
(`GET/POST/PUT /restaurant/coupons`, scoped to the logged-in restaurant's
own `restaurant_id` only, same ownership-enforcement pattern already used
for `/restaurant/menu`).

### 2.2 Restaurant-uploaded offer banners — ✅ SCOPE CONFIRMED by user (this session)
**Requested:** "New offer banner bhi upload kar sakta tha jo dikhega."
**Follow-up clarification (this session):** "jaha main advertisement
banner hai waha restaurant ke approved banner **by admin** show honge" —
confirms **option (a)** from the original open question: restaurants
submit a banner, **admin approves it**, and only then does it appear in
the **same main Home carousel slot** (not a separate restaurant-only
banner area).

**What this needs (backend):**
- Extend `promo_banners` (Phase 3.6 table) with: `restaurant_id` (FK
  NULL — null means platform/admin-created, non-null means
  restaurant-submitted), `submitted_by_restaurant_id`, `status` ENUM
  (`pending`,`approved`,`rejected`) DEFAULT `pending`, `reviewed_by_admin_id`
  FK NULL, `reviewed_at` TIMESTAMP NULL. Only rows with `status='approved'`
  AND `is_active=1` are returned by `home/promo-banners.php` (the Customer
  App carousel query gets a `WHERE status='approved'` clause added).
- New Restaurant App endpoint: `POST /restaurant/banners` (submit — image
  URL/upload + title/subtitle + target_type/target_value, always lands as
  `pending`), `GET /restaurant/banners` (see their own submissions +
  status).
- New Admin Panel screen (Phase 5 scope): pending-banner review queue —
  approve/reject with an optional rejection reason, same pattern as
  restaurant approval workflow already in `02_API_Contract.md` §6.
- **Image upload itself:** this is the first real file-upload feature in
  the project (per `00_README.md`'s "Image Storage" row — planned to land
  on the server's `/uploads/` folder for now). Needs a basic
  multipart-upload PHP endpoint with file-type/size validation — doesn't
  exist yet anywhere in the codebase, this would be the first one.

### 2.3 Service-area check — "not available in your area yet" state
**Requested:** "app open hote hi location wala — agar uske area mein koi
service nahi hai to 'Sorry, we are not available in your area, coming
soon' dikhna chahiye."

**Current state:** the Home screen has no concept of "no restaurants
deliver here at all" — it just shows an empty restaurant list, which
reads as broken rather than "we haven't launched in your city yet."
**What this needs:**
- **Backend:** `GET /restaurants?lat=&lng=` already computes
  `distance_km` per restaurant server-side. Add a lightweight
  `GET /system/service-area-check?lat=&lng=` (or just reuse the existing
  restaurants call and check `meta.total === 0` client-side) — if **zero**
  restaurants have any `delivery_radius_km` covering that point, the area
  is unserved.
- **Customer App:** on first location resolve (once Phase 4 or the §2.5
  location-bar stop-gap wiring from the previous doc is in place), if the
  service-area check comes back empty, show a dedicated full-screen state
  — illustration + "We're not available in your area yet — coming soon!"
  + maybe an "Notify me when you launch here" input (optional, flag if
  wanted) — instead of the normal Home content.
- **Scope note:** this depends on location actually being wired up first
  (see bug **1.6** in the previous section, and Phase 4's fuller location
  module) — sequence this after that.

### 2.4 Zomato-style collapsing header on Home (sticky search, everything else scroll-hides)
**Requested (with reference screenshot):** category slide row should
**not** just sit there — scrolling down should hide the ad banner **and**
the category-icon row **and** the filter-chip row, leaving only the
search bar pinned; scrolling up even slightly should bring everything
back immediately (exactly Zomato's Home behavior).

**Current state:** Phase 3.6 (§1.4, `06_...md`) already made only the top
bar + search bar permanently pinned, with everything else (banner,
categories, filters, list) inside one scrolling `NestedScrollView` — but
that's a **one-way, permanent** pin. It does not do Zomato's **collapse
on scroll-down / snap back on scroll-up** animation, which is a distinct,
fancier behavior.
**What this needs:** replace the current `NestedScrollView` structure
around the banner+categories+filters block with an `AppBarLayout` +
`CollapsingToolbarLayout`-style scroll-flag setup
(`scrollFlags="scroll|enterAlways"` on that block, inside a
`CoordinatorLayout`), OR a manually-coded scroll-listener that animates
that block's `translationY`/visibility based on scroll direction if the
`AppBarLayout` approach conflicts with the existing `SwipeRefreshLayout`.
This is a real layout-architecture change to `activity_home.xml`, bigger
than a typical bug fix — flagging as its own item, not a quick tweak.

**✅ FIXED (v7) — collapse-flicker + banner-crop, both root-caused.**
1. **Filter row "collapses for a moment then snaps back":** the previous
   direction-anchor scroll tracking reset itself to neutral on every
   callback it ignored during a collapse/expand animation, so the first
   real callback right after an animation finished was always treated as
   a fresh direction change — able to re-arm an almost-immediate
   re-trigger off a single fling-settle blip. Replaced with a plain
   per-callback `dy` accumulator (`filterDownAccumPx`/`filterUpAccumPx`)
   that resets on direction flip and has no special case for "an
   animation is running" — `animateFilters()` itself now no-ops if
   already in the requested state, so redundant calls are harmless by
   construction instead of needing to be prevented upstream.
2. **Promo banner not fully visible:** root cause was static layout, not
   scrolling — `collapsibleFilters` is a deliberately opaque overlay
   (see its own comment) drawn on top of the scrollable content's top
   edge, and the promo banner is the first item in that scrollable
   content. Whenever filters were expanded (the normal resting state),
   the overlay's own height covered the first ~50dp of the banner
   underneath it. Fixed with `filterOverlaySpacer`, a plain `View` at
   the top of the scrollable content sized once at runtime to exactly
   `collapsibleFiltersExpandedHeight` — the banner now always starts
   below where the overlay sits, never behind it.
**Files changed:** `ui/home/HomeActivity.kt` (`setupCollapsingHeader()`,
`animateFilters()`), `res/layout/activity_home.xml` (new
`filterOverlaySpacer`).

**Update (v8) — flicker was still happening after v7; real root cause found.**
The v7 fix removed the old "ignore scroll callbacks while animating" guard
entirely, reasoning that `collapsibleFilters` (an overlay) never resizes
`homeNestedScroll`/`swipeRefresh` so nothing needed suppressing — true,
but incomplete: `collapsibleFilters` is still a `ConstraintLayout`
**sibling**, and animating its `layoutParams.height` forces the whole
`ConstraintLayout` to re-run its solver over **every** child each frame,
not just itself. That full re-layout pass can nudge `homeNestedScroll`'s
`scrollY` by a stray pixel or two as a side effect — and since
`filtersCollapsed` flips to `true` the instant the collapse animator
*starts* (not once it finishes), that one stray "scroll up" blip mid-
animation was enough to immediately fire `animateFilters(collapse =
false)` again, i.e. exactly "hides for a moment then snaps back".
**Fix:** reinstated an ignore-while-animating guard (`isFiltersAnimating`)
in the scroll listener — but unlike the old part-17/18 version, it keeps
`filterScrollLastY` synced to the live `scrollY` on every ignored frame
instead of resetting direction/anchor bookkeeping, so the first real
callback once the animation settles computes a small, genuine `dy`
instead of a stale/inflated one. **File changed:** `ui/home/HomeActivity.kt`
(`setupCollapsingHeader()`).

### 2.4b Filter chip borders inconsistent
**Reported:** chip row borders didn't match across chips — the active
("All"/selected) chip had no border at all while unselected chips had a
very light one (`@color/outline`, #E0E0E0 on a white chip = barely
visible), making the row look uneven.
**Fix:** `bg_chip_selected.xml` and `bg_chip_unselected.xml` both now
draw a 1dp stroke — new `chip_border_unselected` (#D6D6D6, clearly
visible on white) and `chip_border_selected` (matches `anydrop_primary`)
so every chip has a consistent, visible border regardless of state.
**File changed:** `res/drawable/bg_chip_selected.xml`,
`res/drawable/bg_chip_unselected.xml`, `res/values/colors.xml`.

### 2.5 Floating "Menu" jump button on Restaurant Detail (enhances 3.6 §2.1's category tab bar)
**Requested (with reference screenshots):** on a restaurant's menu page,
a floating "Menu" pill button (bottom-right, fork/knife icon) that opens
a modal list of every category **with item counts** (e.g. "Pizza — 17",
"Burgers — 6"), tapping a row jump-scrolls to that section and closes the
modal.

**Current state:** Phase 3.6 §2.1 already built a **horizontal
scrollable chip tab bar** for the same jump-to-category need
(`RestaurantDetailActivity.buildCategoryTabs()` / `jumpToCategory()`).
That still works and stays — but a horizontal tab bar gets cramped once a
restaurant has 8-10+ categories (this reference restaurant has ~10). The
floating button + full-list modal is Zomato's answer to that same
problem at scale.
**What this needs:** a `FloatingActionButton`-style pill (`ExtendedFloatingActionButton`,
Material lib already in the project) anchored bottom-end of the menu
screen, opens a `BottomSheetDialog` listing every category name + its
item count (count = size of that category's item list, already available
client-side, no backend change needed) + a "Close" button, tapping a row
reuses the exact same `jumpToCategory()` scroll logic the tab bar already
has. **Recommend keeping both** — chip tab bar for restaurants with few
categories (visually lighter), floating button becomes visible/useful
once category count crosses a threshold (e.g. show it always, it's
useful either way and matches the reference app).

### 2.6 ✅ RESOLVED — Full dish customization sheet — addon groups with a max-select cap + cooking notes
**Requested (with reference screenshots):** tapping a dish (or its ADD
button) should open a proper customization sheet: addon checkboxes
grouped with a "select up to N" cap and per-addon price, a quantity
stepper, an optional free-text "cooking request" field (with a character
limit and quick-select preset chips like "No onion or garlic"), and a
sticky "Add item ₹149" button showing the live running total.

**Current state:** this directly **absorbs and expands bug 1.9** from
the previous section (the "open a real item-detail view" gap) —
`menu_item_addons` already exists in the schema with a price each, and
`order_items.addons_json` already stores whatever was picked, but:
- there is **no grouping/max-select concept** on addons at all today —
  every addon is currently independent, no "pick up to 3" cap
- there is **no cooking-request field anywhere** — not on `order_items`,
  not on `orders` (the existing `delivery_instructions` is order-level,
  not per-item)
- there is **no dish detail/customization screen at all** in the Customer
  App yet (confirmed in the previous doc's bug 1.9)

**What this needs (bigger than a typical bug — this is the real scope
behind 1.9):**
- **Backend:** new table `menu_item_addon_groups` (id, menu_item_id,
  group_name, max_select, is_required) with `menu_item_addons` gaining a
  `group_id` FK; new column `order_items.special_instructions` (TEXT
  NULL, per-item, distinct from the order-level `delivery_instructions`);
  `cart/validate` and `orders` creation logic updated to accept and
  price-check grouped addon selections against `max_select`.
- **Customer App:** new `ItemDetailBottomSheet` (resolves 1.9's open
  question — bottom sheet, not a full Activity, matching this reference
  exactly) — image/name/bookmark/share row, addon checkboxes per group
  with live-disable once the group's cap is hit, quantity stepper,
  cooking-request text field with quick-select chips that append into
  the same field, sticky Add button showing `base_price + selected_addons_total`
  live. This becomes the tap target for **every** dish card body across
  the app (Popular row, Search, Category-browse, in-menu items) —
  replacing the current "opens the restaurant" behavior with "opens this
  sheet"; the restaurant is still reachable via a "from ⟨name⟩" link
  inside the sheet.

**Resolution confirmed (2026-08-10):** `ItemDetailBottomSheetFragment`
(`ui/itemdetail/`) built and wired as the tap target for dish card bodies
across Restaurant Detail (`MenuAdapter`), Home Popular row
(`PopularItemsAdapter`), and Search (`SearchResultsAdapter`) — verified
directly in code (`onDishClick` callbacks present and connected in all
three adapters plus their host activities). Bookmark-sync, cart-qty-sync,
and per-dish share link were polished separately in
`docs/10_Handover_Part12_ItemDetail_Sync_And_Share.md`. **Build verified
via Gradle and confirmed working on-device.**

### 2.7 Restaurant cards — dish-photo carousel with Instagram-story-style progress bars (instead of one static cover image)
**Requested (with reference screenshot):** restaurant cards on Home
shouldn't show one static logo/cover photo — they should show a
**rotating set of that restaurant's actual dish photos** (e.g. "Thali ·
₹230" tag overlayed on the photo), auto-advancing, with small
progress-bar segments at the bottom (not plain dots) that **visibly fill
up over a few seconds** before sliding to the next photo — exactly
Instagram Stories' / Zomato's progress-bar pattern, not a static dot
indicator.

**Current state:** ✅ confirmed — `RestaurantAdapter.kt`'s `VH.bind()`
loads exactly **one** static image into a single `ImageView`
(`binding.restaurantCover`, via `restaurant.coverUrl`, one Coil `.load()`
call). There's no carousel, no per-dish photo, no progress indicator at
all on restaurant cards today — this is a genuinely new capability, not a
tweak to something existing (it's different from the Home-level promo
carousel built in Phase 3.6 §2.2, which is banner images, not
per-restaurant dish photos).

**What this needs:**
- **Backend:** restaurant cards need a small ordered list of photos per
  restaurant instead of one `cover_url`. Two ways to source this without
  new upload infrastructure: (a) auto-derive it from that restaurant's
  own `menu_items.image_url` (pick top N by `is_bestseller`/
  `is_recommended`/highest-price, already-existing columns, zero new
  tables) — recommended, since it needs no new admin/restaurant upload
  work and directly matches the reference screenshot's "Thali · ₹230"
  dish+price overlay pattern; or (b) a new `restaurant_gallery_photos`
  table if you want restaurants to curate a dedicated photo set separate
  from their menu. **Recommend (a)** — reuses data that already exists,
  ships faster, and is literally what the reference screenshot shows
  (dish name + price overlaid on the photo).
  `restaurants/list.php` gains a `gallery: [{image_url, dish_name, price}]`
  array (capped at ~5-8 per restaurant) alongside the existing fields.
- **Customer App — the card itself:** replace the single `ImageView`
  with a small `ViewPager2` inside `item_restaurant.xml` (same widget
  already used for the Phase-3.6 Home promo carousel, so no new
  dependency), showing each gallery photo with the dish-name+price text
  overlay (bottom-left, matching the reference's "Thali · ₹230" pill).
- **The progress-bar-fill animation specifically:** this is the detail
  the user is pointing at explicitly — **not** simple static dots
  (`dot_promo_selected`/`dot_promo_unselected`, the drawables built for
  the Home banner carousel in 3.6, are the wrong pattern here). Needs a
  **new** custom view: a row of thin horizontal bar segments (one per
  photo, equal width, small gap between), where the **currently-showing**
  segment animates its fill from 0%→100% width over the slide's dwell
  time (~3-4s, matching Instagram Stories' typical timing) using a plain
  `ValueAnimator` driving that segment's width/scaleX, while already-seen
  segments stay fully filled and not-yet-seen segments stay empty. On
  reaching 100%, advance `ViewPager2` to the next photo and reset/start
  the next segment's fill. Manual swipe should also update which segment
  is "current" and restart its timer, same as real Stories UI.
  **This is a genuinely new, non-trivial custom view** — it doesn't exist
  anywhere in the codebase yet (the closest thing, `TabLayoutMediator`
  dots on the Home banner carousel, is plain static dots with no fill
  animation) — flagging so it isn't underestimated as "reuse the existing
  dots."
- **Performance note:** since this runs inside a `RecyclerView` (the
  restaurant list itself), each visible card's carousel needs its own
  independent timer that starts/stops with view attach/detach
  (`onViewAttachedToWindow`/`onViewDetachedFromWindow` in the adapter) —
  otherwise off-screen cards keep animating and burn battery/CPU for
  nothing. Cap how many photos load per card (the 5-8 suggested above) so
  scrolling a long restaurant list doesn't trigger dozens of simultaneous
  image loads.

### 2.8 Further Zomato/Swiggy pattern research — additional ideas worth flagging
Per this session's "aur kya ho sakta hai, achhe se deep research karo"
request — patterns from Zomato/Swiggy not yet requested explicitly but
worth having on record since they're natural companions to what's already
scoped above:
- **Skeleton/shimmer loading placeholders** instead of blank white space
  or a bare spinner while Home/restaurant-list/menu data loads — cheap to
  add (a shimmer drawable + placeholder-shaped views swapped for real
  content on load), and directly improves the "feels premium" quality bar
  this whole doc is chasing.
- **"Add" button morphs into a quantity stepper in place**, with a small
  bounce/scale animation on tap (both apps do this) — the app **already
  has** the `btnAdd`↔`qtyStepper` swap logic (Phase 3.6 §1.6/§2.4) but
  currently swaps instantly with no transition animation; adding a short
  scale/fade transition here is a small, cheap visual upgrade to
  something that already works functionally.
- **Restaurant-open/closed dimming:** Zomato/Swiggy visually dim
  (reduced opacity + greyscale) a restaurant's card and disable its "Add"
  buttons when it's closed, rather than just showing a red "Closed" text
  label like the current `restaurantStatus` field does — makes closed
  restaurants immediately scannable-past instead of requiring reading the
  status text.
- **Pull-to-refresh custom animation:** the project already uses
  `SwipeRefreshLayout` (stock Android spinner) — Zomato/Swiggy use a
  branded custom refresh animation instead. Cosmetic-only, low priority,
  noting it since it's a common "why does this still look like a
  template" complaint once everything else above is polished.
- **Cart bottom-bar (mini-cart) persists across screens while browsing** —
  today the cart is only reachable via the cart icon in the top bar;
  Zomato/Swiggy show a small persistent "View Cart (3 items) · ₹347"
  bar pinned to the bottom of Home/Restaurant-detail once the cart is
  non-empty, so checkout is always one tap away without hunting for the
  icon. Flag if wanted — moderate-sized addition (a new persistent bottom
  bar view + visibility wiring keyed off `CartManager.totalItemCount()`).

These four are **not** added to the fix-order list below since they
weren't explicitly requested — flagging only so nothing gets silently
missed. Say which (if any) you want added to the queue.

---

## 3. Decisions (my call, since you asked me to pick)
Two items genuinely can't be decided by me — they're facts only you have,
not judgment calls:
- **1.1 (filter revert):** still need to know which APK build is on your
  test phone (pre- or post-3.6). Not blocking the start of work — it's
  Step 1 of Phase B below, we'll check it live.
- **1.3 (search crash):** still need a logcat or repro steps. Not
  blocking either — it's parked at the point in the queue where we'll
  ask again if it hasn't arrived yet.

Everything else, my call:

| # | Question | Decision | Why |
|---|---|---|---|
| 2.3 | "Notify me" capture on service-area screen? | **Skip it for v1** — just the message, no capture | An email/waitlist capture needs a new table + form + validation for a screen most users will only see once, in an area you haven't launched in yet. Ship the message now; add capture later only if you actually start getting requests from unserved areas worth tracking. |
| 2.4 | Full animated collapsing header? | **Defer — keep the current Phase-3.6 permanent-pin behavior** | It's the single biggest layout-architecture item in this whole doc, purely cosmetic, and touches a screen that already works correctly. Everything else in this doc either fixes something broken or adds a capability that doesn't exist yet — this is the one item that's pure polish on top of working behavior, so it goes last, after every functional gap is closed. |
| 2.7 | Dish-photo source: auto from `menu_items` vs. dedicated restaurant upload? | **Auto from `menu_items`** (option a) | Zero new upload infrastructure, ships in one phase instead of two, and is literally what the reference screenshot shows (dish + price overlay, which only makes sense if the photo *is* an actual menu item, not a generic restaurant photo). |
| 2.8 | Which extra research ideas? | **Yes to:** shimmer loading, Add-button transition animation, closed-restaurant dimming. **Defer:** persistent mini-cart bar. | The first three are each small, isolated, cheap wins that directly raise the "feels premium" bar with almost no risk of breaking anything. The mini-cart bar is a real new persistent UI element touching multiple screens — bigger than the others, and the cart icon already works, so it's a nice-to-have rather than a gap. Parked in Phase E below rather than dropped. |

---

## 4. Project-level sequencing note (confirmed this session)
User's explicit build priority: **Customer App fully finished and
polished first → then Restaurant App → then Admin Panel → then Rider/
Delivery Boy App.** This doesn't replace the original Phase 0-7 roadmap's
technical dependencies (e.g. Restaurant App still needs *some* backend
work alongside Customer App fixes, since they share endpoints) — it's a
priority signal for **where attention goes when there's a choice**:
Customer-App-facing bugs/features (this whole document) take priority
over starting Restaurant App work (2.1's coupon UI, 2.2's banner-review
UI) or Admin Panel (Phase 5) or Rider App (Phase 4), until the Customer
App is in a state the user is happy with. Logged here and carried into
`Status.md`.

**New this session — Payments:** user will provide **real UPI gateway
source code shortly**. Today, Checkout's "UPI" option (per the screenshot
in this doc's Phase-3.6 predecessor) is a placeholder — no real payment
gateway is wired in yet (`Status.md`/`02_API_Contract.md` currently
assume Cash on Delivery as the only fully-real path). Once that source
arrives it becomes its own phase (**Phase F** below) — sequenced after
the Customer-App UI/UX work in this doc, since a broken UI with working
payments is worse than a polished UI still on COD. Flagging now so it's
on the roadmap and doesn't get bolted on last-minute later.

---

## 5. Workflow — phased, in priority order
Same 16 line items as before, now grouped into phases by **dependency and
risk**, not just a flat list — each phase is a natural checkpoint (build +
test + confirm before moving to the next), same discipline as Phase 3.6.

### Phase A — Data loss & stability (do first, no dependencies)
Real bugs causing lost work or crashes — always highest priority
regardless of anything else in this doc.
1. **1.7** — cart silently loses items across restaurants (add the
   confirm-before-clearing dialog, option A)
2. **1.3** — search crash (first sub-step: get logcat/repro from you if
   it hasn't arrived by the time we reach this point; can't skip ahead of
   it blind)

### Phase B — Quick isolated fixes (small, independent, low risk)
Nothing here depends on anything else — safe to batch together.
3. **1.5** — notification permission popup loop
4. **1.1** — filter revert (Step 1: confirm which build is on your test
   device; Step 2: fix if still real post-3.6)
5. **1.8** — popular-dishes row not hiding when a filter is applied
6. **1.4** — veg badge missing its inner dot
7. **1.2** — filter chip needs a close/× icon

### Phase C — Location & service area (sequential — C2 depends on C1)
8. **1.6** — "Delivering to your location" bar stop-gap wiring
9. **2.3** — service-area "not available yet" state (needs 1.6 done
   first; no notify-capture, per §3 decision)

### Phase D — Ordering experience (the big functional gap — do as one block)
The core "tap a dish → customize → add to cart" flow doesn't exist yet;
this is the most user-facing gap in the whole app, so it comes right
after stability/quick-fixes/location, before any pure polish.
10. **2.6 / 1.9 combined** — full dish customization bottom sheet (addon
    groups with max-select cap, per-item cooking notes, schema changes)
    — ✅ DONE, built + wired + Gradle-build-verified + on-device tested
    (2026-08-10)
11. **2.5** — floating "Menu" jump button on Restaurant Detail

### Phase E — Visual polish (do after everything functional above works)
Cheap, isolated, cosmetic wins — safe to defer behind anything that
actually fixes or adds a capability, per §3's reasoning.
12. Shimmer/skeleton loading placeholders (Home, restaurant list, menu)
13. Add-button ↔ quantity-stepper transition animation
14. Closed-restaurant card dimming
15. **2.7** — restaurant card dish-photo carousel + story-style
    progress-bar fill (new custom view)
16. **2.4** — full animated collapsing header (biggest single polish
    item — genuinely last, per §3)

### Phase F — Payments (blocked on your UPI source)
17. Integrate real UPI gateway once source code arrives; wire into
    existing Checkout screen and `orders`/payment-status flow per
    `02_API_Contract.md`; COD stays as the fallback path throughout.

### Phase G — Restaurant App / Admin Panel scope (per §4 sequencing)
Only starts once Customer App (Phases A-F above) is in a state you're
happy with.
18. **2.1** — restaurant coupons (Restaurant App UI + backend)
19. **2.2** — restaurant banners, admin-approved (Restaurant App + Admin
    Panel)
20. (deferred from §3) persistent mini-cart bar — small enough to slot in
    here or pull forward into Phase E later if you want it sooner; parked
    here for now since it wasn't explicitly requested
21. Update `docs/Status.md`, re-zip, deliver

---

**This is the working plan now — say "start" and we begin at Phase A,
item 1 (**1.7**, the cart data-loss fix), and move through in this order.
If Phase B's build-check (item 4) or Phase A's crash repro (item 2)
aren't answerable yet when we get there, we'll skip ahead within the
phase rather than block the whole queue.**
