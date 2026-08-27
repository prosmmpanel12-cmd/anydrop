# Handover — Restaurant Finance / Payout Analytics (2026-08-26)

Closes PENDING.md item 17 / recall.md item 13's own flagged sub-item,
last called out as the next candidate in doc 46's closing note:
> Restaurant Finance / Payout Analytics (recall.md item 13's own
> still-🔴 sub-item — Today/Weekly/Monthly earnings + GST breakdown
> columns on `admin/settlements.php`'s per-restaurant view; doc 19 §6
> describes the exact columns).

## Finding this session

**No new code was needed.** `backend/admin/settlements.php` already
contains the full "Payout Analytics" card on the per-restaurant detail
view (`?restaurant_id=N`) — it was built in the same file but never
got its own handover doc, so `recall.md` and `PENDING.md` still marked
it 🔴 not built. This session verified the existing code against doc
19 §6's spec line by line and found it complete:

| Doc 19 §6 column | Implemented as |
|---|---|
| Total Orders | `COUNT(*)` on `orders` in range |
| Cash Collected (COD) | `SUM(grand_total)` where `payment_method='cod'` |
| Online Collected (UPI) | `SUM(grand_total)` where `payment_method='upi'` |
| Commission | `SUM(commission_amount)` |
| GST | `commission × app_settings.gst_percent / 100` |
| Net Payable | `online_collected − commission − gst − online_platform_fee` |
| Already Paid | `SUM(amount)` from verified `restaurant_payments` in range |
| Pending | live `restaurants.current_due`, signed, not range-scoped (by design — it's a running balance, not a period figure) |

Range toggle is **Today / This Week (-6 days) / This Month (-29 days)**
via `?payout_range=`, defaulting to `today` — the same rolling-window
convention `analytics.php` already uses, so the two ranged admin
screens behave consistently. Non-revenue statuses (`cancelled`,
`rejected`, `failed`, `expired`) are excluded from every money figure
but still counted in Total Orders, matching how `analytics.php` treats
the same statuses.

## What this session actually changed

Documentation only, to stop the next session from re-investigating a
gap that's already closed:

- `recall.md` — the "Today/weekly/monthly earnings, GST/commission
  analytics breakdown" line (financial module summary) and item 23
  (Phase C status list) both updated from 🔴/NOT-built to ✅/built.
- `PENDING.md` item 17 — flipped from a bare PENDING checklist to
  🟡 BUILT with the same per-checkbox detail the other recently-closed
  items (15, 16) already use, so the file stays self-consistent.

## Deliberately NOT touched

- **List mode** (`settlements.php` with no `?restaurant_id`) still
  only shows a single running `current_due` badge per restaurant, no
  per-period columns. Doc 19 §6 specs this analytics card as a
  **per-restaurant** screen, not a cross-restaurant table — so this
  is in-spec, not a gap. If the app owner wants a Today/Week/Month
  column set across *all* restaurants at once on the list page too,
  that's new scope beyond doc 19 §6 and should be its own item, not
  folded into this one silently.
- **Export** — doc 19 §6 mentions the ledger statement is "exportable
  (reuses the Excel/PDF export already planned for Analytics)", but no
  export exists anywhere in admin yet (`analytics.php`'s own handover
  flagged the same gap). Not built here either — still a real,
  separate follow-up.
- No migration, no schema change — `app_settings.gst_percent` (seeded
  '18' by migration 38) and every column read here already existed.

## Needs a real machine, not this sandbox

Same standing limitation as every prior session — no PHP CLI, no live
DB, no browser here.

1. `php -l backend/admin/settlements.php`.
2. Open a restaurant's Settlement page, cycle through Today / This
   Week / This Month — confirm the date range shown in the muted text
   matches the selected option and all eight stat cards update.
3. Pick a restaurant with **zero orders** in the selected range —
   confirm every card shows ₹0.00 / 0 cleanly, no divide-by-zero (Net
   Payable's formula is pure subtraction so this should be safe, but
   worth confirming on a real empty result set like doc 44's own
   analytics.php checklist did for AOV/acceptance rate).
4. Cross-check one restaurant, one day: sum that restaurant's COD +
   UPI `grand_total` for the day manually against the Cash Collected +
   Online Collected cards, and confirm Commission/GST match
   `commission_amount` × `gst_percent` for that same order set.
5. Confirm the GST percentage shown matches whatever's currently saved
   in Settings → `gst_percent` (test by changing the setting and
   reloading — the figure should move).

## Suggested next step

Per the user's own stated order: **Support / Ticket system admin side
next** (recall.md item 9/20) — this one genuinely needs a new DB
migration first (no `tickets`/`support_requests` table exists yet in
`backend/sql/`), so it should start with the schema design, not admin
UI code.
