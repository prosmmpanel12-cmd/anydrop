# Handover — 2026-08-29: Restaurant Bank Details Submission — Backend Complete, Android In Progress

## What was asked

Continue from doc 62's "Suggested next session" step 4: per `today.md`'s
priority list, build the **Bank Details submission form** (PENDING.md
§15) next, since the Temp Closure/Holiday Scheduling work (doc 60/61/62)
is now fully built and waiting on a real device build/QA pass the app
owner is doing themselves — not blocking further backend/Android work
in this container.

## Status: Backend 100% done. Android partially done (2 of ~6 pieces).

PENDING.md §15 asked for: restaurant-side bank details form,
account-holder name, account number, IFSC, validation, verification
status, secure display/storage, admin verification, audit trail.

Admin-side bank details infrastructure already existed (migration 38,
`admin/settlements.php`'s "Bank Details" form) — admins could already
enter/edit a restaurant's bank details on its behalf. What was missing,
and what this session adds: a restaurant-side submission path, plus the
verification workflow that submission needs (a restaurant self-entering
its own payout account is not the same trust level as an admin typing
it in on the restaurant's behalf).

## What was built this session

### Backend (complete)

1. **`backend/sql/59_migration_restaurant_bank_verification.sql`** —
   adds to `restaurant_bank_details`: `verification_status` ENUM
   (`pending`/`verified`/`rejected`, default `pending`), `admin_remarks`,
   `verified_by_admin_id` (FK to `admins`), `verified_at`, `created_at`.
   Existing rows (all admin-entered so far — no restaurant-side path
   existed before this) are backfilled to `verified` rather than forcing
   a re-review of data an admin already typed in.

2. **`backend/lib/restaurant_bank.php`** (new) — shared validation +
   serialization, same "one lib, thin endpoints" split as
   `restaurant_closures.php`:
   - `validate_bank_fields()` — account holder (non-empty, ≤100 chars),
     bank name (same), account number (`^[0-9]{9,18}$` — no single
     fixed length across Indian banks, so a range not an exact digit
     count), IFSC (`^[A-Z]{4}0[A-Z0-9]{6}$`, the standard RBI shape,
     uppercased before checking), UPI ID (optional, loose
     `handle@psp` shape check).
   - `serialize_bank_details_for_restaurant()` — masks `account_number`
     to `XXXXXXXX1234`-style (all but last 4 digits) on every read.
     Full account number is never echoed back after the initial save
     response either — same "don't redisplay sensitive data on every
     load" reasoning as password fields being `unset()` elsewhere.

3. **`backend/api/v1/restaurant/bank-details-get.php`** (new) — GET,
   restaurant token, returns `{ bank_details: {...} | null }` via the
   serializer above.

4. **`backend/api/v1/restaurant/bank-details-save.php`** (new) — POST,
   restaurant token, `INSERT ... ON DUPLICATE KEY UPDATE` (restaurant_id
   is the table's primary key, so always exactly one row per
   restaurant). **Every restaurant-initiated save — first submission or
   an edit of an existing row — resets `verification_status` to
   `pending`** and clears any prior `admin_remarks`/`verified_by_admin_id`/
   `verified_at`. An edited account number is a new claim about where
   money should go, so it needs re-review the same as a first-time
   submission — carrying forward a stale "verified" flag onto changed
   details would defeat the point. Writes `write_audit_log('restaurant',
   ..., 'bank_details_submitted', [...])` with only the last 4 digits of
   the account number in the log payload, not the full number.

5. **`backend/admin/settlements.php`** (edited) — two behavior changes
   plus new UI:
   - `save_bank_details` (existing admin-entry form) now explicitly
     sets `verification_status = 'verified'` and stamps
     `verified_by_admin_id`/`verified_at` — admin typing values in
     directly is already supervised entry, so it doesn't need the
     restaurant's own review cycle.
   - New `verify_bank_details` / `reject_bank_details` form actions —
     the review step for a **restaurant's own self-submission**.
     Rejecting requires a non-empty `admin_remarks` (enforced
     server-side) so the restaurant always sees *why* — verifying's
     remarks field is optional. Both write
     `write_audit_log('admin', ..., 'restaurant_bank_details_verified'
     |'_rejected', [...])`.
   - Display: a colored status badge (pending=amber, verified=green,
     rejected=red) shown above the existing bank details form/view, with
     `admin_remarks` shown next to it when present. Verify/Reject buttons
     only render when status is `pending` and the admin has edit
     permission.

**Verification done this session:** no PHP CLI in this container (same
standing gap every prior session has noted) — `php -l` still needs a dev
machine. Ran a string/comment-aware Python brace/paren-balance checker
over all 4 touched/created PHP files. First pass flagged
`admin/settlements.php` as mismatched; that was the checker naively
counting braces inside HTML/attribute text outside `<?php ?>` tags
(e.g. `style="color:<?= ... ?>;"`), not a real bug — re-ran extracting
only the contents of `<?php ... ?>` segments before counting, which
confirmed it's balanced. All 4 files: balanced. Ran the same
comment/string-aware balance checker over the two edited Kotlin files
(`Models.kt`, `ApiService.kt`) — both balanced. Ran
`xml.dom.minidom` well-formedness parsing over `strings.xml` — well-formed.

### Android (in progress — 2 of ~6 pieces)

1. **`network/Models.kt`** — added `BankDetails` (mirrors the masked
   server shape — `accountNumberMasked`, not `accountNumber`, so no
   field on this model could be mistakenly displayed expecting a full
   value), `BankDetailsResult`, `BankDetailsSaveBody`.

2. **`network/ApiService.kt`** — added `getBankDetails()` (GET
   `restaurant/bank-details-get.php`) and `saveBankDetails()` (POST
   `restaurant/bank-details-save.php`), same shape as the existing
   `getProfile()`/`updateProfile()` pair.

3. **`res/values/strings.xml`** — added the full string set for the
   screen ahead of writing it: `account_row_bank_details`,
   `bank_details_title`, all label/hint strings for the 5 fields,
   `btn_save_bank_details`, 3 validation error strings
   (`error_invalid_account_number`/`_ifsc_code`/`_upi_id`),
   save-result strings, 3 status-label strings
   (`bank_status_pending`/`_verified`/`_rejected`), empty-state string,
   masked-account-label string.

## What's still open (real gaps, not just the standing sandbox limitation)

- [ ] **`res/layout/activity_bank_details.xml`** (new) — not started.
      Plan: same header-with-back-button shell as
      `activity_edit_profile.xml` (`btnBack` + title + a save action),
      5 `TextInputLayout`/`TextInputEditText` fields (account holder,
      bank name, account number, IFSC, UPI optional) in the same
      `Widget.Material3.TextInputLayout.OutlinedBox` style
      `activity_edit_profile.xml`'s GST/FSSAI fields already use
      (`inputGstNumber`/`inputFssaiNumber` around line 270-303 of that
      file — was mid-review of that exact pattern when this session's
      tool budget ran out), plus a status badge area (color + label +
      remarks text, matching the 3-state badge just added to
      `admin/settlements.php`) shown above the form when bank details
      already exist.
- [ ] **`ui/account/BankDetailsActivity.kt`** (new) — not started. Plan:
      `loadBankDetails()` on open via `api.getBankDetails()`, populate
      fields + status badge if a row exists (pre-fill everything except
      the account number, which only ever comes back masked — leave
      that field blank with the masked value shown as a label/hint
      instead, same reasoning as `serialize_bank_details_for_restaurant()`'s
      kdoc); client-side validation mirroring
      `validate_bank_fields()`'s regexes exactly (account number
      9–18 digits, IFSC `^[A-Z]{4}0[A-Z0-9]{6}$` uppercased before
      checking, UPI optional loose check) so a typo shows inline
      instantly instead of round-tripping to the server first, same
      "check here too" reasoning `EditProfileActivity.save()`'s GST/FSSAI
      checks use; `save()` calls `api.saveBankDetails()`, shows
      `bank_details_saved` on success (mentioning "submitted for
      verification" since that's what actually happens — status always
      resets to pending on save, per the backend's kdoc above).
- [ ] **`ui/account/AccountFragment.kt`** — not wired. Plan: one new
      `binding.btnBankDetailsRow.setOnClickListener { startActivity(...) }`,
      same one-line pattern every other row in this file already uses
      (`btnClosuresRow`, `btnReviewsRow`, etc.).
- [ ] **`res/layout/fragment_account.xml`** — not wired. Plan: one new
      row (`btnBankDetailsRow` / `account_row_bank_details`), same
      plain-clickable-TextView style as `btnClosuresRow`, placed
      directly after the existing view-only "Payout" card (the
      `upiIdText`/`currentDueText` card) — that card is where a
      restaurant currently sees its UPI ID and running balance with no
      way to *set* the UPI ID itself; the new row is where it actually
      gets set. Exact insertion point (right after that card's closing
      `</com.google.android.material.card.MaterialCardView>`) was
      already located this session via `grep -n -B3 -A15
      "btnClosuresRow"` / a follow-up read of lines 288-340 of that
      file, just not edited yet.
- [ ] Balance/well-formedness checks on the new Kotlin/XML files once
      they exist (same Python checkers used elsewhere this project,
      see `docs/62...`'s "Verification done this session" for the
      script shape) — n/a yet, nothing to check.
- [ ] Real Android build — same standing gap as every other Android
      piece in this project, dev machine only, and now stacked behind
      doc 62's Temp Closure build-verification, which the app owner
      said they'd do themselves.
- [ ] `today.md` / `PENDING.md` / `recall.md` — **not yet updated**
      this session (unlike doc 62, which updated all three) since the
      feature isn't done. Left as-is so they still correctly show §15
      as fully PENDING rather than claiming partial credit.

## Files touched this session

- `backend/sql/59_migration_restaurant_bank_verification.sql` (new)
- `backend/lib/restaurant_bank.php` (new)
- `backend/api/v1/restaurant/bank-details-get.php` (new)
- `backend/api/v1/restaurant/bank-details-save.php` (new)
- `backend/admin/settlements.php` (edited — save_bank_details status
  behavior, new verify/reject actions, status badge UI)
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/Models.kt`
  (edited — BankDetails/BankDetailsResult/BankDetailsSaveBody added)
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/ApiService.kt`
  (edited — getBankDetails()/saveBankDetails() added)
- `restaurant/app/src/main/res/values/strings.xml` (edited — bank
  details string set added)
- `docs/63_Handover_2026-08-29_BankDetails_Built.md` (this file)

## Suggested next session

1. `view` `restaurant/app/src/main/res/layout/activity_edit_profile.xml`
   lines ~110-330 (the GST/FSSAI `TextInputLayout` blocks specifically)
   as the direct style reference, then write
   `activity_bank_details.xml` — 5 fields + status badge + save button,
   header shell copied from the same file's top ~55 lines.
2. Write `BankDetailsActivity.kt` per the plan above.
3. Wire `AccountFragment.kt` + `fragment_account.xml` (both one-line
   additions, insertion points already identified above).
4. Run the brace/paren + XML well-formedness checkers over every new
   file.
5. Update `today.md` §3 / `PENDING.md` §15 checkboxes / `recall.md`
   once the Android half is actually done — not before, so a
   half-built feature never shows as more complete than it is.
6. Then: real Gradle build (this feature + doc 62's Temp Closure work,
   whichever the app owner hasn't already build-verified by then).
