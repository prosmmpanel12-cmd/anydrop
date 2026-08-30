# Handover — 2026-08-30 (doc 74): Prepaid-Cancel Auto-Wallet-Refund +
# Wallet Withdrawal — Backend Foundation Built, Session Ended Mid-Work

## What was asked

App owner request (Hinglish, paraphrased): fix payment/refund so that
if a **prepaid order is cancelled while still within the cancel
window**, the refund goes straight into the customer's in-app Wallet
(no manual admin step). Also add a **Wallet withdrawal** feature: a
withdraw option inside the wallet, asking for UPI/bank details (or
using already-saved ones) at withdrawal time, submitting a withdrawal
request; admin reviews and marks it paid. Also: "there's a PHP syntax
error somewhere on the admin refunds page" — investigate and fix.
Explicit instruction: don't introduce any vulnerabilities.

**Session ended (context/tool-call limit) partway through** — backend
foundation for both features is built and internally consistent, but
customer-facing API endpoints, the admin review UI, and all Android
work are NOT built yet. Do not tell the app owner either feature is
usable end-to-end; it is not.

## PHP syntax error — investigated, NOT found

No PHP CLI exists in this sandbox (standing constraint, confirmed
again this session — `apt-get install php-cli` fails, network egress
to the Ubuntu archive is blocked in this container). In its place:

- Wrote a comment/string/PHP-tag-aware brace-paren-bracket balance
  checker (`/home/claude/work/check_php.py` on the sandbox that built
  this — not part of the project repo, recreate if needed) and ran it
  across **all 170** `backend/**/*.php` files. Zero imbalances found
  anywhere in the project, including `admin/refunds.php`,
  `lib/refunds.php`, `admin/_bootstrap.php`, `admin/_layout_head.php`,
  `admin/_layout_foot.php`.
- Manually read `admin/refunds.php` and `lib/refunds.php` end to end
  looking for anything a brace-checker can't catch (stray quotes,
  short-tag issues, PHP-version-only syntax) — found nothing.

**Conclusion: could not reproduce.** The app owner reported this from
their own real machine/server, which is the only place it can
actually be confirmed (no PHP CLI here). **Next session should ask for
the exact error text + line number** rather than re-guessing blind —
that will point straight at the real file/line in seconds, versus
another blind full-project re-read.

## Backend — built this session

### Migration 65 (`backend/sql/65_migration_wallet_withdrawal_and_auto_refund.sql`)
No new table needed for the auto-refund half (the existing
`refunds`/`wallet_transactions`/`platform_ledger` shape already covers
it). New for the withdrawal half:
- `customer_bank_details` — one row per customer, same shape as
  `restaurant_bank_details` (migration 38) but no verification-status
  workflow on the row itself (review happens per withdrawal request
  instead, see below).
- `wallet_withdrawals` — full lifecycle table, `wallet_debit_txn_id`
  FK back to the exact `wallet_transactions` row that held the money,
  payout details **snapshotted** at request time (not a live join to
  `customer_bank_details`).
- `wallet_transactions.reason` ENUM widened: added `'withdrawal'`.
- `platform_ledger.entry_type` ENUM widened: added
  `'wallet_withdrawal_out'` (distinct from `'refund_out'` — a
  withdrawal isn't a refund).
- New `wallet_withdrawal_min_amount` app_setting (default 100).
- New `wallet_withdrawals_view` / `wallet_withdrawals_manage` admin
  permissions, granted to every role already holding `wallets_manage`.

**Not yet run on any DB** — same standing sandbox limitation as every
migration in this project.

### `lib/refunds.php` — new `auto_wallet_refund_on_cancel()`
Called from `orders/cancel.php` in place of the old
`create_refund_request()` call, **only** for the customer-self-cancel-
within-window path. Writes one `refunds` row already in its terminal
`refunded` state (method `wallet`, actor `system`), credits the wallet
via the existing `credit_wallet()`, all inside the cancel endpoint's
own transaction — a failure here rolls the cancellation back too.
Restaurant-reject (`orders-reject.php`) and admin-force-cancel
(`admin/orders.php`) are **unchanged** — those still create a
manual-review refund row exactly as before, since those aren't
customer-initiated, no-judgement-call cancellations.

### `api/v1/orders/cancel.php` — edited
Swapped the refund call as above; added the `orders.payment_status =
'refunded'` flip after it (kept in the endpoint, not the lib function
— see that function's own kdoc for why).

### `lib/wallet.php` — edited
`credit_wallet()`/`debit_wallet()`'s `$reason` whitelist both widened
to accept `'withdrawal'` (needed by the new withdrawal hold/reversal
flow below). No other change to this file.

### `lib/customer_wallet_withdrawal.php` — new
Full withdrawal library:
- `validate_wallet_payout_fields()` — bank OR upi method, different
  required fields per method.
- `save_customer_bank_details()` / `get_customer_bank_details()` /
  `serialize_customer_bank_details()` (masked, same convention as the
  restaurant version).
- `request_wallet_withdrawal()` — **the only place a withdrawal is
  created.** Debits the wallet immediately via the existing row-locked
  `debit_wallet()` before the withdrawal row even exists. This is the
  load-bearing security decision this session made: an up-front hold
  (not a hold-at-admin-approval-time) closes the double-spend window a
  later-hold design would leave open — a customer can't place a wallet
  order or request a second withdrawal against balance a pending
  request already claimed, because the balance is already gone the
  moment the request exists. No new locking code was written for this
  — it's 100% the same `debit_wallet()` every other wallet debit in
  the codebase already trusts.
- `admin_list_wallet_withdrawals()`, `approve_wallet_withdrawal()`,
  `mark_wallet_withdrawal_processing()`, `complete_wallet_withdrawal()`
  (writes the `wallet_withdrawal_out` platform-ledger entry),
  `reject_wallet_withdrawal()` (credits the hold back to the wallet).
  Lifecycle deliberately mirrors `lib/refunds.php`'s
  `requested → approved → processing → completed/rejected` shape —
  same admin mental model as the existing Refunds page.

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live DB in this
sandbox.

- Comment/string/tag-aware brace-paren-bracket balance check on all 5
  new/edited files this session (migration 65 SQL, `lib/refunds.php`,
  `lib/wallet.php`, `lib/customer_wallet_withdrawal.php`,
  `api/v1/orders/cancel.php`) — all balanced.
- Re-ran the same checker across the full 170-file backend tree
  (investigating the reported syntax error) — zero imbalances found
  project-wide.
- No `.htaccess` route, customer API endpoint, or admin UI exists yet
  for the withdrawal feature, so nothing beyond the checks above could
  be verified this session — see "Genuinely still open" below.

## Genuinely still open — this is NOT a usable feature yet

Backend library functions exist and are internally consistent, but
**nothing calls them from an actual HTTP endpoint yet**. Still needed,
roughly in order:

1. **Customer API endpoints** (none built yet):
   - `GET`/`POST` wallet bank-details (get saved details / save new
     ones) — thin wrappers around
     `get_customer_bank_details()`/`save_customer_bank_details()`.
   - `POST` withdrawal request — thin wrapper around
     `request_wallet_withdrawal()`, needs `require_auth('customer')`,
     field validation via `require_fields()`, then
     `validate_wallet_payout_fields()`.
   - `GET` withdrawal history for the customer (or fold into the
     existing `api/v1/customer/wallet.php` response) —
     `list_wallet_withdrawals_for_customer()` already exists to back
     this.
   - `.htaccess` clean routes for all of the above (see existing
     `wallet`/`complete-profile` entries for the pattern).
2. **Admin panel page** — new `admin/wallet-withdrawals.php`, same
   shape as `admin/refunds.php` (list + Approve/Mark
   Processing/Complete/Reject forms with CSRF), gated on the new
   `wallet_withdrawals_view`/`wallet_withdrawals_manage` permissions.
   Admin needs to see the **full, unmasked** account number/IFSC/UPI
   (same as `admin/settlements.php` already does for restaurant
   payouts) since they're the one sending the actual transfer.
3. **Android — Customer app**:
   - Wallet screen needs a "Withdraw" entry point (bank/UPI details
     form, pre-filled if already saved; amount field with the min-
     amount setting surfaced).
   - Withdrawal history / status list.
   - Nothing here has been started — no Models.kt/ApiService.kt
     changes, no new Activity, no layout.
4. **Migration 65 needs to actually run** on a live DB before any of
   the above can be tested even manually.
5. **The reported PHP syntax error** — see section above, needs the
   actual error text from the app owner's own machine to chase down.
6. **Live end-to-end test**, once built: place a UPI order → cancel it
   within the window → confirm wallet balance increases and one
   `refunds` row lands already `refunded`/`wallet`. Separately: submit
   a withdrawal request → confirm wallet balance drops immediately →
   Approve → Mark Processing (enter a reference) → Mark Completed →
   confirm `platform_ledger` gets a `wallet_withdrawal_out` row.
   Also test Reject from both `requested` and `approved` states →
   confirm the wallet balance is credited back exactly.

## Files touched this session

**Backend:** `backend/sql/65_migration_wallet_withdrawal_and_auto_refund.sql`
(new), `backend/lib/refunds.php` (edited — `auto_wallet_refund_on_cancel()`
added), `backend/lib/wallet.php` (edited — `withdrawal` reason
whitelisted), `backend/lib/customer_wallet_withdrawal.php` (new),
`backend/api/v1/orders/cancel.php` (edited).

**Docs:** this file; `PENDING.md` new §37 tracking this item;
`NEXT_SESSION_PROMPT.md` updated; `recall.md` appended.

**Android:** nothing touched.

## Suggested next session

Pick up exactly where this one stopped — the backend library layer for
both halves is done and self-consistent; what's missing is entirely
the "wire it up" layer: customer endpoints + `.htaccess` routes, the
admin review page, and Android. None of that is architecturally
uncertain (this doc + `lib/customer_wallet_withdrawal.php`'s own kdoc
spell out the exact shape to follow, mirroring `admin/refunds.php` and
`bank-details-get.php`/`bank-details-save.php` closely enough to copy
their patterns directly) — it's just volume of remaining work. Also
ask the app owner for the exact PHP syntax error text before spending
time re-hunting for it blind.
