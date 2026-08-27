# Handover — Admin Order Control (2026-08-26)

Implements docs/41's plan in full. See that doc for the design
rationale; this is just the "what actually landed" summary.

## ✅ Done this session

- **`backend/admin/orders.php`** (new) — full Order Control page per
  doc 21 §4.6:
  - Filters: Order ID, Customer (name/mobile), Restaurant, Rider,
    Status, Payment method, Payment status, Area, Date range.
    Area resolved via `delivery_address_id -> customer_addresses.
    area_id`, same join path `customers.php` already uses.
  - List: paginated (20/page), same pattern as `customers.php`.
  - Detail (`<dialog>` modal per row, reuses `format_order()` from
    `lib/orders.php` for items/timeline/refund): Customer, Restaurant,
    Delivery address + resolved area, Rider + last known location
    (latest `rider_locations` row for that order — static snapshot,
    not the live-tracking stream), Items, Pricing breakdown, Payment,
    Delivery OTP (masked, click to reveal), Timeline
    (`order_status_history`), Cancellation reason (if any), Refund
    summary (read-only — links conceptually to `refunds.php` for
    actions).
  - **Force-Cancel** override action: admin can cancel any order still
    in a non-terminal status, with a required reason. Reuses the exact
    same `insert_status_history()` + `create_refund_request()` calls
    `cancel.php`/`orders-reject.php` already make (not a bespoke
    status-flip), so it produces identical downstream effects. Every
    use also writes an `order_force_cancelled` row via
    `write_audit_log()` — the "always logged" half of doc 21's
    requirement.
  - Gated `orders_view` (list/detail) / `orders_manage` (Force-Cancel)
    — both permissions already existed since migration 29, just never
    wired to a page until now. No new migration needed.
- **`backend/admin/_layout_head.php`** — new "Order Control" nav item
  (between Customers and Service Areas), gated `orders_view`; docblock's
  `$activeNav` list updated to include `'orders'`.

## What stays out of scope (see docs/41 for the reasoning)

- Refund lifecycle actions (Approve/Reject/Mark Processing/Mark
  Refunded) — unchanged, still live only on `refunds.php`.
- No rider reassignment, no post-placement item/price editing, no
  generic "set status to X" dropdown — only Force-Cancel, since that
  was the one gap with no existing path at all.
- Financial Command Center (§4.7) / Admin Analytics (§4.19) — not
  started; both were flagged last session as depending on this page's
  data model existing first, which it now does.

## Needs a real machine, not this sandbox

Same standing limitation as every session before this one — no PHP
CLI, Kotlin compiler, Gradle, or live DB here.

1. `php -l` on `backend/admin/orders.php` and the two-line
   `_layout_head.php` diff — hand balance-checked (parens/braces/
   brackets counted programmatically, `<?php`/`<?=`/`?>` tag counts
   matched: 123 opens, 123 closes) but never compiler-checked.
2. A live page load — confirm every filter narrows results correctly,
   pagination works past page 1, and the area LEFT JOIN doesn't fatal
   for an order whose address has no resolved `area_id` (should just
   show "All" / no area, not an error).
3. Force-Cancel click-through: force-cancel a `paid` UPI order still
   `preparing` → confirm a `requested` refund row appears on
   `refunds.php` exactly like a normal cancellation would, and that
   the `order_status_history`/`audit_logs` rows both land. Force-cancel
   a `cod`/unpaid order → confirm NO refund row is created. Try to
   force-cancel an already-`delivered` order → confirm the button
   isn't even rendered (not just rejected server-side).
4. Confirm the new nav item appears immediately for an existing Super
   Admin (no re-grant needed, since `orders_view`/`orders_manage` have
   existed since migration 29) and is correctly hidden for a narrower
   role that was never granted `orders_view`.

## Suggested order for next session

1. Whatever machine access unblocks §1-4 above.
2. If new feature work is wanted before that: Financial Command Center
   (doc 21 §4.7) is the natural next module — it's the one doc 21
   itself calls "one of the most important modules," and Order
   Control's data (now visible/filterable) is exactly what it needs to
   summarize (restaurant ledger, platform ledger, payout amounts).
   `platform-ledger.php` and `settlements.php` already exist and cover
   pieces of this — worth reading both before planning §4.7 to see how
   much is already there versus net-new.
