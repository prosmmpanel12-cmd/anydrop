# Handover — 2026-08-28 (cont'd, part 5): Temp Closure/Holiday Scheduling — BACKEND PARTIAL

## What was asked

Continue exactly where doc 59 left off, per `today.md`'s priority list:
**§3 Temp Closure/Holiday full scheduling** next.

## Status: backend ~70% done, Android not started. Stopped mid-session
## on explicit request to package a handover at the current point —
## this is NOT a complete feature, see "What's still open" below.

## What was built this session

- **Migration 58** (`backend/sql/58_migration_restaurant_closures.sql`)
  — two pieces, same idempotent DELIMITER/CONTINUE-HANDLER pattern as
  migrations 56/57:
  - `restaurants.temp_closed_until` (DATETIME NULL) — optional
    resume-timestamp for the *existing* on-demand temp-closed toggle
    (`operational_status = 'temp_closed'`), so "closed until 6 PM" is
    expressible without the restaurant manually flipping the switch
    back. NULL on every pre-existing row — indefinite-pause behavior
    unchanged unless a resume time is explicitly set.
  - New `restaurant_closures` table — `closure_type` ENUM
    ('date_range' | 'weekly_recurring'), `start_date`/`end_date` (for
    date_range), `day_of_week` 1–7 (for weekly_recurring, same
    Mon=1..Sun=7 convention `restaurants.working_days` already uses),
    `reason`, soft-delete `is_active`. FK to `restaurants(id)`, index
    on `(restaurant_id, is_active)` for the batch lookup below.
- **`backend/lib/restaurant_closures.php`** (new) — ownership check
  (`require_owned_closure`), shared validation
  (`validate_closure_fields` — date_range needs both dates with
  start<=end, weekly_recurring needs a 1–7 day), serialization
  (`get_closures_for_restaurant` for a restaurant's own management
  list), and the batch lookup
  (`get_restaurants_with_active_closure($db, $restaurantIds, $date, $dow)`
  — one `IN(...)` query covering many restaurants at once, same
  avoids-N+1 pattern as `list.php`'s existing `tagsByRestaurant`).
- **`backend/lib/restaurant_status.php`** — `compute_restaurant_status()`
  extended, backward-compatibly:
  - New 4th param `bool $hasActiveClosureToday = false` — when true,
    forces `is_open_now=false`/`is_paused=true` regardless of hours or
    `operational_status`. Default `false` means every call site that
    hasn't been updated yet (see below) behaves exactly as before —
    this alone doesn't break anything.
  - Auto-expiry: once `now() >= temp_closed_until`, `is_paused` reads
    as `false` even though the DB row's `operational_status` itself
    still literally says `temp_closed` — only stops *misrepresenting*
    an expired pause as active, doesn't itself flip the DB row back to
    `open` (no write access here, `status-update.php` remains the only
    write path — see its own kdoc for why this is deliberate).
- **`status-update.php`** — now accepts optional `resume_at`
  (`YYYY-MM-DD HH:mm:ss`) alongside `operational_status: "temp_closed"`,
  validated (must parse, must be in the future), stored into
  `temp_closed_until`. Switching to `open`/`busy` always clears
  `temp_closed_until` even if the caller didn't ask, since a stale
  resume-time attached to an inactive pause is meaningless.
- **4 new endpoints** (restaurant's own closure-schedule CRUD):
  `closures-list.php` (GET), `closures-create.php`, `closures-update.php`
  (full replace, not partial patch — see its own kdoc for why),
  `closures-delete.php` (soft-disable, same convention as
  `addon-groups-delete.php`).
- **`restaurants/list.php`** wired end-to-end — batches
  `get_restaurants_with_active_closure()` once per request (reusing
  the same `$ids` array already built for `tagsByRestaurant`) and
  passes the per-restaurant closure flag into
  `compute_restaurant_status()`. A restaurant on a scheduled holiday
  now shows Closed on the Home/list screen.

All new/edited PHP files manually brace/paren-balance checked (script
run this session, all OK). No XML touched this session (backend-only
so far). `php -l`, as always in this sandbox, not run — no PHP/network
here (same standing gap as every prior session).

## What's still open (real gaps, not just standing sandbox limitations)

- [ ] **`search/search.php`'s restaurant-results block** — still calls
      `compute_restaurant_status()` with the old 3-arg signature (that
      still works, since the new 4th param defaults to `false` — this
      is a functionality gap, not a break). A restaurant with a
      scheduled closure will show correctly on Home (`list.php`) but
      NOT yet in search results — same restaurant, contradictory
      status across two screens until this is done. Same batch-lookup
      pattern as `list.php` above should be copied in.
- [ ] **`search/search.php`'s items sub-block** (the `$rArr` built
      inline around line 218 for menu-item search results) — doesn't
      even have `id`/`temp_closed_until` in its `SELECT`, let alone a
      closure check. Smaller/rarer surface (a searched dish's card
      showing a stale open/closed badge) but still open.
- [ ] **`restaurants/menu.php`** — the restaurant-detail screen's own
      `compute_restaurant_status($restaurant)` call also still uses
      the old 3-arg form. Single-restaurant case (no batching needed,
      just one more `get_restaurants_with_active_closure()` call with
      a 1-element array, or a small single-id variant of it).
- [ ] **Android — nothing built yet.** No `Models.kt`/`ApiService.kt`
      entries, no `ClosureScheduleActivity`, no layouts, no
      `AccountFragment` wiring (new row + optional resume-time picker
      on the temp-closed switch), no manifest entry, no strings. This
      is the majority of the remaining work — a restaurant currently
      has **no way to actually create a closure** through the app; the
      backend CRUD exists but nothing calls it yet.
- [ ] Real Android build/run pass — n/a yet, nothing built.
- [ ] Backend `php -l` on all 6 new/touched files
      (`58_migration_restaurant_closures.sql`,
      `lib/restaurant_closures.php`, the 4 `closures-*.php` endpoints,
      `status-update.php`, `restaurant_status.php`, `restaurants/list.php`)
      + a live end-to-end test (create a date-range closure, confirm
      `list.php` shows the restaurant closed on a covered date; create
      a weekly-recurring one, confirm it on the matching weekday;
      confirm `temp_closed_until` auto-expiry via `status-update.php`
      → wait → re-check `list.php`) — same standing container gap, dev
      machine only.

## Files touched this session

- `backend/sql/58_migration_restaurant_closures.sql` (new)
- `backend/lib/restaurant_closures.php` (new)
- `backend/lib/restaurant_status.php`
- `backend/api/v1/restaurant/status-update.php`
- `backend/api/v1/restaurant/closures-list.php` (new)
- `backend/api/v1/restaurant/closures-create.php` (new)
- `backend/api/v1/restaurant/closures-update.php` (new)
- `backend/api/v1/restaurant/closures-delete.php` (new)
- `backend/api/v1/restaurants/list.php`
- `today.md` (§3 closure item marked 🟡 partial, this file linked)
- `docs/60_Handover_2026-08-28_TempClosureScheduling_BackendPartial.md`
  (this file)

## Suggested next session

Pick up exactly at "What's still open" above, in order:
1. `search/search.php` restaurant-results block (copy `list.php`'s
   batch pattern).
2. `search/search.php` items sub-block (add `r.temp_closed_until` /
   closure check to the inline `$rArr`).
3. `restaurants/menu.php` (single-restaurant closure check).
4. Android side — this is the big remaining piece. Suggested shape,
   not yet built: `ClosureScheduleActivity` reusing
   `activity_notification_list.xml` as its shell (same pattern as
   `AddonGroupsActivity`/`ReviewListActivity`), a single add/edit
   dialog with a type toggle (date-range date-pickers vs. a
   day-of-week picker for weekly), launched from a new row in
   `AccountFragment` (same row style as `btnReviewsRow`/`btnOffersRow`
   etc.). The existing temp-closed switch in `AccountFragment` could
   also grow an optional resume-time prompt (`MaterialDatePicker` →
   `MaterialTimePicker`, same chaining pattern already used in
   `CouponManagerActivity.setUpValidUntilPicker()`) to actually use the
   new `resume_at` field on `status-update.php` — currently backend-
   ready but nothing sends it.
5. `php -l` + live end-to-end test on all 6 backend files, on the dev
   machine.

Then, per `today.md`'s original priority list: **Bank Details form**
(§3) after this.
