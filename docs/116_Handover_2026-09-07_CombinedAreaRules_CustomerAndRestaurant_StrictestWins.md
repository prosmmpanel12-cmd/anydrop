# Handover — 2026-09-07 — COD/Payment/Pricing Rules Now Combine Customer's Area AND Restaurant's Own Area (Strictest Wins)

**App owner decision (asked directly, answered explicitly):** COD/pricing
rules should check BOTH the customer's delivery-address area and the
restaurant's own assigned area, and whichever is stricter applies.

## Why this was needed

Before this change, `get_effective_cod_rule()`, `get_effective_payment_restrictions()`,
and `calculate_delivery_fee()` all resolved their rule from the
**delivery address's** lat/lng only — the restaurant's own admin-assigned
`area_id` was never consulted for these three. That was a deliberate,
documented design choice (COD/pricing risk is "where is this being
delivered to", not "which restaurant is it from") — but the app owner
wants both sides checked from now on, strictest wins.

## What changed — three lib files, five call sites

### `backend/lib/cod_rules.php`
- `get_effective_cod_rule($db, $lat, $lng, ?int $restaurantAreaId = null)` —
  new optional 4th param. Customer-side resolution (nearest-node +
  parent, from lat/lng) is unchanged, pulled into
  `resolve_cod_rule_for_address()`. New `resolve_cod_rule_for_restaurant_area()`
  resolves from a **known** `area_id` (the restaurant's own
  `restaurants.area_id`, admin-assigned — not a fresh geometric lookup
  of the restaurant's own lat/lng), walking the full parent chain via
  new `resolve_area_cod_rule_by_area_id()` (same shape as
  `delivery_pricing.php`'s existing `resolve_area_pricing_rule_row()`).
- Combine rule, field by field, when a restaurant area is given:
  - `cod_enabled`: AND (either side disabling it disables it)
  - `min_prepaid_orders`: MAX (higher requirement wins)
  - `max_cod_order_amount`: MIN, null-safe (a null/no-cap on one side
    never weakens a real cap on the other)
  - `max_cod_orders_per_day`: MIN, same null-safe handling
  - `new_customer_cod_blocked`: OR
- Return shape gained a `restaurant_area_id` key (null when omitted or
  unassigned) alongside the existing `area_id` (still the customer-side
  match), for transparency/debugging. `source` becomes
  `'combined_strictest'` when either side contributed a real override,
  `'platform_default'` when neither did.
- Omitting the 4th param (or passing `null`) reproduces the exact old
  address-only behaviour — nothing regresses for a call site that
  isn't updated.

### `backend/lib/payment_restrictions.php`
- Same shape of change: `get_effective_payment_restrictions($db, $lat,
  $lng, ?int $restaurantAreaId = null)`. Since both fields here are
  plain booleans, "stricter" is just AND — `upi_allowed`/`cod_allowed`
  are only true if BOTH the address's area and the restaurant's own
  area allow them. New `resolve_area_payment_restriction_by_area_id()`
  helper, same known-area_id parent-chain walk.

### `backend/lib/delivery_pricing.php`
- `calculate_delivery_fee(..., ?int $restaurantAreaId = null)`. Computes
  the fee TWICE for the same distance — once using the delivery
  address's area rate/base, once using the restaurant's own area's
  rate/base (via the pricing lib's existing `resolve_area_pricing_rule_row()`)
  — and returns the **higher** of the two (stricter = costs the
  customer more). Response gained `restaurant_area_id`; `source` can
  now also be `'combined_strictest'`.

### Five call sites updated to pass the restaurant side through
- **`backend/lib/orders.php`** (`price_cart()`) — already had the full
  `$restaurant` row loaded; passes `$restaurant['area_id']` into
  `calculate_delivery_fee()`.
- **`backend/api/v1/orders/create.php`** — added one lightweight
  `SELECT area_id FROM restaurants WHERE id = :id` lookup ahead of the
  payment-restriction/COD checks (which run before `price_cart()`
  does), so both checks get the restaurant side too. A restaurant that
  doesn't exist yet just contributes `null` here — `price_cart()`
  further down remains the actual "restaurant not found" source of
  truth, unchanged.
- **`backend/api/v1/orders/payment-switch-cod.php`** — same addition,
  using the order's own `restaurant_id` (already on the order row, no
  extra risk of resolving the wrong restaurant).
- **`backend/api/v1/customer/cod-eligibility.php`** and
  **`backend/api/v1/customer/payment-methods.php`** — both gained a
  new **optional** `restaurant_id` query param. When given, resolves
  that restaurant's `area_id` and passes it through. A bad/unknown id
  is tolerated (contributes nothing, same as an unassigned restaurant)
  rather than rejected — these are convenience pre-checks, not the
  source of truth, so they're deliberately more forgiving than
  `orders/create.php`'s real enforcement. Backward compatible: an
  older app build that never sends `restaurant_id` gets the exact old
  address-only answer.

## What this means in practice

- If a restaurant's `area_id` is **not set**, none of this changes
  anything for that restaurant — the restaurant side simply
  contributes platform defaults, same as before.
- If a restaurant **is** assigned to an Area/City with its own COD or
  pricing override, that override now also applies to every order for
  that restaurant, on top of whatever the customer's own delivery
  address's area already required — whichever combination is stricter.
- The Customer App itself needed **no changes** — it already only ever
  reads whatever `eligible`/`reason`/`upi_allowed`/`cod_allowed`/`fee`
  the server hands back; the combining happens entirely server-side.
  The two checkout-screen pre-check endpoints get slightly better
  answers once/if the app is updated to pass `restaurant_id` on those
  two GET calls, but nothing breaks if it isn't.

## Not done / follow-ups worth knowing about

- **App not updated to send `restaurant_id`** on
  `customer/cod-eligibility` / `customer/payment-methods` yet — those
  two pre-check screens will keep giving the old address-only answer
  until/unless the Customer App passes it. Not a bug, just an
  incomplete rollout of the new optional param on the client side.
- **No live/browser/device test** — same standing sandbox limitation
  as every other session in this project (no PHP interpreter, no DB,
  no app build tooling here). Verified via direct code reading:
  brace/paren balance on every touched file, every real call site
  re-grepped to confirm it passes the new param, no duplicate function
  definitions.
- `get_min_order_floor_for_area_id()` (restaurant's own min-order-amount
  floor, checked at profile-save time) was **not** touched — there's
  no "customer's own floor" for that one to combine against, it was
  already restaurant-area-only and stays that way.
