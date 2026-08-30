# Handover — 2026-08-28 (cont'd, part 6): Temp Closure/Holiday Scheduling — BACKEND COMPLETE, ANDROID PARTIAL

## What was asked

Continue exactly where doc 60 left off — its own "Suggested next
session" list, in order:
1. `search/search.php` restaurant-results block
2. `search/search.php` items sub-block
3. `restaurants/menu.php`
4. Android side (the big remaining piece)
5. `php -l` + live end-to-end test on all touched backend files

## Status: backend now 100% done. Android ~30% done (2 of ~7 pieces).
## Stopped mid-session on explicit request to package a handover at the
## current point — Android side does NOT compile yet, see "What's still
## open" below.

## What was built this session

**Backend (complete — closes doc 60's last 3 backend gaps):**

- **`backend/api/v1/search/search.php`**
  - Added `require_once .../lib/restaurant_closures.php`.
  - Restaurant-results block: added `$currentDate`, a batched
    `get_restaurants_with_active_closure($db, $restaurantIds, ...)`
    call (same `$restaurantIds` array already built for the tags
    query), and passed the per-restaurant flag into
    `compute_restaurant_status()` — same pattern `restaurants/list.php`
    already used.
  - Items sub-block: added `r.temp_closed_until AS r_temp_closed_until`
    to the SELECT (previously missing entirely, per doc 60). Built a
    second batch lookup keyed off the *distinct restaurant ids actually
    present in `$rawItems`* (`$itemRestaurantIds`) rather than reusing
    `$restaurantIds` — deliberate: a cross-restaurant dish match can
    surface a restaurant that never matched by name/cuisine/dish
    directly, so it wouldn't be in `$restaurantIds` at all. Folded into
    the inline `$rArr`/`compute_restaurant_status()` call for each item.
- **`backend/api/v1/restaurants/menu.php`**
  - Added `require_once .../lib/restaurant_closures.php`.
  - Single-restaurant case: one more `get_restaurants_with_active_closure()`
    call with a 1-element `[(int) $restaurant['id']]` array (no separate
    single-id helper written — same query shape, N=1 `IN(...)` isn't
    worth a second function) feeding into the existing
    `compute_restaurant_status($restaurant)` call, now passed
    `$currentTime`/`$currentDow`/the closure flag explicitly instead of
    relying on the function's internal `new DateTime()` fallback.

All three files manually brace/paren-balance checked this session
(Python-based checker run over each file, string/comment-aware) — all
OK. `php -l` still not run — no PHP/network in this sandbox, same
standing gap as every prior session.

**Result:** a restaurant on a scheduled closure now shows Closed
consistently across all three public-facing surfaces — Home
(`list.php`, done in doc 60), Search (`search.php`, both blocks, done
this session), and the restaurant-detail screen (`menu.php`, done this
session). No more contradictory status between screens.

**Android (partial — 2 of roughly 7 pieces):**

- **`res/layout/item_closure.xml`** (new) — RecyclerView row for
  `ClosureScheduleActivity`'s list. Same title+edit+delete-icon card
  shape as `item_addon_group.xml`, minus that one's nested addons
  container (a closure has no children). IDs: `closureDateText`,
  `closureReasonText`, `btnEditClosure`, `btnDeleteClosure`.
- **`res/layout/dialog_add_closure.xml`** (new) — add/edit form, one
  dialog handles both create and edit (matches
  `closures-update.php`'s "full replace, not partial patch" design).
  `typeToggleGroup` (RadioGroup: `radioDateRange`/`radioWeeklyRecurring`)
  switches visibility between `dateRangeFields` (two
  `MaterialDatePicker`-backed date-only inputs, `inputStartDate`/
  `inputEndDate`) and `weeklyFields` (a `Spinner` `spinnerDayOfWeek`,
  entries from a new `day_names_full` string-array, index
  0=Monday..6=Sunday to line up with the existing 1=Mon..7=Sun
  `day_of_week` convention). `inputReason` applies to both types.
- **`ui/account/ClosureAdapter.kt`** (new) — RecyclerView adapter for
  the list. No pagination (same "a restaurant's own list is always
  small" reasoning as `AddonGroupAdapter`). Formats a weekly closure as
  "Every <Day>" and a date-range closure as "<start> – <end>" via two
  new string resources (see below — **not yet added**, this file will
  not compile on its own yet).

## What's still open (real gaps, not just standing sandbox limitations)

- [ ] **`network/Models.kt`** — no `Closure`/`ClosureCreateBody`/
      `ClosureUpdateBody`/`ClosuresListResult`/`ClosureResult` data
      classes yet. Also `OperationalStatusUpdateBody` still doesn't
      carry an optional `resume_at` field, and
      `OperationalStatusResult` doesn't carry `temp_closed_until` back
      — both needed so `AccountFragment`'s temp-closed switch can
      actually send/receive the resume-time the backend
      (`status-update.php`) has supported since doc 60.
- [ ] **`network/ApiService.kt`** — no `getClosures()`/`createClosure()`/
      `updateClosure()`/`deleteClosure()` entries for
      `closures-list/create/update/delete.php`. `updateOperationalStatus()`
      already exists but its body type needs the `resume_at` field
      added above before it's useful here.
- [ ] **`ui/account/ClosureScheduleActivity.kt`** — not written yet.
      Suggested shape (unchanged from doc 60's plan): reuse
      `activity_notification_list.xml` as the shell exactly like
      `AddonGroupsActivity` does — `screenTitle` = "Closure Schedule" (or
      similar, needs a string), `btnAction` shows `ic_add` / "+ Add
      Closure", `loadClosures()` calls `api.getClosures()` and feeds
      `ClosureAdapter`, `showClosureDialog(existing: Closure?)` inflates
      `DialogAddClosureBinding`, wires `typeToggleGroup`'s checked-change
      to toggle `dateRangeFields`/`weeklyFields` visibility, wires
      `inputStartDate`/`inputEndDate` to `MaterialDatePicker` (date-only,
      no time — unlike `CouponManagerActivity.setUpValidUntilPicker()`,
      no `MaterialTimePicker` chaining needed here since `start_date`/
      `end_date` are whole calendar days), populates `spinnerDayOfWeek`
      on edit (`existing.dayOfWeek - 1` as the 0-indexed selection),
      calls `createClosure()`/`updateClosure()` on save, `deleteClosure()`
      behind the same `dialog_confirm_delete.xml` confirm pattern
      `AddonGroupsActivity.confirmDeleteGroup()` uses. Launched from
      `AccountFragment` with no extras needed (unlike
      `AddonGroupsActivity`, a closure schedule isn't scoped to one
      menu item).
- [ ] **`ui/account/AccountFragment.kt` wiring** — no new row's
      click listener yet (row itself doesn't exist either, see
      `fragment_account.xml` below). Also: the existing
      `switchTempClosed`/`setTempClosed()` on-toggle-on flow doesn't yet
      prompt for an optional resume-time — per doc 60's plan, turning
      the switch on should offer a `MaterialDatePicker` →
      `MaterialTimePicker` chain (copy
      `CouponManagerActivity.setUpValidUntilPicker()`'s chaining
      pattern, including its "guard against double-tap opening two
      overlapping picker fragments" and "post() the time picker rather
      than showing it synchronously" details — both were real bugs
      fixed there, not defensive-for-no-reason code) with a "Skip" path
      that just sends `resume_at: null` (today's exact behavior,
      unchanged) — this is what actually lets a restaurant use the
      `resume_at` field `status-update.php` has accepted since doc 60;
      right now nothing in the app sends it.
- [ ] **`res/layout/fragment_account.xml`** — no new row added yet.
      Suggested: same `btnReviewsRow`-style plain clickable TextView,
      placed near the temp-closed switch card (e.g. just above or
      below it) since they're related concepts — the on-demand switch
      for "closed right now, resume whenever" vs. this new row for
      "closed on specific future dates/every week". Needs a new string
      (e.g. `account_row_closures`).
- [ ] **`AndroidManifest.xml`** — `ClosureScheduleActivity` not
      registered. Same block shape as `AddonGroupsActivity`'s entry
      (`exported=false`, `windowSoftInputMode=adjustResize`).
- [ ] **`res/values/strings.xml`** — none of the strings the two new
      layout files and `ClosureAdapter.kt` already reference exist yet:
      `closure_type_date_range`, `closure_type_weekly_recurring`,
      `hint_closure_start_date`, `hint_closure_end_date`,
      `label_closure_day_of_week`, `hint_closure_reason`,
      `closure_summary_weekly` (`"Every %1$s"`),
      `closure_summary_date_range` (`"%1$s – %2$s"`), plus a
      `day_names_full` string-array (Monday..Sunday, full names, 0=Mon
      matching the Spinner index convention noted above). Also will
      need save/delete/load-failed/success strings for
      `ClosureScheduleActivity` itself (e.g.
      `closures_load_failed`/`closure_saved`/`closure_deleted`/
      `closure_save_failed`, following the exact `addon_group_*` naming
      pattern) once that activity is written. **Until these exist, the
      two new layout files and `ClosureAdapter.kt` will fail to
      compile** — this is the very next thing to fix.
- [ ] Real Android build/run pass — n/a yet, doesn't compile (missing
      strings above).
- [ ] Manual brace/paren-balance + XML well-formedness check on the two
      new layout files and `ClosureAdapter.kt` — **not done this
      session** (ran out of turns), should be first thing done at the
      top of next session before adding anything else.
- [ ] Backend `php -l` on all 8 backend files touched across doc 60 +
      this session (`58_migration_restaurant_closures.sql`,
      `lib/restaurant_closures.php`, the 4 `closures-*.php` endpoints,
      `status-update.php`, `restaurant_status.php`, `restaurants/list.php`,
      `search/search.php`, `restaurants/menu.php`) + a live end-to-end
      test (create both closure types, confirm all three surfaces —
      Home/Search/Detail — agree on the restaurant's status; confirm
      `temp_closed_until` auto-expiry) — same standing container gap,
      dev machine only.

## Files touched this session

- `backend/api/v1/search/search.php`
- `backend/api/v1/restaurants/menu.php`
- `restaurant/app/src/main/res/layout/item_closure.xml` (new)
- `restaurant/app/src/main/res/layout/dialog_add_closure.xml` (new)
- `restaurant/app/src/main/java/com/anydrop/restaurant/ui/account/ClosureAdapter.kt` (new)
- `today.md` (§3 closure item progress updated, this file linked)
- `docs/61_Handover_2026-08-28_TempClosureScheduling_BackendComplete_AndroidPartial.md`
  (this file)

## Suggested next session

Pick up exactly at "What's still open" above, in this order (front-load
the strings so nothing sits uncompilable longer than necessary):

1. `res/values/strings.xml` — add every string listed above. Quick,
   unblocks the two layout files + `ClosureAdapter.kt` immediately.
2. `network/Models.kt` — `Closure`/`ClosureCreateBody`/
   `ClosureUpdateBody`/`ClosuresListResult`/`ClosureResult`, plus
   `resume_at`/`temp_closed_until` on the existing operational-status
   bodies.
3. `network/ApiService.kt` — the 4 closure endpoints.
4. `ui/account/ClosureScheduleActivity.kt` — full activity per the
   shape sketched above.
5. `AndroidManifest.xml` — register it.
6. `fragment_account.xml` + `AccountFragment.kt` — new row to launch
   it, plus the resume-time prompt on the existing temp-closed switch.
7. Manual brace/paren-balance + XML checks on everything touched across
   both this session and next.
8. `php -l` (all 8 backend files) + live end-to-end test — dev machine.

Then, per `today.md`'s original priority list: **Bank Details form**
after this.
