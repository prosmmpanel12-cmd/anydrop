# Rider Earnings — Ledger, Backend, Admin UI, Android Dashboard Wiring

Session date: 04 Sep 2026 (continuation — closes deep-plan §19-20, the
"Rider Earnings" item doc 88/89 both flagged as the next major unbuilt
phase)

Rate model decided this session (person's own call, 2026-09-04):
**rider earning = a configurable % of the order's own `delivery_charge`
(already distance/area-based via `lib/delivery_pricing.php`'s
`calculate_delivery_fee()`), floored at a configurable minimum** — NOT
deep-plan §19's literal "independent flat base + own per-km rate"
wording. Chosen specifically to reuse the pricing engine that already
computes real distance + area-configurable rates, instead of
maintaining two parallel distance-based money formulas that could
drift out of sync with each other. Full reasoning is in migration 73's
own header comment.

## ✅ Done this session

**Database**
- `backend/sql/73_migration_rider_earnings_ledger.sql` — new
  `rider_earnings_ledger` table. Shape mirrors `rider_cod_ledger`
  (migration 53) closely (`running_balance` snapshot per row, signed
  `amount`, `created_by` system/admin) but is a **separate table**,
  not a new entry_type on the COD ledger — deep-plan §20 explicitly
  says not to mix cash-holding entries with earnings entries, and this
  keeps that boundary real rather than just documented.
  - Entry types: `delivery_earning`, `incentive`, `bonus`,
    `adjustment_credit`, `adjustment_debit`, `payout` — exactly
    deep-plan §20's list.
  - `riders.earnings_balance` — new column, same role as
    `riders.cod_cash_held` but opposite direction of money (platform
    owes rider, vs. rider owes platform).
  - Two new `app_settings` keys, seeded with placeholder defaults:
    `rider_earning_share_percent` (80), `rider_earning_minimum` (₹20).

**Backend library**
- `backend/lib/rider_earnings.php` — new file, same
  lock-then-update-then-insert pattern `rider_ledger.php` already
  established for `write_rider_cod_ledger_entry()`:
  - `calculate_rider_earning(deliveryCharge)` — pure function, no DB
    writes, returns the computed amount + whether the minimum floor
    kicked in. Split out from the recording function so a future
    "preview payout" UI (or this session's admin settings card) can
    show what an order *would* earn without writing a ledger row.
  - `record_rider_delivery_earning()` — the one real call site, fired
    from `orders-deliver.php`.
  - `record_rider_payout()` / `record_rider_earnings_adjustment()` —
    admin-side actions, mirror `record_rider_settlement()`'s shape
    exactly (single write path, signed amount, own-transaction only if
    caller hasn't already opened one).
  - `rider_earning_share_percent()` clamps to 0-100 defensively (an
    admin typo of "800" can't make a delivery pay 8x its own delivery
    charge).

**Backend — delivery flow**
- `backend/api/v1/rider/orders-deliver.php` — `record_rider_delivery_earning()`
  now fires inside the same transaction as the `delivered` status
  flip, for **every** order (unlike the COD ledger calls right above
  it, this one is NOT payment-method-gated — a rider earns for
  delivering a UPI order exactly the same way as a COD order,
  deep-plan §19's framing: paid for the delivery, not for handling
  cash). Response now includes `earning_amount`. Stale kdoc comment
  that said "deliberately NOT written here" replaced with the real
  behavior.

**Backend — new endpoint**
- `backend/api/v1/rider/earnings-summary.php` — GET, rider auth.
  Returns `today_total` (sum of `delivery_earning` entries since
  server-local midnight — deliberately excludes payouts/adjustments so
  "today" reads as "what did today's deliveries pay"), `balance`
  (the one true "what's owed" number, `riders.earnings_balance`,
  reflects every entry type), `share_percent`, and the 20 most recent
  ledger rows (order code joined in, for a future "Earnings" screen
  beyond just the dashboard card).

**Admin UI**
- `backend/admin/rider-earnings.php` — new page, mirrors
  `rider-settlements.php`'s list/detail split closely but for the
  opposite money direction:
  - List mode: every rider, sorted by `earnings_balance` DESC, search
    by name.
  - Detail mode (`?rider_id=N`): full ledger table, Record Payout
    form (mirrors Record Settlement), Manual Adjustment form
    (credit/debit + remarks — new, no COD-side equivalent needed one).
  - Rate-settings card (share % + minimum) lives on the list view,
    right above the rider list — "the setting lives next to what
    reads it," same placement logic `fcm-settings.php` uses. Explicit
    warning text: changing the rate does not recalculate past
    deliveries.
  - Gated on `payouts_view`/`payouts_manage` — same permission
    `rider-settlements.php` already uses, no new RBAC migration.
- `backend/admin/_layout_head.php` — nav entry added (`rider_earnings`,
  right after `rider_settlements`, same `finance` group) and added to
  the `$activeNav` docblock list. Patched directly, same call as last
  session's Directions Settings nav edit (small, isolated, low
  collision risk).

**Android — Rider app**
- `network/ApiService.kt` — `getEarningsSummary()` →
  `GET rider/earnings-summary.php`.
- `network/Models.kt` — `EarningsSummaryResult`, `EarningsLedgerEntry`;
  `DeliverOrderResult` gained a nullable `earningAmount` field
  (defaulted, so it deserializes fine even against a server that
  hasn't had migration 73 applied yet).
- `ui/dashboard/RiderDashboardActivity.kt`:
  - New `refreshEarnings()` — fills the dashboard's "TODAY" earnings
    card, which was previously a static `₹0` placeholder (see that
    layout's own long-standing comment, R2/doc 83). Called once in
    `onCreate()` and again right after a successful delivery
    (`deliverOrder()`'s success branch) — **not** on every 5s
    `dashboardPollRunnable` tick, since the number only actually
    changes at delivery time and that tick already fires for
    unrelated reasons (offer/current-order polling).
  - Any failure leaves the currently-rendered text alone (starts as
    the placeholder, then whatever was last successfully fetched) —
    same "don't disrupt the screen for a transient failure" stance
    `refreshFromServer()` already takes.
- `res/values/strings.xml` — new `dashboard_earnings_amount_format`
  (`₹%.0f`) alongside the existing placeholder string, which stays as
  the pre-load text so the card never renders blank on first paint.

## Verification (no PHP interpreter, Gradle, or network in this sandbox — same standing caveat as every prior session)

- Brace/paren balance, every touched/new file:
  - `73_migration_rider_earnings_ledger.sql`: parens 31/31 (no braces
    in SQL).
  - `rider_earnings.php`: 27/27 braces, 103/103 parens.
  - `orders-deliver.php`: 16/16 braces, 77/77 parens.
  - `earnings-summary.php`: 4/4 braces, 34/34 parens.
  - `rider-earnings.php` (admin): 23/23 braces, 150/150 parens.
  - `_layout_head.php` (after edit): 3/3 braces, 52/52 parens —
    unchanged shape, one more array entry.
  - `RiderDashboardActivity.kt`: 144/144 braces, 364/364 parens.
  - `ApiService.kt` / `Models.kt`: both balanced.
- `strings.xml` re-parsed with Python's `xml.etree` — well-formed.
- Every helper function called from new code (`admin_has_permission`,
  `write_audit_log`, `set_setting`/`get_setting`, `admin_escape`, etc.)
  was read directly from its own definition first, not assumed, same
  discipline as prior sessions.
- Confirmed by direct read (not assumed) that `orders.delivery_charge`
  is populated by `calculate_delivery_fee()` at order-creation time
  (`lib/orders.php`'s `price_cart()`) — so `record_rider_delivery_earning()`
  reading `$order['delivery_charge']` inside `orders-deliver.php` is
  reading a real, already-computed value, not a null/zero column.
- Confirmed `haversine_km()`/`calculate_delivery_fee()` were NOT
  duplicated — this session's earning calculation reuses the existing
  `delivery_charge` value already stored on the order row rather than
  recomputing distance itself.

## Not built / not tested this session

- **Real `php -l` / Gradle build / device test** — same sandbox
  limitation flagged in docs 86, 87, 88, and 89. Now five sessions
  running with this same flag. Strongly recommend a real-machine pass
  before shipping any of this.
- **Earnings ledger fired on zero real deliveries** — this endpoint
  has never actually run; the whole flow (migration → lib →
  `orders-deliver.php` → both new endpoints → admin page → Android
  card) is reasoned through against the schema and each function's own
  signature, not exercised end-to-end.
- **No rider-facing "Earnings" screen beyond the dashboard card** —
  `earnings-summary.php`'s `recent` array (20 ledger rows) is returned
  and modeled on the Android side (`EarningsLedgerEntry`) but nothing
  currently renders it; only `today_total` feeds the existing
  dashboard card. A dedicated screen with the full ledger + payout
  history is a natural next slice, not built here.
- **No push notification on a new earning** — `create_notification()`
  is not called from `record_rider_delivery_earning()`'s call site;
  the rider only sees the update by opening/refreshing the dashboard.
- **Payout request flow (deep-plan §21, "rider requests a payout")**
  not built — only the admin-initiated Record Payout action exists.
  Deep-plan §21 lists this as rider-visible "available earnings /
  pending earnings / payout history / payout status," which this
  session's dashboard card + admin ledger only partially covers.
- **Rate change does not retroactively recalculate** — stated
  explicitly in the admin UI's own copy; intentional, not a gap, but
  worth restating here.
- Admin Directions API key UI (doc 89, prior session) — unrelated,
  already complete, unaffected by this session's changes.

## Still open — next steps, in order

1. Same standing item across every recent session: **real Gradle
   build + `php -l`** on a real machine before shipping.
2. Decide whether a dedicated rider-facing Earnings screen (full
   ledger, payout history) is worth building now, or whether the
   dashboard card is enough for this phase.
3. Rider Documents (deep-plan §22) or Payouts self-service request
   flow (deep-plan §21) are the two most obviously-adjacent unbuilt
   pieces if continuing straight through the rider-money phases —
   worth confirming with the person before starting either.
4. doc 88's own still-open items (real Google Directions API key,
   end-to-end map smoke test) remain untouched by this session.
