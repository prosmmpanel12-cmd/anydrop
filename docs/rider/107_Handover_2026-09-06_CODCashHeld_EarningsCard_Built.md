# Handover — COD Cash-Held card added to Earnings screen (deep-plan §17-18)

Session date: 06 Sep 2026, continuing from doc 106's "owner's choice"
list. Picked deep-plan §17-18 (COD Collection / COD Settlement Limit),
specifically §17's explicit, previously-unactioned instruction: **"The
rider app should display the running cash-held amount separately from
earnings."** Same standing caveat as every prior handover: nothing this
session was compiled, run, or checked against a live backend/Gradle/
real device (no PHP CLI, no Gradle, no Android SDK in this sandbox).
Manual brace/paren-balance checks and XML well-formedness checks were
run on every touched file — see "What was verified" below.

## What was verified before writing anything

- Grepped the entire `rider/` Android tree for `cod_cash_held`/"cash
  held"/"settle" — found nothing. This gap was real, not already
  covered by an existing screen under a different name.
- Confirmed `riders.cod_cash_held` already exists (migration 53) and
  is already the live enforcement value `backend/lib/dispatch.php`
  checks against `rider_cod_settlement_limit` before assigning a new
  COD order to a rider (`if ($isCod && cod_cash_held >= $codLimit)`,
  `dispatch.php` line ~88) — this session only surfaces a number the
  backend already maintains and enforces; no new column, no new
  ledger logic, no new migration.
- Read `backend/lib/rider_ledger.php` to confirm `cod_cash_held` is
  kept in sync transactionally (`SELECT ... FOR UPDATE` + single write
  path) every time a COD delivery completes or a settlement is
  recorded — the number this card reads is already correct, this
  session just had to surface it.
- Read `rider/earnings-summary.php` in full (the endpoint
  `EarningsActivity` already calls on load/refresh) and decided to
  fold the two new numbers into its existing response rather than a
  new endpoint — same reasoning `route.php`'s own kdoc gives for
  folding its three deviation-recalc numbers into an existing call
  rather than a new round trip: this is the one network call
  `EarningsActivity` already makes on the relevant cadence.
- Read `EarningsActivity.kt`/`activity_earnings.xml` in full to place
  the new card consistently with the existing today/balance card and
  share-percent note.
- Read `ApplicationStatusActivity.kt`'s status-pill pattern
  (`backgroundTintList = ColorStateList.valueOf(getColor(...))`) and
  reused it exactly for this card's pill + progress-bar tint, rather
  than inventing a second tinting convention.
- Confirmed `bg_status_pill.xml` (already exists, used by
  `ApplicationStatusActivity`) fits this card's pill without changes —
  no new drawable needed for the pill itself.

## What was built this session

### `backend/api/v1/rider/earnings-summary.php` (edited)
Response gains two fields: `cod_cash_held` (from `riders.cod_cash_held`,
same row already being read for `earnings_balance`) and
`cod_settlement_limit` (`get_setting('rider_cod_settlement_limit',
2000)` — same key and default `dispatch.php` uses, so the Android
"blocked" warning can never claim a state the server disagrees with).
Both rounded to 2 decimals, same convention every other money field in
this response already uses.

### `rider/.../network/Models.kt` (edited)
`EarningsSummaryResult` gains `codCashHeld`/`codSettlementLimit`, both
with safe defaults (`0.0`/`2000.0`) matching the endpoint's own
fallback values, so an old cached response or a partially-mocked test
payload deserializes without crashing.

### `rider/.../ui/earnings/EarningsActivity.kt` (edited)
New `renderCodCard()`, called from `loadEarnings()`'s success branch
right after the existing ledger-list render. Computes `ratio =
held/limit` (guarded against a zero/negative limit), sets the amount
text, progress-bar fill, and a three-state pill + note:
- **OK** (green, `status_approved_*`) — held well under limit.
- **NEAR LIMIT** (amber, `status_pending_*`) — `ratio >= 0.8`, a
  client-only heads-up threshold, not server-enforced, so tunable here
  without a backend change.
- **LIMIT REACHED** (red, `status_rejected_*`) — `held >= limit`,
  matching `dispatch.php`'s own `>=` comparison exactly so this label
  is never wrong about whether new COD orders are actually being
  blocked right now.

A small private `Quintuple` data class was added as a local 5-tuple
return type for the three-way `when` — not worth a library dependency
for one private helper's return value.

### `rider/.../res/layout/activity_earnings.xml` (edited)
New card inserted between the existing today/balance summary card and
the share-percent note: label + status pill (top row), large amount
text, horizontal `ProgressBar`, and a limit-note text line. Kept as its
own card rather than merged into the today/balance card — per §17's own
wording, this is money the rider owes the platform, the opposite
direction from the earnings figures above it, so a visually distinct
card avoids it reading as a third earnings number.

### `rider/.../res/values/strings.xml` (edited)
Six new strings: the card's label, three pill states, and two limit-
note format strings (normal vs blocked wording) — see file for exact
text.

## Deliberately not built this session

- **Dashboard badge/indicator** — deep-plan §17 only asks for the
  rider app to show this somewhere; the Earnings screen (already the
  "money" screen, reached from the dashboard's TODAY card) was judged
  the natural single home for it rather than duplicating a second
  smaller indicator on the dashboard itself. Could be added later if
  the owner wants a rider to see this without opening Earnings.
- **Any change to how `cod_cash_held` is written or how the
  settlement limit is enforced** — this session is display-only;
  `dispatch.php`'s existing block and `rider_ledger.php`'s existing
  write path are both untouched.
- **A rider-facing "request settlement" action** — §18 mentions
  "settle with admin" but doesn't specify a rider-initiated flow, and
  `backend/admin/rider-settlements.php` already exists as the
  admin-side reconciliation screen for this. Whether riders should
  also get a self-service "I've handed over cash" button is a product
  decision for a future session, not assumed here.

## Still open

- **Nothing this session was compiled or device-tested.** Manual
  brace/paren/bracket-balance checks: `EarningsActivity.kt`,
  `Models.kt` (Kotlin) and `earnings-summary.php` (PHP, no `php -l`
  available) all balanced clean. XML well-formedness checks (Python's
  `xml.etree.ElementTree`): `activity_earnings.xml` and `strings.xml`
  both parsed clean. Cross-checked every new view-binding ID
  (`codCashHeldValue`/`codCashHeldBar`/`codCashHeldPill`/
  `codCashHeldLimitNote`) and every new string resource reference
  against their respective XML files — each resolves exactly once, no
  typos, no orphaned references.
- **Recommended first live check, backend**: call
  `earnings-summary.php` for a rider with a non-zero `cod_cash_held`
  and confirm the two new fields appear with the right values; also
  check a rider at/above the configured limit to confirm the numbers
  agree with what `dispatch.php` would currently block.
- **Recommended first live check, Android**: once a Gradle build is
  possible, open Earnings for riders in all three states (well under
  limit / near limit / at-or-over limit) and confirm the pill color,
  progress-bar fill, and note text all match; confirm the card doesn't
  crash or misrender when `cod_cash_held` is `0` (new rider, no COD
  deliveries yet).
- Everything doc 106 already carried over unchanged and untouched this
  session: `php -l` still never run anywhere in this project,
  migrations 75/76 still never run, PENDING.md/recall.md still not
  re-audited (flagged stale since doc 97), COD settlement warning
  itself now has a UI but the underlying reconciliation-workflow
  question doc 106 didn't touch remains open, the two deliberately-
  unbuilt §23 sub-events ("Restaurant ready", "Customer cancellation")
  unchanged.

## Next step, owner's choice

Same fork doc 106 left open, now with a third session's worth of
untested Android work stacked on top: a real Gradle build + device
test (would now cover doc 105's notifications work, doc 106's Order
Detail screen, and this session's Earnings card together), or
continuing to a different still-unbuilt deep-plan section (Live
Location System §12-15 Android customer-side pieces, Delivery OTP §16
double-check against its full text, Admin Rider Command Center §25).
