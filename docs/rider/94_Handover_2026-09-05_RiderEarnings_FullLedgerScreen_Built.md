# Handover — Rider Earnings Screen (Full Ledger), BUILT

Session date: 05 Sep 2026. Closes the gap doc 90 explicitly flagged as
the natural next slice: "No rider-facing 'Earnings' screen beyond the
dashboard card" — `earnings-summary.php`'s `recent` array and the
matching `EarningsLedgerEntry` model existed but nothing rendered them.

> **Not built/tested against a live backend or device.** Same standing
> caveat as every prior session in this project — no PHP CLI, Android
> SDK, Gradle, or DB in this sandbox. Kotlin/XML checked by hand for
> brace/paren balance and XML well-formedness only (see "Verification"
> below). Treat as `🟡 IMPLEMENTED — TEST PENDING` per `done.md`'s own
> rule until it's actually run.

## What changed

### `rider/app/build.gradle`
Added `androidx.recyclerview:recyclerview:1.3.2`,
`androidx.cardview:cardview:1.0.0`, and
`androidx.swiperefreshlayout:swiperefreshlayout:1.1.0` — checked
first and confirmed **none of the three existed yet** in this module
(the rider app had no RecyclerView-based screen before this one).
Versions matched exactly to the customer app's own `build.gradle`
rather than picked fresh, so both apps stay on the same AndroidX
versions for these common libs.

### New: `rider/.../ui/earnings/EarningsActivity.kt`
Read-only screen: today's total + running balance (same two figures
the dashboard card already shows, kept consistent rather than
introducing a third framing), a `share_percent` context line (hidden
if the value is 0/unset — showing "You earn 0% of each delivery fee"
on a mid-rollout server with the setting not yet configured would be
actively misleading, so it degrades to hidden instead of wrong), and
the last 20 ledger entries via `EarningsLedgerAdapter`. Swipe-to-refresh
re-calls the same `getEarningsSummary()` endpoint the dashboard already
uses — no new backend endpoint needed, this screen is purely a second
consumer of doc 90's existing API. Empty state shown when `recent` is
empty. Reached only by tapping the dashboard's TODAY card — no other
entry point, mirroring `ApplicationStatusActivity`'s own "one choke
point" shape.

Deliberately out of scope, per doc 90's own listed gaps this session
did NOT try to close:
- No payout-request flow (doc 90: "rider requests a payout" is a
  separate deep-plan §21 piece, not part of a read-only ledger view).
- No pagination beyond the endpoint's existing 20-row cap — the
  endpoint itself has no `offset`/`page` parameter yet (see its own
  kdoc); "load more" is a later slice if 20 rows proves too short in
  practice, not guessed at here.
- No push notification on new earnings (doc 90's own flagged gap,
  unrelated to this screen either way).

### New: `rider/.../ui/earnings/EarningsLedgerAdapter.kt`
The rider app's first `RecyclerView.Adapter` — modeled directly on the
customer app's `WalletWithdrawalAdapter` (same card-row shape, same
"read raw `entry_type`/status from the server, format the label on
the Android side" split, same duplicated-not-shared date formatter
since the two apps are separate Gradle modules with no shared code
module in this project's structure). Amount is shown signed (+/−)
based on `entry_type` — `delivery_earning`/`incentive` always credit,
`payout` always debit, `adjustment` follows the raw sign since a
correction could go either way (confirmed against `rider_earnings_
ledger`'s schema comment in migration 73, not guessed).

### New layouts
- `layout/item_earnings_ledger.xml` — one ledger row (entry type,
  optional order-code line, optional note line, date, signed amount),
  same CardView-row shape as the customer app's `item_withdrawal.xml`.
- `layout/activity_earnings.xml` — header (back arrow + title — no
  shared Toolbar convention exists anywhere in this rider app yet, so
  this is a new minimal header row, not a deviation from an established
  one), today/balance summary card (reuses `bg_card_rounded`, same
  drawable the dashboard card already uses), share-percent note,
  "RECENT ACTIVITY" heading, `SwipeRefreshLayout`-wrapped
  `RecyclerView` + empty state.

### `rider/.../ui/dashboard/RiderDashboardActivity.kt` + `activity_rider_dashboard.xml`
- Dashboard's earnings card `LinearLayout` given `id="@+id/dashboardEarningsCard"`,
  `clickable`/`focusable`/`selectableItemBackground` ripple — was
  previously a plain non-interactive info card.
- New click listener in `onCreate()`'s existing listener-setup block,
  right before the pickup/deliver button listeners: opens
  `EarningsActivity` via a plain `Intent`. No new import needed
  (`Intent` already imported) — fully-qualified the `EarningsActivity`
  reference inline instead of adding an import line, to keep this a
  minimal, easy-to-diff addition to an already-large file.

### `AndroidManifest.xml`
New `<activity>` entry for `.ui.earnings.EarningsActivity`,
`exported="false"`, placed directly after `RiderDashboardActivity`
with a kdoc-style comment matching this file's existing per-activity
comment convention.

### `values/strings.xml`
New block: `cd_back`, `earnings_title`, `earnings_today_label`,
`earnings_balance_label`, `earnings_share_note_format`,
`earnings_recent_heading`, `earnings_empty`,
`earnings_order_code_format`, four `earnings_type_*` labels, and
`earnings_load_error`. `cd_back` didn't exist anywhere in this app
before (checked first) — the rider app had no back-arrow icon button
until this screen.

## Verification

No PHP CLI, Android SDK, Gradle, or emulator in this sandbox (standing
limitation, same as every prior session). Checked instead:
- Brace/paren balance on all 3 touched/new Kotlin files — all balanced
  (`EarningsActivity.kt` 15/15 braces, 43/43 parens;
  `EarningsLedgerAdapter.kt` 15/15, 47/47; `RiderDashboardActivity.kt`
  145/145, 366/366 — recount after the edit matches balanced before,
  confirming the new listener block didn't break anything).
- `python3 xml.etree.ElementTree` well-formedness check on all new/
  edited XML: `strings.xml`, `activity_earnings.xml`,
  `item_earnings_ledger.xml`, `activity_rider_dashboard.xml`,
  `AndroidManifest.xml` — all parse clean.
- Confirmed the three new Gradle dependencies were genuinely absent
  before adding them (`grep` across `rider/app/build.gradle` came back
  empty for all three), and matched their version numbers directly
  against the customer app's own `build.gradle` rather than picking
  fresh ones.
- `EarningsSummaryResult`/`EarningsLedgerEntry` field names in the new
  Kotlin were cross-checked against `Models.kt`'s actual existing
  definitions (doc 90), not re-typed from memory.

## Still open — next steps, in order

1. **Real Gradle build** — now the standing item across essentially
   every recent session in this project. This slice adds three new
   Gradle dependencies for the first time in the rider module, which
   makes a real build check slightly higher-value than usual before
   this ships (dependency resolution, not just code correctness, is
   unverified here).
2. **Live smoke test**: log in as an approved rider with at least one
   completed delivery on record, open the dashboard, tap the TODAY
   card, confirm the Earnings screen shows the same today-total as the
   dashboard card, confirm the balance and share-percent line look
   right, confirm the ledger list renders real rows with correct
   signs/labels, pull to refresh.
3. **Empty-ledger case** — a brand-new rider with zero deliveries
   should see the empty state, not a blank list or a crash; worth
   confirming on a real account with no ledger rows yet.
4. Next real feature slice after this one, per doc 90's own remaining
   list: Rider Documents (deep-plan §22) or the Payouts self-service
   request flow (deep-plan §21) — worth confirming with the person
   before starting either, same standing note doc 90 already made.
