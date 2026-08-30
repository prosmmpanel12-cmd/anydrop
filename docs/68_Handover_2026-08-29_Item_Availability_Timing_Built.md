# Handover — 2026-08-29 (doc 68): Item Availability Timing — Complete (Backend + Android)

## What was asked

Continuing the same conversation as docs 66/67. App owner asked to skip
Self Delivery and continue other pending Restaurant App work per
`today.md`. Picked up **today.md §1's real gap**: "Item availability
timing" (e.g. "breakfast item, 7am-11am only") — no `available_from`/
`available_until` concept existed anywhere on `menu_items`.

Session was interrupted mid-Android-wiring after the backend half; the
app owner said "continue" and the remaining Android wiring
(`MenuFragment.kt`) was finished in the same session, documented below.

## Backend — 100% complete this session

- **`backend/sql/62_migration_menu_item_availability_timing.sql`** (new)
  — adds `available_from`/`available_until` (`TIME NULL`, no default)
  to `menu_items`. Same idempotent CONTINUE-HANDLER-for-1060 pattern
  every ADD COLUMN migration in this project uses. Both null on every
  existing row and any new item that never sets a window — no behavior
  change for anything that doesn't opt in.
- **`backend/lib/menu_item_availability.php`** (new) — single function
  `is_menu_item_available_now(array $item, ?string $currentTime = null): bool`.
  Combines the manual `is_available` toggle AND the optional time
  window (both must pass). Handles:
  - Both columns null → no restriction (existing behavior unchanged).
  - Only one of the two set → treated as no restriction (a half-open
    window has no defined meaning).
  - `from < until` → same-day range check.
  - `from > until` → overnight wraparound (e.g. 22:00-02:00: available
    at/after `from` OR before `until`).
  - `from == until` → treated as no restriction (a zero-width window
    would mean "never," which the plain `is_available` OFF toggle
    already covers — avoids a confusing dead state reachable only by a
    form typo).
- **`backend/api/v1/restaurant/menu-items-create.php`** (edited) —
  accepts optional `available_from`/`available_until` ("HH:MM" or
  "HH:MM:SS"), stores and returns them.
- **`backend/api/v1/restaurant/menu-items-update.php`** (edited) — same
  two fields, partial-update. Unlike every other field on this
  endpoint, sending `""` (empty string) for either is a meaningful
  **explicit clear to NULL** (turns a previously-set window back off);
  `null`/absent means "not touched," same convention as the rest of
  the endpoint.
- **`backend/api/v1/restaurant/menu-items-list.php`** (edited) — returns
  the two raw fields alongside the existing item shape.
- **`backend/api/v1/restaurants/menu.php`** (customer-facing, edited) —
  **key design decision**: the `is_available` field this endpoint
  returns now reflects *effective* right-now availability
  (`is_menu_item_available_now()`), not just the raw column. This
  reuses `MenuAdapter.kt`'s existing "grey out + Out of stock badge"
  UI for free — no Android change needed to visually reflect a
  time-windowed item being currently closed. Raw `available_from`/
  `available_until` are also included (for a possible future "opens at
  7am" caption — not built this session). A manually-off item and a
  currently-out-of-window item look identical to the customer today
  (both just "not orderable right now") — same non-distinction
  `compute_restaurant_status()` already makes between "manually
  paused" and "outside opening hours" at the restaurant level.
- **`backend/api/v1/customer/cart-sync.php`** (edited) — a saved cart
  item outside its time window no longer restores on next app open
  (same reasoning as the existing is_available-based drop).
- **`backend/lib/orders.php`'s `price_cart()`** (edited) — **server-
  authoritative enforcement**, the important one: an item outside its
  window is rejected the same way an `is_available = 0` item already
  was (`reason: 'unavailable'`). Since both `cart/validate.php` and
  `orders/create.php` call this same function, both are covered by
  this one change — no separate wiring needed at either call site.

### Explicitly known, NOT fixed this session (flagged in code comments)

- **`search.php`, `home/popular-items.php`, `home/category-items.php`,
  `home/offers-browse.php`** still show the raw `is_available` column
  only, not the effective time-window check. An item could show as
  available on Search/Home but then get rejected at cart/checkout time
  (never *silently* wrong — `price_cart()` still blocks it — just an
  inconsistent display). Same class of gap `docs/60`/`61` fixed for
  restaurant-level closures across list/search/menu; this session
  didn't have time to do the equivalent sweep for item-level timing.
  **First thing to close in a follow-up pass** if this matters before
  the next real device test.
- **Scheduled ("order for later") orders** — `price_cart()`'s new
  check uses the real current server time, not the customer's chosen
  `scheduled_for` time. A customer scheduling an order for 8am
  tomorrow for a 7-11am breakfast item, placed today at 3pm, would be
  incorrectly rejected. Flagged in the code comment at the exact check
  site — needs `validate_scheduled_for()`'s parsed time threaded
  through if this interaction matters in practice.

## Android — Restaurant app — now complete

- **`restaurant/app/src/main/res/layout/dialog_add_menu_item.xml`**
  (edited) — "Availability timing (optional)" section with two
  `TextInputLayout`/`TextInputEditText` pairs
  (`itemAvailableFromLayout`/`inputItemAvailableFrom`,
  `itemAvailableUntilLayout`/`inputItemAvailableUntil`), placed right
  after the Veg switch. Exact structural copy of
  `dialog_add_offer.xml`'s happy-hour `startTimeLayout`/`endTimeLayout`
  fields (clock icon, custom clear/"X" end icon, non-focusable
  click-to-open). XML well-formedness checked (`xml.dom.minidom`).
- **`strings.xml`** (edited) — `label_item_availability_timing`,
  `label_item_availability_timing_hint`, `hint_item_available_from`,
  `hint_item_available_until`.
- **`Models.kt`** (edited) — `MenuItem`/`MenuItemCreateBody`/
  `MenuItemUpdateBody` all gained `availableFrom`/`availableUntil:
  String? = null` (`@SerializedName("available_from")`/
  `"available_until"`).
- **`MenuFragment.kt`** (edited, finishes the feature end to end):
  - New imports: `MaterialTimePicker`, `TimeFormat`, `SimpleDateFormat`,
    `Locale`.
  - `setUpItemTimePicker()`/`applyItemTimeValue()` (new, private) —
    close copy of `OfferManagerActivity.setUpTimePicker()`/
    `applyTimeValue()`, adapted to a `Fragment` (uses
    `parentFragmentManager` instead of `supportFragmentManager`) since
    that pair is private to the Activity, not a shared util.
  - `showItemDialog()` — calls `setUpItemTimePicker()` for both fields
    first (so the clear-icon's initial visibility reads `field.tag`
    correctly), then `applyItemTimeValue()` for each of
    `existingItem?.availableFrom`/`availableUntil` if present — same
    "set up listeners, then apply existing value" order
    `OfferManagerActivity.showEditOfferDialog()` uses for its own
    start/end time pair.
  - Save button handler — reads both fields' `field.tag` ("HH:mm:ss"
    wire value, or null if never set/cleared) and passes them into
    `saveItem()`.
  - `saveItem()` — gained `availableFrom`/`availableUntil: String?`
    params. **Design decision, documented in the function's kdoc**:
    for *create*, passed straight through (null → omitted, same as any
    other optional field). For *update*, null is turned into `""`
    (explicit clear per `menu-items-update.php`'s contract) rather than
    left out — this is safe because the dialog always fully
    re-populates both fields from the existing item before showing, so
    their on-save state is always the complete, definitive desired
    value; there's no "touched vs. untouched" case to preserve for just
    these two fields, unlike `image_url` (which stays null unless a
    *new* photo was picked this session).

## Verification done this session

Same standing constraint: no PHP CLI, Android SDK, or live DB in this
sandbox.

- Manual comment-aware (PHP `//`/`/* */`) brace/paren balance check on
  every new/edited `.php` file — all balanced.
- Full string-literal-and-comment-aware brace/paren balance check on
  `Models.kt` and `MenuFragment.kt` (a more careful checker than the
  crude line-based one — this file's edit needed it, see below) —
  balanced relative to the pre-session baseline.
  - Note: `MenuFragment.kt` shows a nonzero raw brace/paren count under
    the simple checker (brace +2, paren +1) — confirmed by diffing
    against the **pristine pre-session file extracted from the
    originally uploaded zip** that this exact imbalance already existed
    before this session touched the file (likely a checker limitation
    around some existing string-template or nested-generic pattern
    elsewhere in this 1000+ line file, not a real syntax error). A
    full diff of old-vs-new confirmed every change in this session was
    exactly the intended addition, with nothing structurally broken.
- XML well-formedness (`xml.dom.minidom`) on `dialog_add_menu_item.xml`
  and `strings.xml` — both parse cleanly.
- Cross-checked every view ID referenced from `MenuFragment.kt`
  (`itemAvailableFromLayout`, `itemAvailableUntilLayout`,
  `inputItemAvailableFrom`, `inputItemAvailableUntil`) exists in
  `dialog_add_menu_item.xml` — confirmed by grep count on both files.
- Cross-checked `menu-items-update.php`'s existing field-handling
  convention before adding `available_from`/`available_until`, to keep
  the explicit-clear semantics consistent with how the rest of that
  endpoint already treats `""` vs `null`.
- Confirmed both `cart/validate.php` and `orders/create.php` call the
  same `price_cart()` function edited in `lib/orders.php`, so the
  server-authoritative check covers both without separate wiring.

## Genuinely still open

- [ ] `php -l` on all 7 touched/new backend files + a real end-to-end
      test (create an item with a window, confirm it's rejected outside
      the window at `/cart/validate` and `/orders/create`, confirm it's
      accepted inside the window) — standing container gap (no PHP/
      network here).
- [ ] Real Android Gradle build — first real compile of this feature,
      none possible in this sandbox.
- [ ] Propagate the effective-availability check to `search.php`/
      `home/*.php` (see "Explicitly known, NOT fixed" above) — display
      consistency gap, not a correctness gap (checkout still blocks it
      either way).
- [ ] Thread `scheduled_for` through the `price_cart()` check for the
      "order for later" interaction (see above) — only matters once
      Scheduled Orders + item timing are both in real use together.
- [ ] A menu-card caption showing the configured window (e.g. "7:00 AM
      - 11:00 AM") — nice-to-have, not asked for, not started.

## Files touched this session

**Backend:** `backend/sql/62_migration_menu_item_availability_timing.sql`
(new), `backend/lib/menu_item_availability.php` (new),
`backend/lib/orders.php` (edited), `backend/api/v1/restaurant/
menu-items-create.php` (edited), `backend/api/v1/restaurant/
menu-items-update.php` (edited), `backend/api/v1/restaurant/
menu-items-list.php` (edited), `backend/api/v1/restaurants/menu.php`
(edited), `backend/api/v1/customer/cart-sync.php` (edited).

**Android — Restaurant:** `dialog_add_menu_item.xml` (edited),
`strings.xml` (edited), `Models.kt` (edited), `MenuFragment.kt` (edited).

**Docs:** this file.

## Suggested next session

1. `php -l` + real end-to-end backend test once a PHP/network-capable
   environment is available.
2. Real Android Gradle build.
3. Peak-hours analytics (today.md §6) — still needs its own design
   decision first.
4. Staff/RBAC, Rider App — large, untouched, separate phases (Self
   Delivery explicitly excluded from current scope per app owner).
