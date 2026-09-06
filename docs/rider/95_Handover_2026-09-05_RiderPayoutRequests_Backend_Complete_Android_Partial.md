# Handover — Rider Self-Service Payout Requests, BACKEND COMPLETE / ANDROID PARTIAL

Session date: 05 Sep 2026. Deep-plan §21 ("Payouts"), the piece doc 90
explicitly scoped OUT of the Rider Earnings ledger screen ("No
payout-request action here either — doc 90 explicitly called that flow
... a separate unbuilt piece"). Chosen over deep-plan §22 (Rider
Documents) — the person's own pick when asked, since doc 90's next-step
note said to confirm before picking between the two rather than guess.

> **Not built/tested against a live backend or device — and Android is
> only half-built this session (see "Still open" below, item 0).** Same
> standing caveat as every other handover in this project — no PHP
> CLI, Android SDK, Gradle, or DB in this sandbox. Backend PHP checked
> by hand for balanced braces/parens only; `php -l` isn't available
> here either. Treat as `🟡 IMPLEMENTED — TEST PENDING` per `done.md`'s
> own rule once Android is finished, and as **INCOMPLETE** until then.

## Design — mirrors migration 65 (Customer Wallet Withdrawal) closely, deliberately

Same admin, same "no real payout gateway, human sends it, this system
tracks it" model already established for customer wallet withdrawals,
restaurant settlements, and refunds — not a new pattern invented from
scratch:

```text
Rider earnings_balance
     ↓
Payout request (rider app) — debits earnings_balance IMMEDIATELY
     ↓
Admin review: Approve
     ↓
Mark Processing (admin sends the transfer themselves, records ref)
     ↓
Mark Completed (platform_ledger gets the money-out entry)
```

Reject is an off-ramp from `requested`/`approved` only (not
`processing` — by then real money has already moved externally), same
reasoning `reject_wallet_withdrawal()` already uses, and credits the
hold back via `rider_earnings_ledger`'s existing `adjustment_credit`
entry type — no new ledger entry type needed for the reversal.

**Security-critical decision (copied, not re-derived):** the earnings
balance is debited AT REQUEST TIME, not at admin-approval time — closes
the double-spend window an approval-time-only hold would leave open.
Reuses `write_rider_earnings_ledger_entry()`'s existing row-locked
writer; no new locking code written.

This is deliberately a **separate** entry point from
`admin/rider-earnings.php`'s existing "Record Payout" form (an admin
proactively paying a rider with no request behind it) — both correctly
write the same `entry_type='payout'` ledger rows and update the same
`earnings_balance` column; they're two independent ways into one
ledger, same as the customer side already has (admin wallet
adjustments vs. customer-initiated withdrawal).

## What changed — Backend (complete this session)

### `backend/sql/74_migration_rider_payout_requests.sql`
- New `rider_bank_details` table — same shape as `customer_bank_details`
  (migration 65)/`restaurant_bank_details` (migration 38), one row per
  rider, create-or-replace. No `verification_status` workflow on this
  row itself (review happens per REQUEST, not per bank-details save).
- New `rider_payout_requests` table — `earnings_debit_ledger_id` FK
  points at the exact `rider_earnings_ledger` row that placed the hold
  (traceability, same principle as `wallet_withdrawals.wallet_debit_txn_id`).
  Payout details are a SNAPSHOT at request time (not a live join), same
  reasoning `wallet_withdrawals`'s own snapshot columns already follow.
  Status lifecycle identical shape to `wallet_withdrawals.status`.
- `platform_ledger.entry_type` widened: `+ 'rider_payout_out'`.
- `notifications.type` widened: `+ 'payout'` — deliberately a new,
  actor-neutral value (unlike migration 43's `'wallet'`, which reads as
  customer-specific) so it can cover this rider flow now and any future
  restaurant/customer payout notification later without growing a new
  value per actor.
- New setting `rider_payout_min_amount` (default 100), same
  "never hardcode a business rule" convention as
  `wallet_withdrawal_min_amount`.
- New RBAC pair `rider_payouts_view`/`rider_payouts_manage` —
  deliberately separate from the existing broad `payouts_view`/
  `payouts_manage` (already shared today by Settlements, Rider
  Earnings, Platform Ledger, and Commission Rules) since reviewing a
  RIDER-INITIATED request against an outside bank/UPI account is its
  own distinct blast radius — same reasoning migration 65 kept
  `wallet_withdrawals_*` separate from `wallets_manage`. Granted to
  every role currently holding `payouts_manage` (today, just Super
  Admin) — no access silently reduced.

### New: `backend/lib/rider_payout.php`
Full library, closely mirrors `lib/customer_wallet_withdrawal.php`
function-for-function:
- `validate_rider_payout_fields()` — same loose IFSC/account-number
  regex checks as the customer side; a UPI-only request needs no bank
  fields. Deliberately NOT shared/imported from the customer file even
  though the rules are identical today — different actor, independent
  validation, so a future rider-only rule doesn't have to touch the
  customer function.
- `save_rider_bank_details()` / `get_rider_bank_details()` /
  `serialize_rider_bank_details()` (masked account number).
- `rider_payout_min_amount()`.
- `request_rider_payout()` — locks `riders.earnings_balance` under
  `FOR UPDATE`, checks it covers the request + the minimum-amount
  setting, then calls `write_rider_earnings_ledger_entry()`
  (`lib/rider_earnings.php`) to do the actual debit+insert (that
  function re-locks the same row inside the same transaction — harmless,
  MySQL row locks don't conflict with themselves in one transaction —
  and avoids duplicating its UPDATE+INSERT logic here). Inserts the
  `rider_payout_requests` row referencing that ledger row's id.
- `list_rider_payout_requests_for_rider()` / `admin_list_rider_payout_requests()`.
- `approve_rider_payout_request()` / `mark_rider_payout_request_processing()`
  / `complete_rider_payout_request()` (writes the `rider_payout_out`
  platform-ledger entry, only here, at completion) /
  `reject_rider_payout_request()` (reverses via
  `record_rider_earnings_adjustment(..., isCredit: true, ...)` —
  no bespoke reversal write, reuses the existing adjustment primitive).
- Every state-changing function writes its own `write_audit_log()` entry
  and (where relevant) a rider-facing `create_notification()` call —
  see "Known gap" below re: the rider app has no way to surface these
  yet.

### New backend endpoints (rider auth, no `.htaccess` clean routes —
matches every other `rider/*.php` endpoint in this codebase, which are
all called by filename directly, unlike customer/restaurant)
- `GET/POST api/v1/rider/payout.php` — history / request. Thin wrapper
  around the library, same division of responsibility as
  `customer/wallet-withdrawal.php`.
- `GET api/v1/rider/payout-bank-details-get.php`
- `POST api/v1/rider/payout-bank-details-save.php`

### New: `backend/admin/rider-payouts.php`
Review queue, mirrors `admin/wallet-withdrawals.php` closely: full list
with Approve / Reject (from `requested`), Mark Processing / Reject
(from `approved`), Mark Completed (from `processing`), unmasked payout
details shown (admin is the one sending the transfer). Gated on the new
`rider_payouts_view`/`rider_payouts_manage` pair. Wired into
`admin/_layout_head.php`'s nav (Finance group, right after "Rider
Earnings") and its `$activeNav` doc-comment enum.

## What changed — Android (PARTIAL — this is the incomplete half)

### `rider/.../network/Models.kt`
Added, mirroring the customer app's wallet-withdrawal models field-for-
field (same backend design, same JSON shape): `RiderBankDetails`,
`RiderBankDetailsResult`, `SaveRiderBankDetailsBody`,
`RiderPayoutRequest`, `RiderPayoutHistoryResult`,
`RequestRiderPayoutBody`, `RequestRiderPayoutResult`. Inserted right
after `EarningsLedgerEntry`, before the Service Areas section.

**Nothing else on the Android side was built this session.** No
`ApiService.kt` entries, no Activity, no Adapter, no layouts, no
manifest entry, no strings, no button wired into `EarningsActivity`.
The backend is fully callable (via a REST client / curl once deployed)
but there is currently **no in-app way for a rider to use this
feature**.

## Still open — next steps, in order

0. **Finish the Android half** (the actual remaining work from this
   session, not a standing sandbox limitation):
   - `ApiService.kt` — 4 new `@GET`/`@POST` entries mirroring the
     customer app's `getWalletBankDetails()`/`saveWalletBankDetails()`/
     `getWalletWithdrawalHistory()`/`requestWalletWithdrawal()` exactly,
     pointed at `rider/payout-bank-details-get.php` /
     `-save.php` / `rider/payout.php` (GET) / `rider/payout.php` (POST).
   - New `ui/earnings/RequestPayoutActivity.kt` — model directly on
     `customer/.../ui/profile/WithdrawActivity.kt` (balance snapshot
     passed in or re-fetched via `getEarningsSummary()`, bank/UPI
     method toggle, saved-details pre-fill with the same
     never-pre-fill-a-full-account-number caveat, submit + history list
     below).
   - New `ui/earnings/RiderPayoutAdapter.kt` — model on
     `WalletWithdrawalAdapter.kt` (status pill colors, reject-reason
     line, date formatting).
   - New layouts `activity_request_payout.xml` (model on
     `activity_withdraw.xml`) and `item_rider_payout.xml` (model on
     `item_withdrawal.xml`).
   - `AndroidManifest.xml` — new `<activity>` entry, `exported="false"`,
     matching this file's existing per-activity comment convention.
   - `strings.xml` — payout title/hints/status labels/error strings,
     mirroring the customer app's `withdraw_*` string block naming.
   - `EarningsActivity.kt` + `activity_earnings.xml` — add a "Request
     Payout" button (below the share-percent note, above "RECENT
     ACTIVITY") that opens `RequestPayoutActivity`, same relationship
     `WalletActivity`'s own "Withdraw" button has to `WithdrawActivity`.
   - Brace/paren balance + XML well-formedness checks on everything
     above, same as every other handover in this project.
1. **Migration 74 run on a live DB.**
2. **`php -l` on the 5 new/touched backend files** (no PHP CLI in this
   sandbox — only manual brace/paren balance was possible; see the
   individual files' own byte-count checks done this session).
3. **Live click-through** (needs step 0 finished first): request a
   payout as a rider → confirm balance drops immediately → confirm a
   `requested` row appears on `admin/rider-payouts.php` → Approve →
   Mark Processing (reference) → Mark Completed → confirm
   `platform_ledger` gets a `rider_payout_out` row. Separately test
   Reject from both `requested` and `approved` → confirm the rider's
   `earnings_balance` is credited back exactly (visible on
   `EarningsActivity`'s balance figure and on `admin/rider-earnings.php`'s
   detail-mode ledger).
4. **Known gap, not fixed this session:** the rider app has no FCM
   integration and no in-app notification/bell surface at all yet
   (deep-plan §23 is entirely unbuilt) — `create_notification()` is
   still called on approve/complete/reject (writes the `notifications`
   table row, same "record it always" discipline every other part of
   this codebase follows), but a rider currently has no way to see that
   row. Not a regression from this session — just flagging that the
   push/bell half of "Payout approved"/"Payout completed" won't
   actually reach a rider's device until §23 exists.
5. Once this is fully closed, PENDING.md item 22 ("Rider
   Earnings/Settlement") should be re-audited — the checklist there
   ("Payout history", parts of "Rider ledger"/"Settlement") is now
   substantially covered between this feature and doc 90's Earnings
   screen, but that file wasn't touched this session and still shows
   the stale all-unchecked state from before either was built.
