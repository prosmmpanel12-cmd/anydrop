# Handover — Admin Analytics: Remaining Filters + Riders/Payments/
Coupons + Export Built (2026-08-27, session 11)

Closes PENDING.md item 2's remaining scope (State/District/Restaurant/
Category filters, Rider analytics, Payment analytics, Coupon
analytics, Export). This was the recommended pick at the top of
NEXT_SESSION_PROMPT.md going into this session, chosen because it
extends already-built code (`backend/admin/analytics.php`, doc 44)
rather than starting fresh.

**This session did NOT touch anything else in PENDING.md.** Full
Offers Engine (item 4), Restaurant Self Delivery (item 5), and
everything below them are untouched, in the exact state doc 49 left
them.

## What was verified from source before writing anything (not assumed
from docs)

- `backend/admin/analytics.php` (doc 44's version) — read in full
  first. Confirmed the existing `$range`/`$fromDate`/`$toDate` pattern,
  `$nonRevenueStatuses` list, and the Orders/Revenue/Restaurants/Items/
  Customers/Areas sections exactly as doc 44 described.
- `backend/admin/orders.php` (doc 41/42's Order Control page) — read
  in full for its filter-form HTML pattern (`<select>` dropdowns,
  `http_build_query` for pagination, `admin_escape()` everywhere) and
  its area-filter convention (`$areaFilterOptions` restricted to
  `city_village`/`area` levels only). New filters in this session copy
  that shape rather than inventing a new one.
- `backend/sql/01_schema.sql` — read `orders`, `order_items`,
  `coupon_usages`, `riders`, `restaurants` table definitions directly.
  Key finding that changed this session's scope vs. doc 44's original
  framing: **`orders.rider_id` and `riders.name` are real, populated
  columns** (Order Control's own detail view already joins on them for
  its rider-contact display). Doc 44 had flagged "Rider analytics — no
  Rider App data exists yet" as out of scope — that reasoning doesn't
  hold up against the actual schema: no Rider App/session is needed to
  report "how many delivered orders did rider X have in this range,"
  since every delivered order already has `rider_id` set. Re-derived
  the scope decision from source rather than trusting doc 44's older
  framing at face value.
- `backend/sql/30_migration_service_areas.sql` — read in full to
  confirm the geography model: **one self-referencing `service_areas`
  table** with a `level` ENUM (`state`/`district`/`city_village`/
  `area`) and `parent_id`, NOT four separate State/District/City/Area
  tables and NOT a `state_id`/`district_id` column anywhere else. This
  is why the State/District filters had to be built as a `parent_id`
  tree-walk (new `admin_area_descendant_ids()` helper) that resolves a
  State or District pick into "every leaf `service_areas` id under it"
  up front, then reuses the exact same `ca.area_id IN (...)` WHERE
  shape the pre-existing Area filter already used — one query pattern
  for all three geography filters, not three different ones.
- `backend/sql/32_migration_restaurant_categories.sql` — confirmed
  `restaurants.restaurant_category_id` → `restaurant_categories` is
  the actual FK (not an ENUM on `restaurants`, and NOT the same table
  as `menu_categories` or `food_categories` — migration 32's own
  header explicitly warns against confusing these three).
- `backend/sql/42_migration_refund_system.sql` — read `refunds` table
  definition directly to confirm `order_id`/`refunded_at`/`amount`
  column names before joining through it for the filtered Refunds
  figure in Revenue.
- `backend/admin/_bootstrap.php` — read `admin_escape()`,
  `admin_csrf_token()`, `admin_area_breadcrumb_compact()` definitions
  directly. Confirmed the breadcrumb helper's own docblock note that
  it's deliberately "smallest-first" (unlike `areas.php`'s
  `area_breadcrumb()`, which is biggest-first) — used it as-is for the
  new State/District/Area dropdown options, no new breadcrumb variant
  written.
- `backend/lib/admin_auth.php` — confirmed
  `admin_has_permission(int $adminId, string $key): bool` signature,
  same one `orders.php` already uses for its own `$canManage` flag.
- `backend/lib/audit.php` — confirmed
  `write_audit_log(string $actorType, ?int $actorId, string $action, array $details = []): void`
  signature before calling it from the new export action.
- `backend/sql/29_migration_admin_rbac.sql` — confirmed
  `reports_export` already exists as a permission key (seeded
  alongside `reports_view`), just never gated on anything until this
  session.
- Grepped all of `backend/` for `Content-Disposition` — **zero
  results**. No export pattern exists anywhere in this project to
  follow. This session's CSV export establishes the first one, rather
  than picking an existing convention.
- Grepped all of `backend/admin/` for any existing State/District
  cascading-dropdown pattern — none found; the new State→District
  dropdown pair in this page is genuinely new UI, not copied from
  elsewhere (District options are pre-filtered server-side to the
  selected State via `array_filter`, same array-filtering style the
  existing Area dropdown already used).

## What's new in `backend/admin/analytics.php`

**Filters** (all `$_GET`-driven, AND-combined, same shape as
`orders.php`):
- State (`state_id`) — new
- District (`district_id`) — new, options list narrows to the
  selected State's children once one is picked
- City/Village or Area (`area_id`) — pre-existing filter, kept as-is
- Restaurant (`restaurant_id`) — new, plain `<select>` of all
  restaurants by name
- Category (`category_id`) — new, plain `<select>` of active
  `restaurant_categories`

Precedence when more than one geography filter is somehow set: Area >
District > State (most specific wins) — Area is already a single leaf
id needing no tree-walk, District/State each resolve to a list of leaf
ids via the new `admin_area_descendant_ids()` helper.

A single `$extraWhereSql` / `$extraParams` pair is built once from
whichever filters are active, then reused across every section's query
(Orders, Revenue, Refunds, Restaurants top/bottom, acceptance rate,
Items ×3, Returning-customers, Areas, Riders, Payments, Coupons) — one
query pattern extended everywhere, not a one-off per section.

Two sections deliberately do NOT get the new filters, both flagged
inline in the file's own comments and in the page's own "Showing ..."
caption:
- **New Customers** — pure `customers` table signup count, no
  restaurant/area tie exists on that row.
- **Avg Customer LTV** — deliberately lifetime/account-wide, same
  reasoning docs/43 already gave for why it isn't range-scoped either;
  a restaurant/area filter on a lifetime-across-every-order figure
  would be a different, unasked-for metric.

**New sections:**
- **Riders** — top 10 by delivered-order count in range: rider name,
  delivered count, revenue, avg delivery time (`picked_up_at` →
  `delivered_at`, minutes). Delivered-only (matches the "delivered"
  revenue-recognition rule the rest of this page already uses).
  Deliberately does NOT touch `rider_locations` (the live GPS ping
  stream) — that's Order Control's job for a single order's tracking
  point, not a reporting page's.
- **Payments** — UPI vs COD, each as a stat card: GMV, order count,
  failed-payment count if nonzero. Off `orders.payment_method`/
  `payment_status` directly, no new table.
- **Coupons** — top 10 by usage count: code, uses, unique customers,
  total discount. Joins `coupon_usages` → `coupons` → `orders`.
  Discount figure comes from the **order's own `discount_amount`
  snapshot**, not recomputed from the coupon's current
  `discount_value` — a coupon's rules can change after the fact: the
  order row reflects what the customer actually got at checkout time.

**Export** — new `?export=csv` flag on the same GET request (added via
an "Export CSV" link next to Apply/Clear, only rendered when the admin
has `reports_export`). Gated separately from `reports_view` via
`admin_require_permission($admin, 'reports_export')` at the top of the
export branch — a `reports_view`-only admin sees the page but not the
Export link, and hitting the URL directly without `reports_export`
gets whatever `admin_require_permission()`'s own standard rejection
is. Streams a CSV (Orders / Revenue / Restaurants top+bottom / top
items / Customers / Areas / Riders / Payments / Coupons, each as its
own labeled block) via `fputcsv()` straight to `php://output`, same
filters and range as whatever the admin was looking at. Writes an
`analytics_exported` audit log entry (range + from/to dates) on every
export, same `write_audit_log()` call shape every other admin action
in this project already uses.

No new migration — every column touched (`orders.rider_id`,
`restaurants.restaurant_category_id`, `service_areas.level`/
`parent_id`, `coupon_usages.*`, `refunds.*`) already existed before
this session.

## Cross-checked before finishing (same substitute as every prior
session — no PHP CLI in this sandbox)

- Parens/braces/brackets balance-counted programmatically across the
  whole file: 459/459 parens, 42/42 braces, 199/199 brackets — all
  matched.
- PHP tag counts: 64 `<?php` + 68 `<?=` = 132, matching 132 `?>` closes
  exactly.
- Alt-syntax control structures in the HTML-rendering half of the file
  counted separately from the brace-style PHP-logic half (a naive
  whole-file `if`/`endif` count mixes both styles and looks
  mismatched even when correct) — isolated the HTML section
  specifically and got 12 `if(...):` opens / 12 `endif`, 14
  `foreach(...):` opens / 14 `endforeach`. Both balanced.
- Manually re-read the full rendered file top to bottom once assembled
  (not just the diffs) to catch anything the balance-counts wouldn't
  (wrong variable name, wrong array key, a filter silently not wired
  into one section's query) — this is how the `$areaNodeById` was
  caught being built twice (top-of-file for the dropdown walk, and
  again lower down for the Areas section as in doc 44's original) and
  de-duplicated to a single build, reused everywhere.

## What stays out of scope (flagged, not forgotten)

- **Commission analytics as its own section** — PENDING.md's original
  wishlist under item 2 didn't list this as a separate line (only
  "Commission" appears inside the existing Revenue stat cards, which
  doc 44 already built and this session didn't touch). Not treated as
  a gap.
- **City/Village as a filter distinct from "Area"** — the existing
  Area dropdown (`$areaFilterOptions`) already includes both
  `city_village` and `area` level nodes together, same restriction
  `orders.php` uses for its own Area filter and the same reasoning:
  those are the only two levels that ever actually appear on a
  resolved `customer_addresses.area_id`, so a State or District node
  could never match an order's area_id, and listing City/Village
  separately from Area would just split one already-working dropdown
  into two for no behavioral gain.
- **COD-commission revenue figure** — doc 44 flagged this as "always
  ₹0 today" for a structural reason unrelated to this session's work;
  not re-investigated, no evidence it changed.

## Needs a real machine, not this sandbox

Same standing limitation as every session in this project's history —
no PHP CLI, no Android SDK/Gradle, no live DB, no device here.

1. `php -l backend/admin/analytics.php`.
2. Load the page with no filters — confirm all 9 sections (Orders,
   Revenue, Restaurants, Items, Customers, Areas, Riders, Payments,
   Coupons) render without error across Today/7d/30d/Custom.
3. Pick a State, confirm the District dropdown narrows to that
   State's children on the next page load (it's server-filtered via
   `$_GET['state_id']`, not JS-driven — confirm the page reload
   actually re-populates it, since there's no `onchange="this.form.submit()"`
   on the State select currently, only on Range).
4. Pick a Restaurant filter, confirm every section's numbers actually
   narrow to that restaurant only, including Riders (a rider who never
   delivered for that restaurant should disappear) and Coupons (a
   coupon never used at that restaurant should disappear).
5. Pick an Area/District/State filter and a Restaurant filter
   together, confirm they AND-combine correctly (an order must match
   BOTH to count) rather than OR.
6. Confirm New Customers and Avg LTV visibly do NOT change when a
   Restaurant/Area/Category filter is applied — this is by design (see
   above), worth confirming it isn't accidentally filtered too since
   both figures share `$extraWhereSql`'s presence to reason about but
   deliberately don't reference it in their own queries.
7. Click Export CSV with a role that has `reports_export` — confirm
   the file downloads with the right filename
   (`anydrop_analytics_<from>_to_<to>.csv`) and every section appears
   as a labeled block with correct figures matching what the HTML page
   showed for the same filters.
8. Try hitting `?export=csv` directly as a role with `reports_view`
   but NOT `reports_export` — confirm it's rejected the same way any
   other `admin_require_permission()` failure in this project already
   is, and that the Export link itself is invisible to that role on
   the normal page.
9. Confirm the `analytics_exported` audit log entry actually appears
   in `admin_logs`/wherever `write_audit_log()` writes to, with the
   correct range/dates.
10. Zero-data edge case: a Restaurant/Area/Category filter combination
    that matches nothing — confirm every section shows its existing
    "no data"/"No orders in range" empty states cleanly (no
    divide-by-zero; `$acceptanceRate`, `$aov` were already
    null/zero-guarded before this session and that guard logic wasn't
    touched, but worth confirming live against a genuinely empty
    filtered result specifically, not just an empty date range as doc
    44's own checklist covered).

## Recommended next session

PENDING.md item 2 (Admin Analytics) is now fully closed on the
"written" side (Implemented ≠ Tested still applies — see the
verification checklist above and PENDING.md's own item 34 completion
rule; this is NOT ready to move to `done.md` yet).

With items 1 (Admin Order Control) and 2 (Admin Analytics) now both
written, **PENDING.md's P0 list is fully written-out** except
Restaurant Insights' own flagged leftovers (Peak hours needs a design
decision; Export PDF/Excel has no pattern to extend yet — see doc 49).

Next natural pick, per PENDING.md's own P1 ordering:

**Item 4 — Full Restaurant Offers Engine finish.** Read
`docs/33_Handover_2026-08-25_Offer_Coupon_Toggle_And_Badges_Extended_Partial.md`
FIRST — this work was left mid-session and needs picking up carefully,
not restarted from scratch. Per PENDING.md's own item 4 framing:
migration 48's offer_tag badges on Home/Search are partially wired,
but the Offers category chip + browse screen backend and the checkout
offer-strip UI wiring were left unfinished.

If the app owner instead wants machine verification before more
feature work, running through this doc's own checklist above (plus
doc 49's still-open Restaurant Insights checklist, and doc 44's
original Analytics checklist which items 1-6 above supersede/extend)
is the other valid next step — same standing choice every session in
this project's history has flagged.

Item 5 (Restaurant Self Delivery, not started) is next after item 4 if
continuing straight down PENDING.md's P1 order.
