# Handover — 2026-08-28 (cont'd, part 7): Temp Closure/Holiday Scheduling — ANDROID COMPLETE

## What was asked

Continue exactly where doc 61 left off — its own "Suggested next
session" list, in order, steps 1-7 (step 8, `php -l` + live
end-to-end test, is the one standing item that needs a dev machine
and is explicitly out of scope for this container).

## Status: Backend 100% done (doc 60/61, unchanged this session).
## Android now 100% done (7 of 7 pieces) — not build/device-verified,
## see "What's still open" below.

## What was built this session

1. **`res/values/strings.xml`** — added every string doc 61 flagged
   as missing: `closure_type_date_range`/`closure_type_weekly_recurring`,
   `hint_closure_start_date`/`hint_closure_end_date`,
   `label_closure_day_of_week`, `hint_closure_reason`,
   `closure_summary_weekly` ("Every %1$s"), `closure_summary_date_range`
   ("%1$s – %2$s"), `dialog_add_closure_title`/`dialog_edit_closure_title`,
   `closure_save_failed`/`closure_saved`/`closure_deleted`/
   `closures_load_failed`, `closure_delete_confirm_title`/`_message`,
   `empty_closures`, `account_row_closures`, `closure_schedule_title`,
   `btn_add_closure`, `closure_resume_time_title`, `btn_skip`, plus the
   `day_names_full` string-array (Monday..Sunday, 0-indexed to match
   `spinnerDayOfWeek`). This unblocked `item_closure.xml`,
   `dialog_add_closure.xml`, and `ClosureAdapter.kt`, all three of
   which already referenced these IDs from doc 61's session.

2. **`network/Models.kt`**
   - New `Closure`/`ClosuresListResult`/`ClosureResult`/
     `ClosureCreateBody`/`ClosureUpdateBody` data classes, matching
     `closures-list/create/update/delete.php`'s field shapes exactly
     (`closure_type`, `start_date`, `end_date`, `day_of_week`, `reason`,
     `is_active`). `ClosureUpdateBody` is a separate data class from
     `ClosureCreateBody` (not a `typealias`) even though the shape is
     identical today — a `typealias` would make the two silently
     diverge-unsafe if one of the two endpoints' request shape ever
     needs to change independently.
   - `OperationalStatusUpdateBody` gained an optional `resume_at:
     String?` field; `OperationalStatusResult` gained an optional
     `temp_closed_until: String?` field. Both were the two remaining
     items blocking `AccountFragment`'s temp-closed switch from
     actually using `status-update.php`'s `resume_at` support (live
     since doc 60).

3. **`network/ApiService.kt`** — added `getClosures()` (GET
   `restaurant/closures-list.php`), `createClosure()` (POST
   `restaurant/closures-create.php`), `updateClosure(id, body)` (POST
   `restaurant/closures-update.php?id=`), `deleteClosure(id)` (POST
   `restaurant/closures-delete.php?id=`) — same `@Query("id")` +
   `@Body` shape every other update/delete endpoint in this file
   already uses (`updateAddonGroup`, `deleteAddonGroup`, etc.).

4. **`ui/account/ClosureScheduleActivity.kt`** (new) — the full
   screen doc 61 sketched:
   - Reuses `activity_notification_list.xml` as its shell, same as
     `AddonGroupsActivity`/`ReviewListActivity`. `screenTitle` = "Closure
     Schedule" (fixed, not per-item — a closure schedule isn't scoped
     to one menu item). `btnAction` shows `ic_add` / "+ Add Closure".
   - `loadClosures()` calls `api.getClosures()`, feeds `ClosureAdapter`
     (already written in doc 61), no pagination — same "always a small
     list" reasoning as `AddonGroupsActivity`.
   - `showClosureDialog(existing: Closure?)` inflates
     `DialogAddClosureBinding`, wires `typeToggleGroup`'s
     checked-change listener to toggle `dateRangeFields`/`weeklyFields`
     visibility, pre-fills every field on edit (`existing.dayOfWeek -
     1` as the 0-indexed `spinnerDayOfWeek` selection, per the
     convention doc 61 called out).
   - `inputStartDate`/`inputEndDate` each get their own
     `MaterialDatePicker` via `setUpDatePicker()` — **date-only, no
     `MaterialTimePicker` chaining**, since `start_date`/`end_date` are
     whole calendar days (doc 61 was explicit this differs from
     `CouponManagerActivity.setUpValidUntilPicker()` for that reason).
     Each field uses its own fragment tag (`closure_start_date_picker`
     / `closure_end_date_picker`) so opening one while the other is
     mid-transaction can't collide.
   - `createClosure()`/`updateClosure()` on save, validated:
     weekly_recurring sends `day_of_week` (1-indexed from the 0-indexed
     spinner selection) with `start_date`/`end_date` null; date_range
     requires both dates picked (empty tag → inline error via
     `InAppNotifier`, no request sent) and sends `day_of_week` null.
   - `deleteClosure()` behind `dialog_confirm_delete.xml`, same
     confirm pattern as `AddonGroupsActivity.confirmDeleteGroup()`.

5. **`AndroidManifest.xml`** — registered `.ui.account.ClosureScheduleActivity`,
   same block shape as `AddonGroupsActivity`'s entry (`exported=false`,
   `windowSoftInputMode=adjustResize`).

6. **`res/layout/fragment_account.xml`** — new `btnClosuresRow`
   (`account_row_closures`), same plain-clickable-TextView style as
   `btnReviewsRow`/`btnCouponsRow`/`btnBannersRow`, placed directly
   below the temp-closed switch card per doc 61's suggestion (related
   concepts: the switch is "closed right now, resume whenever", this
   row is "closed on specific future dates/every week").

7. **`ui/account/AccountFragment.kt`** — two changes:
   - `btnClosuresRow`'s click listener launches `ClosureScheduleActivity`
     (no extras, same launch style as `BannerManagerActivity`/
     `CouponManagerActivity`/`OfferManagerActivity` above it).
   - `switchTempClosed`'s on-toggle-on flow now prompts for an
     optional resume-time instead of always sending `resume_at: null`:
     turning the switch **on** shows a small `MaterialAlertDialogBuilder`
     ("When will you reopen?") with **Save** (opens the picker chain)
     and **Skip** (sends `resume_at: null`, today's exact prior
     behavior) — cancelling the dialog (back press / outside tap) also
     falls through to Skip rather than silently reverting the switch.
     **Save** opens `MaterialDatePicker` → `MaterialTimePicker`, copied
     from `CouponManagerActivity.setUpValidUntilPicker()` including
     both bugs-fixed-there details doc 61 explicitly called out: the
     `findFragmentByTag` double-tap guard against two overlapping
     picker fragments, and `binding.root.post { ... }` before showing
     the time picker so it doesn't race the date picker's own dismiss
     transaction. `AccountFragment` uses `childFragmentManager` for
     both pickers (it's a `Fragment`, not an `Activity` —
     `CouponManagerActivity`'s version uses `supportFragmentManager`
     for the same reason its host is an Activity). Turning the switch
     **off** skips the prompt entirely and sends `resume_at: null` as
     before — a resume-time is only meaningful when *closing*.
     `setTempClosed()`'s signature grew a required `resumeAt: String?`
     parameter to thread this through to `OperationalStatusUpdateBody`.

**Verification done this session (same standing container gap, no
PHP/network/Android toolchain here):**
- Wrote a small string/comment-aware Python brace/paren-balance
  checker (tracks Kotlin `//`, `/* */`, `"..."`, `'...'`, and
  `"""..."""` so it doesn't miscount a `{`/`}` sitting inside a string
  or comment) and ran it over every Kotlin file touched or created
  this session (`Models.kt`, `ApiService.kt`,
  `ClosureScheduleActivity.kt`, `ClosureAdapter.kt`,
  `AccountFragment.kt`) — all balanced.
- Ran `xml.dom.minidom` well-formedness parsing over every XML file
  touched or created this session (`strings.xml`, `fragment_account.xml`,
  `item_closure.xml`, `dialog_add_closure.xml`, `AndroidManifest.xml`)
  — all well-formed.
- Did **not** attempt real compilation — no Android toolchain in this
  container, same standing gap as every prior session.

## What's still open (real gaps, not just standing sandbox limitations)

- [ ] Real Android build/run pass — n/a yet in this container. Next
      session (or dev machine) should do a real Gradle build first,
      before touching anything else — this is the first time all 7
      pieces exist together and a real compile hasn't confirmed the
      seams (e.g. binding class names, import paths) actually line up.
- [ ] Manual QA pass once it builds: create both closure types
      (date-range and weekly-recurring), edit each, delete each,
      confirm the list re-loads correctly after each mutation, confirm
      the temp-closed switch's new resume-time prompt (Save path and
      Skip path both) round-trips correctly against
      `status-update.php`.
- [ ] Backend `php -l` on all 8 backend files touched across doc 60 +
      doc 61 (`58_migration_restaurant_closures.sql`,
      `lib/restaurant_closures.php`, the 4 `closures-*.php` endpoints,
      `status-update.php`, `restaurant_status.php`, `restaurants/list.php`,
      `search/search.php`, `restaurants/menu.php`) + a live end-to-end
      test (create both closure types, confirm all three surfaces —
      Home/Search/Detail — agree on the restaurant's status; confirm
      `temp_closed_until` auto-expiry; confirm the new `resume_at` →
      `temp_closed_until` round trip via `status-update.php`) — same
      standing container gap, dev machine only. **Unchanged from doc
      61 — nothing backend-related changed this session.**

## Files touched this session

- `restaurant/app/src/main/res/values/strings.xml`
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/Models.kt`
- `restaurant/app/src/main/java/com/anydrop/restaurant/network/ApiService.kt`
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/ClosureScheduleActivity.kt` (new)
- `restaurant/app/src/main/AndroidManifest.xml`
- `restaurant/app/src/main/res/layout/fragment_account.xml`
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/AccountFragment.kt`
- `today.md` (§3 closure item marked Android-complete, this file linked)
- `PENDING.md` (§6 checkboxes updated to reflect built-vs-still-open)
- `recall.md` (§12 status line updated)
- `docs/62_Handover_2026-08-28_TempClosureScheduling_AndroidComplete.md`
  (this file)

## Suggested next session

1. Real Gradle build of the `restaurant/` module on a dev machine —
   first actual compile of everything doc 60/61/62 built. Fix any
   binding/import mismatches that only a real compiler catches (the
   balance/well-formedness checks in this container can't catch
   those).
2. Manual QA pass per "What's still open" above.
3. Backend `php -l` (8 files) + live end-to-end test, same dev
   machine.
4. Then, per `today.md`'s original priority list: **Bank Details
   form** next.
