# Handover — continue from here (2026-08-25, session 8)

Continues docs/31. App owner confirmed offer creation now works (the
error-detail fix in that session presumably surfaced the real cause,
which then got fixed on the server side — not something visible from
this sandbox either way). This session is docs/29 item 2 — "Customer
App offer display" — finally started, scoped to exactly what the app
owner asked for: item tags on the menu screen + a category discount
icon. Checkout-screen offer strip (cart/validate.php already returns
`offer_title`/`offer_discount_amount`, nothing reads it yet) is still
untouched — separate, smaller follow-up.

Same standing sandbox limitation as always — nothing below is
build/device/DB verified, only carefully re-read + brace/paren-checked.

---

## Backend — `lib/offers.php` (new functions, nothing existing changed)

Three new functions, all additive:

- `get_browsable_offers_for_restaurant(PDO $db, int $restaurantId, int
  $customerId): array` — a deliberately *looser* eligibility check than
  `is_offer_eligible()`/`price_cart()` use. There's no cart yet at
  browse time, so `min_order_amount` and the daily/total/per-customer
  usage limits are skipped on purpose — a badge means "this offer
  exists and could apply to you," not "your current cart already
  qualifies." Date range, weekday/happy-hour window, and new/existing
  customer eligibility are still checked, since a badge that's wrong
  about "is this live right now, for you" would be actively
  misleading, not just approximate. `free_delivery` is excluded
  outright — restaurant-wide checkout perk, no natural home on a menu
  card. **price_cart() remains the one authoritative check for what a
  customer is actually charged — nothing here changes that.**
- `offer_badge_label(array $offer): string` — short per-type display
  text: `"3 @ ₹50"` (quantity_deal/buy_x_for_y), `"Buy 2 Get 1 Free"`
  (buy_x_get_y), `"20% OFF"` (percent_discount), `"₹50 OFF"`
  (flat_discount).
- `pick_item_badge_offer(array $browsableOffers, int $itemId, ?int
  $categoryId): ?array` — item-scoped beats category-scoped beats
  restaurant-wide, same "more specific wins" precedence
  `select_best_auto_offer()` uses at cart time (can't compare by
  discount *value* here since there's no cart amount yet).

## Backend — `restaurants/menu.php` (wired in)

- `require_once lib/offers.php` added.
- `$browsableOffers = get_browsable_offers_for_restaurant(...)` fetched
  once per request, right after the existing saved-items lookup.
- Each item in the response gains `offer_tag` (string|null) via
  `pick_item_badge_offer()` + `offer_badge_label()`.
- Each category (including the `id:null "Other"` fallback bucket)
  gains `has_active_offer` (bool) — true if any item actually shown in
  that category ended up with a non-null `offer_tag`. This naturally
  covers item-scoped, category-scoped, and restaurant-wide offers
  alike, since a restaurant-wide offer tags every item in every
  category the same way.

No new endpoint, no schema change — this is the same one request the
menu screen already makes, just returning two more (safe-default)
fields.

## Customer App (Kotlin/XML)

- `network/Models.kt` — `MenuItem.offerTag: String? = null`,
  `MenuCategory.hasActiveOffer: Boolean = false`. Both defaulted so
  older cached responses (or any other code path constructing these
  with named args, which is every existing call site — checked, none
  use positional args) keep compiling and rendering exactly as before.
- `item_menu_item.xml` — new `itemOfferTag` pill, same
  `bg_pill_highly_reordered`-style shape as the existing "Highly
  reordered"/"Out of stock" pills, paired with two new colors
  (`offer_bg`/`offer_fg` — `offer_fg` already existed and is used
  elsewhere in the app for offer/promo accents; `offer_bg` is new,
  same light-tint-of-the-fg pattern `success_bg`/`error_bg` already
  establish). New drawable `bg_pill_offer.xml`.
- `item_menu_category_header.xml` — new `categoryHeaderOfferIcon`
  `ImageView` next to the category title, reusing the already-present-
  but-previously-unused `ic_offer_tag.xml` drawable, tinted with
  `offer_fg`.
- `MenuAdapter.kt` — `Row.Header` now carries `hasActiveOffer` through
  `submit()`/`onBindViewHolder()`; `HeaderVH.bind()` toggles the new
  icon. `ItemVH.bind()` toggles `itemOfferTag` off `item.offerTag`,
  independent of the existing discount-percent corner badge (that one
  is the item's own base `discount_percent` field; this one is a
  restaurant-created `promo_offers` entry — an item can have either,
  both, or neither showing at once).

---

## Not done this session (flagged, not forgotten)

1. **Checkout-screen offer strip** (docs/29 item 2's other half) —
   showing which offer auto-applied at checkout. `cart/validate.php`
   already returns `offer_title`/`offer_discount_amount`/
   `free_delivery_offer_title` (built in docs/29's session); nothing
   on the Android side reads it yet. Smaller, separate follow-up —
   `CheckoutActivity` is the natural place, no new backend work
   needed.
2. **Home screen / search results** (`PopularItemsAdapter`,
   `SearchResultsAdapter`) — don't show offer tags either. Out of
   scope for what was asked this session (menu screen specifically),
   but the same `offer_tag` field could be added to whichever backend
   endpoints feed those lists later if the app owner wants it there
   too (`home/popular-items.php`, `search/search.php` — neither
   touched this session).
3. **`item_offer_row.xml`/`OffersBottomSheetFragment.kt`** (the "N
   offers ⌄" strip on the restaurant detail header) — this is the
   *old* `restaurant_offers` table (bestseller auto-discount
   descriptions, migration 14), a completely different, older feature
   that predates the Offers Engine. Not touched, not related — flagged
   here only so a future session doesn't confuse the two when
   searching for "offers" in the customer app.

## Needs a real machine, not this sandbox

1. `php -l backend/lib/offers.php backend/api/v1/restaurants/menu.php`
   — never run.
2. Gradle build of the Customer app — view-binding IDs
   (`itemOfferTag`, `categoryHeaderOfferIcon`) were hand-matched
   against their XML same as always, never compiler-checked.
3. Device test: open a restaurant with at least one active item-scoped
   offer and one category/restaurant-scoped offer → confirm the item
   pill shows the right text and the category header shows the icon
   for every category containing a tagged item; confirm an item with
   no matching offer shows neither.
