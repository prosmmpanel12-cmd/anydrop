# Handover 131 — 2026-09-12

## Restaurant `current_due` sign fix, Cash Flow quick-action buttons, and a full settings-defaults seed — BUILT, not build/device-verified

Three app-owner asks in the same session, following directly from doc
130's Cash Flow work:

1. "Restorent mai outstanding balance kiu hai wo remove karo, sara cash
   to apne paas aayega na, rider ke through?" — a real bug, not just a
   UI ask. Fixed in `lib/ledger.php`.
2. "Cash flow ke andar hi rider and restorents ke payment wale buttons
   daal de and auto sync" — quick-action buttons added directly to
   `admin/cash-flow.php`.
3. "Sari default values ke liye sabhi app ke setting table mai
   different colum bnao taki dekh/check kar shke" — every code-default
   setting seeded into `app_settings`, plus a new full-list admin page.

---

### 1. Restaurant `current_due` sign bug — real fix, not just cosmetic

**The bug:** `record_cod_order_ledger_entry()` wrote ONLY a
`commission_cod` `+commission_amount` entry for every delivered COD
order — billing the restaurant for commission with nothing ever
crediting them back for the rest of that order's value. Meanwhile
`record_paid_order_ledger_entries()` (online orders) already wrote a
single net `payout_payable` entry correctly. This asymmetry is exactly
why `current_due` could climb positive ("restaurant owes admin") for
COD-heavy restaurants — a state that doesn't actually make sense under
this platform's own cash model: COD cash flows Customer → Rider →
Admin in full (see docs/00_Deep_Plan...'s §0), so admin already holds
100% of that cash, same as it does for online orders via the payment
gateway. The restaurant never touches any of it either way — there was
never anything for them to "owe".

**Fix — `lib/ledger.php`:**
- `record_cod_order_ledger_entry()` now writes ONE entry per COD order
  — `payout_payable` for `-(grand_total - commission_amount -
  platform_fee)` — the exact same restaurant-share formula
  `record_paid_order_ledger_entries()` already uses for online orders.
  Commission is netted out of what admin owes, never billed separately.
- File-header sign-convention comment rewritten: `current_due` should
  now only ever go positive from a genuine admin overpayment, never
  from a phantom "commission owed" balance.
- `commission_cod` stays in the `entry_type` ENUM (historical rows,
  and code comments elsewhere reference it) but nothing writes it
  anymore.

**Historical data — `scripts/backfill-cod-payout-payable.php` (NEW):**
One-time script, run once after deploying this fix. Finds every
historical COD order with a `commission_cod` row but no matching
`payout_payable` row (i.e. every order the old bug left uncorrected)
and inserts the missing `payout_payable` entry via the same
`write_due_ledger_entry()` the live code uses — so `running_balance`
and `current_due` end up exactly as if it had been recorded correctly
at delivery time. Supports `--dry-run` to preview before writing.
Safe to re-run (skips orders that already have a `payout_payable` row).
**Must be run against the real database — nothing here touches
existing rows destructively, but it does need PHP CLI + live MySQL,
neither available in this sandbox, so it has NOT been run or verified
against real data yet.**

**Not touched:** `record_paid_order_ledger_entries()` (online orders)
was already correct, no change needed there.

---

### 2. Cash Flow — inline quick-action buttons ("Settle" / "Pay Now")

`admin/cash-flow.php` was previously read-only — every action ("Record
Settlement", "Pay Now") required leaving the page for
`rider-settlements.php` / `settlements.php`. Added:

- **Rider table**: each row now has an inline **"Settle"** form next
  to "View" (gated on `payouts_manage`, same as those detail pages) —
  amount pre-filled to `cod_cash_held`, posts back to this same file,
  calls `record_rider_settlement()` (the exact function
  `rider-settlements.php` already uses).
- **New "Restaurants with an Outstanding Balance" table**: lists every
  restaurant with a non-zero `current_due` (biggest first, same list
  `settlements.php` shows), each row with an inline **"Pay Now"** form
  — amount pre-filled to `abs(current_due)`, direction auto-picked
  from the sign, calls `record_settlement()` (same function
  `settlements.php` uses, just without the optional UTR/screenshot
  fields — that full form is still linked right next to it for when
  proof needs attaching).
- Both quick actions POST back to `cash-flow.php` itself; since every
  number on the page is already recomputed fresh from the ledger
  tables on every load (same as before this change), acting here
  "auto syncs" the page the moment it reloads — no separate sync step,
  no caching to invalidate.
- Added an explainer paragraph directly on the page for what
  "outstanding balance" means for a rider vs. a restaurant, per the
  app owner's question in the previous session.

---

### 3. Settings — seed every code-default into `app_settings`

**`sql/85_migration_seed_all_setting_defaults.sql` (NEW):** every
`get_setting($key, $default)` call in the codebase was audited
(48 distinct keys); any key with no existing `app_settings` row gets
one seeded with its own code-default value and a plain-English
description, via `INSERT ... ON DUPLICATE KEY UPDATE key = key` (same
deliberate no-op-on-conflict pattern migration 72 established) — never
overwrites a value an admin already customized, only fills real gaps.
One flagged inconsistency: `otp_length` defaults to `4` in one call
site and `6` in another — seeded as `4`, flagged in the row's own
description for the app owner to confirm.

**`admin/all-settings.php` (NEW) + nav entry:** a generic, searchable
list of every `app_settings` row (key, inline-editable value,
description) — the "dekh/check kar shke" ask. Gated on
`settings_manage`, listed in the sidebar under Settings as "All
Settings (Full List)". This doesn't replace the existing
topic-specific settings pages (commission-rules.php, otp-settings.php,
rider-earnings.php, etc.) — those still have real validation/range
checks this generic page doesn't; this is the catch-all view for
everything, including settings that had no dedicated page at all
before today.

---

### Not done / explicitly out of scope this session

- **Build/device verification** — same standing sandbox limitation as
  every phase before this one (no PHP CLI, no live MySQL here). All
  PHP files brace/paren-balance-checked only.
- **`scripts/backfill-cod-payout-payable.php` has not been run** — it
  needs a real database with actual historical COD orders to act on.
  Run it (with `--dry-run` first) right after deploying the
  `lib/ledger.php` fix, before trusting any restaurant's `current_due`
  figure on Cash Flow / Settlements.
- **`otp_length` 4-vs-6 default mismatch** — flagged, not resolved;
  confirm with app owner which call site is supposed to win.
- No change to `record_paid_order_ledger_entries()` (online orders) —
  it was already correct.
