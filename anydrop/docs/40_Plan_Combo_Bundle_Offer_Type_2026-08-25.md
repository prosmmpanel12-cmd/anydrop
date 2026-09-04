# Plan — Combo/Bundle Offer Type (2026-08-25)

**Status:** DONE — Steps 1–6 all complete. Not device/build-verified
anywhere (no PHP CLI/Kotlin compiler/Gradle/DB in this sandbox — see
Step 6's own closing note for the full verification checklist still
needed).

## Why this is its own doc, not just a recall.md line

recall.md item 29 has flagged combo pricing as "explicitly deferred —
needs a multi-item bundle model item 28's schema doesn't cover" since
docs/29. `lib/offers.php`'s own file header says the same thing. This
confirms why: every existing offer type (`quantity_deal`,
`buy_x_for_y`, `buy_x_get_y`, `percent_discount`, `flat_discount`,
`free_delivery`) is `scope`-bound to exactly one item, one category, or
the whole restaurant (`get_offer_scoped_lines()`). A combo is
fundamentally different — it needs a *specific set* of distinct menu
items, each with its own required quantity (e.g. 1 Burger + 1 Fries + 1
Coke @ ₹199), which no existing column or matching function
represents. This needs a real child table and a new code path, not a
reuse of `scope`.

## Step-by-step plan

### Step 1 — DB migration (this doc + migration file) ✅ DONE (2026-08-25)
- `backend/sql/50_migration_combo_offers.sql` created.
- Adds `'combo'` to `promo_offers.offer_type` ENUM via an idempotent
  conditional `MODIFY COLUMN` (checked via `information_schema.COLUMNS
  .COLUMN_TYPE LIKE '%''combo''%'` since ENUM values aren't exposed as
  their own rows the way columns/indexes are — a different idempotency
  check than migration 49's plain "does this column exist" pattern).
- New `offer_combo_items` table (`offer_id`, `menu_item_id`,
  `required_qty`) via `CREATE TABLE IF NOT EXISTS`, FKs to
  `promo_offers`/`menu_items`, unique on `(offer_id, menu_item_id)` so
  the same item can't be double-listed in one combo.
- `promo_offers.offer_price` (existing column) reused as the combo's
  fixed bundle price — no new promo_offers column added.
- Balance-checked (parens, PREPARE/EXECUTE/DEALLOCATE pairing) — not
  compiler/DB-run, no MySQL CLI in this sandbox.

### Step 2 — Backend matching + discount calc (`lib/offers.php`) ✅ DONE (2026-08-25)
- `get_offer_combo_items(PDO $db, int $offerId): array` added — fetches
  each combo's `offer_combo_items` rows (menu_item_id, required_qty).
- New `'combo'` case in `compute_offer_discount()`: bypasses
  `get_offer_scoped_lines()` entirely (a combo's `scope` stays unused/
  'restaurant' per migration 50); collapses the cart into one qty/
  unit_price entry per menu_item_id, then checks the cart contains ≥
  `required_qty` of *every* `offer_combo_items` row before granting any
  discount — a combo is all-or-nothing, not partial credit.
- Discount = (sum of each combo item's cart unit price × its
  required_qty) − `offer_price`, floored at 0, same "never negative"
  guard every other type already uses.
- Multiple simultaneous bundles: same `intdiv()`-based "how many full
  sets fit" approach `quantity_deal` already uses, applied per-
  ingredient and capped by whichever ingredient item runs out first
  (`$maxSets`).
- `select_best_auto_offer()` needed no change — combo is just another
  case in the same `foreach`, `free_delivery`/`coupon_based` exclusion
  already skips the right things.
- Balance-checked (braces/parens/brackets) — no PHP CLI in this
  sandbox, so not `php -l`-verified.

### Step 3 — Checkout/price_cart integration + validation ✅ DONE (2026-08-25, verification only, no code change)
- Confirmed `price_cart()` needs no structural change: `backend/api/v1/cart/validate.php`
  (preview) and `backend/api/v1/orders/create.php` (placement) both call
  `price_cart()` fresh against the real, client-submitted `$items` — there is no
  separate/duplicate scope-match re-check anywhere else in `create.php` for
  *any* existing offer_type; `price_cart()` being re-invoked with real cart
  data at placement time (not client-trusted cached numbers) IS the
  re-validation every other offer type already relies on. Confirmed
  `create.php` has no client-supplied `offer_id` field either — the applied
  offer is always server-selected via `select_best_auto_offer()`, so there's
  no way for a client to force a stale/no-longer-matching combo through.
- Since Step 2's `'combo'` case in `compute_offer_discount()` re-derives
  `$cartByItem` from whatever `$lineItems` it's called with, and both preview
  and placement always pass the actual current cart, a combo whose required
  items were removed/reduced between preview and placement automatically
  re-computes to a 0 discount at placement — same effect as every other
  offer type's built-in re-validation, no combo-specific code needed.
- `create.php`'s existing `recordOfferUsage()` closure (daily/total/
  per_customer limit re-check under `FOR UPDATE`, same TOCTOU protection
  every other offer type gets) already covers combo too, since it keys off
  `$priced['offer_id']` generically — not per offer_type.

### Step 3b — `offers-create.php` / `format_offer()` gap fix ✅ DONE (2026-08-26)
- **Found during Step 4 prep, not part of the original plan:** Steps 1–3
  only ever touched `lib/offers.php` (matching/discount calc) and
  verified `price_cart()`'s existing re-validation. Nobody had touched
  `backend/api/v1/restaurant/offers-create.php` — the actual creation
  endpoint — which still rejected `offer_type: "combo"` outright
  (`'combo'` wasn't in `$validTypes`) and had no code path to insert
  `offer_combo_items` rows even if it had been. `format_offer()` also
  didn't return a combo's item list, so no create/list/edit response
  could show one back. Building the Step 4 Android dialog against this
  would have shipped a UI that 422's on every real save.
- `offers-create.php` fixed:
  - `'combo'` added to `$validTypes`.
  - `scope` forced to `'restaurant'` server-side when `offer_type ===
    'combo'` (migration 50's own contract — scope is unused for combo
    matching), so the client doesn't need special-case logic to avoid
    tripping the quantity-type `scope === 'restaurant'` rejection.
  - `offer_price` (reused field, same as quantity_deal/buy_x_for_y)
    validated as the bundle price for combo.
  - New `combo_items` request field: array of `{menu_item_id,
    required_qty}`, required for combo (2+ distinct items — a 1-item
    "combo" is rejected, since that's just a mis-labeled single-item
    offer), each item ownership-checked against the calling
    restaurant's own non-deleted menu items in one batched `IN()`
    query, de-duplicated by `menu_item_id` before insert so a
    client-side duplicate gets a clean `validation_error` instead of
    hitting `offer_combo_items`'s own unique index as a raw SQL error.
  - The `promo_offers` insert + `offer_combo_items` inserts are now
    wrapped in one transaction (new for this endpoint — every other
    offer_type only ever needed the single insert) so a combo can never
    end up half-written (a promo_offers row with zero items, which
    `get_offer_combo_items()` would then silently treat as a
    zero-discount no-op rather than an error — confusing for the
    restaurant that thinks they created a working combo).
- `format_offer()` gained an optional `?PDO $db = null` param;  when
  given and `offer_type === 'combo'`, it now also returns `combo_items`
  (`[]` for every non-combo type, and for a combo fetched without
  `$db`, so no caller needs a null-check). `offers-create.php`,
  `offers-list.php`, and `offers-update.php` all updated to pass `$db`
  through. `offers-update.php` itself needed no other change — combo
  items are correctly *not* editable there, same "delete and recreate"
  policy already applied to every other type's mechanic fields.
- Bonus fix while in this area: `backend/admin/offers.php`'s
  `$offerTypeLabels` map was missing a `'combo'` entry (would've
  displayed the raw enum string `combo` instead of a label) — added
  `'Combo/Bundle'`. Doesn't change Step 5's own scope (per-combo item
  list display), just the type-column label.
- Also swept the wider `backend/` tree for the same class of bug
  (string-concatenated SQL, unbound `$_GET`/`$body` interpolation) —
  no other endpoint does raw interpolation; every partial-update
  endpoint (`menu-items-update.php`, `coupons-update.php`,
  `profile-update.php`, `offers-update.php`, etc.) builds its `SET
  field = :placeholder` list dynamically but always binds values via
  prepared-statement params, never concatenates user input directly
  into SQL. No new injection findings from this pass.
- Balance-checked (braces/parens), not `php -l`-verified — same
  sandbox limitation as every prior step.

### Step 4 — Restaurant App create/edit dialog (Android) ✅ DONE (2026-08-26)
- `dialog_add_offer.xml`: new `chipTypeCombo` chip in the offer-type
  group; new `mechanicComboGroup` section (a `comboItemsContainer` that
  rows get added into at runtime, a `btnAddComboItem` button, and a
  dedicated `inputComboPrice` field for the bundle price — a separate
  View from `inputOfferPrice` since the two mechanic groups are
  mutually exclusive but both need to exist in the layout tree). Scope
  chips + item/category pickers are hidden entirely for combo (matching
  50's "scope forced to restaurant, unused for matching" contract) —
  not just defaulted, since showing them would imply scope affects a
  combo's discount when it doesn't. New `comboItemsLockedLabel` shown
  in edit/view mode instead (combo_items is create-only, same "delete
  and recreate" boundary as every other mechanic field).
- New `item_offer_combo_row.xml` — one repeatable row (menu-item
  dropdown + qty field + remove button), inflated by
  `OfferManagerActivity.addComboItemRow()` per combo item; the add
  dialog starts with 2 pre-added rows (docs/40's own 2+ item minimum)
  rather than an empty builder.
- `OfferManagerActivity.kt`: offer-type visibility/scope logic extended
  for combo; new `addComboItemRow()`/`comboMenuItemsCache` (reuses the
  same `getMenuItems()` fetch the scope-item picker already makes, no
  extra round trip); `submitNewOffer()` collects+de-dupes the combo
  rows into `ComboItemBody` list, validates 2+ items and a bundle
  price > 0 client-side (mirroring `offers-create.php`'s own
  validation so the request never round-trips just to 422); new
  `applyComboItemsLockedLabel()` shared by edit/view mode — shows an
  id-based placeholder immediately from `PromoOffer.comboItems`, then
  upgrades to real item names once a `getMenuItems()` fetch resolves
  (`format_offer()` doesn't return names, only ids — see that
  function's own kdoc).
- `Models.kt`: `PromoOffer` gained `comboItems: List<ComboItem>`
  (mirrors `offer_combo_items` — `menu_item_id`/`required_qty`, no
  name); `OfferCreateBody` gained `comboItems: List<ComboItemBody>?`.
  `OfferUpdateBody` deliberately unchanged — combo items aren't
  editable post-creation.
- `strings.xml`: new offer_type_combo/offer_hint_combo_*/
  btn_add_combo_item/offer_combo_min_items_error/
  offer_combo_items_locked_fmt/offer_combo_item_line_fmt entries.
- `OfferAdapter`/`item_offer_card.xml` needed no change — the card
  already renders title/usage/validity/status generically per
  offer_type, with no per-mechanic summary text to extend (the fire/
  delivery icon split is the only type-conditional rendering, and
  combo correctly falls into the "fire" bucket like every non-
  free-delivery type).
- Balance-checked (braces/parens for the .kt file, tag-count balance
  for both .xml files) — no Gradle/Kotlin compiler or Android Studio
  in this sandbox, so not build-verified.

### Step 5 — Admin panel visibility (`backend/admin/offers.php`) ✅ DONE (2026-08-26)
- New batched query (one `IN()` lookup against just the combo offer
  ids present on the current page, same batching pattern
  `offers-create.php`'s Step 3b ownership check already uses) joins
  `offer_combo_items` to `menu_items` for a display name —
  `get_offer_combo_items()` in `lib/offers.php` deliberately returns
  ids only (it's a matching-path helper, not a display one), so this
  page reads `offer_combo_items` directly instead of reusing it.
- Type column now prints each combo's item list under the type label
  (`Item Name ×qty`, comma-joined) when `offer_type === 'combo'`; a
  combo row with zero `offer_combo_items` rows (the same "incorrect
  but not impossible" case `get_offer_combo_items()`'s own kdoc
  already flags) shows `(no items on file)` instead of silently
  rendering nothing, so a half-written combo is visible to the admin
  rather than looking identical to a normal one.
- No other offer type shows its mechanic value (percent/flat amount,
  X/Y quantities, etc.) in this table today, so — matching that
  existing scope, not expanding it — the combo's bundle price
  (`offer_price`) is intentionally NOT added here; only the item set,
  per this doc's original Step 5 line.
- Balance-checked (`<?php`/`<?=`/`?>` tag counts, braces/parens/
  brackets) — no PHP CLI in this sandbox, so not `php -l`-verified,
  same standing limitation as every prior step.

### Step 6 — Customer App display ✅ DONE (2026-08-26, backend only — see note below)
- **Found while starting this step, not part of the original plan:**
  every browse-time item badge (`restaurants/menu.php`,
  `home/popular-items.php`, `search/search.php`,
  `home/offers-browse.php`) calls `pick_item_badge_offer()`, whose
  third precedence tier matches any offer with `scope === 'restaurant'`
  — and migration 50 forces a combo's `scope` to `'restaurant'` (it's
  unused for matching, per that migration's own contract). Before this
  step, a live combo would therefore have matched that tier and
  badged **every single menu item in the restaurant** with the combo's
  tag, not just its own required items — a real correctness bug, not
  just a missing display, that would have shipped to Step 6's first
  screen touched.
- New `index_combo_offers(PDO $db, array $offers): array` in
  `lib/offers.php` — one batched query (only run when the offer set
  actually has a combo row) building two maps from a restaurant's
  already-fetched `$browsableOffers`: `menu_item_id => combo offer id`
  (for matching) and `offer_id => [menu_item_id => name]` (for
  labeling). Built once per restaurant, same "batch once, not N+1"
  discipline Step 5's admin query already used.
- `pick_item_badge_offer()` gained a new combo tier (checked after
  item/category scope, before the restaurant-wide fallback) using that
  index, and the restaurant-wide fallback itself now explicitly
  excludes `offer_type === 'combo'` — closing the bug above at its
  root, so all four badge endpoints are fixed by one shared change.
- `offer_badge_label()` gained a `'combo'` case: instead of falling to
  the generic `default` (which would've just echoed the offer's own
  title, same as any other admin-only fallback), it now names the
  *other* items in the bundle plus the bundle price — e.g. `"Combo w/
  Fries, Coke — ₹199"` on the Burger's own badge — capped at 3 named
  items (`+N more`) since the pill has no max-width/ellipsize in any
  of its 5 layout uses (checked all 5 XML files touched by this
  change: `item_menu_item.xml`, `item_popular_dish.xml`,
  `item_search_dish.xml`, `item_offer_browse_dish.xml`, plus
  `item_cart_line.xml` which reads the same field from a cached
  `MenuItem` but isn't itself one of the 4 endpoints changed here).
  This is the "item list + bundle price on the offer card" this step's
  own line asked for — delivered through the existing generic
  `offer_tag` string field, not a new one.
- All 4 badge-producing endpoints (`restaurants/menu.php`,
  `home/popular-items.php`, `search/search.php`,
  `home/offers-browse.php`) updated to build the combo index once per
  restaurant and thread it through both calls.
- **Android side needed zero changes** — `MenuItem.offerTag`/
  `PopularItem.offerTag`/`SearchItem.offerTag`/`OfferBrowseItem.offerTag`
  are all plain `String?` already wired end-to-end to an unconstrained
  pill `TextView` (no `maxLines`/`ellipsize` on any of them, confirmed
  by re-reading all 5 layouts above), so the richer combo text renders
  through the existing pipeline with no client rebuild required beyond
  a normal backend deploy. This also means the checkout offer strip
  (`CheckoutActivity.renderBill()`, already generic over `offerTitle`/
  `offerDiscountAmount` since Step 2 needed no change there) and the
  Offers screen (`OfferScreenActivity`/`OfferBrowseAdapter`, docs/36)
  both pick up correct combo behavior automatically.
- Balance-checked (comment-stripped brace/paren/bracket counts, since
  several of this change's own explanatory comments contain
  unbalanced parens in prose) — no PHP CLI in this sandbox, not
  `php -l`-verified, same standing limitation as every prior step.

This closes docs/40's plan — Steps 1–6 all done. Still not
device/build-verified anywhere in this feature (no PHP CLI, Kotlin
compiler, Gradle, or live DB in this sandbox); a real `php -l` pass +
migration 50 run + live click-through (create a combo via the
Restaurant App → confirm ONLY its own items badge on Home/Search/menu/
Offers screen, not the whole restaurant's menu → confirm the badge
text lists the other items + price → place an order through it) is
still required before this is production-ready, per `PENDING.md` item
31's existing "full build/device/live DB regression" requirement.

## What stays out of scope for this feature

- No changes to the stacking rule (doc 20 §13) — a combo offer still
  only occupies the existing single "item/restaurant offer" slot,
  same as every other non-free-delivery type.
- No changes to coupon-based apply mode — a combo offer can still be
  `apply_mode = 'coupon_based'` or `'default'` exactly like any other
  offer_type, no special-casing needed there.

## Verification note

Same sandbox limitation as every prior session (docs/38, docs/39) — no
PHP CLI, Kotlin compiler, Gradle, or DB here. Each step gets a manual
balance-check where applicable; a real `php -l` / Gradle build / DB
migration run is still required before this is production-ready,
consistent with `PENDING.md` item 31's existing "full build/device/
live DB regression" requirement.
