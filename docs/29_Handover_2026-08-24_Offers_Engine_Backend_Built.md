# Handover — continue from here (2026-08-24, session 5)

Restaurant Offers Engine (recall.md Phase D item 28) — backend +
admin built this session. Android (both apps) is NOT built yet — see
"Not done" below.

Same standing limitation as every prior session: no Android SDK/
Gradle/PHP CLI/live DB in this sandbox, so everything below is
manually verified only (brace/paren counts checked, imports/requires
checked, logic re-read carefully) — not a substitute for a real build
or a real migration run.

---

## ✅ Done this session — Backend (fully wired end to end)

### What this IS vs the existing `coupons` table
`coupons` = admin/restaurant-issued CODES a customer types in.
`restaurant_offers` (new) = restaurant-created promotions that
**auto-apply** at checkout with no code entry — doc 20 §1's own
framing. The two coexist and can stack together (see stacking rule
below); neither replaces the other.

### Migration
`sql/47_migration_restaurant_offers_engine.sql`:
- `restaurant_offers` table — one row per offer, covers all 6 types
  from doc 20 §1: `quantity_deal`, `buy_x_for_y`, `buy_x_get_y`,
  `percent_discount`, `flat_discount`, `free_delivery`. Also carries
  scope (item/category/restaurant), min order, customer eligibility
  (all/new/existing), date range, happy-hour time window, weekday
  restriction, and three usage-limit fields (daily/total/per-customer).
- `offer_usages` — append-only redemption ledger, same
  never-trust-a-counter reasoning `coupon_usages` already established.
  `discount_amount` snapshotted per row so a later offer edit can never
  retroactively change a historical order's numbers.
- `orders` gets 4 new columns: `offer_id`, `offer_discount_amount`,
  `free_delivery_offer_id`, `free_delivery_discount_amount` — kept
  **separate** from `coupon_id`/`discount_amount` (not merged) so a
  bill can show "Item Discount" and "Coupon" as two distinct lines per
  doc 20 §42's price-breakdown mock.
- New admin permissions `offers_view`/`offers_manage`, granted to
  every role already holding `coupons_edit` (today, just Super Admin) —
  same "don't silently reduce anyone's access" pattern every prior
  migration since 42 uses.
- Idempotent (CONTINUE-HANDLER pattern for the ALTER, IF NOT EXISTS for
  the CREATE TABLEs) — safe to re-run.

**Not yet run against the live DB — run this first**, same as every
migration since 40.

### `lib/offers.php` (new) — the pricing engine
Single source of truth for "which offer applies to this cart, for how
much" — same one-function-every-caller pattern `lib/commission.php`/
`lib/delivery_pricing.php`/`lib/cod_rules.php` already establish.

- `get_date_eligible_offers_for_restaurant()` — status='active', not
  deleted, within start/end date.
- `is_offer_time_eligible()` — weekday + happy-hour window check.
- `is_offer_customer_eligible()` — new/existing customer gate, defined
  the same way as elsewhere in this codebase (zero delivered orders
  ever = new).
- `is_offer_usage_available()` — daily/total/per-customer limits,
  live-COUNT against `offer_usages`, not a cached counter.
- `is_offer_eligible()` — combines all of the above + min_order_amount.
- `get_offer_scoped_lines()` — resolves which cart line(s) an
  item/category/restaurant-scoped offer actually matches.
- `compute_offer_discount()` — the actual math per offer type (see the
  file's own kdoc for the quantity_deal/buy_x_for_y/buy_x_get_y/
  percent/flat formulas — averaged per-unit price across matched lines
  for the quantity-based types, so a category-scoped offer over
  mixed-price items still behaves sensibly).
- `select_best_auto_offer()` — picks the single best-value item/
  restaurant offer (highest discount wins; oldest-id breaks ties).
- `select_best_free_delivery_offer()` — separate function, separate
  stacking slot, capped at the actual delivery charge.
- `format_offer()` — API response shaper.

### `lib/orders.php`'s `price_cart()` — wired in
Runs the offer engine **after** the existing coupon block (both
discounts computed independently, then combined) and **before**
delivery/tax/grand_total. Implements doc 20 §13's own recommended
initial stacking rule exactly: **1 auto-applied item/restaurant offer
+ 1 coupon (unchanged, existing) + 1 free-delivery offer** — never
offer-vs-offer, never unlimited stacking. Not admin-configurable yet
(doc 20 §13 itself flags that as a later step).

Price breakdown order, matching doc 20 §2 exactly:
```
Subtotal
- Item Discount (offer)
- Coupon Discount
= (tax base)
+ Delivery Fee
- Free Delivery Discount
+ Platform Fee + Packing + Tax
= Grand Total
```
A defensive floor was added so item-discount + coupon-discount
together can never exceed the item subtotal (mirrors the coupon
block's own pre-existing `min($discount, $itemTotal)` floor, extended
now that two discounts combine).

`price_cart()`'s return array and `format_order()`'s response shape
both grew the new offer fields — `cart/validate.php` (preview) exposes
them so the checkout screen can show which offer is being auto-applied
before placing the order.

### `orders/create.php` — offer usage recording
Inside the same insert transaction as the order itself (never a
separate write that could land without the order, or vice versa):
- New `offer_id`/`offer_discount_amount`/`free_delivery_offer_id`/
  `free_delivery_discount_amount` columns populated on insert.
- A shared `$recordOfferUsage` closure does a **row-locked re-check**
  of daily/total/per-customer limits immediately before inserting into
  `offer_usages` — same TOCTOU race protection bugs.md #1.3 already
  established for coupons (two near-simultaneous orders both passing
  the cheap pre-check against the same stale count can't both win the
  last slot of a limited offer anymore).
- New `offer_usage_limit_reached` error code in the catch block, same
  shape as the existing `coupon_usage_limit_reached` one.

### Restaurant App REST endpoints (new)
- `GET /restaurant/offers-list.php` — every non-deleted offer this
  restaurant owns, with a live-computed `is_currently_active` flag and
  a `times_used` count. Same ownership-scoping pattern
  `coupons-list.php` already uses.
- `POST /restaurant/offers-create.php` — full validation per offer
  type (every field `compute_offer_discount()` reads for a given type
  is required here, so a malformed offer can never silently become a
  no-op). Starts `status='active'` — no pre-publish approval queue in
  v1 (see "Not built" below).
- `POST /restaurant/offers-update.php?id=` — partial update (status
  active↔paused, title, min_order_amount, max_discount, date/time/
  weekday window, usage limits) + soft-delete via `is_deleted`. The
  offer's core mechanic (type/scope/item/category/required_qty/etc.)
  is **not** editable after creation — same "delete and recreate
  instead" reasoning `coupons-update.php` already documents for
  code/discount_type, since editing the mechanic would make existing
  `offer_usages` history impossible to interpret correctly.
  Deliberately refuses to let a restaurant move a `disabled` offer
  back to `active` themselves (403 `offer_disabled_by_admin`) — only
  `admin/offers.php` can undo an admin disable.

### Admin oversight page (new)
`admin/offers.php` — lists every restaurant's offers with filters
(status, restaurant id), Pause/Resume (mirrors the restaurant's own
toggle) and Disable/Re-enable (admin-only override the restaurant
can't undo). Nav entry added to `_layout_head.php`, gated on
`offers_view`/`offers_manage`.

---

## 🔴 Not built this session (flagged, not forgotten)

1. **Restaurant App "Offers" screen (Kotlin/XML)** — doc 20 §14's own
   Active/Scheduled/Expired/Paused tabbed layout, create-offer form,
   offer cards with usage counters. The REST endpoints above are ready
   for this to consume; no Android code was touched this session.
2. **Customer App offer display** — menu item badges ("🔥 3 Samosa @
   ₹50"), a checkout-screen offer strip showing which offer
   auto-applied (the data is already in `cart/validate.php`'s
   response — `offer_title`/`offer_discount_amount`/
   `free_delivery_offer_title` — nothing on the Android side reads it
   yet).
3. **Combo/bundle offers** — doc 20's "Combo/bundle offers" bullet
   (multi-*different*-item bundles, e.g. "1 Biryani + 1 Drink + 1
   Dessert for ₹249"). Explicitly deferred — the current schema's
   scope model (single item / single category / whole restaurant)
   doesn't cover a specific multi-item combination, and half-modeling
   it risked a schema that would need reworking rather than extending
   later. Needs its own join table (offer_id → set of required
   menu_item_ids) as a follow-up.
4. **Offer analytics** (doc 20 §16 — views/orders/items-sold/revenue/
   discount-given roll-ups, both restaurant-facing and admin-facing).
   `offer_usages` is the raw ledger any such report would aggregate
   from, but no reporting query or page exists yet.
5. **Admin pre-publish approval queue** (doc 20 §15's Approve/Reject
   actions). v1 auto-approves every restaurant-created offer the
   instant `offers-create.php` succeeds — `admin/offers.php`'s only
   lever is pause/disable *after* the fact, not a review gate before
   an offer goes live. Flag for the app owner: is auto-live acceptable
   for launch, or does this need a review step before real customers
   can see the app?

---

## Needs a real machine, not this sandbox

1. **Run migration 47** against the live DB. Nothing else here works
   until this lands — every new PHP file below `require_once`s
   `lib/offers.php`, which queries `restaurant_offers`/`offer_usages`
   directly.
2. **No PHP syntax/lint check has run.** Every new/edited `.php` file
   this session was only brace/paren-count-balanced and re-read
   carefully — run `php -l` on all of them (listed below) before
   trusting they parse:
   - `backend/lib/offers.php` (new)
   - `backend/lib/orders.php` (edited — `price_cart()`, `format_order()`)
   - `backend/api/v1/orders/create.php` (edited)
   - `backend/api/v1/cart/validate.php` (edited)
   - `backend/api/v1/restaurant/offers-list.php` (new)
   - `backend/api/v1/restaurant/offers-create.php` (new)
   - `backend/api/v1/restaurant/offers-update.php` (new)
   - `backend/admin/offers.php` (new)
   - `backend/admin/_layout_head.php` (edited — new nav entry)
3. **Live click-through, once 1+2 are done:**
   - Restaurant token: `POST /restaurant/offers-create.php` a
     `quantity_deal` (e.g. "3 Samosa @ ₹50") and a `free_delivery`
     offer against a test restaurant/menu item.
   - `GET /restaurant/offers-list.php` — confirm both appear,
     `is_currently_active` reads correctly.
   - As a customer: `POST /cart/validate.php` with a cart matching the
     quantity_deal's item/qty — confirm `offer_discount_amount` and
     `offer_title` come back correctly, and a cart above the free-
     delivery threshold shows `free_delivery_discount_amount` too.
   - Place the order (`POST /orders`) — confirm `orders.offer_id` /
     `offer_discount_amount` land, an `offer_usages` row appears for
     each, and `grand_total` matches what `cart/validate.php` already
     previewed (no drift between preview and real order).
   - Hit the offer's `per_customer_limit`/`daily_limit` and confirm a
     second matching order gets rejected or simply no longer selects
     that offer (whichever point in the flow it's still eligible for).
   - `admin/offers.php`: Pause the offer → confirm a fresh
     `cart/validate.php` call no longer applies it. Disable it → as
     the restaurant, confirm `offers-update.php` refuses to
     re-activate it (`403 offer_disabled_by_admin`). Re-enable from
     admin → confirm the restaurant can resume it again.

## Suggested order for next session
1. Run migration 47.
2. `php -l` every file in the list above, fix any syntax issues found.
3. Live click-through per the checklist above.
4. Once confirmed working: start the Restaurant App "Offers" screen
   (item 1 above) — that's the one piece that makes any of this
   actually usable by a real restaurant, everything else this session
   is API-only.
