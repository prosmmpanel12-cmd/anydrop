# Handover — 2026-08-29 (session 16): Restaurant Bank Details — Android Complete

## What was asked

Continue from doc 63's "Suggested next session": finish the Android
half of PENDING.md §15 (Restaurant Bank Details Submission). Backend
was already 100% done (migration 59, `lib/restaurant_bank.php`,
`bank-details-get.php`/`-save.php`, `admin/settlements.php`'s
verify/reject flow) — `Models.kt`/`ApiService.kt`/`strings.xml` were
already wired ahead of time. The screen itself (layout + Activity) and
the two AccountFragment wiring points were the only remaining pieces.

## Status: PENDING.md §15 is now fully built (backend + Android). Not build/device-verified.

## What was built this session

1. **`restaurant/app/src/main/res/layout/activity_bank_details.xml`**
   (new) — header shell copied from `activity_edit_profile.xml`'s top
   ~55 lines (back button + title + save action). Body: a status badge
   card (`bankStatusCard`/`bankStatusText`/`bankAdminRemarksText`,
   hidden until a record exists), an empty-state hint
   (`bankEmptyStateText`, hidden once a record exists — the two never
   show together), 5 `TextInputLayout` fields (account holder, bank
   name, account number, IFSC, UPI optional) in the same
   `Widget.Material3.TextInputLayout.OutlinedBox` style
   `activity_edit_profile.xml`'s GST/FSSAI fields use, and a
   `maskedAccountLabel` shown under the account-number field once a
   masked value is on file.

2. **`restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/BankDetailsActivity.kt`**
   (new) — `loadBankDetails()` on open via `api.getBankDetails()`,
   populates every field except account number (left blank — the
   server only ever returns a masked value, per
   `serialize_bank_details_for_restaurant()`'s own kdoc) and renders
   the masked value as a label instead. Status badge color is set
   programmatically (`success_fg`/`error_fg`/`status_pending_fg`) since
   there's no fixed single color for a 3-state field. Client-side
   validation mirrors `validate_bank_fields()`'s regexes exactly
   (account number `^[0-9]{9,18}$`, IFSC `^[A-Z]{4}0[A-Z0-9]{6}$`
   uppercased before checking, UPI optional loose `handle@psp` check).
   `save()` calls `api.saveBankDetails()` and shows
   `bank_details_saved` ("submitted for verification" wording, since
   that's what actually happens — status always resets to pending on
   any save, per the backend's own kdoc).

   **One design decision made this session, not explicitly spelled out
   in doc 63's plan:** `bank-details-save.php` has no "leave account
   number unchanged" sentinel — every POST requires a real
   `account_number`. Since the Android field can never be pre-filled
   with the real number (only ever masked), a blank account-number
   field is treated as invalid **even when a record already exists** —
   the owner must re-type the full number to save *any* change on this
   screen, not just an account-number change. This is stricter than a
   normal "blank = unchanged" convention, but avoids ever sending a
   fake placeholder number that could silently overwrite a correct one
   with garbage. Documented in the Activity's own class kdoc. Flag to
   the app owner if a different UX is wanted here (e.g. a explicit
   "change account number" toggle that unlocks the field, leaving it
   otherwise disabled-but-prefilled with the masked value).

3. **`restaurant/app/src/main/res/layout/fragment_account.xml`** —
   new `btnBankDetailsRow` (same plain-clickable-TextView style as
   `btnClosuresRow`), inserted directly after the existing view-only
   Payout card's closing tag — that card is where a restaurant sees
   its UPI ID/running balance with no way to *set* the UPI ID itself;
   this new row is where bank/payout details actually get submitted.

4. **`restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/AccountFragment.kt`**
   — one new `binding.btnBankDetailsRow.setOnClickListener { ... }`,
   same one-line pattern every other row in this file already uses.

5. **`restaurant/app/src/main/AndroidManifest.xml`** — `BankDetailsActivity`
   registered (`exported="false"`, `windowSoftInputMode="adjustResize"`),
   same block shape as `ClosureScheduleActivity`.

## Verification done this session

No PHP CLI/Android SDK in this container (same standing gap every
prior session has noted) — real `php -l`/Gradle build still need a dev
machine. Ran the same checks prior sessions have used:

- `xml.dom.minidom` well-formedness parsing over
  `activity_bank_details.xml`, `fragment_account.xml`, and
  `AndroidManifest.xml` — all well-formed.
- A comment/string-aware brace/paren balance checker over
  `BankDetailsActivity.kt` (new) and `AccountFragment.kt` (edited) —
  both balanced (88/88 parens + 31/31 braces; 158/158 parens + 56/56
  braces respectively).
- Cross-checked every view-binding ID referenced in
  `BankDetailsActivity.kt` against `activity_bank_details.xml`'s actual
  `android:id` attributes via a script diff (not just eyeballed) — full
  match both directions, same discipline doc 62's "Verification done
  this session" used for `ClosureScheduleActivity`.

## Genuinely still open

- [ ] `php -l` on the 4 backend files from doc 63 (no PHP CLI in this
      container).
- [ ] Real Android build/device pass — same standing gap, now stacked
      behind doc 62's Temp Closure build-verification, which the app
      owner said they'd do themselves.
- [ ] Live click-through once tooling exists: submit fresh bank
      details as a restaurant, confirm status shows "Pending Review";
      verify/reject from `admin/settlements.php`, confirm the badge +
      remarks update on the Android screen's next open; edit an
      already-verified record and confirm status resets to pending;
      confirm a blank account-number field is correctly rejected both
      on a fresh submission and on an edit of an existing record.
- [ ] Secure-at-rest storage for the account number (still plain
      VARCHAR, unchanged from migration 38 — masking-on-read exists,
      full encryption was never in this feature's scope and hasn't
      been evaluated).

## Files touched this session

- `restaurant/app/src/main/res/layout/activity_bank_details.xml` (new)
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/BankDetailsActivity.kt` (new)
- `restaurant/app/src/main/res/layout/fragment_account.xml` (edited — new row)
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/AccountFragment.kt` (edited — one-line wiring)
- `restaurant/app/src/main/AndroidManifest.xml` (edited — activity registered)
- `PENDING.md` §15 (updated — now 🟡 BUILT, not device-verified)
- `today.md` (updated — §3 Bank Details checkbox + priority-list item 7)
- `docs/64_Handover_2026-08-29_BankDetails_Android_Complete.md` (this file)

## Suggested next session

PENDING.md's own priority order (today.md's suggested-order list is
now fully checked off except device verification and the big
not-started items):

1. **Customer Complete-Profile, Android side** (PENDING.md item 11b) —
   backend done since doc 52, Android not started. Single
   self-contained screen + one login-routing change, backend contract
   already fixed — good next pick if continuing feature work.
2. **Restaurant Self Delivery** (PENDING.md item 5) — not started.
3. **Machine verification pass** — with §15 now closed, the
   accumulated "not build/device-verified" backlog across docs 29-64
   is the other standing option, per every recent session's own
   framing (today.md's own §8, recall.md's 2026-08-28 note) — whenever
   real PHP CLI/Android SDK/live DB/device access exists, running the
   accumulated checklists is higher value than more feature work on an
   ever-growing surface.
4. Staff/RBAC, Rider App — both still fully untouched, separate large
   phases.
