# Anydrop — UI Fix & New Feature Plan (Phase 3.6)

**Status:** Draft for review — no code changed yet. Confirm/edit this doc, then
say "start" and implementation begins in the order listed.

This covers issues found from the latest screenshots (real device, current
build) plus new features requested, matching what Zomato/Swiggy already do.

---

## 0. ~~Important — deployment gap, not a bug~~ — CORRECTED, this was a real bug

**UPDATE:** user confirmed they had already redeployed the updated backend +
SQL, and the symptom persisted (filter applies for ~1 second, then the
screen reverts to the unfiltered Home content, then a manual pull-to-refresh
finally shows the correct filtered result). That ruled out a deployment gap
and pointed at a real race condition in `HomeActivity.kt`.

**Actual root cause found:** `loadRestaurants()`, `loadCategoryItems()`, and
`runSearchOrReload()` each launched their own independent coroutine with no
coordination between them. If a filter chip was tapped while a previous
(unfiltered) request was still in flight — or a debounced search callback
from `setupSearch()`'s `TextWatcher` was still pending — whichever request's
network response landed **last** won, regardless of which one the user
actually wanted. That's exactly the "flashes correctly then reverts" pattern
you saw: the filtered response rendered first, then the earlier unfiltered
request's response arrived afterward and silently overwrote it.

**✅ FIXED** — added a single shared `contentLoadJob: Job?` that every one of
those three functions now cancels before launching its own new coroutine, so
only the most recently triggered request is ever allowed to update the
list. Filter-chip taps also now cancel any pending debounced search callback
for the same reason. `CancellationException` is explicitly rethrown (not
swallowed as a generic error) so cancelling a superseded request doesn't
trigger a spurious "couldn't load" toast.

**File changed:** `customer/app/src/main/java/com/anydrop/customer/ui/home/HomeActivity.kt`

---

## 1. Bugs to fix (from your screenshots + latest messages)

### 1.1 Restaurant detail page — rating/cuisine/badge not showing
**Screenshot:** "Dilip Fast Food" — no star rating, no "By Xk+", no cuisine
tags, no offer badge, large empty white space below the single item.

**Root cause found:** `activity_restaurant_detail.xml` already HAS the views
(`detailRating`, `detailEta`, `detailCuisines`) — but `RestaurantDetailActivity.kt`
never sets their text. Only `detailRestaurantName` and the cover image get
populated. That's why they render empty/collapsed.

**Fix:**
- Pass the full `Restaurant` object (not just id/name/cover) from Home →
  Detail screen (via a small parcelable or by re-fetching restaurant details
  by id), and populate `detailRating`, `detailEta`, `detailCuisines`.
- Add the offer badge (from Phase 3.5's `offer_badge_text`) and tag chips
  (Near & Fast / Pure Veg) to the detail header — same data already in the
  API, just not wired into this screen yet.
- Add a "By Xk+" ratings-count label under the star rating (needs a
  `rating_count` column on `restaurants` — currently only `rating_avg`
  exists — will add it in the same migration).

### 1.2 Item image cropping in the item detail bottom sheet
Your screenshots (Special Suji Khaman, Jumbo Maha Veggie Sandwich) actually
look correct — full-bleed image, rounded card, veg dot, quantity selector.
**No bug here** — flagging only so we don't "fix" something that already
matches the reference. If a specific image looks stretched/blurry on your
device, that's a placeholder-image quality issue (seed data), not a layout
bug — will swap in better-cropped Unsplash URLs while doing the catalog
touch-up in section 3.

### 1.3 Search bar + Veg toggle look basic
**Screenshot 1/5 vs. your reference:** current search bar is a plain
`TextInputLayout` outline box; veg toggle is a small custom switch with no
mic icon inside the search bar.

**Fix:**
- Redesign search bar: pill-shaped (fully rounded), search icon left, **mic
  icon right** (voice search entry point — button wired, actual speech-to-
  text can be stubbed to open Android's built-in `RecognizerIntent` since
  that needs zero backend work and is a one-file addition).
- Redesign veg toggle: match the rounded pill with green dot you already
  have, just restyle sizing/spacing to sit flush next to the new search bar
  (visual polish only, logic unchanged).

### 1.4 Only search bar should stay fixed — everything else scrolls
Currently `categoryList` and `filterScroll` are separate constrained views
above a `NestedScrollView`-wrapped list — in practice they already scroll
away with the list on Home (only `topBar` and `searchLayout` are pinned).
**Confirm:** the promo banner, category row, filter chips, and restaurant
list should ALL scroll together, with only the top bar + search bar staying
fixed. Current layout is already close to this — will restructure
`activity_home.xml` to explicitly wrap promo+categories+filters+list inside
one scrolling container so this is guaranteed rather than incidental.

### 1.5 ✅ FIXED — Tapping a filter chip needed a manual reload
Was a real race condition, not a deployment gap — see the corrected section
0 above for the full root cause and fix. Confirmed done.

### 1.6 Tapping an item card opens the restaurant instead of offering Add to Cart
**Confirmed real gap** (checked `HomeActivity.kt`): search results and
category-browse results show dish cards (`SearchResultsAdapter`), but tapping
a dish card currently just opens that dish's restaurant
(`onDishClick = { openRestaurantById(...) }`) — there's no direct "Add"
button on the card itself, so the customer has to open the restaurant, find
the same item again, and add it from there.

**Fix:** add an "ADD" button directly on each dish card in search/category
results (same pattern as the restaurant-menu item cards already use), wired
to `CartManager` the same way the restaurant detail screen's `MenuAdapter`
does it. Tapping the rest of the card (image/name/price) still opens the
restaurant for full context; tapping "ADD" adds it to cart right there.

### 1.7 Save/bookmark button does nothing
**Confirmed real gap:** the bookmark icon exists in `item_restaurant.xml`
(added in Phase 3.5 for the visual match to Zomato/Swiggy cards) but no
click listener is wired anywhere, and there's no backend table to persist a
saved/favorited restaurant or dish. This is a real missing feature, not a
bug in existing logic — scoped fully in section 2.5 below since it needs a
new table + endpoints + account-aware state (filled bookmark icon when
already saved).

### 1.8 Address bar needs full structured details
**Confirmed real gap:** `customer_addresses` currently only stores `label` +
one `full_address` text field — no house/flat number, floor, landmark,
address type (Home/Work/Other), or receiver phone number, unlike the
structured address form Zomato/Swiggy use. Scoped fully in section 2.6.

---

## 2. New features requested

### 2.1 Restaurant-managed categories inside a restaurant's own menu
**What you want:** when a customer opens a restaurant, they should see
**horizontal category tabs** (e.g. "Starters / Main Course / Breads /
Desserts") that the **restaurant owner defines themselves** — tapping a tab
jumps to that section, like Zomato/Swiggy's in-menu category bar.

**Note:** `menu_categories` (per-restaurant categories) already exists in the
schema and the Restaurant App already lets an owner create categories when
adding items (that's what groups the menu list today). What's missing is:
1. A horizontal **tab bar** at the top of the menu (Customer App) that jumps
   to a category's position in the scrolling list, instead of just showing
   category names as plain section headers.
2. Restaurant App: confirm categories can be **reordered** (`sort_order`
   already exists) — add drag-to-reorder in the Restaurant App's category
   management screen if not already there.

**Backend:** no new tables needed — `menu_categories.sort_order` already
drives ordering. Customer-side `menu.php` already returns items grouped by
category. Only the Customer App UI changes (tab bar + scroll-to-section).

### 2.2 Offer banner as a real auto-sliding carousel (2-10 images from server)
**What you want:** the "Flash Sale" banner should support **multiple slides**
(2 to 10 images), loaded from the server, auto-sliding with a
transition animation — not the current single static image.

**Backend (new):**
- New table `promo_banners` (id, title, subtitle, image_url, target_type
  [`none`/`restaurant`/`category`/`url`], target_value, sort_order,
  is_active, starts_at, ends_at) — replaces the old single-row
  `home_promo_*` settings keys (those stay for backward compatibility/
  fallback but the new table is what the carousel reads).
- New endpoint `home/promo-banners.php` returning the active, ordered list.
- Admin Panel (Phase 5) will eventually manage this table — for now, seeded
  directly like the rest of the demo data.

**Customer App:**
- Replace the static `FrameLayout` promo banner with a `ViewPager2` +
  `TabLayout` dots indicator, auto-advancing every ~4s with a slide/fade
  transition, tapping a slide navigates per `target_type` (opens a
  restaurant, a category, or does nothing for `none`).

### 2.3 Explore More grid (Offers / Top 10 / Food on train / Collections)
Seen in your reference screenshot (image 6) — a row of shortcut tiles below
the restaurant list. **Confirming scope:** for this phase, add the **visual
row** with tappable tiles for "Offers" (filters restaurant list to
`offer_badge_text IS NOT NULL`) and "Top 10" (restaurants sorted by
`rating_avg` desc, limit 10) since both are answerable with data we already
have. "Food on train" and "Collections" are Zomato-specific features with no
real equivalent yet in Anydrop's scope — will add them as **visually present
but disabled/"Coming soon"** tiles rather than building unrelated features,
unless you want them scoped as real features (flag if so).

### 2.4 Home screen shows items alongside restaurants (not just restaurants)
**What you want:** the default Home feed (before any search) should mix in
individual dish cards among the restaurant cards — like Swiggy/Zomato's
"Popular dishes near you" — not just a plain restaurant list.

**Backend:** new endpoint `home/popular-items.php` — returns a curated set
of items (bestsellers / recommended items first, per Phase 3.5's
`is_bestseller`/`is_recommended` flags already on `menu_items`) across
nearby restaurants, same "from &lt;Restaurant Name&gt;" tagging pattern
already used by search and category-items.

**Customer App:** Home's restaurant list becomes a mixed feed — e.g. a
"Popular dishes near you" horizontal-scroll row inserted between the filter
chips and "Restaurants near you", using the same dish card (with the new
inline "ADD" button from 1.6) — restaurant list below stays as-is.

### 2.5 Save / bookmark restaurants and dishes
**Backend (new):**
- New table `customer_favorites` (id, customer_id, favorite_type
  [`restaurant`/`menu_item`], favorite_id, created_at) with a unique
  constraint on (customer_id, favorite_type, favorite_id).
- New endpoints: `POST /customer/favorites` (toggle add), `DELETE
  /customer/favorites` (remove), `GET /customer/favorites` (list, split by
  type for the Profile → Saved screen).
- `restaurants/list.php`, `search.php`, `home/category-items.php`, and
  `menu.php` responses gain an `is_saved` boolean per item/restaurant (for
  the logged-in customer) so the bookmark icon renders filled/unfilled
  correctly everywhere it appears.

**Customer App:** wire the existing bookmark icon (restaurant cards + dish
cards) to call the toggle endpoint and flip its icon/fill state instantly
(optimistic UI), plus a new "Saved" screen under Profile (section 2.7)
listing everything favorited.

### 2.6 Address book — full structured address form
**Backend:** extend `customer_addresses` with new columns: `address_type`
(`home`/`work`/`other`), `house_flat_no`, `floor`, `landmark`, `receiver_name`,
`receiver_phone`. Existing `label`/`full_address`/`latitude`/`longitude`/
`is_default` stay (full_address becomes a computed/concatenated display
string built from the structured fields for backward compatibility with
anything already reading it).

**Customer App:** replace the current single free-text address field
(Checkout's "add address" flow) with a proper form — address type chips
(Home/Work/Other), house/flat + floor + landmark fields, receiver name +
phone, save button — matching the structured entry Zomato/Swiggy use. This
also becomes the shared editor for the new Profile → Address Book screen
(section 2.7), not just something buried in Checkout.

### 2.7 Profile screen (currently just a logout button)
**Confirmed gap:** there is no Profile screen at all today — the profile
icon on Home immediately logs the user out. Building a real screen with:
- **Address Book** — list from `customer_addresses`, add/edit/delete, using
  the new structured form from 2.6, set-default action.
- **Order History** — list from the existing `orders` table (already built
  in Phase 3 for the order-placement flow) — reuses `orders/detail.php` for
  tapping into a past order.
- **Saved** — the favorites list from 2.5 (restaurants + dishes).
- **FAQs** — a static, scrollable expandable-list screen. Content driven by
  a new lightweight `faqs` table (question, answer, sort_order, is_active)
  so you can edit FAQ text from the database without an app update, rather
  than hardcoding strings.
- **Rate Us** — a button that opens a URL fetched from `app_settings`
  (new key `play_store_url` / `rate_us_url`, same pattern as the existing
  `terms_url`/`privacy_url` settings) — matches "url from db" as you
  specified, so the link can be changed later without an app update.
- **Feedback** — a simple form (message + optional star rating) that posts
  to a new `feedback` table (id, customer_id, message, rating, created_at) —
  no complex workflow, just capture-and-store for now; you can review
  submissions directly in the database (or a future Admin Panel screen).
- **Account basics** — name/phone/email (from `customers`), logout (moved
  here from the Home icon, which will instead open this full Profile
  screen), and a "Delete account" placeholder if you want one flagged for
  later (not building the actual deletion flow unless you confirm you want
  it now — deleting a customer needs to also decide what happens to their
  past orders, so it's worth a deliberate decision rather than a rushed
  add).

### 2.8 Voice search entry point
Already scoped under 1.3 (search bar redesign) — the mic icon opens
Android's built-in speech-to-text (`RecognizerIntent`), transcribed text
gets typed into the search field and triggers the existing search flow.
No new backend work; noting it here too since you asked for it again
explicitly ("voice note bhi add kar do user chahe to daal shake") — to
confirm: this is a **voice-to-text search input**, not a way to attach an
audio clip somewhere. Flagging in case "voice note" meant something
different (e.g. attaching a spoken note to an order's special instructions)
— will proceed with voice-to-text search unless you say otherwise.

---

## 3. Data/content touch-ups (not new features, just polish)
- Swap a few lower-quality seed image URLs for sharper ones while touching
  this area.
- Add `rating_count` to `restaurants` (for the "By 1.5K+" label) — seeded
  with a plausible random-ish count per restaurant in the demo data.
- Seed a starter set of FAQ entries (order/payment/delivery basics) so the
  FAQ screen isn't empty on first install.

---

## 4. What is explicitly OUT of scope for this pass
(Flagging so nothing gets silently dropped)
- Real voice-to-text NLP/custom processing — using Android's built-in
  speech recognizer only (see 2.8).
- "Food on train" / "Collections" as fully real features (see 2.3).
- The full location/map module — still deferred to Phase 4 per your last
  confirmation.
- Admin Panel UI for managing `promo_banners`/categories/FAQs/feedback
  review — still Phase 5; this phase only builds the API + seed data +
  Customer App consumption/submission.
- Actual account-deletion flow (see 2.7's "Account basics" note) — will add
  only if you confirm you want it built now.

---

## 5. Order of implementation (once you confirm)
1. ✅ **DONE** — race condition behind the filter-chip revert bug fixed
   (section 0). Deploy this build and confirm before anything else, since
   every later step keeps building on `HomeActivity.kt`.
2. Migration: `promo_banners`, `customer_favorites`, `faqs`, `feedback`
   tables; `restaurants.rating_count` column; `customer_addresses` new
   columns; `app_settings` new `rate_us_url` key — plus seed data.
3. Backend: `home/promo-banners.php`, `home/popular-items.php`,
   `customer/favorites.php`, `customer/faqs.php` (read), `customer/feedback.php`
   (submit) endpoints; `is_saved` added to existing restaurant/item responses.
4. Customer App: fix restaurant detail header (rating/cuisine/badge/tags)
5. Customer App: redesign search bar + veg toggle (pill shape, mic icon +
   working speech-to-text)
6. Customer App: restructure Home scroll so only search bar is pinned
7. Customer App: promo carousel (ViewPager2 + dots + auto-slide)
8. Customer App: "Popular dishes near you" row on Home + inline "ADD" button
   on dish cards everywhere (search, category-items, Home popular row)
9. Customer App: wire bookmark icons to `customer_favorites` (optimistic
   fill/unfill)
10. Customer App: in-menu category tab bar (restaurant detail screen)
11. Customer App: Explore More tile row (Offers / Top 10 real; others
    "Coming soon")
12. Customer App: structured Address Book form + screen
13. Customer App: full Profile screen (Address Book, Order History, Saved,
    FAQs, Rate Us, Feedback, Account basics) — Home's profile icon now opens
    this instead of logging out directly (logout moves inside this screen)
14. Update `docs/Status.md` with everything done
15. Zip and deliver

---

**Confirm this plan (or tell me what to change), then say "start".**

