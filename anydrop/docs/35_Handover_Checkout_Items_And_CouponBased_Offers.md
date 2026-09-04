# Handover 35 — Checkout item list, cart offer badge, coupon-based offers

Session date: 2026-08-25. Zip: everything up to and including this doc.
**Nothing in this session has been build/device-verified** — no PHP
CLI, Android SDK, or network in this sandbox (same standing constraint
every prior session note in this repo already flags). Read/cross-check
only. Test each piece before trusting it in production.

## What the app owner asked for (in their own words, translated)

1. Checkout page pe cart ke items properly show hone chahiye — image +
   price, aur agar koi offer laga hai to wo bhi dikhe; cart mein bhi
   agar B1G1 ya koi aur offer hai to wo item + ₹0 waghera dikhna
   chahiye.
2. Offer Engine mein har offer (B1G1 sameet) do tarah ka ho sake:
   - **Default** — jaisa abhi chalta hai, auto-apply, koi code nahi.
   - **Coupon Based** — same offer mechanics, lekin sirf tab apply ho
     jab customer khud coupon code type/apply kare. Ye "coupon based"
     toggle **Restaurant App ke offer-create screen par hi** ho — usi
     screen se public/private bhi choose ho sake (jaise coupon banate
     waqt hota hai). No separate auto-apply-without-code system —
     sirf ye do explicit modes.

## What's DONE this session

### Backend — fully wired
- **Migration 49** (`backend/sql/49_migration_offer_apply_mode.sql`) —
  `promo_offers` gets `apply_mode` ENUM('default','coupon_based')
  DEFAULT 'default', `code` VARCHAR(50) NULL UNIQUE, `is_public`
  TINYINT(1) DEFAULT 1. Idempotent, same conditional-ALTER pattern as
  18/47/48. **Not yet run against any real DB** — run this before
  anything else in this batch, same as every prior numbered migration.
- `lib/offers.php`:
  - New `find_coupon_based_offer_by_code($db, $restaurantId, $code)`
    — looks up one coupon_based offer by code, scoped to the
    restaurant. Does NOT check eligibility itself (same "lookup vs
    eligibility are separate" split the rest of the file already
    follows) — caller checks `is_offer_eligible()` after.
  - `select_best_auto_offer()` / `select_best_free_delivery_offer()` —
    both now skip any offer with `apply_mode !== 'default'`.
    Coupon-based offers are never auto-applied.
  - `get_browsable_offers_for_restaurant()` (menu/search item-tag
    badges) — same coupon_based exclusion. A coupon_based offer no
    longer badges a menu item, since the customer can't get it without
    typing the code.
  - `format_offer()` — now returns `apply_mode`, `code`, `is_public`.
- `lib/orders.php` (`price_cart()`) — the coupon block, when the typed
  code doesn't match a row in `coupons`, now falls through to
  `find_coupon_based_offer_by_code()` before giving up as
  `invalid_coupon`. A matched coupon-based offer's discount is
  computed via the existing `compute_offer_discount()` and folded into
  the **same `offer_id`/`offer_discount_amount`/`offer_title` result
  slot** the auto-offer already uses (that's the column wired to
  `offer_usages`, which is the correct ledger for any `promo_offers`
  row — never `coupon_id`/`coupon_usages`, which stay reserved for
  real `coupons` rows).
  - **Stacking rule, exactly as agreed**: a typed coupon-based offer
    occupies the same conceptual "coupon slot" a real coupon code
    does. If an auto default offer already won the cart's one
    item/restaurant-offer slot AND that offer has
    `allow_coupon_stacking = 0`, the typed coupon-based offer is
    dropped (`coupon_disabled_by_offer = true`) exactly like a real
    coupon would be. If no auto offer won anything, the coupon-based
    offer takes the offer slot itself.
  - `orders/create.php` needed **zero changes** — its existing
    `if ($priced['offer_id'] !== null) { $recordOfferUsage(...) }`
    already handles a coupon-based offer correctly since it's the same
    slot.
- `offers-create.php` — accepts `apply_mode` ("default"|"coupon_based",
  default "default"). When `coupon_based`, `code` is required
  (uppercased, trimmed, ≤50 chars, pre-checked for uniqueness against
  the `uniq_offer_code` index) and `is_public` is accepted (default
  true).
- `offers-update.php` — `apply_mode`/`code` are **NOT editable** after
  creation (same "delete and recreate" convention `coupons-update.php`
  already uses for `code`/`discount_type` — an offer with usage
  history shouldn't have its mechanic or its customer-facing code
  changed under it). `is_public` IS editable (pure visibility toggle,
  same as `allow_coupon_stacking`).
- `coupons/list.php` ("View all offers" screen, H5) — now UNIONs in
  every public, active, in-date coupon-based `promo_offers` row for
  the restaurant, reshaped into the identical response object real
  coupons return. New field `discount_type: "offer"` (not in the
  original "flat"|"percent" enum) + `offer_label` (from the existing
  `offer_badge_label()` helper, e.g. "Buy 1 Get 1 Free") — the app
  distinguishes on `discount_type == "offer"` to show the label instead
  of a %/₹ line. Private coupon-based offers are excluded from this
  list (same as private coupons already are) but remain fully
  redeemable by typed code at Checkout.

### Android (customer app) — mostly wired
- `CouponListItem` model + `CouponsAdapter` — handle the new
  `"offer"` discount_type / `offerLabel` field from `coupons/list.php`.
- **Checkout screen items list** (the app owner's #1 ask) —
  `activity_checkout.xml` now has a "Your Items" card above "Bill
  details", backed by a nested (`nestedScrollingEnabled=false`)
  RecyclerView. New `CheckoutItemAdapter.kt` — a read-only sibling of
  the cart sheet's existing `CartItemAdapter` (same
  `item_cart_line.xml`/`ItemCartLineBinding`, same image/price/veg-dot
  rendering), stepper hidden, plain "× qty" shown instead. Wired in
  `CheckoutActivity.onCreate()` right after the cart-empty guard, fed
  once from `cart.getLines()` — this list is static per checkout
  session since quantity can only be changed back in the cart sheet.
- **Offer badge in both the cart sheet AND checkout** (app owner's #1
  ask, "B1G1 item show + 0₹ etc") — `item_cart_line.xml` gained a
  `cartLineOfferTag` pill (same offer-tinted rounded-badge treatment
  used elsewhere in the app) bound in `CartItemAdapter.bind()` off
  `line.item.offerTag` — a field that was **already present end-to-end
  on every `MenuItem`** (menu.php/search.php → `Models.kt`) from an
  earlier session, so this needed no new network call, no new backend
  endpoint — purely wiring an existing field into a new visual spot.
  Since `CheckoutItemAdapter` reuses the same layout/binding logic,
  the badge shows on Checkout's item list too, automatically.
- The existing server-driven checkout offer strip + B1G1 free-item row
  (`renderBill()` → `offerAppliedRow`/`offerFreeItemRow` in
  `activity_checkout.xml`) was **already built** before this session
  (dated comments say "docs/33", "app owner ask 2026-08-25" — i.e. it
  shipped earlier the same day) — untouched here, still works
  unchanged for both default AND coupon-based offers, since both now
  flow through the same `offer_id`/`offer_discount_amount` result.

## What's NOT done yet — the real gap for next session

**Restaurant App offer create/edit screen (Kotlin/XML) has NOT been
touched.** This is the one piece the app owner explicitly asked to sit
on that screen ("copun screen par hi... offer create ke time copun
based select kar ke public private chose"). The backend
(`offers-create.php`/`offers-update.php`) is fully ready to receive
`apply_mode`/`code`/`is_public` — nothing blocks building this next:

- Find wherever the Restaurant App currently builds its offer-create
  form (search the restaurant Android module for wherever it calls
  `offers-create.php` today — this session never located/opened that
  file, only the backend endpoint it hits).
- Add: a Default/Coupon Based toggle (radio or switch). When "Coupon
  Based" is selected, reveal a code input field (reuse whatever
  text-input styling the coupon-create form already uses, if a
  restaurant-side coupon-create screen exists — check
  `coupons-create.php` callers) and a Public/Private switch. Both only
  submitted (and only meaningful) when apply_mode = coupon_based.
- The offer's *type* picker (B1G1, % off, flat off, quantity deal,
  free delivery) is unrelated to and unaffected by this toggle — every
  existing offer_type still works under either apply_mode, exactly as
  the app owner asked ("usme B1G1 rakh diya to usme 2 cheez hongi").

**Customer-app checkout coupon-input UX note (not started, worth
flagging):** typing a coupon-based offer's code today goes through the
exact same `applyCoupon()` / `btnApplyCoupon` flow as a real coupon —
no code changes needed there since price_cart() already resolves it —
but the error-message mapping in `CheckoutActivity.placeOrder()`
(`COUPON_ERROR_CODES` set, the `when (errInfo.code)` block) hasn't
been reviewed for whether a coupon-based-offer failure produces a
sensible message. Worth a pass next session.

## Known rough edges to keep in mind

- `coupons/list.php`'s new offer branch calls
  `is_offer_usage_available()`/`is_offer_time_eligible()` per row —
  same N+1-ish query shape the original coupon branch already has
  (per-coupon usage-limit queries), not something this session
  introduced, just carried forward.
- No admin-side visibility into `apply_mode`/`code`/`is_public` was
  added (`admin/offers.php` wasn't touched). Admin's pause/disable
  lever still works (status column untouched), but an admin browsing
  offers won't see whether one is coupon-based or its code yet.
