# Plan — Admin Analytics (2026-08-26)

## First: Financial Command Center (doc 21 §4.7) status correction

Before starting new work, checked §4.7 against what already exists —
**it's already built**, just never explicitly checked off against this
doc's section number:

- Restaurant Ledger (owes-admin / admin-owes) → `settlements.php`
  (`restaurants.current_due` + `restaurant_due_ledger` statement).
- Platform Ledger (money in/out/held/revenue) → `platform-ledger.php`
  — its four stat cards are a literal match for doc 21 §4.7's own
  example numbers.
- "Immutable ledger entries, not editable balances" → already true;
  both ledger tables are insert-only, written exclusively through
  `lib/ledger.php`'s one-function-per-transition-type functions.

What WAS wrong: two comments (in `lib/ledger.php` and
`settlements.php`) claiming `record_paid_order_ledger_entries()` was
"not called anywhere yet." That was true when first written, but the
native UPI gateway work since wired it up (`PaymentService.php`,
`orders/create.php`). Fixed both comments this session — no functional
change, just correcting stale documentation that would have misled
the next session into re-investigating a gap that's already closed.

**The one real remaining gap** (not new — already flagged, just now
confirmed current): COD orders never write a `commission_cod` ledger
entry, because nothing anywhere sets `orders.status = 'delivered'` —
there's no rider-facing API namespace at all yet. That's the Rider App
(Phase G, recall.md items 43-48) — a separate, much larger build, out
of scope here. Flagged again in both files' comments; not attempted
this session.

## Now: Admin Analytics (doc 21 §4.19)

This is the actual gap. `dashboard.php` only ever shows **today's**
numbers (by its own header's design) — there is no dated/ranged
reporting view anywhere. Doc 21 §4.19 asks for a proper analytics
module: Orders, Revenue, Restaurants, Items, Customers, Areas — all
range-able, not just "today."

### Design decisions

- **New page:** `backend/admin/analytics.php`, gated `reports_view`
  (migration 29's existing key — never wired to a page until now,
  same situation `orders_view`/`orders_manage` were in before docs/41).
- **Date range:** Today / 7 days / 30 days / Custom, per doc 21 §3.6's
  own phrasing (that section is the Restaurant-App-facing analytics
  spec; re-using the exact same range options here for the admin-wide
  equivalent keeps the two consistent). Defaults to 7 days.
- **Orders:** Total / Completed(`delivered`) / Cancelled / Rejected /
  Failed(`failed`+`expired` combined — doc 21 doesn't list `expired`
  as its own bucket, and both mean "never happened," same as
  `dashboard.php`'s existing revenue-exclusion list already treats
  them).
- **Revenue:** GMV (`grand_total` sum, non-cancelled/rejected/failed/
  expired), Platform revenue (`commission_amount + platform_fee`, same
  definition `dashboard.php` already uses), Commission
  (`commission_amount` alone, split out since doc 21 lists it
  separately from platform revenue), Discounts (`discount_amount +
  offer_discount_amount + free_delivery_discount_amount` — all three
  discount mechanisms this codebase has, per `format_order()`'s own
  field list), Refunds (`refunds.amount` where `status = 'refunded'`,
  by `refunded_at` in range, not `requested_at` — a refund only
  actually left the platform on the date it completed).
- **Restaurants:** Top 5 / Bottom 5 by revenue in range (bottom list
  requires ≥1 order in range, so a restaurant with zero orders doesn't
  crowd out one with a genuinely low but real number), plus an
  overall acceptance rate (`accepted / (accepted + rejected)` across
  every order whose restaurant has acted on it in range).
- **Items:** Top-selling (by quantity), Most profitable (by summed
  `order_items.subtotal`), Most cancelled (quantity ordered on
  `cancelled`/`rejected` orders) — all three read `order_items` joined
  to `orders` for the date filter, same join every other section here
  needs anyway.
- **Customers:** New (`customers.created_at` in range), Returning
  (placed an order in range AND has at least one order before the
  range start — i.e., not a first-timer), AOV (GMV above ÷ order
  count), LTV (lifetime, not range-scoped — total historical
  `grand_total` across each customer's own orders, averaged; doc 21
  lists it under Customers without a date qualifier, and a "range-
  scoped LTV" isn't really LTV).
- **Areas:** per-`service_areas`-node Orders/Revenue/Customers
  (distinct customers who ordered)/Restaurants (distinct restaurants
  with ≥1 order), resolved the same way docs/41's Order Control page
  resolves area — `delivery_address_id -> customer_addresses.area_id`
  — joined once per query, not per row.
- **Export:** doc 21 §3.7 (Reviews, unrelated) aside, `reports_export`
  already exists as a permission but this session only wires the
  on-screen view — CSV export is flagged as a fast, obvious follow-up
  once the app owner actually asks for it, not built speculatively
  here (doc 21 §4.19 itself doesn't call for an export format, unlike
  §4.6's explicit mention there).

### What stays out of scope

- Rider analytics (top/bottom riders, delivery time) — no Rider App
  data exists yet (see the Financial Command Center note above); the
  `riders` table has rows but no order ever actually gets ridden
  through a real API today.
- Any COD-commission figure — same reason; COD orders never reach
  `delivered`, so that revenue component is always ₹0 today, same gap
  as settlements.php's ledger view.
- A saved/scheduled-report feature — doc 21 §4.19 doesn't ask for one;
  this is a live, filtered, on-demand view like every other admin
  report page in this codebase.

## Verification note (same standing sandbox limitation)

No PHP CLI, Kotlin compiler, Gradle, or live DB here. Balance-checked
(braces/parens/brackets, `<?php`/`<?=`/`?>` tag counts) on the new
file and both comment-only edits. Still needs, before production:

1. `php -l` on `backend/admin/analytics.php`.
2. A live page load across all four date-range options (including a
   Custom range spanning zero orders — confirm every section renders
   "no data" gracefully rather than a divide-by-zero on AOV/acceptance
   rate).
3. Confirm the Areas section's numbers sum sensibly against the
   ranged Orders total above it (every order with a resolved area
   should be counted in exactly one area row).
