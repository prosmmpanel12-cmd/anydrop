# Handover — Bug §6.3 verify/fix + Out-of-stock (2026-08-14 session #2)

Continuation of the same day's earlier session
(`17_Handover_Bugs_6.1_6.2_2026-08-14.md`). That session's plan was:
build-verify 6.1/6.2, then start 6.3. This session did neither of those
literally — here's what actually happened and why.

## What this session found before writing any code

1. **6.1 and 6.2 — still not build-verified.** No Android SDK, no
   `gradlew` wrapper present in `customer/`, and no network access in
   this environment (egress disabled) — so a real Gradle build still
   isn't possible here, same limitation as the prior session. Did the
   next best thing: re-read both touched files end to end, checked
   brace/paren balance, cross-checked every `binding.<id>` reference
   against the layout XML it binds to, and confirmed `AddAddressBody`'s
   constructor call in `AddressBookActivity.setDefaultAddress()` matches
   the class's actual field list (name-for-name, no typos). All clean.
   **Still needs an actual compile** — first thing whenever this project
   next has Android Studio/CI available.

2. **6.3 turned out to already be fixed** — `price_cart()` in
   `backend/lib/orders.php` already checks `operational_status !== 'open'`
   and rejects with `restaurant_not_accepting_orders` (dated 2026-08-13,
   landed during the I4 followups session per
   `docs/16_Handover_I4_Followups_And_Order_Toggle.md`, point 3), and
   `CheckoutActivity.kt` already has a friendly error message wired for
   that exact code. `docs/bugs.md`'s §6.3 entry simply hadn't been
   updated when that fix landed elsewhere. Flagged this to the person
   instead of silently either re-doing it or silently skipping it.

3. Person asked to (a) verify the badge is consistent across **all**
   surfaces, not just the ones bugs.md's original write-up assumed, and
   (b) scope out real out-of-stock support, since bugs.md had explicitly
   flagged that gap as unresolved. Investigated before writing anything:

   - **Home cards** (`RestaurantAdapter.kt` + `restaurants/list.php`) —
     already correct.
   - **Search results** (`SearchResultsAdapter` reuses `RestaurantAdapter`
     + `search/search.php`) — already correct.
   - **Restaurant detail/menu page** (`RestaurantDetailActivity.kt` +
     `restaurants/menu.php`) — **zero badge logic, confirmed by grep
     returning nothing.** This is the actual answer to bugs.md's open
     clarifying question ("was there no badge at all on that particular
     screen") — yes, on the detail page specifically.
   - **Out-of-stock** — `menu_items.is_available` (TINYINT) already
     existed in the schema and was already enforced on the read path
     (`menu.php`, `search.php`, `category-items.php`, `popular-items.php`
     all filter `is_available = 1`) and the order path (`price_cart()`
     already rejects with reason `unavailable`). The gap was entirely
     "nothing shows this to anyone" — customers never see an unavailable
     item exists, and restaurants have no way to set the flag at all
     (no toggle anywhere in the restaurant app — currently DB-only).

Surfaced both findings to the person with the actual code evidence
before starting, per the usual approach here. Person chose: build the
detail-page badge fix, build full out-of-stock, but restaurant-app
toggle deferred — restaurant app itself isn't built out yet, so a
toggle screen doesn't make sense to add in isolation.

## What was built

### Detail-page badge (closes 6.3's real remaining gap)

**New file:** `backend/lib/restaurant_status.php` —
`compute_restaurant_status($restaurant, $currentTime = null, $currentDow = null)`,
returns `['is_open_now' => bool, 'is_paused' => bool]`. Consolidates
logic that was previously duplicated twice already (inline in
`list.php`, a separate local function in `search.php`) — added as a
third independent copy would have made exactly the "three places drift
apart" problem bugs.md's §6.1 spec warned about, so `list.php` and
`search.php` were both refactored to call this instead of keeping their
own copies. `search.php` had a second call site (the dish-match/"also
available at" item results, ~line 218) using the old local function —
caught and fixed too; grepped for any other stray references after,
none left.

**Files touched:**
- `backend/api/v1/restaurants/menu.php` — now calls
  `compute_restaurant_status()` and returns `is_open_now`/`is_paused` in
  the `restaurant` block.
- `backend/api/v1/restaurants/list.php`,
  `backend/api/v1/search/search.php` — refactored to call the shared
  function instead of their own inline/local versions. Behavior
  unchanged, just de-duplicated.
- `customer/app/src/main/java/com/anydrop/food/network/Models.kt` —
  `RestaurantDetail` gets `isOpenNow`/`isPaused` (both defaulted so an
  old cached response without these fields doesn't crash/misbehave —
  defaults lean "assume open" since that's the safer failure mode for a
  detail page the user already chose to visit).
- `customer/app/src/main/res/layout/activity_restaurant_detail.xml` —
  new `detailStatus` TextView, placed right after `detailCuisines`
  (mirrors the Home card's name → cuisines → status ordering).
- `customer/app/src/main/java/com/anydrop/food/ui/restaurant/RestaurantDetailActivity.kt`
  — `bindRestaurantDetail()` sets `detailStatus`'s text/color using the
  exact same three states, copy, and colors (`success_fg`/`paused_fg`/
  `error_fg`, `restaurant_temporarily_unavailable` string) as
  `RestaurantAdapter`'s Home card badge — deliberately not inventing new
  copy, per 6.1's "three places must agree" requirement. One deliberate
  difference from the Home card: the detail page does **not** dim the
  whole screen the way a closed Home card dims — the user already opened
  this restaurant on purpose, so the label alone is the warning; dimming
  the entire detail page (photos, menu, everything) seemed like it would
  read as broken rather than informative. Worth the person's opinion if
  this reads wrong in practice.

### Out-of-stock — customer side only

**Files touched:**
- `backend/api/v1/restaurants/menu.php` — item query dropped its
  `AND is_available = 1` filter (now `ORDER BY is_available DESC, name
  ASC` so out-of-stock items sort to the end of each category rather
  than being interleaved); each item's JSON now includes `is_available`.
- `customer/app/src/main/java/com/anydrop/food/network/Models.kt` —
  `MenuItem.isAvailable: Boolean = true` (defaulted true so a stale
  cached response missing the field doesn't wrongly greyscale
  everything).
- `customer/app/src/main/res/layout/item_menu_item.xml` — new
  `itemOutOfStock` pill, same style/position pattern as the existing
  `itemHighlyReordered` pill, error-colored instead of success-colored.
- `customer/app/src/main/res/values/strings.xml` — added `out_of_stock`.
- `customer/app/src/main/java/com/anydrop/food/ui/restaurant/MenuAdapter.kt`
  — `ItemVH.bind()`: shows the pill, dims the row (`alpha = 0.5f`) and
  image (`0.6f`) when `!item.isAvailable`, and — this is the part that
  matters more than the visual — **hides `btnAdd`/`qtyStepper` entirely
  and skips wiring their click listeners**, rather than just disabling
  them. A visibly-dimmed-but-still-clickable button reads as a bug, not
  as "unavailable." Card body tap (→ item detail sheet) is intentionally
  left wired even for out-of-stock items — the person can still see the
  dish's full description/photo, just can't add it; didn't block that
  without being asked to.

**Explicitly NOT done — restaurant-app toggle.** No PUT/PATCH endpoint
for `menu_items` was checked for or built on the backend, and no UI was
touched in `restaurant/app/`. Right now `is_available` can only be
flipped directly in the DB. Deferred at the person's explicit call —
restaurant app isn't built out yet as its own project. **When that work
starts**, first thing to check: whether a `menu_items` update endpoint
already exists anywhere in `backend/api/v1/restaurant/` (wasn't searched
this session — don't assume either way) before building one from
scratch, same "check what already exists before building" approach used
for 6.2's address PUT endpoint.

## docs/bugs.md updated

- §6.3 marked ✅ resolved, with a pointer to this doc and a note that the
  fix predates this session (landed 2026-08-13, I4 followups) — the
  entry just hadn't been updated. Original text kept below the update
  for history, not deleted.
- New §6.4 added for out-of-stock, marked 🟡 (customer done, restaurant
  side explicitly deferred, not just forgotten).

## Left for next session

1. **Still no build/compile verification for ANY of this session's work,
   or last session's 6.1/6.2** — same root cause (no SDK/network here).
   This is now three sessions' worth of Kotlin/layout changes sitting
   unverified: 6.1, 6.2, the detail badge, and out-of-stock. Strongly
   worth prioritizing an actual build the next time Android Studio or CI
   is available, before this backs up further. Smoke-test list to run
   once it compiles:
   - Detail page: open a restaurant with `operational_status = 'open'`
     within hours (green "Open"), one `busy`/`temp_closed` (amber
     "Temporarily unavailable"), one outside its hours or otherwise
     closed (red "Closed") — confirm the label matches what that same
     restaurant's Home card and a search result for it both show.
   - Menu list: an item with `is_available = 0` shows the grey pill, no
     ADD button/stepper, and tapping the card body still opens the item
     detail sheet; an available item is completely unaffected
     (no pill, full color, normal ADD flow).
   - Confirm `price_cart()`'s existing `unavailable` rejection still
     fires correctly if somehow an add is attempted anyway (e.g. a stale
     cart from before the item went out of stock) — this logic wasn't
     touched this session, but worth confirming in the same pass.
2. **Restaurant-app `is_available` toggle** — not started, see above.
   Needs the restaurant app's own menu-management screen to exist first
   (also not yet checked how far along that is).
3. **Phase J notifications and the earlier security fixes** — still not
   build-verified, carried over unchanged from `Status.md`'s own "Left
   for next session" list (this is now the second consecutive session
   this note has been carried forward — worth checking directly with the
   person whether this is still actually next in priority, or whether
   it's been superseded by everything since).
4. Everything else `Status.md` already listed as untouched (Phase H
   items 1-5, Phase K, bug 1.2, bug 2.3's "confirm PAT revoked" action
   item) is still untouched — unrelated to this session's scope.
