# Handover — Deep Plan Phase 7: Admin Cash Flow Dashboard (built, NOT device/build-verified)

**Date:** 2026-09-10 (same day, session following Phase 6's `124_Handover_...md`)
**Scope:** Deep Plan §7 (`docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md`)
**Status:** 🟡 BUILT — read-only admin page, no schema change, no lib
changes. Same standing sandbox limitation as every session before this
one: no `php`/`mysql`/`adb`, network egress still blocked. Only static
checks were possible (brace/paren balance, manual read-through).

## What Phase 7 asked for (deep-plan §7)
A single unified admin page showing, per rider and in total: cash
currently held, total ever collected, total deposited to admin, last
deposit date, over/under-limit status — plus a separately-visible
Restaurant Settlement summary block (payable/due/paid this cycle).
Explicitly "mostly a read/aggregation + UI layer" since Phases 1-6
already write everything needed.

## What's built
**New: `backend/admin/cash-flow.php`**
- Gated on `payouts_view` (same module as Settlements/Rider
  Settlements/Platform Cash Flow) — read-only, no POST handler at all.
- **Rider COD Cash section:** per-rider table (search by name) reading
  `riders.cod_cash_held` + a single `GROUP BY rider_id` aggregate query
  over `rider_cod_ledger` (`cod_collected` sum, `settlement_to_admin`
  sum, latest `settlement_to_admin` date) — one query for all riders,
  not N+1. Over-limit flag reuses `rider_cod_settlement_limit()` from
  `lib/rider_ledger.php`, same threshold `rider-settlements.php` itself
  uses. Each row links to that same page's detail view — this page
  doesn't duplicate the Record Settlement form.
- **Restaurant Settlement Summary section:** "Total payable to
  restaurants" / "Total due from restaurants" computed directly from
  `SUM(restaurants.current_due)` split by sign — a **design decision**
  worth flagging: the deep-plan's own wording said "SUM `payout_payable`
  unsettled" / "SUM `commission_cod` unsettled", but re-summing raw
  `entry_type` rows a second time risks silently disagreeing with
  `current_due` (which `lib/ledger.php`'s `write_due_ledger_entry()`
  already maintains as the authoritative running balance, and which
  `settlements.php`'s own list mode sorts/displays by). Reading
  `current_due` directly means this page can never drift from what
  Settlements already shows. "Paid to restaurants" / "Received from
  restaurants" reads `restaurant_payments` directly (`status =
  'verified'`, split by `direction`), with an optional From/To date
  filter mirroring `platform-ledger.php`'s existing pattern — defaults
  to all-time when unset.
- **Nav:** added a `cash_flow` entry to `_layout_head.php`'s nav array
  (right after `platform_ledger`, same `finance` group, `payouts_view`
  perm) and to the `$activeNav` doc-comment enum list. Labelled "Cash
  Flow" to stay distinct from the existing "Platform Cash Flow" link,
  which is a different thing (the admin's own UPIPE merchant-account
  balance — customer payments in, refunds/payouts out — not rider COD
  cash or restaurant settlement).

**No backend files modified besides `_layout_head.php`'s nav array** —
Phase 7 needed no new endpoint logic beyond the page itself; all data
already existed from Phases 1-6 and earlier (commission/settlement
work).

## Design note flagged to the owner
Deep-plan §7's table literally said "SUM `payout_payable` unsettled" /
"SUM `commission_cod` unsettled" as the source for the two due-totals.
Built instead from `restaurants.current_due` (see above) since it's
the already-authoritative signed balance and avoids a second
independent derivation that could drift. If the owner specifically
wants period-scoped commission/payout totals (e.g. "how much COD
commission accrued this month" rather than "how much is owed right
now, all-time"), that's a different, additive query
(`SUM(restaurant_due_ledger.amount) WHERE entry_type = 'commission_cod'
AND created_at BETWEEN ...`) — not built, since the deep-plan's own
"unsettled" wording reads more like a running-balance ask than a
period ask, but flagging the ambiguity rather than guessing silently.

## Static checks performed this session
- Brace `{}`/paren `()` balance on `cash-flow.php` — balanced (6/6
  braces, 115/115 parens).
- Manual read-through of every SQL string for placeholder/column-name
  correctness against the actual schema (`backend/sql/01_schema.sql`,
  `38_migration_commission_rules_and_settlement.sql`,
  `53_migration_rider_cod_ledger.sql`, `82_migration_rider_cod_
  deposit.sql`) — `riders.cod_cash_held`, `rider_cod_ledger.entry_type`
  values (`cod_collected`/`settlement_to_admin`), `restaurants.
  current_due`, `restaurant_payments.direction`/`status`/`amount`/
  `created_at` all confirmed to exist with those exact names.
- Confirmed `rider_cod_settlement_limit()` (used for the over-limit
  flag) and `admin_escape()`/`admin_require_permission()` (used for
  output/auth) are already defined in `lib/rider_ledger.php` /
  `_bootstrap.php` — no new helper needed.
- **Not** run against a real PHP/MySQL instance — no live query
  execution, no confirmation the JOINs/GROUP BY actually return the
  shape expected, no confirmation nav rendering doesn't collide with
  an existing `cash_flow`-keyed anything (grepped for it first — no
  prior use of that key existed).

## NOT verified — do this first in a real environment
1. Run against a real MySQL instance with a realistic mix of rider
   deposits (admin-manual, auto-verified UPI, admin-approved-via-UTR)
   and restaurant Pay Now settlements (both directions) — confirm every
   total on this page matches summing `rider-settlements.php` and
   `settlements.php`'s own per-row numbers by hand.
2. Confirm the From/To filter on the restaurant "Paid"/"Received"
   figures behaves correctly across a date boundary (a settlement
   recorded exactly at `payment_date` vs `created_at` — the filter uses
   `created_at`, `settlements.php`'s Pay Now form separately captures an
   editable `payment_date` that can differ from `created_at`; confirm
   with the owner which one this filter should actually key off).
3. Confirm nav renders correctly and the new "Cash Flow" link doesn't
   read as confusingly similar to "Platform Cash Flow" in practice —
   naming was a judgment call this session, open to the owner renaming
   either.

## Deliberately not touched
- No schema/migration change — nothing new to write, only new reads.
- `rider-settlements.php` and `settlements.php` themselves — left
  exactly as-is; this page links out to both rather than duplicating
  their Record Settlement / Pay Now forms.
- Did **not** correct the stale kdoc comments in `rider-settlements.php`
  (still says the COD-collected trigger "isn't wired up yet") and
  `settlements.php`/`platform-ledger.php` (still describe
  `record_cod_order_ledger_entry()` as uncalled) even though a grep
  this session confirmed both triggers **are** now called (from
  `backend/admin/orders.php` and `backend/api/v1/rider/orders-
  deliver.php`) — out of scope for a Phase 7 UI task; flagging here per
  recall.md's own rule about not silently leaving known-stale notes
  uncorrected, so a future session can fix those three kdocs directly.

## NEXT SESSION
Either (a) get this into a real build/test environment and run the
verification checklist above, resolving the `current_due`-vs-raw-sum
design question and the `created_at`-vs-`payment_date` filter question
with the owner, or (b) staying in this sandbox: the Deep Plan's Build
Order Recap (Phases 1-7) is now fully built end-to-end — no further
phases remain in that plan. A good next step would be a full read-
through pass fixing the three stale kdocs flagged above (small,
Claude-actionable, no DB needed), or picking up whatever the owner
raises next.
