# Handover — Deep Plan Phase 6: Rider App Deposit Tracking History (built, NOT device/build-verified)

**Date:** 2026-09-10 (session following Phase 5's `123_Handover_...md`, which
this session re-verified was actually already fully complete — see
recall.md's 2026-09-09 "same day, continued" entry for that re-check;
nothing further needed there this session).
**Scope:** Deep Plan §6 (`docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md`)
**Status:** 🟡 BUILT — backend + Android both built this session. Nothing
device/build-verified — same standing sandbox limitation every session
here has had: no `php`, `mysql`, or `adb`/Android SDK binaries, network
egress blocked (confirmed again this session with a live `curl`, 403).
Only static checks were possible: brace/paren balance on every touched
`.kt`/`.php` file, XML well-formedness on every touched layout/strings
file, and a manual cross-check of every view ID a Kotlin file
references against the XML that's supposed to define it (view binding
would otherwise fail to compile silently until a real Gradle build).

## What Phase 6 asked for (deep-plan §6, verbatim)
> New section (can live inside Statement screen) — list of past
> deposits: date, amount, status (auto-verified / manual / pending),
> reference. Pulls from `rider_cod_ledger WHERE entry_type =
> 'settlement_to_admin'`.

## Design decision made this session
`rider_cod_ledger` rows with `entry_type = 'settlement_to_admin'` come
from two different origins (established back in Phase 5, migration
82's own header comment):
- `payment_transaction_id IS NULL`, `created_by = 'admin'` — admin's
  pre-existing manual "Record Settlement" button
  (`admin/rider-settlements.php`, `record_rider_settlement()`).
- `payment_transaction_id` set, `created_by = 'system'` — a rider's own
  Phase 5 self-service UPI deposit (`record_rider_cod_deposit_via_upi()`).

For the second kind, `payment_transactions.verified_by_admin_id` tells
auto-verify apart from admin-approved-via-UTR (the exact same column
`admin/payment-pending.php`'s own review queue already keys off of —
not a new concept, reused). So the three statuses the deep-plan asked
for map as:

| Status shown | Condition |
|---|---|
| `auto_verified` | `payment_transaction_id` set AND `verified_by_admin_id IS NULL` |
| `manual_review` | `payment_transaction_id` set AND `verified_by_admin_id IS NOT NULL` |
| `manual_settlement` | `payment_transaction_id IS NULL` (admin's manual button, no UPI txn at all) |

The deep-plan's own wording said "pending" as one of the three example
statuses, but a `rider_cod_ledger` row is only ever written **after**
money has actually moved (the ledger write is the confirmation event
itself, per `write_rider_cod_ledger_entry()`'s own contract) — there is
no "pending deposit" ledger row to show; an in-flight, not-yet-confirmed
deposit only exists as a `payment_transactions` row the rider is
currently polling on `PayCodDepositActivity`, which is a different
screen/concern (Phase 5) and deliberately NOT duplicated into this
history list. Flagging this interpretation to the owner in case
"pending" was meant to appear here after all — if so, the fix is small
(surface any `payment_transactions WHERE purpose='rider_cod_deposit'
AND status IN ('initiated','utr_pending_window','utr_available',
'utr_submitted')` row for this rider, no ledger row exists for those
yet).

## What's built (backend)

**New: `backend/api/v1/rider/cod-deposit-history.php`**
- `GET`, rider auth via `require_auth('rider')`, no query params.
- Single query: `rider_cod_ledger` LEFT JOIN `payment_transactions` on
  `payment_transaction_id`, filtered to this rider +
  `entry_type = 'settlement_to_admin'`, `ORDER BY created_at DESC`,
  capped at the last 200 rows (same "always small, no real pagination
  needed" reasoning `statement.php`'s own kdoc already uses for a
  single day — 200 is a sane ceiling, not an expected real count).
- `amount` is stored negative for this entry_type in the ledger (the
  signed-amount convention `write_rider_cod_ledger_entry()` uses) —
  the endpoint flips it positive for display; this screen only ever
  shows deposits, never a mixed ledger.
- `reference` prefers `utr`, falls back to `provider_bank_ref`, falls
  back to `provider_txn_id` (order swapped between the two branches —
  auto-verify rows are more likely to have a `provider_bank_ref`
  first, per migration 42's dedupe note; manual-review rows are more
  likely to have a customer-typed `utr` first — either way, whichever
  is actually populated wins, this is just tie-break ordering when
  more than one happens to be set).
- Read-only — writes nothing, changes no existing table or function.

**No backend files modified** — this phase's data (`rider_cod_ledger`,
`payment_transactions.verified_by_admin_id`) already existed in full
from Phase 5 and earlier; only a new read endpoint was needed.

## What's built (Android — Rider App)

- **`network/Models.kt`** — added `RiderDepositHistoryItem` (id,
  createdAt, amount, status, reference, note) and
  `RiderDepositHistoryResult` (wraps `deposits: List<...>`).
- **`network/ApiService.kt`** — added `getRiderDepositHistory()`, a
  direct `GET rider/cod-deposit-history.php` call, same direct-filename
  convention every rider endpoint uses.
- **`ui/statement/DepositHistoryAdapter.kt`** (new) — RecyclerView
  adapter for the deposit list, same no-pagination/re-fetch-whole-list
  pattern as `StatementOrderAdapter`. Status pill uses the exact same
  bg/fg color-pair + `@drawable/bg_status_pill` + `backgroundTintList`
  convention `RiderPayoutAdapter` already established (not a raw fill
  color) — `auto_verified` → `status_approved_*` (green),
  `manual_review` → `status_pending_*` (yellow), `manual_settlement` →
  `status_suspended_*` (grey, neutral — matches how `doc_not_submitted_*`
  and `status_suspended_*` are already used elsewhere for "neutral,
  not a review state" rows). Reference line falls back to the ledger
  `note` when `reference` is null (true for every `manual_settlement`
  row, since there's no `payment_transactions` row behind those at
  all).
- **`res/layout/item_deposit_history.xml`** (new) — CardView row,
  amount/date/reference stacked left, status pill right, same shape
  family as `item_statement_order.xml`/`item_rider_payout.xml`.
- **`res/layout/activity_statement.xml`** (modified) — this is the
  "can live inside Statement screen" part of the deep-plan wording:
  - Added an Orders/Deposits tab toggle (two `TextView`s,
    `tabOrders`/`tabDeposits`) between the header row and the
    summary-card/list area, styled with the same `bg_status_pill`
    drawable + tint-swap pattern used elsewhere in this app for
    pill-shaped UI, not a new drawable.
  - Gave the existing summary `CardView` an id (`summaryCard`, it had
    none before) so it can be hidden on the Deposits tab — that card's
    fields (orders delivered, earnings, COD collected, cash held) are
    Orders-tab-only, all-time deposit history has no equivalent daily
    summary to show.
  - `contentList` (RecyclerView) and `emptyState` (TextView) are
    **shared** between both tabs — the adapter is swapped and
    `emptyState`'s text is swapped, rather than building two separate
    list areas.
  - `btnPickDate` hides on the Deposits tab (that list isn't
    date-scoped — it's the rider's whole history, same "whole list,
    no per-day filter" reasoning the endpoint itself uses).
- **`ui/statement/StatementActivity.kt`** (modified) —
  - Renamed the single `adapter` field to `ordersAdapter` and added
    `depositsAdapter`; `contentList.adapter` is swapped in
    `selectTab()`.
  - New `selectTab(deposits: Boolean)` — toggles tab visual state
    (active/inactive tint+text color), shows/hides `summaryCard` and
    `btnPickDate`, swaps `emptyState`'s message, swaps the adapter, and
    triggers `loadDepositHistory()` the first time the Deposits tab is
    opened (guarded by `depositsLoadedOnce` so re-tapping the tab
    doesn't re-fetch).
  - New `loadDepositHistory()` — same try/catch/finally shape as the
    existing `loadStatement()`, its own `InAppNotifier` error string
    (`deposit_history_load_error`), and correctly gates
    `emptyState.visibility` updates on `showingDeposits` so a slow
    Orders-tab response that lands after the user has already switched
    to Deposits (or vice versa) can't stomp on the wrong tab's empty
    state.
  - `swipeRefresh`'s pull-to-refresh now branches: reloads whichever
    tab is currently active, not always the Orders statement.
- **`res/values/strings.xml`** — added `statement_tab_orders`,
  `statement_tab_deposits`, `deposit_history_empty`,
  `deposit_history_load_error`, `deposit_status_auto_verified`,
  `deposit_status_manual_review`, `deposit_status_manual_settlement`.

## Static checks performed this session (not a substitute for a real build)
- Brace/paren balance: `cod-deposit-history.php`, `StatementActivity.kt`,
  `DepositHistoryAdapter.kt`, `Models.kt`, `ApiService.kt` — all
  balanced.
- XML well-formedness: `activity_statement.xml`,
  `item_deposit_history.xml`, `strings.xml` — all parse clean.
- Every view id `StatementActivity.kt` references via `binding.*`
  (`tabOrders`, `tabDeposits`, `summaryCard`, `contentList`,
  `emptyState`, `btnPickDate`, `btnBack`, `swipeRefresh`,
  `statOrdersDelivered`, `statEarnings`, `statCodCollected`,
  `statCashHeldNow`) cross-checked against `activity_statement.xml` —
  all present, no mismatches. Same cross-check for
  `DepositHistoryAdapter.kt`'s `binding.*` refs
  (`depositAmount`, `depositDate`, `depositReference`,
  `depositStatusChip`) against `item_deposit_history.xml` — all
  present.
- `@drawable/bg_status_pill` confirmed to already exist (Phase 5/
  earlier phases) — not a new drawable.

## NOT verified — do this first in a real environment
1. Run against a real PHP/MySQL setup: confirm `cod-deposit-history.php`
   actually returns the three statuses correctly for a mix of
   admin-manual, auto-verified, and admin-approved-via-UTR rows for
   the same rider.
2. Android Studio/Gradle compile check — view binding class generation
   for the two new/modified layouts was only manually cross-checked
   against Kotlin usage, never compiled.
3. Full click-through: open Statement → tap Deposits tab → list loads
   → pull-to-refresh only refreshes the Deposits list, not Orders →
   switch back to Orders tab → date picker reappears and still works →
   empty states render correctly for a rider with zero deposits ever.
4. Confirm the "pending" wording question above with the owner before
   assuming the current three-status interpretation is final.

## Deliberately not touched
- No change to `PaymentService.php`, `rider_ledger.php`, migration 82,
  or any Phase 5 file — Phase 6 is read-only on top of what Phase 5
  already wrote.
- No change to `admin/rider-settlements.php` or
  `admin/payment-pending.php` — this phase is rider-app-only, per the
  deep-plan's own Build Order Recap (admin Cash Flow overhaul is
  Phase 7, explicitly last, explicitly wants 1–6 done first).

## NEXT SESSION
Either (a) get this into a real build/test environment and run the
checklist above, or, staying in this sandbox: Phase 7 — Admin Cash
Flow Page Overhaul (deep-plan §7) is next in the Build Order Recap,
and per the deep-plan's own note is "mostly a read/aggregation + UI
layer" since Phases 1–6 already write everything it needs to
aggregate.
