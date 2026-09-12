# Handover 130 — 2026-09-11

## Rider offer timeout (30s → 3 min), earning preview, and restaurant/admin commission visibility — BUILT, not build/device-verified

Two unrelated app-owner asks, done in the same session:

1. Rider order-accept screen: show distance (**already existed**), raise the
   accept-decision window from the deep-plan's original 40s default to
   3 minutes, and show the rider what they'd actually earn before they
   accept.
2. Restaurant Statement screen: show payment received / commission
   taken / net payable per order and per day. Same numbers surfaced on
   the admin Cash Flow dashboard too, period-scoped.

Both are additive — no existing endpoint's response shape lost fields,
no existing column removed, no migration is destructive.

---

### 1. Rider assignment timeout — 40s → 180s

Doc 85 (Phase 3, R3 Assignment Engine) set `rider_assignment_timeout_seconds`
to 40 as a deep-plan-suggested default. App owner asked for 3 minutes.

- **`sql/72_migration_rider_assignment_engine.sql`** — seed value edited
  in place, 40 → 180, so a *fresh* install gets the right default.
- **`sql/84_migration_rider_assignment_timeout_180s.sql`** (NEW) —
  editing 72's seed does nothing for a database that already ran it,
  since 72's own `INSERT ... ON DUPLICATE KEY UPDATE key = key` is a
  deliberate no-op on conflict (so a later admin edit to the setting
  is never silently clobbered by re-running 72). 84 explicitly
  `UPDATE`s the existing row to 180, with an `INSERT ... WHERE NOT
  EXISTS` safety net underneath for the (shouldn't-happen) case where
  72 never ran at all. **Run 84 on every existing database** — editing
  72 alone does nothing post-install.
- **`lib/dispatch.php`** — `get_setting('rider_assignment_timeout_seconds', 40)`
  fallback default also bumped to 180, so a DB missing the row entirely
  (shouldn't happen, but matches 84's own defensive stance) still gets
  3 minutes, not 40s.

No Android change needed here — `expiresInSeconds` was already read
live from the API response and the countdown was never hardcoded
client-side (see `RiderDashboardActivity.kt`'s existing offer-timer
code, doc 85). Changing the DB value is the entire fix.

### 2. Rider earning preview on the offer card

Previously the offer card showed distance/items/payment method but not
what the rider would earn — they only found out after accepting.

- **`api/v1/rider/orders-available.php`** — now also selects
  `o.delivery_charge` and calls `calculate_rider_earning()` (the exact
  same pure function in `lib/rider_earnings.php` that
  `record_rider_delivery_earning()` calls at actual delivery time) to
  compute `estimated_earning`, added to the offer response. This is
  read from the *same* function as the real payout, not a duplicated
  formula — the preview can't drift from what the rider actually gets
  credited later, short of a mid-flight `delivery_charge` change on the
  order (not currently possible once an offer is out).
- **`Models.kt`** (rider) — `Offer.estimatedEarning: Double? = null`.
  Nullable-with-default is defensive only; the backend always sends it
  now, but this avoids a crash if some other cached/mocked response
  shape is ever fed through.
- **`RiderDashboardActivity.kt`** — `renderOffer()` now sets a new
  `offerEarning` TextView to "You'll earn ₹X" (string resource
  `dashboard_offer_earning_format`), hidden (not shown as "₹0") if the
  field is somehow null.
- **`activity_rider_dashboard.xml`** — new `offerEarning` TextView
  added between the existing `offerDetails` line and the accept/reject
  button row, styled bold green (`@color/anydrop_green`, already used
  elsewhere on this screen for the accept button/timer).

### 3. Restaurant Statement — commission + net payable

`commission_amount` already existed per-order on this endpoint and in
the Android model — it just was never rendered anywhere on the
Statement screen, and there was no "net payable" figure at all
(per-order or as a daily total).

- **`api/v1/restaurant/statement.php`**:
  - Per-order: added `net_payable` = `grand_total - commission_amount`.
    Deliberately **not** gated on delivered/settlement status — it's a
    plain arithmetic fact about the order regardless of where it sits
    in the settlement pipeline; a not-yet-settled order still has a
    well-defined net payable, it just hasn't been paid out yet.
  - Summary: added `total_commission` and `total_net_payable`, summed
    only over `delivered` orders — same revenue definition
    `admin/settlements.php`'s `$payoutNonRevenueStatuses` exclusion and
    this endpoint's existing `total_amount`/`settled_amount` already
    use, so nothing about what counts as "revenue" changed, only what's
    derived from it.
- **`Models.kt`** (restaurant) — `StatementSummary.totalCommission` /
  `.totalNetPayable`, `StatementOrder.netPayable`, all `= 0.0` defaults
  for the same defensive-only reason as the rider side above.
- **`StatementActivity.kt`** — populates two new summary TextViews,
  `statCommissionAmount` and `statNetPayableAmount`.
- **`StatementOrderAdapter.kt`** — new `orderCommissionNet` TextView per
  row, "Comm. ₹42 · Net ₹378" (string `statement_row_commission_net_format`).
- **`activity_statement.xml`** — two new stat rows under the existing
  Settled/Pending pair, same card, separated by a thin divider:
  Commission (red, `@color/error_fg`) / Net Payable (bold, `@color/text_primary`).
- **`item_statement_order.xml`** — new small secondary-color line under
  the order amount, above the settlement badge.
- **`strings.xml`** (restaurant) — `statement_commission_label`,
  `statement_net_payable_label`, `statement_row_commission_net_format`.

### 4. Admin Cash Flow — period-scoped revenue/commission/net

The existing Restaurant Settlement Summary section on `cash-flow.php`
only showed **running-balance** figures (`current_due`-derived
Payable/Due) plus **all-time-or-filtered settlement-record** figures
(Paid/Received, from `restaurant_payments`). Neither of those answers
"how much order revenue came in this period, how much commission was
that, what do restaurants net" — which is a third, genuinely different
question from both (see the section's own expanded kdoc for why these
aren't reconciled against each other on this page: `current_due` is a
running balance that carries forward across periods, while this new
row resets to whatever the date filter covers).

- **`admin/cash-flow.php`** — new query block right after the existing
  `$paidStmt` totals, same optional `$fromDate`/`$toDate` filter
  (reused via a fresh `$revenueWhere`/`$revenueParams` pair — kept
  separate from `$payWhereSql` only because the bound-parameter names
  needed to be unique per prepared statement, not because the date
  range itself differs). Sums `grand_total`, `commission_amount`, and
  `grand_total - commission_amount` from `orders WHERE status =
  'delivered'` — same delivered-only definition as everywhere else in
  this codebase that computes restaurant revenue.
  - New vars: `$totalOrderRevenue`, `$totalCommissionTaken`,
    `$totalNetToRestaurants`.
- UI: a new `<p class="muted">` explainer + a second `.grid` of 3 stat
  cards, directly under the existing Payable/Due/Paid/Received grid in
  the Restaurant Settlement Summary section. Labels say "This Period" /
  "All Time" exactly like the existing Paid/Received cards already do,
  driven by the same `$fromDate !== '' || $toDate !== ''` check.
- File-level kdoc's Section 2 description extended to document this
  addition and explicitly note the two rows are answering different
  questions on purpose.

---

### Not done / explicitly out of scope this session

- **Build/device verification** — same standing sandbox limitation as
  every phase before this one (no PHP/MySQL/Android SDK available
  here). All four PHP files brace/paren-balance-checked; all four
  Kotlin files brace/paren-balance-checked. No syntax-level issue
  found, but nothing has actually been run.
- **Rider app: no equivalent "period revenue" summary was requested or
  built** — the ask was specifically about the *offer card* (distance/
  timeout/earning), which is per-delivery, not a period rollup. Rider
  earnings history/statement already existed pre-session
  (`rider/statement.php`) and was not touched.
- **Admin Cash Flow's new revenue row is NOT reconciled against
  `current_due`** — by design, per this doc's §4 explanation above. If
  the app owner later wants a reconciliation check here (the way
  Section 3's Platform Cash Flow already has one), that's new scope,
  not a bug in what's built.
