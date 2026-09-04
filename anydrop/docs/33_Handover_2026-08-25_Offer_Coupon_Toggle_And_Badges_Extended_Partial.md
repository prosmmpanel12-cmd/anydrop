# Handover — continue from here (2026-08-25, session 9)

Continues docs/32 (Customer App offer badges on the restaurant menu
screen). App owner's ask this session, in their own words:

1. Tags everywhere (specifically: offer badges on Home popular-items
   and Search too, per docs/32's own "not done" #2).
2. A first category chip with an offer logo — tapping it opens an
   "Offers" screen showing every item + restaurant currently running
   an offer.
3. Same, wired into search.
4. Cart list shows the item's dish photo.
5. Cart/checkout shows which offer applied — e.g. a B1G1 shown as an
   extra item at ₹0, not just a rupee discount number.
6. Offers Engine: a per-offer toggle — "allow this offer to also
   combine with a coupon, or not."

**This session got through items 1 (partial), 4, and 6 (backend +
data layer). Items 2/3/5 are NOT built — see "Not done" below.** The
owner asked to package up what exists so far rather than continue in
one long response, same "stop and hand over cleanly" pattern several
prior sessions (docs/30 explicitly) already used.

Same standing sandbox limitation as every prior session: no PHP CLI /
MySQL / Android SDK here. Every PHP file below was manually brace/
paren/bracket-count balanced (values noted per-file below) and re-read
carefully, not run. No Kotlin file was compiler-checked.

---

## ✅ Done this session

### 6. Migration 48 + offer↔coupon stacking toggle (backend, full stack)

- `sql/48_migration_offer_coupon_stacking_toggle.sql` — new
  `promo_offers.allow_coupon_stacking TINYINT(1) NOT NULL DEFAULT 1`.
  Idempotent (CONTINUE-HANDLER-for-1060 pattern, same as every
  migration since 25/46/47). **Not run against the live DB.**
- `lib/offers.php`:
  - `format_offer()` — new `allow_coupon_stacking` bool field
    (`?? 1` fallback so a DB that hasn't run migration 48 yet doesn't
    fatal — same defensive pattern this file already uses elsewhere).
  - `compute_offer_discount()` — every branch now also returns
    `free_units` (int) and `item_label` (string|null). Only
    `buy_x_get_y` populates them (free units = `sets * get_qty`,
    label = the first scoped line's `item_name_snapshot` — see the
    function's own updated kdoc for why "first matched line" is an
    arbitrary-but-stable choice for a category/restaurant-scoped B1G1
    across mixed items). Every other type returns `0`/`null` — they
    discount money off an existing line, there's no distinct "free
    item" to label.
  - `select_best_auto_offer()` — propagates `free_units`/`item_label`
    through into its own returned array (previously only
    `offer`/`discount`).
- `lib/orders.php` `price_cart()`:
  - Captures `offer_type`, `offer_free_units`, `offer_free_item_label`
    off the winning offer.
  - **New stacking gate**, placed right after the winning offer is
    known (not earlier, since which offer wins can itself depend on
    cart contents): if a coupon was already computed/valid AND the
    winning offer has `allow_coupon_stacking = 0`, the coupon discount
    is dropped (`$discount = 0.0`, `$couponId = null`) and
    `$couponDisabledByOffer = true` is set. Already-set coupon errors
    (`invalid_coupon` etc.) are left alone — this is a stacking
    rejection, not a code-validity rejection, same "not an error, just
    a non-match" convention `$offerDiscount` itself already uses.
  - `$result` gains `offer_type`, `offer_free_units`,
    `offer_free_item_label`, `coupon_disabled_by_offer`.
- `api/v1/cart/validate.php` — response allowlist extended with the
  five new fields above (this endpoint hand-picks fields from
  `price_cart()`'s return array rather than passing it through
  wholesale, so each new field needs adding here explicitly — easy to
  forget, confirmed by re-reading the existing allowlist shape first).
- `api/v1/restaurant/offers-create.php` — accepts optional
  `allow_coupon_stacking` in the request body (default `1`/true when
  omitted, matching the column default), inserts it.
- `api/v1/restaurant/offers-update.php` — accepts it as a partial-
  update field too (editable post-creation, unlike the mechanic
  fields — it's a restriction toggle, doesn't retroactively affect any
  `offer_usages` history). Kdoc's field list updated.

**Restaurant App UI**: NOT wired — `OfferManagerActivity`/
`dialog_add_offer.xml` don't exist yet at all (docs/30 — the create-
offer screen itself is still just "plan, not code"). The toggle has no
UI to sit in until that screen gets built. When it does, add it as a
`SwitchMaterial` in `dialog_add_offer.xml`'s shared (not per-type)
field section, wired into `OfferCreateBody.allowCouponStacking` /
`OfferUpdateBody.allowCouponStacking` in
`restaurant/.../network/Models.kt` — **those two Kotlin fields also
don't exist yet**, not added this session (no consumer for them
without the dialog, would just be dead fields).

### 1 (partial). Offer badges on Home popular-items + Search

- `api/v1/home/popular-items.php` — `require_once lib/offers.php`
  added; `offer_tag` (string|null) added to every item in the
  response, via the same `get_browsable_offers_for_restaurant()` +
  `pick_item_badge_offer()` + `offer_badge_label()` trio
  `restaurants/menu.php` already uses (docs/32), cached once per
  distinct restaurant in the row rather than once per item.
  **Simplification, flagged**: category-scoped offer matching is
  skipped here (`categoryId` passed as `null`) — this row spans many
  restaurants and resolving each item's `food_category_id` would need
  an extra bulk query this endpoint doesn't already run. Item-scoped
  and restaurant-wide offers (the common cases) still badge correctly.
- `api/v1/search/search.php` — same pattern, same simplification,
  applied to the `items` block only (dish search results). The
  `restaurants` block's existing `offer_badge_text` field is the
  **old**, unrelated `restaurant_offers`-table feature (docs/32's own
  warning #3 — not touched, not the same thing).
- `customer/.../network/Models.kt` — `SearchItem.offerTag` and
  `PopularItem.offerTag` added (both defaulted null, mirroring
  `MenuItem.offerTag`'s existing convention). Both `.toMenuItem()`
  extension functions updated to carry `offerTag` through, so an
  ADD-to-cart from a Home/Search card doesn't silently lose the badge
  if any downstream code reads `MenuItem.offerTag` later.

**NOT done**: actually rendering `offerTag` as a pill on
`PopularItemsAdapter`/`SearchResultsAdapter`'s item layouts. The data
is now in the model; no XML/adapter changes were made to display it —
`item_menu_item.xml`'s `itemOfferTag` pill (docs/32) is the pattern to
copy onto whichever layouts those two adapters use, not yet done.

### 4. Cart shows the item's dish photo

- `res/layout/item_cart_line.xml` — new `cartLineImage` `ImageView`
  (48dp, `bg_card_rounded` background — same rounded-corner drawable
  `item_menu_item.xml`'s own `itemImage` already uses, just smaller),
  placed before the existing veg/non-veg dot.
- `ui/cart/CartItemAdapter.kt` — loads `line.item.imageUrl` through
  the same `ApiClient.baseUrlForStaticFiles(...)` prefix every other
  image load in the app needs (`MenuAdapter`'s own comment already
  documents why the raw relative path alone never renders); null/blank
  clears the ImageView so a recycled row never shows a stale photo.

This one needed no backend change — `CartLine.item` is already a full
`MenuItem`, which already carries `imageUrl`.

---

## 🔴 Not built this session (flagged, not forgotten)

1. **Offer badge pills on Home/Search UI** — data ready (`offerTag` on
   both models), no XML/adapter wiring yet. Smallest remaining piece
   of item 1.
2. **"Offers" category tile + Offer browse screen** (item 2/3 of the
   ask) — nothing built. Plan worked out in conversation but not yet
   written to any file:
   - Client-side only for the chip itself: prepend a synthetic
     `FoodCategory(id = -1, name = "Offers", slug = "__offers__",
     iconUrl = null)` in `HomeActivity.loadCategories()` before
     `categoryAdapter.submit(list)` (no backend change needed for the
     chip). `FoodCategoryAdapter`'s icon fallback needs a special case
     for this slug to show `ic_offer_tag` (already exists, used by
     docs/32's category header icon) instead of the generic
     `ic_restaurant` fallback.
   - `HomeActivity.onCategoryTapped()` needs an early branch: if
     `category.slug == "__offers__"`, launch a new
     `OfferScreenActivity` and `return` — skip the normal
     select/filter/`loadCategoryItems()` path entirely (this chip
     never becomes the "active" filter chip).
   - New backend endpoint needed — nothing exists yet.
     `api/v1/home/offers-browse.php` (not created): customer-auth,
     `?lat=&lng=`, should:
     1. `SELECT DISTINCT restaurant_id FROM promo_offers WHERE
        status='active' AND deleted_at IS NULL AND (date range ok)` —
        bounded set of restaurants actually running something right
        now.
     2. For each, reuse `get_browsable_offers_for_restaurant()` +
        `pick_item_badge_offer()` (same trio as everywhere else in
        this feature) to build a per-restaurant list of on-offer
        items — only include items that actually got a non-null
        `offer_tag`.
     3. Response shape: `{ restaurants: [{ id, name, logo_url,
        rating_avg, distance_km, offer_titles: [...], items: [{ id,
        name, image_url, price, is_veg, offer_tag }] }] }` — grouped
        by restaurant so the screen can render one section per
        restaurant (item + restaurant, both, per the ask).
   - New `OfferScreenActivity.kt` + `OfferRestaurantSectionAdapter.kt`
     (or similar) + layout — plain RecyclerView of restaurant sections,
     each with a small item row list underneath, same general shape as
     `CategoryItemsResult`'s existing screen but grouped instead of
     flat. Tapping an item should behave like every other item card
     (open `ItemDetailBottomSheetFragment` or add-to-cart directly,
     match whatever `PopularItemsAdapter` currently does). Tapping the
     restaurant header should open `RestaurantDetailActivity`.
   - Register the new Activity in `AndroidManifest.xml`.
3. **Cart/checkout offer strip + B1G1 free-item row** (item 5 of the
   ask) — backend is ready (`CartTotals.offerTitle`/
   `offerDiscountAmount`/`offerFreeUnits`/`offerFreeItemLabel`/
   `couponDisabledByOffer` all added to the Kotlin model this
   session), **nothing reads them yet**. Deliberately scoped to
   `CheckoutActivity` (not the local-only `CartBottomSheetFragment`) —
   the cart sheet itself has no server round-trip today
   (`CartBottomSheetFragment`'s own kdoc: "server-side validation
   happens at checkout via POST /cart/validate"), and offer eligibility
   is genuine business logic (date/time/usage-limit/customer-type
   rules) that shouldn't be re-implemented client-side just to preview
   it one screen earlier. `CheckoutActivity.loadBill()` already calls
   `validateCart()` and already has the raw `CartTotals` in hand —
   the natural place to add:
   - An offer strip row (icon + `offerTitle` + "−₹`offerDiscountAmount`"),
     shown only when `offerId != null`, visually similar to however
     the existing coupon-applied row already renders (re-use that
     pattern, don't invent a new one).
   - When `offerFreeUnits > 0` (B1G1 case): a second, visually distinct
     row — "`offerFreeItemLabel` × `offerFreeUnits` — FREE" with a ₹0
     price, styled as if it were a cart line (not a discount line) per
     the app owner's own framing ("ek extra item with 0₹"). This is
     synthetic/display-only — it does **not** mean an extra `CartLine`
     gets added to `CartManager`; the real order's pricing already
     accounts for it via `offer_discount_amount`, this row is purely
     showing the customer *why* the total is what it is.
   - When `couponDisabledByOffer == true`: a small inline note (e.g.
     "Coupon not applied — combines only with select offers") near
     wherever the coupon-code entry/applied-coupon UI already lives,
     so a customer who typed a code doesn't wonder why it silently did
     nothing.

---

## Needs a real machine, not this sandbox

1. Run migration 48 against the live DB (after migration 47, if that
   still hasn't run either — check its own docs/29 checklist first).
2. `php -l` every file touched this session:
   - `backend/lib/offers.php`
   - `backend/lib/orders.php`
   - `backend/api/v1/cart/validate.php`
   - `backend/api/v1/restaurant/offers-create.php`
   - `backend/api/v1/restaurant/offers-update.php`
   - `backend/api/v1/home/popular-items.php`
   - `backend/api/v1/search/search.php`
   (all manually brace/paren/bracket-count balanced this session — see
   this doc's own working notes if that check needs re-running; note
   `search.php`'s raw paren count looks unbalanced by a naive count but
   that's pre-existing English-comment parentheses elsewhere in the
   file, not anything touched this session — confirmed by isolating
   just the new lines and counting those separately.)
3. Gradle build of the Customer app — `cartLineImage`'s view-binding
   id was hand-matched between `item_cart_line.xml` and
   `CartItemAdapter.kt` the same way every prior session's new IDs
   were, never compiler-checked.
4. Device test once built: a B1G1 offer + a coupon on the same
   restaurant → confirm `cart/validate.php` now returns
   `coupon_disabled_by_offer: true` and a zeroed coupon discount when
   the offer's `allow_coupon_stacking = 0`, and the normal stacked
   result when it's `1`. A Home/Search item under an active
   item-scoped offer → confirm `offer_tag` comes back non-null (no UI
   to see it yet, but the field should be inspectable in the raw
   response).

## Suggested order for next session

1. Smallest remaining piece first: render `offerTag` pills on
   `PopularItemsAdapter`/`SearchResultsAdapter` (data's already there,
   copy `item_menu_item.xml`'s `itemOfferTag` pill pattern).
2. `CheckoutActivity` offer strip + B1G1 free-item row + coupon-
   disabled note — backend/model fully ready, this is pure UI.
3. `OfferScreenActivity` + `home/offers-browse.php` — the biggest
   remaining piece (new backend endpoint + new screen + new adapter).
4. Only then: Restaurant App `OfferManagerActivity`/
   `dialog_add_offer.xml` (docs/30's own still-not-started item),
   which is also where `allow_coupon_stacking`'s toggle switch UI and
   its two missing Kotlin model fields finally get added.
5. Migration 47 + 48, `php -l` everything, live click-through, real
   Gradle build — same standing checklist every session accumulates.
