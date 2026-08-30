Anydrop project zip attached. Unzip it, read `PENDING.md` first (the
source-checked pending list, §37 especially), then
`docs/75_Handover_2026-08-30_Wallet_Withdrawal_Endpoints_AdminPage_And_Android_Built.md`
for exactly what this session did, why, and what's still missing.

## Kiya hua hai (what's done, as of 2026-08-30 session 19 — doc 75)

Session 18 (doc 74) built the backend LIBRARY foundation for two
app-owner requests (prepaid-cancel auto-refund-to-wallet, and wallet
withdrawal) but wired nothing to an HTTP endpoint. **This session (19)
finished the entire "wire it up" layer for both:**

1. **Customer API endpoints — built.** `api/v1/customer/wallet-bank-
   details-get.php` / `wallet-bank-details-save.php` /
   `wallet-withdrawal.php` (GET history / POST request), all thin
   wrappers around session 18's `lib/customer_wallet_withdrawal.php`.
   3 new `.htaccess` routes.
2. **Admin review page — built.** `admin/wallet-withdrawals.php`,
   mirrors `admin/refunds.php`'s exact shape (list + Approve/Mark
   Processing/Complete/Reject, CSRF), shows the admin the FULL
   unmasked payout details (same as `admin/settlements.php` does for
   restaurants). Nav entry added.
3. **Android — built.** New `WithdrawActivity` (bank/UPI form +
   withdrawal history list) reachable from a new "Withdraw" button on
   `WalletActivity`. New `WalletWithdrawalAdapter` + layouts + status-
   pill drawable. 7 new Retrofit models, 4 new `ApiService` methods.
   `WalletActivity` also fixed to refresh on `onResume()` (previously
   only loaded once) so returning from a withdrawal shows the updated
   balance.

Full detail, every file touched, and the precise "what's built vs
what still needs a live DB/device" line is all in doc 75 — read it
before writing any new code here.

## Kiya pending hai (what's still pending)

**PENDING.md §37 is now code-complete on both halves.** What remains
is verification, not new code:

1. Get the exact PHP syntax error text from the app owner (admin
   Refunds page) — still unresolved since session 18, still a quick
   win once the real error text is in hand. Do this first if the app
   owner is available to ask.
2. Run migration 65 on a live DB.
3. Live end-to-end test — auto-refund half: cancel a UPI order within
   the window, confirm wallet balance up + one `refunds` row
   `refunded`/`wallet`. Withdrawal half: request → balance drops
   immediately → Approve → Mark Processing (reference) → Mark
   Completed → confirm `platform_ledger` gets a `wallet_withdrawal_out`
   row; separately test Reject from both `requested` and `approved` →
   balance credited back exactly.
4. Real Android Studio/Gradle build + device click-through — confirm
   view-binding classes generate as assumed (they follow this
   project's existing naming convention but were not Gradle-confirmed
   this session), the bank/UPI toggle behaves correctly on-device, and
   the status pill colors render as intended.

Once all four are done, §37 moves to `done.md` per the project's own
completion rule.

After §37 is actually complete end-to-end, the prior priority order
from session 17/18 still applies:

5. **Rider App** (PENDING.md item 18) — still fully untouched, largest
   remaining phase.
6. Payment/Refund Reconciliation (item 24).
7. Email OTP Provider Failover (item 25).
8. Security Hardening (item 26).
9. A real machine build/device/live-DB verification pass — every
   "BUILT" item across this project is still unverified in-container;
   check `today.md`/`recall.md` for the latest confirmed-through
   point.

## Next session should start here

**If the app owner can run things on a real machine:** get the PHP
syntax error text, run migration 65, then live-test §37 end-to-end
(steps above) and Gradle-build the Android app. That closes out §37
completely.

**If no live environment is available yet:** there is no more
Claude-actionable code work left in §37 — everything that could be
built without a PHP CLI/Android SDK/live DB has been built. Either
wait for the app owner to run the verification steps, or, if they
want forward progress instead, move to Rider App (item 18) or another
item from the priority list above.

Same standing constraint as every session: no PHP CLI, Android
SDK/Gradle, or live DB in this sandbox. Run the brace/paren + XML
well-formedness Python checkers over every new/edited file (same
script shape as recent handovers' "Verification done this session"),
and update `PENDING.md` checkboxes / `recall.md` once work is actually
done — not before.
