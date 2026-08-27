# Handover — Admin Analytics + Ledger Comment Fix (2026-08-26)

Implements docs/43's plan. See that doc for full rationale.

## ✅ Done this session

- **Stale-comment fix, no functional change:** `lib/ledger.php` and
  `settlements.php` both claimed `record_paid_order_ledger_entries()`
  was "not called anywhere yet." It has actually been wired up since
  the native UPI gateway work (`PaymentService.php`,
  `orders/create.php` both call it). Fixed both comments so the next
  session doesn't waste time re-investigating a gap that's already
  closed. Confirmed via grep that `record_cod_order_ledger_entry()` is
  the one still-genuine gap (no rider-facing API exists at all yet),
  and clarified that in its own comment too.
- **Financial Command Center (doc 21 §4.7): confirmed already built.**
  `platform-ledger.php` + `settlements.php` already cover every line
  item that section asks for. No new page needed — see docs/43 for
  the point-by-point mapping.
- **`backend/admin/analytics.php`** (new) — doc 21 §4.19's Admin
  Analytics module:
  - Date range: Today / 7 days / 30 days / Custom.
  - Orders: Total / Completed / Cancelled / Rejected / Failed.
  - Revenue: GMV / Platform Revenue / Commission / Discounts / Refunds
    (refunds counted by completion date, not request date).
  - Restaurants: Top 5 + Bottom 5 by revenue in range, overall
    acceptance rate.
  - Items: Top-selling, Most profitable, Most cancelled (top 5 each).
  - Customers: New, Returning, Avg Order Value (range-scoped), Avg
    Customer LTV (deliberately lifetime, not range-scoped — see
    docs/43 for why that's correct here).
  - Areas: Orders/Revenue/Customers/Restaurants per resolved
    `service_areas` node, same address→area join Order Control
    (docs/41) already uses.
  - Gated `reports_view` (migration 29's existing key, unused until
    now). No export action added — flagged as a follow-up, not
    speculatively built.
- **`_layout_head.php`** — new "Analytics" nav item (after Order
  Control), gated `reports_view`; docblock's `$activeNav` list updated.

## What stays out of scope (see docs/43)

- Rider analytics — no Rider App data exists yet.
- Any COD-commission revenue figure — same root cause, always ₹0
  today.
- CSV/scheduled export — doc 21 §4.19 doesn't ask for one; can follow
  once actually requested.

## Needs a real machine, not this sandbox

Same standing limitation as every session before this one.

1. `php -l` on `backend/admin/analytics.php`, plus the comment-only
   diffs in `lib/ledger.php`/`settlements.php` (balance-checked
   programmatically — parens/braces/brackets and `<?php`/`<?=`/`?>` tag
   counts all matched; `ledger.php` has no closing `?>` by this
   codebase's own pure-PHP-library convention, not an imbalance).
2. A live page load across all four range options, including a Custom
   range with zero orders in it — confirm every section shows "no
   data" cleanly (no divide-by-zero on AOV/acceptance rate — both are
   already null/zero-guarded in code, but worth confirming on a real
   empty result set).
3. Cross-check: pick one day, sum the Areas table's Orders column,
   confirm it matches the Orders section's Total for the same range
   (every order with a resolved address should land in exactly one
   area row; orders with no address should show under "Unresolved").
4. Confirm the new nav item appears for a role with `reports_view`
   (Super Admin already has it since migration 29) and stays hidden
   for a role that doesn't.

## Suggested order for next session

1. Whatever machine access unblocks §1-4 above — same bottleneck as
   every prior session's own closing note.
2. If new feature work is wanted before that: every doc 21 module
   flagged so far (Order Control, Financial Command Center, Admin
   Analytics) is now either built or confirmed already-built. The
   remaining big items in doc 21 that don't depend on the still-
   unbuilt Rider App are worth a fresh read of `docs/21_Production_
   Feature_Gap_Plan.md` end-to-end to pick the next one — this session
   didn't do that broader pass, only followed the specific §4.6/§4.7/
   §4.19 thread from docs/39.
