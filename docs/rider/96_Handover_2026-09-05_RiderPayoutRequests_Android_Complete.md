# Handover — Rider Self-Service Payout Requests, ANDROID NOW COMPLETE

Session date: 05 Sep 2026 (continuation of doc 95, same day). Doc 95 left
the Android half of deep-plan §21 partial — only `Models.kt` had been
added. This session finished every item in doc 95's "Still open" §0
checklist.

> **Not built/tested against a live backend or device.** Same standing
> caveat as doc 95 and every other handover in this project — no PHP
> CLI, Android SDK, Gradle, or DB in this sandbox. Kotlin files checked
> by hand for balanced braces/parens (script-based this time, not just
> eyeballing); XML files checked for well-formedness via
> `xml.dom.minidom`; every `binding.<id>` reference cross-checked
> against its layout's `android:id` list; every `R.string.*`/`R.color.*`
> reference cross-checked against `strings.xml`/`colors.xml`. All came
> back clean — see "Verification done this session" below for the exact
> checks run. Still `🟡 IMPLEMENTED — TEST PENDING` per `done.md`'s own
> rule until a live click-through happens (doc 95's "Still open" §3).

## What changed — Android (doc 95's §0 checklist, now done)

### `rider/.../network/ApiService.kt`
Added 4 endpoints, mirroring the customer app's wallet-withdrawal calls
exactly: `getRiderBankDetails()` (GET `rider/payout-bank-details-get.php`),
`saveRiderBankDetails()` (POST `rider/payout-bank-details-save.php`),
`getRiderPayoutHistory()` (GET `rider/payout.php`),
`requestRiderPayout()` (POST `rider/payout.php`).

### New: `rider/.../network/ApiErrorParser.kt`
Didn't exist in the rider app before this session (only the customer app
had one). Ported over unchanged apart from the package name — same
Retrofit `errorBody()`-vs-`body()` fix, needed so
`RequestPayoutActivity` can read `insufficient_balance` /
`below_minimum_amount` off `request_rider_payout()`'s 422 responses.

### New: `rider/.../ui/earnings/RequestPayoutActivity.kt`
Modeled on the customer app's `WithdrawActivity.kt` — one form for both
payout methods (bank/UPI fields toggled by
`MaterialButtonToggleGroup` visibility), saved-bank-details pre-fill with
the same never-echo-a-full-masked-account-number caveat, submit +
read-only history list below.

One deliberate difference from the customer screen, noted in this file's
own kdoc: the available-balance figure is **re-fetched** here via
`getEarningsSummary()` on load rather than passed in from
`EarningsActivity`, since this screen has no natural "previous screen
already had it in a field" shortcut the way `WalletActivity` →
`WithdrawActivity` does. Same "display-only convenience number, the
server-side row-locked check is the real guard" reasoning as the
customer side either way.

### New: `rider/.../ui/earnings/RiderPayoutAdapter.kt`
Modeled on `WalletWithdrawalAdapter.kt` — same statuses, same
reject-reason line, same date reformat. **One deliberate deviation** from
directly copying that file: the customer adapter fills the status pill
with a single solid color + white text. This app already has its own
`status_pending_bg/fg`, `status_approved_bg/fg`, `status_rejected_bg/fg`,
`status_suspended_bg/fg` semantic color PAIRS (established by
`ApplicationStatusActivity`'s own status pill) — so this adapter uses
those pairs instead (`processing` reuses `status_approved_*`, `completed`
reuses `success_bg/fg`), so this pill matches every other status chip
already in the app rather than introducing a second pill styling
convention.

### New layouts
- `activity_request_payout.xml` — modeled on `activity_withdraw.xml`'s
  shape (form above, history below), restyled with this app's header row
  (matches `activity_earnings.xml`) and `MaterialCardView` +
  `Widget.Material3.TextInputLayout.OutlinedBox` field styling (matches
  `activity_signup.xml`'s pattern — green box stroke, 12dp rounded
  corners).
- `item_rider_payout.xml` — modeled on `item_withdrawal.xml`, using
  `MaterialCardView` (this app's themed default) instead of the customer
  app's plain `CardView`.

### `AndroidManifest.xml`
New `<activity android:name=".ui.earnings.RequestPayoutActivity" android:exported="false" android:windowSoftInputMode="adjustResize" />`,
placed directly after `EarningsActivity`'s own entry, same per-activity
comment convention the rest of this file already follows.

### `strings.xml`
New `payout_*` string block (title/hints/method labels/status
labels/error strings), naming mirrored off the customer app's
`withdraw_*` block per doc 95's own spec. Plus one string on the
earnings side: `earnings_request_payout_button`.

### `EarningsActivity.kt` + `activity_earnings.xml`
Added a "Request Payout" `MaterialButton` below the share-percent note
and above "RECENT ACTIVITY" (exact position doc 95 specified), opening
`RequestPayoutActivity` — same relationship the customer app's
`WalletActivity`'s "Withdraw" button has to `WithdrawActivity`. Also
added `onResume()` → `loadEarnings()`, same reasoning
`WalletActivity.onResume()` already documents: a payout request debits
`earnings_balance` immediately server-side, so this screen's balance
figure would otherwise show a stale number until the next manual
pull-to-refresh after a rider comes back from submitting one.

## Verification done this session

1. **Brace/paren/bracket balance** — script-based (not just eyeballing),
   run against all 5 touched/new Kotlin files. All `BALANCED`.
2. **XML well-formedness** — `xml.dom.minidom.parse()` against all 3
   touched/new layout files plus the manifest and `strings.xml`. All
   `WELL-FORMED`.
3. **View-binding id cross-check** — every `binding.<id>` reference in
   `RequestPayoutActivity.kt`, `RiderPayoutAdapter.kt`, and the
   `EarningsActivity.kt` addition, diffed against the `android:id`
   list in its corresponding layout. Zero mismatches either direction.
4. **String resource cross-check** — every `R.string.payout_*` /
   `@string/payout_*` / `earnings_request_payout_button` reference across
   the new Kotlin and XML files, diffed against `strings.xml`'s
   definitions. Zero missing.
5. **Color resource cross-check** — every `R.color.*` reference in
   `RiderPayoutAdapter.kt`, diffed against `colors.xml`. Zero missing.

None of this substitutes for `php -l`/Gradle/a live device (still not
available in this sandbox) — it only rules out the class of
copy-paste/typo bugs that check `<-eyeballing`-only would previously have
missed for a 6-file change of this size.

## Still open — unchanged from doc 95, minus item 0

Doc 95's "Still open" list had 6 items; item 0 (finish the Android half)
is what this session closed out. Items 1–5 are **unchanged and still
open**:

1. **Migration 74 run on a live DB.**
2. **`php -l` on the 5 backend files** from doc 95 (no PHP CLI in this
   sandbox — still only manual brace/paren balance was possible for
   those; this session's automated check was Android-only).
3. **Live click-through** — now unblocked (Android is finished): request
   a payout as a rider → confirm balance drops immediately → confirm a
   `requested` row appears on `admin/rider-payouts.php` → Approve → Mark
   Processing → Mark Completed → confirm `platform_ledger` gets a
   `rider_payout_out` row. Separately test Reject from both `requested`
   and `approved` → confirm `earnings_balance` credited back exactly
   (visible on `EarningsActivity`'s balance figure and
   `admin/rider-earnings.php`'s detail-mode ledger).
4. **Known gap, still not fixed:** rider app has no FCM/in-app
   notification surface (deep-plan §23 unbuilt) — `create_notification()`
   fires on approve/complete/reject but a rider has no way to see it yet.
5. Once fully closed, re-audit PENDING.md item 22 ("Rider
   Earnings/Settlement") — still shows the stale all-unchecked state.
