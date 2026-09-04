# Handover — 2026-08-30 (doc 76): Payment / Refund / Settlement
# Reconciliation — Migration + Detection Layer + Admin Queue Built.
# Live Verification Still Open.

## What was asked

§37 (Wallet Withdrawal) was confirmed code-complete with only live
verification left (none of it doable in this sandbox), so the app
owner picked the next item off `PENDING.md`'s priority list:
Payment/Refund Reconciliation (item 24).

## Doc-audit finding (before any new code)

Before building anything, the existing payment/refund code was read
in full to see what "reconciliation" already had to lean on. That
turned up code that neither `recall.md` §28 nor `PENDING.md` item 24
mentioned at all: **Paytm auto-verify + `provider_bank_ref` dedupe**
(migrations `41_migration_upi_antifraud_hardening.sql`,
`42_migration_paytm_autoverify_dedupe.sql`,
`43_migration_dedupe_payment_providers.sql`,
`UpipeProvider::tryAutoVerify()`, `lib/payment/PaytmStatusClient.php`).
This is real, working code — a `UNIQUE` constraint on both `utr` and
`provider_bank_ref` in `payment_transactions`, plus an auto-verify path
that checks Paytm's own transaction status API before falling back to
manual admin review. It answers most of PENDING.md's "Provider
transaction matching" and "Duplicate transaction detection" bullets
already, at the DB-constraint level — this session's build only had to
add the one gap those constraints don't close (see
`order_multiple_successful_transactions` below) plus everything
downstream of a transaction (refunds, settlements, wallet).
`recall.md` §28 and `PENDING.md` item 24 have both been corrected to
mention this.

## What was actually missing

Reading `lib/refunds.php`, `lib/ledger.php`, and every relevant
migration end to end, the real gap wasn't detection logic that didn't
exist conceptually — every write path (`complete_refund()`,
`complete_refund_to_wallet()`, `record_settlement()`,
`debit_wallet_for_order()`, `credit_wallet()`) already writes its
matching ledger/wallet row inside the same transaction as the row it's
paired with. The actual gaps were:

1. **No standing check that those pairs stay in sync.** Nothing
   verified after the fact that a paid order really has a successful
   transaction, that a refunded refund really has its ledger entry,
   or that a wallet balance still equals its own transaction history.
   Doc 21 §5.6's own instruction — "never trust only the client-side
   success callback" — was a one-time code-review rule, not a
   standing check.
2. **`restaurant_due_ledger` had no way to link back to the
   `restaurant_payments` row that caused a settlement entry.**
   `platform_ledger` has had a `restaurant_payment_id` column for this
   exact purpose since migration 38 (commission/settlement migration);
   `restaurant_due_ledger` never got the equivalent column, so
   settlement reconciliation could only ever be fuzzy amount/timestamp
   matching, never an exact join.
3. **No persisted, admin-reviewable queue.** `admin/platform-ledger.php`
   already shows one inline balance check (Net Balance Held vs
   `-1×SUM(negative current_due)`), but it's a single number recomputed
   on every page load — no history, nothing to mark resolved, nothing
   an admin can dismiss as "already reviewed, not a real problem."

## Built this session

### Migration 66 (`backend/sql/66_migration_payment_refund_reconciliation.sql`)

Two independent, additive changes:

- **`restaurant_due_ledger.restaurant_payment_id`** — new nullable FK
  column + index. Backfilled for existing history with a best-effort
  match: same restaurant, matching direction/amount sign, created
  within 120 seconds of the matching `restaurant_payments` row
  (`record_settlement()` writes both inside one request, so a real
  match is seconds apart, not merely same-day). Deliberately 1:1 via
  `ROW_NUMBER()` on both sides — an ambiguous case (two settlements of
  the same amount to the same restaurant in the same 120s window) is
  left unlinked rather than guessed at, and will surface as its own
  `settlement_missing_ledger_entry` flag afterward for a human to
  actually look at.
- **`reconciliation_flags`** table — the persisted mismatch queue.
  Unique on `(flag_type, entity_type, entity_id)` so re-running a scan
  updates existing rows instead of duplicating them. `status` is
  `open`/`resolved`/`ignored`.
- New RBAC keys `reconciliation_view` / `reconciliation_manage`,
  granted to whatever role already holds `payment_providers_manage`
  (today, just Super Admin) — same "extend to existing holders of a
  related permission" pattern every prior finance-feature migration
  here has used.

### `backend/lib/ledger.php` (edited)

`write_due_ledger_entry()` gained an optional 9th parameter,
`?int $restaurantPaymentId`, written straight into the new column.
`record_settlement()`'s two branches (`admin_to_restaurant` /
`restaurant_to_admin`) now pass `$paymentId` through, so every
settlement from this migration forward gets the real link — the
migration's backfill query only ever needed to run once, for history.

### `backend/lib/reconciliation.php` (new)

Explicitly a **detection layer only** — every function in this file is
a read-only `SELECT`; nothing here ever writes to
`orders`/`payment_transactions`/`refunds`/`*_ledger`/
`wallet_transactions`. The only write anywhere in this file is a row
in `reconciliation_flags`. This was a deliberate choice, not an
oversight: an automatic financial correction based on a heuristic
match is exactly the kind of silent-drift risk reconciliation exists
to catch, not cause — every flag needs a human's note to resolve.

11 checks, each its own function so a false positive is traceable to
one exact rule instead of a tangle of joins:

| Check | What it catches |
|---|---|
| `payment_confirmed_order_not_paid` | Successful transaction, order never flipped to `paid` |
| `paid_upi_order_missing_transaction` | Order marked paid/refunded via UPI, no backing transaction |
| `wallet_order_missing_debit` | Order paid by wallet, no matching debit row |
| `order_multiple_successful_transactions` | Two+ successful/refunded transactions on one order (possible double charge) — the one gap the UTR/provider-ref UNIQUE constraints don't close, since those only stop reuse *across* orders |
| `refund_missing_ledger_entry` | Manual-transfer refund marked refunded, no `platform_ledger` `refund_out` row |
| `wallet_refund_missing_credit` | Wallet refund marked refunded, no wallet credit row |
| `wallet_refund_unexpected_ledger_entry` | Wallet refund that *also* has a `refund_out` ledger row (money double-counted as leaving the platform) |
| `order_refunded_no_refund_record` | Order marked `refunded`, no `refunds` row at all |
| `settlement_missing_ledger_entry` | Verified settlement with no linked due-ledger row |
| `wallet_balance_drift` | `customer_wallets.balance` doesn't equal its own transaction-history sum |
| `platform_balance_mismatch` | Same check `platform-ledger.php` already shows inline, now also persisted here |

Plus `run_reconciliation_scan()` (runs all 11), `persist_reconciliation_
flags()` (upsert with dedup/reopen/refresh logic — see the file's own
kdoc for exactly how an `ignored` flag is protected from resurfacing
while a `resolved` one reopens if the same problem is detected again),
and `resolve_reconciliation_flag()`/`ignore_reconciliation_flag()`
(both require a note, both call `write_audit_log()`).

### `backend/admin/reconciliation.php` (new)

Mirrors `admin/refunds.php`'s structure: "Run Reconciliation Scan"
button, a filterable table (status/type), Resolve/Ignore buttons that
`prompt()` for a required note before submitting. Gated on
`reconciliation_view` (list) / `reconciliation_manage` (scan +
resolve/ignore). Nav entry added to `admin/_layout_head.php` (new
`reconciliation` key, finance group, right after `wallet_withdrawals`;
`activeNav` docblock list updated too).

## Genuinely still open

1. **Migration 66 has never run against a live DB.** The backfill
   query's 120-second window is a reasoned guess based on how
   `record_settlement()`'s code is structured (both writes happen
   inside one PHP request with no work between them), not something
   tested against real historical timestamps. First live run should
   include eyeballing how many settlement rows the backfill actually
   linked vs. left `NULL`.
2. **No PHP CLI in this sandbox** — only a manual brace/paren/bracket
   balance check was possible on the 3 touched/new backend files
   (`lib/ledger.php`, `lib/reconciliation.php`, `admin/reconciliation.
   php`), not a real `php -l`.
3. **No live click-through at all.** Needs: run a scan against a
   clean/mostly-clean DB (expect at or near zero flags — the platform
   balance check only fires past ₹0.50 drift by design, to avoid
   flagging float rounding noise); then deliberately break one
   invariant by hand (e.g. delete a `platform_ledger` `refund_out` row
   for a refunded order) and confirm the matching check fires on the
   next scan; confirm Resolve and Ignore both round-trip correctly
   (status, `resolved_by_admin_id`, `resolved_at`, `resolution_note`
   all set); confirm an `ignored` flag does NOT resurface on the next
   scan while a still-broken `open` one does.
4. **No scheduler/cron exists anywhere in this codebase** to run the
   scan automatically. `wallet.php`'s own cashback-expiry note already
   flagged this same standing gap. This scan is admin-triggered only
   for now — running it daily needs that infrastructure to exist
   first, which is out of scope for this session.
5. **Webhook-based payment confirmation** (the original diagram in doc
   21 §5.6) still doesn't apply — there is no live payment gateway yet,
   only the manual/auto-verify UPIPE stub. Revisit once Phase E's
   real-gateway work happens (doc 23 §9).

## Files touched this session

**Backend:** `backend/sql/66_migration_payment_refund_reconciliation.sql`
(new), `backend/lib/ledger.php` (edited — `write_due_ledger_entry()`
+ `record_settlement()`), `backend/lib/reconciliation.php` (new),
`backend/admin/reconciliation.php` (new), `backend/admin/_layout_head.php`
(edited — nav entry + `activeNav` docblock).

**Docs:** this file; `PENDING.md` item 24 + Phase E checklist line
updated; `recall.md` §28 and Phase C build-order item 27 updated;
`NEXT_SESSION_PROMPT.md` updated.

## Suggested next session

Same shape as every other recently-built item in this project: the
code is done, only verification is left, and none of that verification
is Claude-actionable in this sandbox (no PHP CLI, no live DB, no
Android SDK, no network). Once the app owner has run migration 66 and
walked through the 4 "genuinely still open" items above, item 24 can
move to `done.md`.

Until then, the next Claude-actionable choice is the same short list
as last session: Rider App (item 18, the largest remaining untouched
phase), Email OTP Provider Failover (item 25), or Security Hardening
(item 26).
