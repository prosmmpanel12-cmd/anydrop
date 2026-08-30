# Handover — 2026-08-30 (doc 75): Wallet Withdrawal — Customer
# Endpoints, Admin Page, and Android All Built. Migration + Live Test
# + PHP Syntax Error Still Open.

## What was asked

Continue exactly where doc 74 (session 18) left off: PENDING.md §37's
remaining checklist — customer API endpoints, the admin review page,
Android, then migration 65 run + live end-to-end test. This session
picked up the "wire it up" layer doc 74 explicitly deferred; no new
architecture decisions were needed, everything followed the shape
`lib/customer_wallet_withdrawal.php`'s own kdoc and `admin/refunds.php`/
`bank-details-get.php`/`bank-details-save.php`'s existing patterns
already spelled out.

## Backend — built this session

### Customer API endpoints (new)
- `api/v1/customer/wallet-bank-details-get.php` — GET, thin wrapper
  around `get_customer_bank_details()`/`serialize_customer_bank_details()`,
  same shape as `restaurant/bank-details-get.php`.
- `api/v1/customer/wallet-bank-details-save.php` — POST, thin wrapper
  around `validate_wallet_payout_fields()`/`save_customer_bank_details()`.
- `api/v1/customer/wallet-withdrawal.php` — combined GET (history, via
  `list_wallet_withdrawals_for_customer()`) / POST (request, via
  `request_wallet_withdrawal()`) in one file, same "auth once, branch
  on method" shape a couple of other endpoints in this codebase
  already use. POST failure responses map `insufficient_balance` /
  `below_minimum_amount` / `invalid_amount` to HTTP 422, anything else
  to 400.

### `.htaccess` — 3 new routes added under the existing "Item 26 —
Customer Wallet" section:
```
/api/v1/customer/wallet/bank-details          -> wallet-bank-details-get.php
/api/v1/customer/wallet/bank-details/save     -> wallet-bank-details-save.php
/api/v1/customer/wallet/withdrawal            -> wallet-withdrawal.php
```
(Android talks to the `.php` files directly, same convention every
other endpoint in `ApiService.kt` already follows — these pretty
routes exist for completeness/direct-hit testing, not because the app
needs them.)

### `admin/wallet-withdrawals.php` (new)
Full review page, deliberately mirrors `admin/refunds.php`'s structure
line for line: list table, Approve/Mark Processing/Complete/Reject
forms with CSRF, gated on `wallet_withdrawals_view`/
`wallet_withdrawals_manage` (migration 65's permission pair, already
granted to every `wallets_manage` role). One real difference from
refunds.php: the payout details column shows the **full, unmasked**
account number/IFSC/UPI (same as `admin/settlements.php` already does
for restaurant payouts) since the admin is the one sending the actual
transfer — this is the one place in the app that intentionally does
NOT mask payout details. Nav entry added in `admin/_layout_head.php`
(new `wallet_withdrawals` key, finance group, right after `refunds`).

## Android — built this session (customer app)

**Models** (`network/Models.kt`): `CustomerBankDetails`,
`BankDetailsResult`, `SaveBankDetailsBody`, `WalletWithdrawal`,
`WalletWithdrawalHistoryResult`, `RequestWithdrawalBody`,
`RequestWithdrawalResult` — field-for-field mirrors of the new
endpoints' JSON shapes, confirmed by reading the actual PHP files
(same discipline `WalletTransaction` already followed for `wallet.php`).

**API** (`network/ApiService.kt`): `getWalletBankDetails()`,
`saveWalletBankDetails()`, `getWalletWithdrawalHistory()`,
`requestWalletWithdrawal()` — direct-hit `.php` filenames, same
convention as every other method in this interface.

**`WithdrawActivity`** (new) — reachable from a new "Withdraw" button
on `WalletActivity`'s balance card. One screen, two sections:
- Form: amount field, bank/UPI method toggle (`MaterialButtonToggleGroup`),
  account holder name (shared by both methods), bank name/account
  number/IFSC (bank method) or UPI ID (upi method) — fields show/hide
  based on the toggle, same-screen validation mirrors
  `validate_wallet_payout_fields()`'s server-side rules (9–18 digit
  account number, `AAAA0XXXXXX`-shape IFSC, basic UPI-ID regex) so bad
  input is caught before a round-trip, not instead of the server check.
  On load, pre-fills the form from `wallet-bank-details-get.php` if the
  customer has saved details before — **account number is deliberately
  NOT pre-filled** even though holder name/bank name/IFSC/UPI ID are,
  since the get endpoint only ever returns a masked account number
  (see `WithdrawActivity`'s own kdoc for the full reasoning — same
  "never echo a full saved account number back into an editable field"
  principle the restaurant side already follows).
- History: read-only list below the form (`WalletWithdrawalAdapter` +
  `item_withdrawal.xml`, new `bg_status_pill.xml` drawable tinted per
  status at bind time — requested/processing/approved/completed/
  rejected each get a distinct color, rejected rows additionally show
  the reject reason inline).

Submitting a request does **not** re-save bank details as a separate
step — `request_wallet_withdrawal()` snapshots whatever the form
submits directly onto the `wallet_withdrawals` row (this is
migration 65's own design, see its header comment on why payout
details are snapshotted, not live-joined); this screen does not call
`wallet-bank-details-save.php` at all, only `wallet-withdrawal.php`'s
POST. (If the app owner wants "save my details for next time" as a
separate action later, that's a small additive follow-up — the save
endpoint already exists and works, it's just not wired to this
screen's submit button.)

`WalletActivity` changes: added the "Withdraw" button
(`btnWithdraw`) to `activity_wallet.xml`'s balance card, wired it to
launch `WithdrawActivity`, and moved the initial `loadWallet()` call
into `onResume()` (was previously only called once from `onCreate()`)
so the balance/history refresh automatically when the customer
returns from submitting a withdrawal — without this, a customer could
submit a withdrawal, come back, and see a stale balance until a manual
pull-to-refresh.

**Manifest**: `WithdrawActivity` registered (`exported="false"`, same
as every other internal activity in this app).

**Strings**: ~20 new entries in `strings.xml` for the form, history,
and status labels — no hardcoded user-facing text in any new file.

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live DB in this
sandbox.

- Recreated the comment/string/tag-aware brace-paren-bracket balance
  checker (`check_php.py`) and ran it on all 9 backend files touched
  across this session and session 18 (new + previously-edited) —
  all balanced.
- Wrote an equivalent Kotlin-aware checker (`check_kt.py` — same
  approach, adjusted for Kotlin's `"""..."""` raw strings, no heredoc)
  and ran it on all 5 new/edited Kotlin files — all balanced.
- Ran `xml.dom.minidom` well-formedness checks on all 6 new/edited XML
  files (2 new layouts, 1 new drawable, `strings.xml`,
  `AndroidManifest.xml`, and the edited `activity_wallet.xml`) — all
  parse cleanly.
- Manually traced `WalletActivity` → `WithdrawActivity`'s class
  reference to confirm the file that was missing when this session
  started (flagged in the prior response as a build-breaker) now
  exists and matches the referenced constructor/package.

**Not build/device-verified** — same standing sandbox limitation as
every session in this project (no PHP CLI, Android SDK/Gradle, or live
DB here). The balance/well-formedness checks catch syntax-level
breakage; they cannot catch a wrong Gson field mapping, a Retrofit
route mismatch the compiler would catch, or any runtime-only bug.

## Genuinely still open

1. **Migration 65 has still not been run on any live DB.** Nothing in
   this session changed that — same blocker as doc 74 left it.
2. **The reported PHP syntax error on the admin Refunds page is still
   unresolved.** Not re-investigated this session (doc 74's full
   170-file sweep already came up empty) — still needs the app
   owner's actual error text + line number from their own machine.
3. **Live end-to-end test**, once migration 65 runs: submit a
   withdrawal as a customer → confirm balance drops immediately →
   Approve → Mark Processing (reference) → Mark Completed → confirm
   `platform_ledger` gets a `wallet_withdrawal_out` row. Separately:
   Reject from both `requested` and `approved` states → confirm the
   wallet balance is credited back exactly. Also test the auto-refund
   half from doc 74: cancel a UPI order within the cancel window →
   confirm wallet balance increases and a `refunds` row lands already
   `refunded`/`wallet`.
4. **Android Studio/Gradle compile check** — the balance/XML checks
   above are not a substitute for an actual build; view-binding class
   names (`ActivityWithdrawBinding`, `ItemWithdrawalBinding`) are
   assumed correct based on this project's existing naming convention
   (PascalCase of the XML filename + `Binding`) but have not been
   generated/confirmed by Gradle.
5. **"Save my bank details" as its own action** is not wired to any
   UI — the save endpoint works standalone but `WithdrawActivity`'s
   submit only ever calls the withdrawal-request endpoint (see the
   Android section above for why that's fine for v1, but flag it if
   the app owner specifically wants a separate "save for later"
   button).

## Files touched this session

**Backend:** `backend/api/v1/customer/wallet-bank-details-get.php`
(new), `wallet-bank-details-save.php` (new), `wallet-withdrawal.php`
(new), `backend/.htaccess` (edited — 3 new routes),
`backend/admin/wallet-withdrawals.php` (new),
`backend/admin/_layout_head.php` (edited — nav entry + activeNav
docblock).

**Android (customer app):** `network/Models.kt` (edited — 7 new data
classes), `network/ApiService.kt` (edited — 4 new methods),
`ui/profile/WithdrawActivity.kt` (new),
`ui/profile/WalletWithdrawalAdapter.kt` (new),
`ui/profile/WalletActivity.kt` (edited — Withdraw button +
onResume refresh), `res/layout/activity_withdraw.xml` (new),
`res/layout/item_withdrawal.xml` (new),
`res/layout/activity_wallet.xml` (edited — Withdraw button),
`res/drawable/bg_status_pill.xml` (new), `res/values/strings.xml`
(edited — ~20 new strings), `AndroidManifest.xml` (edited —
`WithdrawActivity` registered).

**Docs:** this file; `PENDING.md` §37 updated; `NEXT_SESSION_PROMPT.md`
updated; `recall.md` appended.

## Suggested next session

With the entire "wire it up" layer now built for both customer and
admin, PENDING.md §37's only remaining work is verification, not new
code:

1. Ask the app owner for the exact PHP syntax error text — still
   unresolved, still a quick win once the real error is in hand.
2. Run migration 65 on a live DB.
3. Live end-to-end test both halves (exact steps in "Genuinely still
   open" #3 above).
4. Real Android Studio/Gradle build + device click-through — confirm
   the view-binding classes generate as assumed, the toggle group
   field show/hide behaves correctly on a real device, and the status
   pill colors render as intended.

Once all four are done, §37 can move to `done.md` per the project's
own completion rule (source → migration → API → UI → device → live DB
→ security/edge case, all verified). Only after that should the next
session return to the older priority list — Rider App (largest
remaining untouched phase), Payment/Refund Reconciliation, Email OTP
Provider Failover, Security Hardening.
