# Plan — Admin Order Control (2026-08-26)

**Status:** IN PROGRESS

## Why this feature, why now

docs/39's "suggested order for next session" flagged this as the only
item left from docs/29-38's closing list that doesn't touch the
offers/coupon system that thread had been following. It also maps
directly onto `docs/21_Production_Feature_Gap_Plan.md` §4.6, which this
plan implements.

## Scope (per doc 21 §4.6)

- Admin sees every order, across every restaurant/customer/rider.
- Filters: Order ID, Customer, Restaurant, Rider, Status, Payment,
  Date, Area.
- Detail view: Customer, Restaurant, Items, Pricing, Payment,
  Timeline, Rider, Location, OTP, Cancellation, Refund.
- "Admin override actions should be heavily permission-controlled and
  always logged."

## Design decisions

- **New page:** `backend/admin/orders.php`, gated `orders_view` (list)
  / `orders_manage` (override actions) — both permissions already
  exist in `admin_permissions` since migration 29, just never wired to
  a page. No new migration needed for the page itself.
- **List query pattern:** same filter/pagination shape as
  `customers.php` (dynamic `$where`/`$params`, `LIMIT`/`OFFSET`,
  `http_build_query` pagination links) rather than inventing a new one.
- **Area filter:** orders has no `area_id` column directly — resolved
  via `delivery_address_id -> customer_addresses.area_id`, same join
  path `customers.php` already reads for its own address breadcrumbs.
  A LEFT JOIN, not a subquery, since it's also needed for display.
- **Detail view = `<dialog>` modal per row**, same as `customers.php`'s
  per-customer modal — avoids a second full page/route for what's
  fundamentally "show me everything about one order."
- **Timeline** = `order_status_history` rows for that order, already
  populated by every existing status-changing endpoint via
  `insert_status_history()` — no new table, just a read.
- **Rider location** = latest `rider_locations` row for that order
  (`ORDER BY recorded_at DESC LIMIT 1`), not the live-tracking stream —
  this is a static admin review page, not `LiveTrackingActivity`'s
  moving map.
- **OTP:** shows `delivery_otp` (masked, revealed on click) +
  `otp_verified_at`/`otp_attempts` — for support resolving "rider says
  customer wouldn't give the code" disputes. Gated the same as the
  rest of the detail view (`orders_view`), not a separate permission —
  doc 21 explicitly lists OTP as one of the fields this page shows.
- **Refund:** reads the linked `refunds` row (if any) via
  `get_refund_for_order()` — read-only here; refund *lifecycle*
  actions (Approve/Reject/Mark Processing/Mark Refunded) stay on
  `refunds.php`, which already owns that whole flow. Duplicating those
  buttons here would split one state machine across two pages.

### The one override action this session adds: Force-Cancel

Doc 21 §4.6 asks for admin override actions generically without
listing exactly which ones. Rather than inventing several
under-specified actions, this session adds the one with a real,
already-modeled gap: **today, ONLY the customer (within a short
cancel window) or the restaurant (via reject) can cancel an order.**
There is no path for an admin to step in — e.g. a customer calls
support because a restaurant went unresponsive past the app's own
reject flow, or a rider never showed up. `Force-Cancel` closes that
gap:

- Allowed from any *non-terminal* status (`pending`, `accepted`,
  `preparing`, `ready`, `rider_assigned`, `picked_up`,
  `out_for_delivery`) — not from `delivered`/`cancelled`/`rejected`/
  `refunded`/`failed`/`expired`, same terminal-state boundary
  `cancel.php`/`orders-reject.php` already respect (there's no
  "un-deliver" action, and the other four are already-terminal).
- Reuses the **exact same** two calls those endpoints already make —
  `insert_status_history($db, $orderId, 'cancelled', 'admin', $admin['id'], $reason)`
  and, if `payment_status === 'paid'`,
  `create_refund_request($db, $order, $reason, 'admin')` — instead of
  a bespoke admin-only status-flip, so this path produces the exact
  same downstream effects (refund queue entry, notification) any other
  cancellation already does. `initiated_by = 'admin'` already exists
  in both tables' ENUMs for this reason (migration 42's `refunds.
  initiated_by`, `order_status_history.changed_by_type`) — nothing new
  to add there.
- Reason is a **required** free-text field (no reuse of the
  customer/restaurant reason strings — an admin override reason is
  its own audit trail, distinct from why the customer/restaurant would
  have cancelled).
- Every use additionally calls `write_audit_log('admin', $admin['id'],
  'order_force_cancelled', ['order_id' => ..., 'from_status' => ...,
  'reason' => ...])` — the "always logged" half of doc 21's
  requirement, on top of the normal `order_status_history` row (which
  is order-scoped and customer/restaurant-visible; the audit log is
  the admin-accountability record).
- Gated on `orders_manage`, separate from `orders_view` — matches the
  view/manage split every other admin page in this codebase already
  uses (`refunds_view`/`refunds_manage`, `customers_view`/
  `customers_suspend`, etc.).

**What this does NOT add:** reassigning a rider mid-order, editing
order items/pricing after placement, or a generic "set status to X"
dropdown. None of those were asked for by doc 21's actual field list,
and a generic status-setter would let an admin skip the same
transition guards (cancel window, terminal-state checks) every other
write path enforces — worth a separate, deliberately-scoped follow-up
if the app owner actually wants it, not bundled into this one.

## What stays out of scope

- Financial Command Center (§4.7) and Admin Analytics (§4.19) — both
  flagged in the prior session as depending on this page's data model
  existing first; not started this session.
- Refund lifecycle actions — stay on `refunds.php` (see above).
- Live rider tracking map — stays on whatever the existing live-
  tracking feature already is; this page shows a static last-known
  point only.

## Verification note (same standing limitation as every prior session)

No PHP CLI, Kotlin compiler, Gradle, or live DB in this sandbox.
Balance-checked (braces/parens/tag pairing) on every edited/new file.
Still needs, before production:

1. `php -l` on `backend/admin/orders.php`.
2. A live page load with a real DB — confirm filters narrow correctly,
   pagination works past page 1, and the area-join doesn't fatal for
   an order whose address has no resolved `area_id`.
3. A real Force-Cancel click-through: force-cancel a `paid` UPI order
   → confirm it lands in `refunds.php`'s queue exactly like a normal
   customer cancellation would; force-cancel a COD order → confirm no
   refund row is created (nothing to refund); attempt force-cancel on
   an already-`delivered` order → confirm the button isn't even shown.
4. Confirm the new `orders_view`/`orders_manage`-gated nav item only
   appears for roles that already have those permissions (both existed
   since migration 29, so an existing Super Admin should see it
   immediately with no re-grant needed).
