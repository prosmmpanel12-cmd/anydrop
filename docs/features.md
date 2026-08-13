# Anydrop Customer App — Zomato-style UI Upgrade Plan

Reference screenshots are in `/screenshots` (numbered, referenced by filename below).
Each feature below is scoped to be doable as its own Claude Code session — self-contained,
with the files it touches and a rough spec so a fresh session doesn't need re-explaining.

---

## 1. Rating sort toggle (Restaurant menu screen, top)

**Status: ✅ Done — built, Gradle-build-verified, confirmed working on-device (2026-08-10).**

**Screenshot:** `06_filters_and_sorting_bottomsheet.jpg`, `09_restaurant_header_filters_pureveg_offers.jpg`

**What:** A "Filters" pill sits in the horizontal chip row right under the restaurant header
(next to "Highly reordered" / "Spicy" chips). Tapping it opens a bottom sheet titled
**"Filters and Sorting"** with:
- **Sort by**: `Price - low to high` / `Price - high to low` (pill buttons, single-select)
- A one-line note if the restaurant is pure-veg
- **Top picks**: `Highly reordered` chip
- **Dietary preference**: `Spicy`, `Kid's choice` chips (multi-select)
- Sticky footer: `Clear All` (left) + `Apply (N)` button showing live result count

**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/restaurant/RestaurantDetailActivity.kt`
- `customer/app/src/main/java/com/anydrop/customer/ui/restaurant/MenuAdapter.kt`
- New: `ui/restaurant/MenuFiltersBottomSheet.kt` + matching layout XML
- `res/layout/activity_restaurant_detail.xml` (add chip row above menu list)

**Notes:** Filtering/sorting operates on the already-fetched `RestaurantDetail.menu` list client-side —
no new API needed. "Highly reordered" / "Spicy" / "Kid's choice" need a boolean/tag field on
`MenuItem` (check `Models.kt`; add fields + have backend populate if missing, or derive
"Highly reordered" from an existing order-count field if one already exists).

---

## 2. Bigger Share + Save buttons, badges on item detail sheet

**Screenshot:** `01_pizza_addon_bigger_share_save.jpg`, `07_item_addon_multiselect_checkbox_cooking_request.jpg`, `08_khandvi_qty_variant_radio.jpg`

**What:** In the item detail bottom sheet header (dish photo + name), the bookmark and
share icon buttons currently exist but are too small — make them larger, circular,
outlined touch targets like the screenshots (~44dp).

**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/itemdetail/ItemDetailBottomSheetFragment.kt`
- Its layout XML (find via `ItemDetailBottomSheetFragment` binding class name)

**Notes:** Purely a sizing/styling pass — logic for save/share already exists, just needs
bigger `MaterialButton`/`ImageButton` (background `bg_circle_outline` or similar), consistent
with `RestaurantDetailActivity`'s header buttons too (same icons appear there per screenshots).

---

## 3. "Highly reordered" / recommended badges on menu items

**Screenshot:** `03_menu_deal_badges_customisable.jpg`, `04_menu_filter_chips_highly_reordered_spicy_kids.jpg`, `05_menu_deal_list_more_items.jpg`

**What:** Below an item's price/discount line, a thin green progress-bar-style pill reading
"Highly reordered". Also a small chilli emoji + green-dot veg icon before item names when
applicable, and a "customisable" grey caption under items with add-ons.

**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/restaurant/MenuAdapter.kt`
- `res/layout/item_menu_dish.xml` (or equivalent item row layout)
- `Models.kt` — needs a `isHighlyReordered: Boolean` / `orderRank` type field from backend

**Backend:** `backend/api/v1/...menu.php` — add the field to the menu item query/response.

---

## 4. Discount badge pinned to bottom-right corner of card images

**Screenshot:** `11_home_bottomright_discount_corner_badge.jpg` (e.g. "Chowmein · ₹59 ~~₹99~~" as a
dark pill in the image's **top-left**, plus a bookmark icon top-right — re-check exact corner
against this screenshot when building), `10_home_rotating_text_near_fast.jpg`

**What:** When a restaurant/dish image is shown as a big banner card (home feed "Recommended for you"
style cards), overlay a small dark rounded pill in the bottom-right corner of the image showing
the discount, e.g. "20% OFF" or "Chowmein · ₹59 ₹99", using `FrameLayout` over the `ImageView`.

**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/home/PopularItemsAdapter.kt`
- Its item layout XML — add `FrameLayout` wrapping the image with an overlay `TextView`/pill view
- `RestaurantAdapter.kt` / `item_restaurant.xml` if the same badge is wanted on restaurant list cards too

**Notes:** Confirm actual corner (bottom-right vs top-left) against the referenced screenshot before
implementing — the sample shows the offer pill top-left over the dish photo, with a save icon top-right.
Clarify placement at the start of this session using the screenshot.

---

## 5. Animated rotating text on Home ("Near & Fast" ↔ "3.5 km" / "25–30 mins")

**Screenshot:** `10_home_rotating_text_near_fast.jpg` (see "Near & Fast" green text vs. "25–30 mins | 3 km"
grey text swapping between restaurant cards — i.e., a restaurant card's meta line alternates
between showing ETA/distance and a status label like "Near & Fast")

**What:** On restaurant list/grid cards on Home, the small meta line under the restaurant name
(distance/ETA) periodically cross-fades between 2 states: `"25–30 mins | 3 km"` and a highlighted
`"Near & Fast"` (green, bold) when the restaurant qualifies (e.g., ETA < 20 min).

**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/home/RestaurantAdapter.kt`
- `res/layout/item_restaurant.xml`
- New: small `ValueAnimator`/`ViewFlipper`-based helper, e.g. `ui/common/RotatingTextView.kt`

**Notes:** Use a `ViewFlipper` (built-in Android cross-fade, simplest option) with a 2.5–3s interval,
started in `onViewAttachedToWindow` / stopped in `onViewDetachedFromWindow` on the ViewHolder to avoid
animating off-screen rows (same care already taken for the photo carousel per existing code comments).

---

## 6. Full parity pass: distance/time/rating on Restaurant detail header

**Screenshot:** `09_restaurant_header_filters_pureveg_offers.jpg`

**What:** Restaurant detail header should show: Pure Veg tag, name, rating pill (★ 4.3, "By 9.5K+"),
address + distance ("1.5 km · Chopasni Housing Board"), ETA with lightning icon + "Schedule for later",
a "Frequently reordered" chip, and an offer strip ("60% OFF up to ₹120" with "3 offers" expandable).

**Files to touch:**
- `RestaurantDetailActivity.kt` + `activity_restaurant_detail.xml`
- Needs `ratingCount`, `offers: List<Offer>` fields wired from `RestaurantDetail` model/backend if not
  already present (check `Models.kt` — `offerBadgeText` already exists, so this may mostly be layout work)

---

## 7. Zomato-style location picker + map pin-drop address flow

**Screenshots:** `12_location_picker_saved_addresses.jpg` (main picker screen — opens when app
starts with location not yet resolved/enabled, or when user taps the location bar),
`13_add_address_map_pin_drop.jpg` (map pin-drop screen — opens from "Add Address")

**What — two connected screens:**

**A) Location picker screen (screenshot 12)** — shown on app open if location isn't already
resolved (permission not granted / no active address yet — ties into part 10's
`ActiveAddressManager`), and also whenever the location bar is tapped:
- Search bar: "Search for area, street name..." (can be stubbed/non-functional first pass —
  full search is separate scope, don't block on it)
- **"Use current location"** row — green target icon, subtitle shows the resolved
  reverse-geocoded short address once fetched (e.g. "SH 61, Bhikamkor")
- **"Add Address"** row — green plus icon, opens screen B
- **"SAVED ADDRESSES"** section — list of the account's saved addresses (from
  `getAddresses()`), each showing label ("Home"/"Work"/"Other"), distance from current
  location (e.g. "3.6 km", "0 m"), full address, phone number, and `...` / share / camera
  icon row. Tapping a saved address card = make it active (this is the missing piece flagged
  in part 10's Status.md — "no way to switch active address" — this screen is where that tap
  target belongs) and close the picker back to Home.
- "NEARBY LOCATIONS" section below saved addresses — Google Places nearby suggestions,
  lower priority, can follow existing `places_search`-style pattern already used elsewhere in
  the codebase if one exists, otherwise stub/defer.

**B) Add Address / map pin-drop screen (screenshot 13)**:
- Full-screen Google Map, a fixed center pin (map pans, pin stays centered — standard
  "drag map to position pin" pattern, same as most delivery apps)
- "Use current location" pill floating over the map
- Bottom sheet: reverse-geocoded address name + "X km away from your current location"
  warning when the pin is far from GPS location, an **"Address details*"** required text
  field (this maps directly to `AddAddressBody.houseFlatNo`/`floor` — keep it to just
  house/floor number here, e.g. "E.g. Floor, House no." like the screenshot, NOT the full
  street address)
- "Receiver details for this address" section (maps to `receiverName`/`receiverPhone`)
  — expand at build time to match `AddAddressBody` fields
  - **"Save address" button** — calls `addAddress()` with: `fullAddress` = reverse-geocoded
    string from the pin's lat/lng (Google Geocoding API result, NOT hand-typed by the user),
    `houseFlatNo`/`floor` = the "Address details" field, `latitude`/`longitude` = the pin's
    final position.

**Key product decision (explicitly requested):** full street/area/pincode text entry is
**deferred to checkout time**, not collected here. This screen only needs pin position
(lat/lng) + reverse-geocoded label + house/floor number — that's already exactly what
`Address`/`AddAddressBody` in `Models.kt` support (`fullAddress` is one string set from
geocoding, `houseFlatNo`/`floor` are the only user-typed fields). **No backend/model changes
needed for this feature** — this is a UI-flow-only session (two new screens + wiring into
the existing address endpoints and `ActiveAddressManager` from part 10).

**Files to touch:**
- New: `ui/address/LocationPickerActivity.kt` + layout (screenshot 12 screen)
- New: `ui/address/MapPinDropActivity.kt` (or repurpose `AddressEditorBottomSheet.kt` if its
  existing "Use current location" / geocoding logic can be lifted into a full-screen map
  version) + layout (screenshot 13 screen)
- `HomeActivity.kt` — location bar tap should open `LocationPickerActivity` instead of
  `AddressEditorBottomSheet` directly (picker becomes the entry point; "Add Address" inside
  the picker is what opens the map screen)
- `AddressBookActivity.kt` / `AddressAdapter.kt` — reuse the same "tap card to activate"
  behavior here too if not already covered by the picker screen
- Needs Google Maps SDK + Geocoding API (check `build.gradle` / `AndroidManifest.xml` for an
  existing Maps API key — likely already present if `AddressEditorBottomSheet`'s current
  "Use current location" does reverse geocoding; confirm before adding a new dependency)

**Notes for next session:** Start by re-reading `ActiveAddressManager.kt` and
`AddressEditorBottomSheet.kt` (part 10) — a lot of the "use current location" / reverse
geocoding logic may already exist there and can be moved/reused rather than rewritten.

---

## Suggested session order
1. Feature 3 (badges) + Feature 4 (corner discount pill) — same adapters, do together
2. Feature 1 (filters sheet) — depends on badge/tag fields from step 1
3. Feature 6 (restaurant header parity) — layout-only, no new logic
4. Feature 2 (bigger share/save buttons) — quick styling session
5. Feature 5 (rotating text animation) — independent, do anytime
6. Feature 7 (location picker + map pin-drop) — independent, builds directly on part 10's
   `ActiveAddressManager`; do this once part 10 is confirmed working on-device

Each session should start by re-reading the relevant screenshot(s) listed above and the exact
files listed, since layouts/adapters may have shifted between sessions.

---

## Phase H — Bug fixes + new requests (2026-08-10, full code review pass)

App owner reported 6 items after a full source read (not just docs). Each was traced to
its actual root cause in code before starting any fix. **Do one at a time, confirm working,
then move to the next — same discipline as every other phase in this doc.**

### H1. Cart badge / cart button doesn't update live after removing an item
**Status: ✅ DONE (2026-08-10)**
**Root cause found:** `CartBottomSheetFragment` has no way to tell its host Activity
that a line item changed inside it. `RestaurantCartAdapter`'s `onChanged` callback only
calls the sheet's own `refresh()` (so the sheet's own list looks right) — it never reaches
`HomeActivity.updateCartBadge()` or `RestaurantDetailActivity.updateCartButton()`. Those two
only get called from `onResume()`/initial load, and a `BottomSheetDialogFragment` does **not**
pause/resume its host Activity, so the badge/button silently goes stale until the user
actually leaves and re-enters the screen (matches the report exactly).
**Fix:** add `var onCartChanged: (() -> Unit)? = null` to `CartBottomSheetFragment`, invoke it
from `refresh()`, and wire it at both call sites (`HomeActivity`'s `btnCart`,
`RestaurantDetailActivity`'s `btnViewCart`) to call `updateCartBadge()` /
`updateCartButton()` respectively.

### H2. Saving a restaurant from a detail screen reached via Cart doesn't reflect on Home/Search until refresh
**Status: ✅ DONE (2026-08-10)**
**Root cause found:** `FavoritesManager.toggle()` is purely local/optimistic per-screen —
`onResult` only updates the one adapter item on the screen that made the tap. There's no
shared/observable favorites cache (unlike `ActiveAddressManager`/`VegModeManager`'s
SharedPreferences-backed pattern). So a restaurant bookmarked on `RestaurantDetailActivity`
(opened from a cart card) doesn't show as saved on Home/Search's already-loaded list items —
they were bound before the toggle happened and nothing tells them to re-bind.
**Fix shipped:** `FavoritesManager` now keeps a session-lifetime shared
`restaurantOverrides`/`menuItemOverrides` cache (pure in-memory, no extra network calls) and
exposes `isSaved(type, id, serverValue)`. `RestaurantAdapter` (Home) and `SearchResultsAdapter`
(restaurant rows) both read through this instead of a private per-adapter map, and each gained
a cheap `refreshSavedStates()` — `RestaurantAdapter`'s uses a partial-bind payload so it only
touches the bookmark icon and doesn't restart any card's photo carousel. `HomeActivity.onResume()`
now calls both `refreshSavedStates()` methods (local-only, no API hit) so returning from any
other screen — cart, restaurant detail, search — always shows current bookmark state.
**Scope note:** this pass covers restaurant bookmarks specifically (what was reported). Dish
bookmarks (`MenuAdapter`/`PopularItemsAdapter`) still use their own local override maps —
same shared-cache pattern can be applied to them too if the same staleness shows up for dishes.

### H3. Filter row scroll-collapse animation — remove it, keep it working
**Status: ✅ DONE (2026-08-10)**
**Root cause found:** `HomeActivity`'s `animateFilters()` + the
`filtersAnimator`/`filterScrollLastY`/`filterDownAccumPx`/`filterUpAccumPx`/
`filtersLastCollapseAtMs`/`filterExpandGraceMs` state machine (a scroll-driven
`ValueAnimator` height collapse/expand on the filter chip row) has accumulated several
stacked bug-fix patches across past sessions (see the kdoc comments in that block) —
each one fixing a glitch the previous fix introduced. App owner's call: rather than patch
it again, remove the animated collapse/expand behavior entirely (filter row just stays
visible, or toggles with `View.GONE`/`VISIBLE` with no animation) — simpler, fewer moving
parts, less risk, matches the "keep backend load + performance good" instruction at the
end of this phase.
**Fix plan:** delete the `ValueAnimator`-based `animateFilters()` and its whole scroll-
accumulator state block; keep the filter row always visible (or a plain visibility toggle
if hiding on scroll is still wanted, no cross-fade/height-animate).

**Fix shipped:** Removed `animateFilters()`, `filtersAnimator`, `isFiltersAnimating`,
`filtersCollapsed`, `filterScrollLastY`, `filterDownAccumPx`, `filterUpAccumPx`,
`filtersLastCollapseAtMs`, `filterExpandGraceMs`, and `filterExpandTriggerPx` entirely from
`HomeActivity.kt`, along with the whole filter-collapse branch inside the
`homeNestedScroll` scroll listener and the `filtersAnimator?.cancel()` call in `onDestroy()`.
`collapsibleFilters` now just stays permanently visible (no height animation, no
collapse/expand state machine) — same as `categoryList` above it. `collapseTriggerPx`,
`expandTriggerPx`, and `nearTopThresholdPx` were kept since `btnBackToTop`'s own
distance-from-top logic still uses them and is untouched by this fix.
`collapsibleFiltersExpandedHeight` was also kept — it still sizes `filterOverlaySpacer` so
the overlaid filter row never covers the promo banner beneath it.

### H4. 50% coupon on a ₹30 item shows the whole bill as ₹0 and coupon looks "not applied"
**Status: ✅ DONE (2026-08-10)**
**Root cause found — real backend bug, not a data/coupon-config issue.** In
`backend/lib/orders.php`'s `price_cart()`, the `min_order_amount` check
(`if ($itemTotal < $restaurant['min_order_amount']) { ...; return $result; }`) runs
**after** `$result['line_items']` is already populated but returns immediately with every
totals field still at its initial `0.0` (item_total, discount_amount, delivery_charge,
grand_total, etc. never get computed). `cart/validate.php`'s guard
(`if ($priced['error'] && empty($priced['line_items'])) respond_error(...)`) only treats
an error as fatal when `line_items` is empty — here it isn't, so it falls through to
`respond_ok()` and ships the client an HTTP 200 with `warning = 'below_min_order_amount'`
and every price field zero. On the Kotlin side, `CheckoutActivity.applyCoupon()` only
special-cases `warning` values in `COUPON_ERROR_CODES` (`invalid_coupon`,
`coupon_min_order_not_met`, `coupon_usage_limit_reached`) — `below_min_order_amount` isn't
one of them, so it falls into the "success" branch, sets the coupon as applied, and renders
the all-zero bill as-is. Exactly reproduces the report: a ₹30 item sits below whatever
`restaurants.min_order_amount` is configured, and price_cart() bails before ever applying
the (otherwise perfectly correct) 50%-off math.
**Fix plan (backend):** apply the exact same "stash the error, fall through, keep
computing" pattern the surrounding code comment already documents for coupon errors — move
the `min_order_amount` check to set `$result['error']` and `break`/continue into the normal
delivery/platform/tax/grand_total computation instead of an early `return`, so a below-
minimum cart still gets real (uncoupon'd or coupon'd) numbers back, with the app free to
decide whether to block "Place Order" on `warning === 'below_min_order_amount'` separately
from how it renders the bill preview.
**Fix plan (Android, defense-in-depth):** `applyCoupon()`/`loadBill()` should not render a
bill whose `grand_total == 0` as if it were valid regardless of which `warning` is set —
add a check so any non-null `warning` outside the known coupon codes still surfaces as an
error state rather than silently rendering.

**Fix shipped:**
- **Backend (`backend/lib/orders.php`, `price_cart()`):** the `min_order_amount` check no
  longer `return`s immediately — it stashes `$result['error'] = 'below_min_order_amount'`
  and falls through to the normal coupon/delivery/platform/tax/grand_total computation,
  same pattern the coupon-error block just below it already used. `cart/validate.php` was
  already written to treat this as a non-fatal `warning` (only blocks with 422 when
  `line_items` is empty) — it now gets real numbers alongside that warning instead of an
  all-zero bill. `orders/create.php` was already unconditionally blocking on any
  `$priced['error']`, so order creation itself needed no change.
- **Android (`CheckoutActivity.kt`, defense-in-depth):** added `WARNING_BELOW_MIN_ORDER`
  constant; `renderBill()` (called from both `loadBill()` and `applyCoupon()`) now shows a
  new `belowMinOrderText` view and disables `btnPlaceOrder` whenever
  `warning == "below_min_order_amount"`, or as a fallback, whenever `grand_total == 0.0`
  alongside any warning outside `COUPON_ERROR_CODES` — so a ₹0 bill can never be silently
  treated as placeable regardless of which warning (if any) comes back. `placeOrder()`
  also gained a direct `!binding.btnPlaceOrder.isEnabled` guard as a second layer.
  New string `below_min_order_amount` in `strings.xml`; new `belowMinOrderText` `TextView`
  in `activity_checkout.xml` (below the grand total row, `gone` by default).

**Follow-up bug found + fixed the same session:** while testing H4, "Place Order" on a
below-minimum cart showed a generic **"Couldn't place order"** toast instead of the specific
reason — traced to a real, separate Android bug: `Response.body()` is only populated by
Retrofit on a 2xx HTTP status; `orders/create.php`'s 422 response was landing in
`response.errorBody()` instead, so `response.body()?.error` was always `null` and every
failure silently fell through to the generic fallback string, regardless of which backend
error code actually came back (`below_min_order_amount`, `invalid_coupon`,
`restaurant_unavailable`, etc. — all of them). Same pattern found and fixed at 2 more call
sites with identical code (`response.body()?.error ?: "<fallback>"`):
`OrderStatusActivity.kt` (cancel order) and `LoginActivity.kt` (send OTP).
**Fix shipped:** new `network/ApiErrorParser.kt` — reads and parses `response.errorBody()`
(the actual location of a non-2xx body) into the same `{error, data}` shape as a successful
`ApiResponse`. `CheckoutActivity.placeOrder()`'s failure branch now uses this to show a
friendly, code-specific message — `below_min_order_amount` becomes **"Add ₹X more to your
cart to order"** (X computed from `min_order_amount`/`item_total` now attached to
`orders/create.php`'s 422 error payload), known coupon codes map to their existing friendly
strings, anything else falls back to showing the raw code. `OrderStatusActivity.kt` and
`LoginActivity.kt` got the same one-line swap to `ApiErrorParser` for their own error
messages. `price_cart()` now also always returns `min_order_amount` in its result (used by
both the create-order error payload and `cart/validate.php`'s response, the latter powering
Checkout's live below-minimum banner text with the same "Add ₹X more" wording as the user
edits their cart, not just once they tap Place Order).

### H5. Dedicated Coupons/Offers page on Checkout ("View all offers & coupons")
**Status: ✅ Done (confirmed working on-device, 2026-08-10).**
**What:** a tappable "View all offers" row on Checkout opens a full list of every coupon
usable for that order — both restaurant-specific (`coupons.restaurant_id = X`) and
platform-wide (`coupons.restaurant_id IS NULL`) — each showing code, discount, min-order
line, tap-to-apply.
**Backend:** `GET /coupons/list.php?restaurant_id=&item_total=` — active, in-date,
`restaurant_id IS NULL OR = :rid`, same eligibility columns already used in
`price_cart()`'s query so a coupon's "usable" badge in the list agrees with what actually
applies at checkout.
**Android:** `ui/checkout/CouponsListBottomSheetFragment.kt` + `CouponsAdapter.kt`; opened
from `rowViewAllOffers` on `CheckoutActivity`; tapping a coupon in the list fills
`inputCouponCode` and calls the existing `applyCoupon()` — no new apply logic needed, just
a browse/pick UI in front of it.

### H6. Add Address — full live-location + map pin-drop + photo upload flow
**Status: 🟡 In progress (2026-08-10). Part 1 (Location Picker screen,
screenshot 12) built — untested, no Gradle build run yet. Part 2 (map
pin-drop screen + photo upload, screenshot 13) not started. See
`docs/12_Handover_H6_Map_PinDrop_Photo.md`.**
**Root cause / current state:** today's `AddressEditorBottomSheet` + `fragment_address_editor.xml`
is a **plain form only** — house/floor, area (free text), landmark, receiver name/phone,
type chips, a "Use current location" button that silently fills the area field via
Geocoder. There is **no map view, no draggable pin, and no image upload** anywhere in the
customer app (`grep` for "shimmer", MapView, and image-picker code all come back empty for
this screen). The 2 screenshots the app owner sent are the same Zomato-style reference
already cited in §7 above — confirms §7 was never built, not a regression.
**Additional fields requested now vs. §7's original spec:** §7 already covers "Address
details" (house/floor) + receiver details + map pin + reverse geocode. **Landmark** already
exists as a field in the current form (`inputLandmark`/`AddAddressBody.landmark`) — just
needs to carry over into the new map-based screen. **Door/building photo upload** is new —
not in `Address`/`AddAddressBody` today; needs a new nullable column
(e.g. `addresses.photo_url`) + multipart upload handling, or store as a base64/URL string if
an existing image-upload endpoint pattern exists elsewhere in the backend (check before
adding a new one).
**Fix plan:** build §7 as originally scoped (`LocationPickerActivity` +
`MapPinDropActivity`/repurposed `AddressEditorBottomSheet`, Google Maps SDK + Geocoding,
wired into `ActiveAddressManager` from part 10) — plus the photo-upload addition folded in
as one extra optional field on the same map pin-drop screen, since it's the same screen in
the reference design.

**Order to work through H1–H6:** H1 → H2 → H3 → H4 → H5 → H6 (bugs before net-new features,
small/isolated before big; H5 and H6 are the two biggest and go last). Confirm each one
working before starting the next — do not batch multiple together.

---

## Phase I — New feature requests (queued, not yet scoped in code)

### I1. Reorder button on Order History
**Status: ✅ Done — built, Gradle-build-verified via GitHub Actions (Build Customer APK #8, 2026-08-12).**
**What:** On each past order card in `OrderHistoryActivity`, a "Reorder" button that refills
the cart with the same items in one tap and takes the user straight to the cart/checkout,
instead of re-browsing the restaurant menu.
**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/profile/OrderHistoryActivity.kt`
- `customer/app/src/main/java/com/anydrop/customer/ui/profile/OrderHistoryAdapter.kt`
- `customer/app/src/main/java/com/anydrop/customer/data/CartManager.kt` / `CartSyncManager.kt`
**Notes:** Needs to handle items that are now unavailable/86'd or price-changed since the
original order — decide whether to silently skip them or show a "N items no longer
available" note before adding the rest to cart.

### I2. Order tracking status timeline (visual stepper)
**Status: ✅ Done — built, Gradle-build-verified, confirmed working on-device (2026-08-12).**
**What:** On `OrderStatusActivity`, replace/augment the current status display with a visual
stepper: **Placed → Accepted → Preparing → Out for delivery → Delivered**, highlighting the
current step and checking off completed ones.
**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/orderstatus/OrderStatusActivity.kt`
- Its layout XML — new stepper view (custom view or a row of icons/connectors)
**Notes:** Confirm the exact status string values the backend sends (`orders.status` or
equivalent) so each step maps to the right backend value, including any edge states
(cancelled/rejected) that fall outside the 5-step happy path.

### I3. Search filters — cuisine, price range, rating, nearby
**Status: ✅ Done — built, Gradle-build-verified, confirmed working on-device (2026-08-12).**
**What:** Add filter options to Search (cuisine type, price range, rating, **nearby** —
restaurants within a chosen distance of the user's current location) alongside the
existing sort. Likely a filter bottom sheet similar in spirit to Feature 1's menu filters
sheet, but for restaurant search results.
**Files to touch:**
- `customer/app/src/main/java/com/anydrop/food/ui/search/SearchResultsAdapter.kt`
- Search activity/fragment (find the screen hosting `SearchResultsAdapter`)
- New: a `SearchFiltersBottomSheet.kt` + layout
- Backend search endpoint — check if cuisine/price/rating/distance are already filterable
  server-side or only client-side on already-fetched results; add query params if needed
**Notes:** Reuse Feature 1's filter-sheet pattern (chips, sticky Apply/Clear footer) for
visual consistency instead of designing a new pattern from scratch. "Nearby" needs the
user's current lat/lng (check how distance is already computed elsewhere, e.g. restaurant
detail's "1.5 km" line, and reuse that same source/permission flow rather than a new one).

### I4. Scheduled orders ("Schedule for later")
**Status: Not started — currently a "Coming soon" toast**
**What:** Let users pick a future time slot (same day only, per this request) to schedule an
order instead of ordering "Now". Replaces the current stub toast on the "Schedule for later"
row noted in Feature 6.
**Files to touch:**
- `RestaurantDetailActivity.kt` — "Schedule for later" tap handler (currently the toast)
- New: a time-slot picker (bottom sheet or dialog)
- `CheckoutActivity.kt` — needs to carry/display the chosen slot and send it with the order
- Backend — `orders` table/order-create endpoint needs a `scheduled_for` (nullable datetime)
  field if one doesn't already exist; restaurant/rider-side flows need to know an order is
  scheduled vs. immediate
**Notes:** Same-day only for this pass — keep the picker's available slots within the
restaurant's remaining open hours for today (check `restaurants` opening/closing time fields).

### I5. In-app support chat (AI-powered) — build LAST
**Status: Not started — deferred to end, currently just a static FAQ page**
**What:** Replace/augment `FaqsActivity` with an in-app chat that answers user questions
about the app using an LLM API (Gemini/Claude/GPT), configured from the admin panel.
**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/ui/profile/FaqsActivity.kt` /
  `FaqAdapter.kt` — either extend or add a new chat entry point alongside FAQ
- New: `ui/support/SupportChatActivity.kt` + layout + adapter for chat bubbles
- Backend — new endpoint (e.g. `support/chat.php`) that proxies to the chosen LLM provider
  using a key stored in the admin panel; admin panel needs a settings screen for
  provider/API key selection
- Backend admin panel — new "Support AI" settings section
**Notes:** Explicitly last on the queue per app owner. Needs a decision on which provider(s)
to support and how the system prompt / knowledge about the app (FAQs, order policies, etc.)
is fed to the model before implementation starts.

### I6. Real push notifications on order status change
**Status: Not started — currently only an in-app banner**
**What:** Send an actual push notification (not just `InAppNotifier`'s in-app banner) when an
order's status changes (Accepted, Preparing, Out for delivery, Delivered, etc.).
**Files to touch:**
- `customer/app/src/main/java/com/anydrop/customer/notifications/NotificationHelper.kt`
- `customer/app/src/main/java/com/anydrop/customer/ui/common/InAppNotifier.kt` (keep in-app
  banner for foreground, add push for background/killed state)
- Needs FCM (or equivalent) wired in if not already present — check `build.gradle` /
  `AndroidManifest.xml` for an existing Firebase/push setup before adding a new dependency
- Backend — order-status-update code path needs to trigger a push send (server-side call to
  FCM) whenever `orders.status` changes, plus device token storage/registration if not
  already present
**Notes:** `MealReminderScheduler`/`MealReminderWorker` show local scheduled notifications
already exist in the app — this is different: server-triggered pushes tied to real-time
backend events, not a local timer.

### I7. Dark mode
**Status: Not started**
**What:** Full dark theme across the customer app (colors, keep layouts/logic unchanged).
**Files to touch:**
- `res/values/colors.xml`, `res/values/themes.xml` — add `res/values-night/colors.xml` +
  `res/values-night/themes.xml` with dark equivalents
- Audit screens for any hardcoded (non-theme-attribute) colors in layout XML/Kotlin that
  would break in dark mode
- Settings/Profile screen — add a Light/Dark/System toggle if manual override is wanted
  (vs. just following system theme via `AppCompatDelegate.setDefaultNightMode`)
**Notes:** Straightforward Android-standard approach (`values-night` resource qualifier) —
main effort is auditing every screen for hardcoded colors rather than the theme setup itself.

**Suggested order for Phase I:** I1 → I2 → I3 → I4 → I6 → I7 → I5 (I5 explicitly last, per
app owner's note; the rest are small/isolated and can be reordered based on priority).
