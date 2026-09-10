# AnyDrop — Deep Plan: Statements, T+1 Settlement Visibility, Area-wise COD Hold Limit, Pay-COD QR, and Admin Cash Flow Overhaul

**Date:** 09 Sep 2026
**Requested by:** App owner (2026-09-09 chat)
**Status:** All 7 phases below are 🟡 **CODE-COMPLETE, NOT build/device-verified**
(updated 2026-09-10). Per this project's own completion rule
(`PENDING.md` §34 — "Implemented ≠ Tested, Tested ≠ Production-ready"),
none of these can be marked fully ✅ DONE yet: every phase was built in
a sandbox with no PHP CLI, no live MySQL, and no Android SDK, so
nothing has actually been run. See the per-phase status lines below
and the "Build Order Recap" section at the bottom for the full picture
and the exact handover doc for each phase.

---

## 0. Current Baseline (what already exists — do not rebuild)

- `rider_cod_ledger` table + `riders.cod_cash_held` running balance — already tracks cash a rider is holding.
- `app_settings.rider_cod_settlement_limit` (default ₹2000) — **platform-wide only**, not area-wise yet.
- `restaurant_due_ledger` + `restaurant_payments` — restaurant-side commission/payout ledger, supports both directions (`commission_cod`, `payout_payable`, `settlement_to_restaurant`, `settlement_from_restaurant`).
- `admin/rider-settlements.php` — rider list with cash held, per-rider ledger, manual "Record Settlement".
- `admin/settlements.php` — restaurant settlement, manual "Pay Now" with UTR/reference + screenshot, CSV export. **No automatic T+1 cycle exists today** — it's a manual date-range pull, admin-triggered.
- `admin/rider-payouts.php` — rider payout **requests** (rider requests payout of earnings, admin batch-processes with UTR/screenshot). This is a *different* money flow from COD cash (rider earning payout vs. rider handing over collected cash).
- `payment_transactions` + UPIPE auto-verify provider (`UpipeProvider::tryAutoVerify`) — this is the existing customer-payment QR/UPI auto-verify flow. **This is what we reuse for rider's "Pay COD" QR**, not a new payment gateway.
- Restaurant app already blocks new orders when `restaurants.current_due >= restaurant_due_limit` (`lib/orders.php` ~line 156) — **this exact pattern gets mirrored for riders.**

**Key model clarification to hold onto through every phase:** COD cash flows Customer → Rider → **Admin** (not restaurant). Restaurant settlement (commission/payout) is a fully separate ledger and separate money movement. The "Cash Flow" admin page must show both, clearly separated, never merged into one number.

---

## 1. Phase 1 — Backend: Order Statement Data + T+1 Settlement Status (foundation, no UI yet)

**STATUS: 🟡 BUILT (2026-09-09) — NOT verified.** Migration 80,
`lib/settlement_status.php`, `orders-deliver.php`'s T+1 clock, and
`record_settlement()`'s settle-on-Pay-Now wiring are all in place. See
`docs/Status.md`'s 2026-09-09 "Deep Plan Phase 1" entry.

Everything downstream (restaurant statement, rider statement, admin cash flow) reads from this, so it goes first.

### 1.1 Restaurant order statement
- New endpoint: `GET /api/v1/restaurant/statement.php?date=YYYY-MM-DD` (defaults to today)
  - Returns: order_id, time, item summary, order total, payment mode (COD/online), commission_amount, net payable to restaurant, **settlement status** (`pending_t1` / `settled` / `not_applicable_cod`).
  - Daily summary block: total ₹ order value, total orders, count settled vs pending.
- **Settlement status logic (new):** an order becomes settlement-eligible once `delivered_at + 1 day` has passed AND no open refund/dispute. Add `orders.settlement_eligible_at` (generated at delivery, = `delivered_at + INTERVAL 1 DAY`) and `orders.settlement_status ENUM('pending','eligible','settled')`. A scheduled job (or lazy-eval on read, cheaper) flips `pending → eligible` once eligible_at has passed; `eligible → settled` gets flipped only when `admin/settlements.php` "Pay Now" actually pays that order out (today's Pay Now settles by date-range total, not per-order — needs a join table `restaurant_payment_orders (payment_id, order_id)` so a payment record can point back to exactly which orders it covered → this is what lets us show "settled" vs "not yet" per order).

### 1.2 Rider order statement
- New endpoint: `GET /api/v1/rider/statement.php?date=YYYY-MM-DD`
  - Returns: order_id, delivered time, COD or prepaid, COD amount collected (if any), delivery earning for that order, running COD-cash-held after that order.
  - Daily summary: total orders delivered, total COD cash collected today, total earnings today.

### 1.3 SQL migration `80_migration_order_settlement_tracking.sql`
- `orders.settlement_eligible_at`, `orders.settlement_status`
- `restaurant_payment_orders` join table (payment_id, order_id)
- Backfill script for historical delivered orders (set eligible_at, run status calc once)

**Deliverable:** two JSON endpoints + migration. No screen yet — testable via Postman/admin before touching Android.

---

## 2. Phase 2 — Restaurant App: Statement Screen

**STATUS: 🟡 BUILT (2026-09-09) — NOT verified.** `StatementActivity`
(Restaurant app) wired to Phase 1's `statement.php`. See `docs/Status.md`'s
2026-09-09 "Deep Plan Phase 2" entry.

- New tab/screen "Statement" in Restaurant Android app.
- Day picker (default today) → calls `statement.php`.
- List: Order ID, item count, ₹ amount, badge (● Pending T+1 / ✓ Settled).
- Top summary card: Today's Sales ₹X · Orders N · Settled ₹Y · Pending ₹Z.
- Tap an order → existing order-detail screen (reuse `orders-detail.php`).

---

## 3. Phase 3 — Rider App: Statement Screen

**STATUS: 🟡 BUILT (2026-09-09) — NOT verified.** `StatementActivity`
(Rider app) wired to Phase 1's `rider/statement.php`. See `docs/Status.md`'s
2026-09-09 "Deep Plan Phase 3" entry.

- New tab/screen "Statement" in Rider Android app (near existing Earnings card, per doc `107_Handover…COD_Cash_Held_EarningsCard_Built.md`).
- Day picker → calls rider `statement.php`.
- List: Order ID, delivered time, COD/Prepaid tag, ₹ collected, running cash-held.
- Top summary: Orders Delivered N · COD Collected ₹X · Cash Currently Held ₹Y.

---

## 4. Phase 4 — Area-wise COD Cash-Hold Limit + Block Enforcement

**STATUS: 🟡 BUILT (2026-09-09) — NOT verified.** Migration 81,
`lib/rider_cod_limit.php`, `admin/rider-cod-limits.php`, dispatch
enforcement, and the Rider App's blocked-banner are all in place. Full
detail: `docs/rider/122_Handover_2026-09-09_DeepPlan_Phase4_
AreaRiderCodLimit_Built.md`.

### 4.1 Backend
- Extend `area_cod_rules` pattern (or new small table `area_rider_cod_limits(area_id, cod_hold_limit)`) — one row per service area, falls back to platform `rider_cod_settlement_limit` if no row, exactly like `area_cod_rules` already falls back to platform defaults.
- Admin screen (extend existing `admin/cod-rules.php` or new `admin/rider-cod-limits.php`) — set per-area ₹ limit.
- Enforcement point: `lib/rider_assignment.php` (wherever a rider becomes eligible for a new order) — mirror `restaurant_due_limit` check exactly:
  ```
  $limit = get_area_rider_cod_limit($rider['area_id']); // falls back to platform default
  if ((float)$rider['cod_cash_held'] >= $limit) {
      // exclude rider from assignment pool
  }
  ```
- Rider-facing API (`rider/status.php` or `me.php`) returns `cod_blocked: true/false` + `cod_cash_held` + `cod_limit` so the app can show the notice without a separate poll.

### 4.2 Rider App
- Persistent banner when blocked: **"You've reached your cash hold limit (₹2,000). Deposit your COD cash to receive new orders."**
- No new orders shown/pushed while blocked (reuse existing FCM/order-push gating — same switch restaurant-closed already uses).

---

## 5. Phase 5 — Rider App: "Pay COD Amount" Button (QR + Auto-Verify)

**STATUS: 🟡 BUILT (2026-09-09/10) — NOT verified.** Migration 82,
`cod-deposit-initiate.php`, `PayCodDepositActivity.kt` + layout,
`EarningsFragment` wiring, all confirmed present end-to-end. Full
detail: `docs/rider/123_Handover_2026-09-09_DeepPlan_Phase5_
RiderCodDeposit_BackendComplete_AndroidPartial.md` and recall.md's
2026-09-09 "same day, continued" re-check entry (only the
`AndroidManifest.xml` registration was missing, since fixed).
**Partial-deposit question below: answered** — owner confirmed
partial deposits are allowed (not full-amount-only); implemented as
such.

- Button on Rider Statement/Earnings screen: **"Pay COD Amount"** — shows current `cod_cash_held`.
- Tap → `POST /api/v1/rider/cod-deposit-initiate.php` → creates a `payment_transactions` row (reuse existing table, new `purpose = 'rider_cod_deposit'` column value) tagged to admin's UPI ID, amount = cod_cash_held (or partial, if we allow partial — **owner to confirm: full-only or partial allowed?**).
- Reuses **existing UPIPE QR + auto-verify flow** (same one customer checkout already uses) — no new payment gateway integration needed, just a new `purpose`/context so the callback knows to credit `rider_cod_ledger` (`entry_type = 'settlement_to_admin'`) and decrement `riders.cod_cash_held` instead of marking an order paid.
- On auto-verify success: ledger entry written, `cod_cash_held` reduced, blocked banner clears automatically if now under limit.
- Fallback: if auto-verify doesn't fire within N minutes, same manual-UTR-entry fallback the customer flow already has.

---

## 6. Phase 6 — Rider App: Deposit Tracking History

**STATUS: 🟡 BUILT (2026-09-10) — NOT verified.** New "Deposits" tab
inside `StatementActivity` + `cod-deposit-history.php`. Full detail:
`docs/rider/124_Handover_2026-09-10_DeepPlan_Phase6_
RiderCodDepositHistory_Built_NotVerified.md`. **Open question flagged
to owner, still unanswered:** the deep-plan text below says "pending"
as one of three example statuses, but a `rider_cod_ledger` row is only
ever written after money is already confirmed — there's no ledger row
for an in-flight deposit. Current build shows only 3 confirmed-money
statuses (auto-verified/manual/manual-settlement); "pending" would
need to read `payment_transactions` instead if the owner wants it
here too.

- New section (can live inside Statement screen) — list of past deposits: date, amount, status (auto-verified / manual / pending), reference.
- Pulls from `rider_cod_ledger WHERE entry_type = 'settlement_to_admin'`.

---

## 7. Phase 7 — Admin Cash Flow Page Overhaul

**STATUS: 🟡 BUILT (2026-09-10) — NOT verified.** Built as
`admin/cash-flow.php` (per this section's spec below). Full detail:
`docs/rider/125_Handover_2026-09-10_DeepPlan_Phase7_
AdminCashFlowDashboard_Built_NotVerified.md`. **Follow-up same day:**
owner asked to merge this with the pre-existing standalone
`platform-ledger.php` page rather than have two separate "cash flow"
pages — done, `platform-ledger.php` now just redirects to
`cash-flow.php`, whose 3rd section is the former page's content
verbatim. See `docs/rider/126_Handover_2026-09-10_CashFlowPages_
Merged_NotVerified.md`. **Open questions below: resolved with a design
decision, not yet confirmed by the owner** — used `restaurants.
current_due` directly instead of re-summing raw `payout_payable`/
`commission_cod` rows (see doc 125 for why); "Paid this cycle" filters
on `restaurant_payments.created_at`, not `payment_date` (these can
differ — flagged, not confirmed).

Single unified page (new `admin/cash-flow.php`, or heavily extend `rider-settlements.php` + `settlements.php` into one dashboard) showing, per rider and in total:

| Column | Source |
|---|---|
| Cash currently held | `riders.cod_cash_held` |
| Total ever collected | SUM `rider_cod_ledger` where `cod_collected` |
| Total deposited to admin | SUM `rider_cod_ledger` where `settlement_to_admin` |
| Last deposit date | latest `settlement_to_admin` row |
| Status | Over limit (red) / OK (green) |

And separately, a **Restaurant Settlement summary block** on the same page (pulling from `restaurant_due_ledger`/`restaurant_payments`, kept visually separate from rider cash — different money flow):

| Column | Source |
|---|---|
| Total payable to restaurants | SUM `payout_payable` unsettled |
| Total due from restaurants (COD commission) | SUM `commission_cod` unsettled |
| Paid this cycle | SUM `settlement_to_restaurant` this period |

**Automatic entry requirement (the core ask):** every time admin clicks "Record Settlement" (rider) or "Pay Now" (restaurant) anywhere in the admin panel, this page's numbers update from the same underlying ledger tables — no separate manual bookkeeping step. Since both existing flows already write to `rider_cod_ledger` / `restaurant_due_ledger`, Phase 7 is mostly a **read/aggregation + UI** layer, not new write logic — confirms Phases 1–6 done first so the ledgers are complete and accurate before building the summary on top.

---

## Build Order Recap

1. ✅🟡 Phase 1 — backend statement + settlement-status data model (built, not verified)
2. ✅🟡 Phase 2 — restaurant statement screen (built, not verified)
3. ✅🟡 Phase 3 — rider statement screen (built, not verified)
4. ✅🟡 Phase 4 — area-wise COD limit + block (built, not verified)
5. ✅🟡 Phase 5 — Pay COD QR + auto-verify (built, not verified)
6. ✅🟡 Phase 6 — rider deposit history (built, not verified)
7. ✅🟡 Phase 7 — admin cash flow dashboard (built, not verified; merged with platform-ledger.php same day)

**All 7 phases are code-complete as of 2026-09-10.** Nothing in this
plan is left to *build* — what's left is entirely verification (real
PHP/MySQL/Android SDK environment) plus the still-open questions
below. See each phase's own STATUS line above for its exact handover
doc.

## Open questions for owner (answer before/while building relevant phase)
- Phase 1: is "T+1" calendar days or business days? — **still open,
  not answered; built assuming calendar days (simple `+ INTERVAL 1 DAY`),
  flag if that's wrong.**
- Phase 5: full-amount-only deposit, or can rider deposit partial COD cash? — **answered 2026-09-09: partial allowed.** Built accordingly.
- Phase 7: does restaurant settlement summary need to live on the *same* page as rider cash, or linked-but-separate pages? — **answered by the 2026-09-10 merge request: same page**, and further merged with Platform Cash Flow too (3 sections, 1 page).

### New open questions raised during the build (not in the original plan)
- Phase 6: the "pending" status wording — no ledger row exists for an in-flight deposit, so it's not shown here. Confirm if that's fine, or if it should pull from `payment_transactions` instead.
- Phase 7: `restaurants.current_due` vs. re-summing raw ledger entry types for the two "total" figures — used `current_due` (see doc 125). Confirm this matches what's wanted.
- Phase 7: "Paid this cycle" filters on `restaurant_payments.created_at` vs. the separately-editable `payment_date` field on that same table — used `created_at`. Confirm this is the intended field.
