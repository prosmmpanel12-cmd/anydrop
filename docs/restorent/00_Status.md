## 2026-08-16 — Palette refresh: Exotic Orange + Midnight Blue "ink" chrome (this session)

App owner shared 8 color-pair reference images and asked for the best
one applied to the app, plus a general UI-quality pass, with the plan
doc (`19_Restaurant_App_UI_Plan.md`) kept in sync. See that doc's new
§8.1 for the full picked-palette rationale (short version: warm hues
are the deliberate, non-negotiable choice for a food app — established
appetite-suppressing effect of blue in F&B branding ruled out every
blue-led pair in the reference set as the primary color) and §8.2 for
the superseded original table.

### ✅ Done
- **Token-level palette swap** — `colors.xml`: primary `#E64A19` →
  `#F54F1B` ("Exotic Orange"), plus three new tokens for a dark "ink"
  surface family (`anydrop_ink` `#1E223D` "Midnight Blue",
  `anydrop_ink_light`, `text_on_ink`, `text_on_ink_muted`) that didn't
  exist before.
- **Ink applied to nav chrome** — `activity_main.xml`'s shared top bar
  and `BottomNavigationView` background both switched from plain white
  (`@color/surface`) to `@color/anydrop_ink`; `restaurantNameText`
  recolored to `text_on_ink`; `bottom_nav_item_color.xml`'s unselected
  state recolored from `text_secondary` (too low-contrast on dark navy)
  to the new `text_on_ink_muted`. `themes.xml`'s `statusBarColor` moved
  to `anydrop_ink` to match, with `windowLightStatusBar=false` (status
  bar icons need to be light-colored against a dark bar now — safe to
  set directly, `minSdk 24` is well above the API 23 floor for that
  attribute).
- **Confirmed this cascades app-wide with no other edits needed** —
  grepped for the old hex values (`#E64A19`/`#B23C14`/`#FFE0D3`)
  anywhere else in `res/`; none hardcoded outside `colors.xml` itself,
  so every button/switch/active-state pulling from `colorPrimary` or
  `@color/anydrop_primary` picked up the new orange automatically.

### 🟡 Known gaps / not done this session
- **No build/visual verification** — same standing sandbox limitation.
  This is a genuinely higher-risk change than most prior sessions'
  additive ones: it touches the app's global theme/status-bar color and
  a shared layout every screen sits inside, so a mistake here is
  visible everywhere rather than isolated to one screen. First real-
  toolchain step should be opening the app and eyeballing the top bar
  contrast, bottom nav legibility (checked vs. unchecked), and status
  bar icon color before anything else in this session's queue.
- **Pre-login/detail screens not touched** — `activity_login.xml`,
  `activity_signup.xml`, `activity_otp_verify.xml`,
  `activity_signup_success.xml`, `activity_splash.xml`,
  `activity_order_detail.xml` still use plain white full-screen
  backgrounds from before this refresh. Not an oversight — flagged
  explicitly as plan §10 item 7, a follow-up visual pass, since giving
  them a matching ink treatment needs a per-screen layout look rather
  than a token swap.
- Didn't touch typography/spacing, only color — per the plan doc, the
  bigger hierarchy issues (§4–§7) are structural, not color, and were
  already addressed in earlier sessions' Orders/Menu tab redesigns.

### ⏭️ Next
Same standing top priority as every recent entry — **build both APKs
with a real toolchain**, now with this session's theme/status-bar/nav
chrome change added to the unverified pile (see `NEXT_SESSION_PROMPT.md`
for the full running list). Then plan §10 item 7 (pre-login screens ink
pass) if there's appetite for more visual polish before moving to
Account tab (§10 item 5, unchanged from before).

---

## 2026-08-16 — Menu tab: category-tabs-strip + drag-to-reorder (this session)

Per `NEXT_SESSION_PROMPT.md`, closing out the last two open pieces of §10
item 4 — both were still stacked-cards-only / not-built as of the entry
below. Backend support for both already existed as of this session's
earlier "Backend: category/menu-item PHP endpoints" entry
(`categories-update.php` accepts `sort_order`).

### ✅ Done
- **Category tabs strip** (§5) — new `CategoryTabAdapter.kt` +
  `item_category_tab_chip.xml`, a horizontal `RecyclerView` of pill chips
  ("All" + one per active category) inserted into `fragment_menu.xml`
  between the search bar and the category list. `MenuFragment` shows it
  only once there are 5+ active categories (`TAB_STRIP_MIN_CATEGORIES`)
  and hides it while searching or reordering (see "mutually exclusive"
  design note below). Selecting a tab other than "All" filters
  `categoriesRecycler` down to that one category's card client-side — no
  new network call, reuses whatever `categories`/`items` are already
  loaded. New drawables `bg_chip_unselected.xml` (gray-100 pill) and
  `bg_menu_tab_selected.xml` (solid `anydrop_primary` pill, matching §8's
  color-token table which explicitly lists Primary for "active tab" —
  deliberately not reusing the existing `bg_chip_selected.xml`, since
  that one's default fill, `anydrop_primary_container`, is too light for
  white chip text; that drawable is only safe as used elsewhere, retinted
  per-status at runtime).
- **Drag-to-reorder** (§5) — `CategoryAdapter.kt` gained a `reorderMode`
  flag (default off) and a `moveItem()` used by a new `ItemTouchHelper`
  (`MenuFragment`, vertical drag only, `isLongPressDragEnabled = false` —
  the row's new ☰ drag-handle TextView starts the drag itself via
  `ACTION_DOWN` instead). `MenuFragment`'s "⇅ Reorder"/"Done" toggle
  (new header button) enters/exits reorder mode; entering it clears any
  active search/tab filter and shows every active category collapsed to
  just name + item count (items/edit/delete/add-item hidden — see
  `CategoryAdapter`'s class doc for why). "Done" diffs the on-screen order
  against each category's last-known `sortOrder`, calls
  `categories-update.php` once per category whose position actually
  changed (new sequential 0..n-1 values, not gapped), shows a
  success/failure toast either way, then reloads from the server
  regardless (so any partial-failure leaves the UI showing server truth,
  not a half-applied local reorder).
- **Design call, documented in both `MenuFragment`'s and
  `CategoryAdapter`'s class docs**: search, tab-strip filtering, and
  reorder mode are treated as mutually exclusive rather than combined.
  Starting a search or entering reorder mode clears the tab selection;
  entering reorder mode also clears any active search. Reordering only
  ever operates on the full active-category list, never a filtered
  subset — dragging within a filtered view wouldn't reflect real
  positions relative to hidden categories.
- Added a stale-selection guard in both `MenuFragment.applyDisplayFilter()`
  and `CategoryTabAdapter.submitCategories()`: if the tab-selected category
  gets deleted/deactivated elsewhere (e.g. the 🗑️ button) between loads,
  selection silently falls back to "All" instead of filtering the list
  down to nothing.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation, seven
  sessions running now. New unverified surface this session: the
  `ItemTouchHelper.SimpleCallback` wiring itself (never compiled or
  run in this project before), the nested-RecyclerView-inside-drag
  interaction (dragging a `CategoryViewHolder` whose `itemsRecycler` is
  set to a null adapter mid-drag), and `SwipeRefreshLayout.isEnabled`
  toggling around reorder mode.
- **Reordering only repositions active categories** — inactive
  (soft-disabled) categories keep whatever `sort_order` they had before;
  since they're invisible in every current UI surface this doesn't cause
  a visible bug, but their stored `sort_order` can end up interleaved
  with the newly-sequential active values. Worth a cleanup pass if/when
  category restore (re-activating a disabled category) gets built.
- **No "long-press anywhere on the row" drag start** — deliberately
  scoped to the ☰ handle only (`isLongPressDragEnabled = false`), so a
  future accidental long-press on the category name/count doesn't start
  a drag by surprise. If real-device testing finds the handle's hit area
  too small to grab reliably, widen its padding rather than falling back
  to whole-row long-press.

### ⏭️ Next
§10 item 4 is now feature-complete client + server side (photo thumbnail,
search, skeleton, tabs strip, drag-reorder). Per `NEXT_SESSION_PROMPT.md`'s
standing top priority: **build both APKs with a real toolchain** —
nothing in this project has been compiled since it started (now the
oldest and largest pile of unverified surface, seven sessions deep) —
before adding anything else. Then Account tab (§10 item 5).

---

## 2026-08-16 — Backend: category/menu-item PHP endpoints (this session)

Per `NEXT_SESSION_PROMPT.md`'s note that this upload was a partial project
export — the client side (`ApiService.kt`) already declared 8 endpoints
under `restaurant/` that had no backend file in this zip. Built all 8
now, following this codebase's existing conventions (`lib/response.php`,
`lib/auth.php`'s `require_auth('restaurant')`, `Database::get()`,
partial-update pattern from `status-update.php`/`orders-status.php`).

### ✅ Done — `backend/api/v1/restaurant/`
- **`categories-list.php`** (GET) — all of the restaurant's categories
  (active + inactive; `CategoryAdapter.kt` already filters to
  `is_active` client-side), with a live `item_count` subquery per row.
- **`categories-create.php`** (POST) — `sort_order` defaults to
  "append to end" when omitted (current client never sends it).
- **`categories-update.php`** (POST, `?id=`) — partial update
  (name / sort_order / is_active), ownership-checked.
- **`categories-delete.php`** (POST, `?id=`) — soft-disable
  (`is_active = 0`), **not** a hard delete: `menu_categories` has no
  `deleted_at` column per `01_Database_Schema.md` §2, and this avoids
  orphaning any `menu_items` still pointing at the category.
- **`menu-items-list.php`** (GET, `?category_id=&search=`) — both
  filters optional/combinable; `search` is a `name LIKE %q%` match.
  Includes out-of-stock (`is_available = 0`) items, same reasoning as
  the customer-facing `restaurants/menu.php`.
- **`menu-items-create.php`** (POST) — validates `category_id` belongs
  to the calling restaurant before inserting; `prep_time_minutes`
  defaults to 15 (schema default) when omitted.
- **`menu-items-update.php`** (POST, `?id=`) — partial update; doubles
  as the out-of-stock toggle's write path (`{"is_available": bool}`
  only) and the full edit-dialog save. Re-validates `category_id`
  ownership if that field is being changed.
- **`menu-items-delete.php`** (POST, `?id=`) — soft delete
  (`deleted_at = NOW()`), since `menu_items` (unlike categories) does
  have that column. Past orders are unaffected — `order_items` snapshots
  `item_name_snapshot`/`unit_price` at order time rather than joining
  live to `menu_items` (`01_Database_Schema.md` §"order_items").

No `.htaccess` changes needed — the Restaurant App calls these `.php`
files directly by path (`@GET("restaurant/categories-list.php")` etc.,
same as the already-working `orders-list.php` etc.), not through the
pretty-route rewrites those older endpoints also happen to have.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing PHP-side limitation:
  no PHP CLI in this sandbox to lint-check, let alone a real DB to run
  these against. Balanced braces/parens checked mechanically only.
  First real-toolchain step: hit each endpoint once (Postman/curl) against
  a seeded restaurant before trusting the Android client-side work from
  earlier today against it.
- **Client-side Menu tab work (photo thumbnail, search wiring, skeleton)
  not touched this session** — that was already done per the entry
  below; this session was backend-only, per the app owner's explicit
  "pehle backend sahi karo" instruction.
- `discount_percent`, `is_recommended`, `is_bestseller` aren't settable
  through `menu-items-create.php`/`menu-items-update.php` — no UI sends
  them yet (`MenuItemCreateBody`/`MenuItemUpdateBody` don't expose them
  either), so they're left at their schema defaults for every item
  created through this app so far.

### ⏭️ Next
Build/smoke-test both the backend (this session) and the client
(2026-08-16 Menu tab entry below, 2026-08-15 Orders tab entry further
below) together with a real toolchain — per `NEXT_SESSION_PROMPT.md`'s
standing top priority, oldest unverified risk first. Then close out §10
item 4 (category-tabs-strip + drag-to-reorder) before moving to Account
tab (§10 item 5).

---

## 2026-08-16 — Menu tab: photo thumbnail + search + skeleton (this session, partial)

Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 4, picking up Menu
Management after the Orders tab redesign. **This session only covers
part of item 4** — photo thumbnail slot, search bar wiring, and the
skeleton state. Category-tabs-strip (5+ categories) and drag-to-reorder
are still not done — see gaps below.

### ✅ Done
- **Photo thumbnail slot** (`item_menu_food.xml` + `MenuItemAdapter.kt`):
  44dp `ImageView` added before the veg dot, matching
  `skeleton_menu_item_row.xml`'s already-built proportions (the plan's
  §5 text says "60×60" but the skeleton built two sessions ago locked
  in 44dp first, so this follows that rather than the plan doc — worth
  reconciling if it's noticed). Loaded via Coil (`item.imageUrl`,
  crossfade); falls back to a new `ic_food_placeholder.xml` (tinted
  `text_secondary`, inset) when there's no photo yet or the load fails.
  `restaurant/app/build.gradle` — added `io.coil-kt:coil:2.6.0`, same
  version the Customer app already uses.
- **Search bar** (`fragment_menu.xml` + `MenuFragment.kt`): pill-style
  bar under the header (copied `bg_search_pill.xml`/`ic_search.xml`
  from the Customer app for visual consistency), 400ms-debounced
  `TextWatcher` calling `getMenuItems(search = query)` — the backend
  `?search=` param the plan doc noted as "already ready." Categories
  with zero matching items under an active search are filtered out of
  the list client-side (an empty category card under a search felt
  like noise); a `menu_search_no_results` string swaps in for the
  usual empty-state text while a query is active.
- **Skeleton state** (§9.2): new `skeleton_menu_category_card.xml`
  (title bar + 3× the existing `skeleton_menu_item_row.xml`), wrapped
  in `ShimmerFrameLayout` inside a `ScrollView`, same
  show-on-first-load/hide-after-response pattern as the Orders tab's
  per-section skeletons. Shown on true first load and on a fresh
  search (new result set); **not** shown on pull-to-refresh — that
  keeps `SwipeRefreshLayout`'s own small spinner per §9.1.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation, now
  six sessions running (no Android SDK, no network access in this
  sandbox). New unverified surface this session: the Coil dependency
  addition itself (never built with it in this project), `ImageView`
  padding/scaleType toggling in `MenuItemAdapter.bind()`, and
  `fragment_menu.xml`'s new `ScrollView`-wrapped skeleton stacked
  inside the existing `SwipeRefreshLayout`/`FrameLayout`.
- **Category-tabs-strip variant not built** — §5's "horizontal scroll
  strip once a restaurant has 5+ categories" is still the old stacked
  cards regardless of category count.
- **Drag-to-reorder not built** — `sort_order` exists backend-side per
  the plan doc, but no drag handle or reorder UI was added this
  session.
- **This zip fragment doesn't include the backend `restaurant/`
  category/menu-item PHP endpoints** — `ApiService.kt` already
  declared `getCategories()`/`getMenuItems(search=...)` from a prior
  session, so this session only had to wire the client side. Not a
  gap in the work itself, just a note that this upload was a partial
  project export.

### ⏭️ Next
Per doc 19 §10, still within item 4: category-tabs-strip (5+
categories) and drag-to-reorder, to close out Menu tab. Then, per
`NEXT_SESSION_PROMPT.md`'s standing top priority — build both APKs
with a real toolchain and smoke-test everything unverified so far
(Orders tab redesign from 2026-08-15, plus this session's Menu tab
changes) before moving to §10 item 5 (Account tab).

---

## 2026-08-15 — Orders tab redesign, OrderAdapter + OrdersFragment rebuild (earlier session)

Per `NEXT_SESSION_PROMPT.md`'s "Next work, in order" list — items 1–3,
continuing straight from the UI-groundwork entry directly below (same
day). All three of that list's items landed this session.

### ✅ Done
- **`OrderAdapter.kt` — rebuilt around a `CardMode` enum** (`NEW` /
  `IN_PROGRESS` / `COMPLETED`). One adapter class, but now takes `mode`
  in its constructor — callers create **one instance per section**
  rather than one shared instance for everything, per the plan's
  suggested approach.
  - `NEW`: `countdownChip` + `actionRow` (`btnReject`+`btnAccept`) both
    shown. `btnAccept` invokes an `onAccept(order)` callback; `btnReject`
    opens an inline `AlertDialog.Builder` with a plain `EditText` (no new
    dialog layout, per the plan's "keep it simple" note) collecting a
    reason before invoking `onReject(order, reason)` — empty reason
    blocks submission via `InAppNotifier`, same validation
    `OrderDetailActivity.confirmReject()` does.
  - `IN_PROGRESS`: `stepperRow` shown, dot state derived from
    `order.status` (`accepted`/`preparing` → step 1 filled only;
    `ready`+ → steps 1–2 filled; step 3 "Handed to rider" always empty —
    unreachable from this app). `actionRow` shows only `btnAccept`,
    relabeled via `btn_mark_next_step`, invoking `onMarkNextStep(order)`
    — row hidden entirely once `nextStatusFor(status)` returns null
    (status already `ready` or beyond), matching
    `OrderDetailActivity.configureActions()`'s `else` branch.
  - `COMPLETED`: all three optional rows hidden — identical to the
    pre-redesign card.
  - **Countdown ticker implemented here too** (folds in what was
    planned as a separate item 3): each `NEW`-mode `ViewHolder` runs its
    own `Handler.postDelayed` loop counting down from `order.createdAt`
    (reusing `ScheduledTimeFormatter`'s `"yyyy-MM-dd HH:mm:ss"` parsing
    approach) against a fixed 5-minute local window, formatted via
    `countdown_format`, switching to `countdown_expired` ("Accept now")
    once past it. `onViewRecycled()` stops the handler so a recycled row
    doesn't keep ticking into whatever gets bound next. Comments
    reiterate this is cosmetic-only — no backend deadline exists to
    imply.
- **`OrdersFragment.kt` + `fragment_orders.xml` — full rebuild**,
  replacing the New/Active/History tab filters with the actual §4
  layout:
  - Old `switchAcceptingOrders` + its listener, `toggleAcceptingOrders()`,
    `revertToggle()`, and the summary-text operational-status wiring are
    **deleted** — that's `MainActivity`'s job now (see the entry below),
    and nothing duplicate remains.
  - "Today" stat strip: 3 `bg_stat_chip` chips (orders count / earnings /
    avg prep), fed by the same `getDashboard()` call the old
    `summaryText` used. Avg prep falls back to `stat_placeholder` ("—")
    when `avgPrepMinutes` is null.
  - Three always-visible sections, each its own `RecyclerView` +
    `OrderAdapter` instance (`nestedScrollingEnabled="false"`, stacked
    inside a plain `ScrollView` — matching this project's existing
    `ScrollView` convention elsewhere rather than introducing
    `NestedScrollView`) wrapped in one `SwipeRefreshLayout`:
    - **New** (`status=pending`).
    - **In progress** (`status=accepted,preparing,ready,rider_assigned,
      picked_up,out_for_delivery` — the old `Tab.ACTIVE` filter, now
      always visible instead of behind a tab).
    - **Completed today** (`status=delivered,cancelled,rejected` — old
      `Tab.HISTORY`), collapsed by default behind a tappable header with
      a text-caret ("▸"/"▾", no new drawable) that flips on toggle;
      loads lazily on first expand rather than on every poll tick.
  - `ShimmerFrameLayout` + 2x `skeleton_order_card` wired into each
    section (shown whenever that section's adapter is currently empty
    going into a load, hidden once the response lands).
  - 10s polling loop and `onResume()` refresh-on-return-from-detail
    behavior both carried over as-is, now driving `loadAll()` (all three
    section calls + the stat strip) instead of one call.
  - Each section's mutation flow (accept/reject/mark-next-step) re-loads
    only the section(s) it affects rather than the whole screen — e.g.
    accepting a New order reloads New + In-progress, not Completed.

### 🟡 Known gaps / not done this session
- **Still no build/compile verification** — same standing limitation,
  now five sessions running. No Android SDK, no network access in this
  sandbox. This session's new unverified surface on top of the existing
  stack: `OrderAdapter`'s changed constructor signature (now requires
  `mode`), the `Handler`-based countdown ticker and its recycle-cleanup,
  and `fragment_orders.xml`'s `ScrollView`-wrapping-3-`RecyclerView`s
  layout (each `RecyclerView` relies on `wrap_content` height +
  `nestedScrollingEnabled="false"` to lay out correctly inside a
  `ScrollView` — a well-worn pattern, but unverified here).
- **No dedicated confirmation title string for the reject dialog** —
  reuses `R.string.btn_reject` ("Reject") as the `AlertDialog` title
  rather than a purpose-written "Reject this order?" string. Cosmetic;
  fine to leave or polish later.
- **Reject/accept/mark-next-step failures don't disable buttons
  mid-flight** — unlike `OrderDetailActivity`'s `setActionsEnabled()`
  pattern, a double-tap on `btnAccept`/`btnReject` before the first
  request resolves could fire twice. Low-risk (the backend's status
  transitions are idempotent/guarded either way) but worth tightening
  if it comes up.
- **Stat strip has no skeleton state** — only the three order sections
  got skeleton wiring; the stat chips just show blank text until
  `loadDashboardSummary()` resolves. Minor, since it's usually fast and
  non-blocking.

### ⏭️ Next
Per doc 18's own recommended order (Orders redesign was pulled forward
out of turn — see the 2026-08-14 entry below): resume with **Menu
Management** (Tier 1, biggest remaining functional gap) once this is
build-verified. Before that: build both APKs with a real toolchain and
smoke-test the whole Orders tab (accept/reject/mark-next-step, the
countdown, the Completed-today expand/collapse, skeletons) end-to-end —
this is the largest unverified surface added in one sitting so far.

---

## 2026-08-15 — Orders tab redesign, UI groundwork (earlier this session, partial)

Per doc 19 §10 item 3, continuing from the backend/model groundwork
entry directly below. This session started the actual UI build but did
**not** finish it — `OrderAdapter`/`OrdersFragment`/`fragment_orders.xml`
are still untouched. Everything below is new resources + the shared
top bar only; the Orders tab list itself still looks and behaves
exactly as it did before this session (New/Active/History tab filters,
switch inside the fragment removed — see known gap below).

### ✅ Done
- **New drawables** (`restaurant/app/src/main/res/drawable/`):
  - `bg_stepper_dot_filled.xml` / `bg_stepper_dot_empty.xml` — filled
    (completed/current) vs. outline (upcoming) dots for the §4
    horizontal status stepper.
  - `bg_countdown_chip.xml` — amber pill background for the New-section
    "Accept within 1:45" countdown chip.
  - `bg_stat_chip.xml` — gray-100 background for the "Today" snapshot
    strip chips (§8). **Not yet used anywhere** — waiting on the stat
    strip itself, which is still inside `fragment_orders.xml`'s
    unbuilt redesign.
  - `bg_pill_open.xml` / `bg_pill_closed.xml` — green/red pill
    backgrounds for the shared OPEN/CLOSED toggle.
  - `bg_dot_green.xml` / `bg_dot_red.xml` — small solid dots inside the
    pill (deliberately separate from the stepper dots above, which use
    `anydrop_primary` orange, not semantic green/red).
- **New strings** in `strings.xml`: section headers
  (`section_new_orders`, `section_in_progress`, `section_completed_today`),
  stat chip labels/formats, stepper step labels, countdown format
  strings, `btn_mark_next_step`, per-section empty-state strings, pill
  labels (`restaurant_open_label`/`restaurant_closed_label`), and the
  close-confirmation dialog strings.
- **`activity_main.xml`** — added the shared top bar above the bottom
  nav: restaurant name (left) + OPEN/CLOSED pill (right, tappable).
  Matches §3's "stays pinned in a top bar above the bottom nav on
  every tab."
- **`MainActivity.kt`** — now owns operational-status state and UI:
  - Fetches current status via `api.getDashboard()` on create and
    resume (`operationalStatus != "busy"` → pill shows Open).
  - Restaurant name pulled from `TokenManager.getRestaurantName()`
    (already cached at login — no new API call needed for this part).
  - Tapping the pill while Open shows an `AlertDialog.Builder`
    confirmation (§4: "tap to confirm before closing") before calling
    `updateOperationalStatus(busy)`; tapping while Closed re-opens
    immediately, no confirmation needed.
  - Same revert-on-failure pattern the old `OrdersFragment` switch
    used: `isOpen` only flips after a successful API response;
    `renderPill()` re-syncs the visible state either way.
- **`item_order_card.xml`** — rebuilt with three new optional sections,
  **all `visibility="gone"` by default** so the card looks identical to
  before until `OrderAdapter` is updated to show them:
  - `countdownChip` next to the existing `statusChip`.
  - `stepperRow` — 3 dots (Preparing → Ready → Handed to rider) with
    connecting lines. Note: "Handed to rider" is shown for visual
    completeness only — it's never reachable by tapping this app's
    "Mark next step" button, since `orders-status.php`'s allowed
    transitions are only `accepted→preparing` and `preparing→ready`
    (rider
    assignment is Phase 4, not built).
  - `actionRow` — `btnReject` + `btnAccept` for New cards; the same
    `btnAccept` slot is meant to be relabeled "Mark next step" and
    `btnReject` hidden for In-progress cards (adapter work, not done
    yet).

### 🟡 Known gaps / not done this session
- **`OrderAdapter.kt` untouched** — no `CardMode` enum yet, so none of
  `item_order_card.xml`'s new rows are ever shown or wired to a click
  handler. This is the next concrete step.
- **`OrdersFragment.kt` / `fragment_orders.xml` untouched** — still has
  the old New/Active/History tab-filter UI and its own
  `switchAcceptingOrders` + `summaryText`. This needs to become the
  three-section layout (§4) and **the operational-status switch/summary
  code in `OrdersFragment.kt` needs to be deleted**, not just
  left alongside the new `MainActivity` pill — right now there would be
  two places managing/displaying the same status if both were live
  (the fragment's old switch is still in `fragment_orders.xml` and
  still functional; it just hasn't been removed yet).
- **No countdown timer utility** — the "Accept within 1:45" ticking
  chip needs a small ticker (e.g. a `Handler`/coroutine loop) counting
  down from `order.createdAt` against a fixed local window (5 min
  suggested previously); not started. `Order.createdAt` is the same
  `"yyyy-MM-dd HH:mm:ss"` format `ScheduledTimeFormatter` already
  parses, so that utility's parsing approach can be reused.
- **No skeleton wiring** — `ShimmerFrameLayout` + `skeleton_order_card.xml`
  (built two sessions ago) are still not used anywhere.
- **Still no build/compile verification** — same standing limitation,
  now four sessions running. No Android SDK, no network access in this
  sandbox. `MainActivity.kt`'s new `AlertDialog` import and the
  `ContextCompat.getDrawable` calls in `renderPill()` are the new
  unverified risk this session adds on top of the still-unverified
  Fragment conversion from two sessions ago.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md`: finish §10 item 3 — `OrderAdapter`
`CardMode` wiring, `OrdersFragment`/`fragment_orders.xml` section
rebuild (and deleting its now-superseded switch/summary code), the
countdown ticker, and skeleton wiring. The resources/top-bar above are
there to be consumed, not re-derived.

---



Per doc 19 §10 item 3. This session did **not** get to the actual Orders
tab UI rework — only the small, safe, backend-first groundwork it depends
on. Nothing risky was touched; everything below is additive and backward
compatible with the app as it stood after the bottom-nav shell session.

### ✅ Done
- **`backend/api/v1/restaurant/dashboard.php`** — added a real
  `avg_prep_minutes` to the `today` object: `AVG(TIMESTAMPDIFF(MINUTE,
  accepted_at, ready_at))` for today's orders, restricted to orders that
  actually have both timestamps set (still-preparing orders are excluded,
  not counted as 0 min). Backs §4's "Today" snapshot strip third chip
  (Orders · Earnings · Avg prep time). `orders.accepted_at`/`ready_at`
  already existed in the schema and are already written by
  `orders-accept.php`/`orders-status.php` — this only adds a read.
- **`network/Models.kt`** — added `avgPrepMinutes: Int?` to
  `TodaySummary`, nullable (no orders reached "ready" yet today = no
  stat, not a misleading "0 min").
- **`colors.xml`** — added `warning_amber` / `warning_amber_bg` (§8's
  amber for the order countdown timer as it runs low) and
  `stat_chip_bg` (§8's neutral gray-100 for stat chips, to sit apart
  from white order cards). Not wired into any layout yet.

### 🟡 Known gaps / not done this session
- **The actual Orders tab redesign is not started** — no top-bar
  OPEN/CLOSED pill, no New/In-progress/Completed sections, no countdown
  timer, no status stepper, no matching skeleton wiring. This session
  only got as far as confirming what real data the backend has to work
  with before writing any Kotlin/XML for it.
- **Important scope note discovered this session, for whoever picks this
  up:** there is no accept-deadline/auto-reject concept anywhere in the
  backend (checked — no `accept_deadline`/`expires_at`/timeout column on
  `orders`, no cron/scheduled job for it). §4's "Accept within 1:45"
  countdown chip can only be a **client-side visual cue** (e.g. counting
  down from `created_at` against a fixed local window like 5 minutes),
  not something the backend enforces or would reject against. Build it
  that way and say so in the UI/code comments — don't imply a real
  deadline exists.
- **Still no build/compile verification** — same standing limitation,
  now three sessions running. No Android SDK and no network access in
  this sandbox (confirmed again this session — no `java`/Android SDK,
  bash_tool has network disabled). The Fragment-conversion risk flagged
  in the bottom-nav shell session is still unverified.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 3: actually build the
Orders tab redesign — shared top bar (restaurant name + OPEN/CLOSED
pill, §3) in `MainActivity`, then `OrdersFragment`/`fragment_orders.xml`
sections + countdown + stepper + skeleton. The three additions above
(avg_prep_minutes, colors) are there to be consumed, not re-derived.

---

# Restaurant App (Anydrop For Restaurant) — Status

Full scope/priority reference: `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`.
This file tracks what's actually been *built* against that plan — updated
each session, newest at the top.

---

## 2026-08-15 — Bottom nav shell (this session, after the shimmer block)

Per doc 19 §10's build order, item 2 — unblocks Insights/Account tabs
and the Orders/Menu tab redesigns that come next.

### ✅ Done
- **`ui/main/MainActivity.kt` + `activity_main.xml`** — new post-login
  entry point: a `FragmentContainerView` above a persistent
  `BottomNavigationView` with the 4 tabs from §3 (Orders / Menu /
  Insights / Account). `SplashActivity` and `LoginActivity` now route
  here instead of the old `DashboardActivity`.
- **`DashboardActivity` → `ui/orders/OrdersFragment.kt`** — same
  behavior (New/Active/History sub-tabs, 10s polling, accepting-orders
  switch), just re-hosted as a fragment. Polling is now tied to
  `viewLifecycleOwner.lifecycleScope`, so it's cancelled automatically
  on tab switch instead of needing manual onPause/onResume bookkeeping.
- **`MenuManagementActivity` → `ui/menu/MenuFragment.kt`** — same
  category/item CRUD behavior, minus the back-arrow/slide-transition
  (it's a tab now, not a pushed screen).
- **`ui/insights/InsightsFragment.kt`** — empty "coming soon" placeholder.
- **`ui/account/AccountFragment.kt`** — minimal placeholder: restaurant
  name + a Logout button. Logout moved here from the old Dashboard top
  bar (flagged as still-needed in docs/restorent/20 §3) — it's not the
  full §7 profile screen yet, just enough that logout has a home.
- Removed the now-superseded `DashboardActivity.kt`,
  `MenuManagementActivity.kt`, `activity_dashboard.xml`,
  `activity_menu_management.xml`, and their manifest entries. New
  manifest entry for `MainActivity` carries over the
  `adjustResize` flag Menu's add/edit dialogs needed.
- New icons: `ic_nav_orders`, `ic_nav_menu`, `ic_nav_insights` (Account
  reuses the existing `ic_person`); new `bottom_nav_item_color.xml`
  color selector (primary when selected, secondary otherwise).

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation.
  Fragment conversions are the riskiest kind of change to make blind;
  double-check view-binding class names (`FragmentOrdersBinding`,
  `FragmentMenuBinding`, `FragmentInsightsBinding`,
  `FragmentAccountBinding`) actually generate as expected and that
  `InAppNotifier.show(activity, ...)` (it takes a nullable `Activity`,
  not a `Context` — easy to get wrong when converting Activity code to
  Fragment code, caught once already this session) is called correctly
  everywhere before trusting this compiles clean.
- **Tabs reload on every switch** — `MainActivity` uses a plain
  fragment `replace()`, so Orders/Menu re-fetch from the network every
  time you switch back to them rather than keeping state in the
  background. Flagged in `MainActivity`'s own doc comment as an
  acceptable simplification for this shell step, not a final decision.
- **OPEN/CLOSED toggle not yet promoted to the shared top bar** — §3
  calls for it pinned above the bottom nav on every tab; it's still
  inside `OrdersFragment` only, same as before. That's Orders tab
  redesign work (§10 item 3), not this step.
- Insights/Account are placeholders only, as planned — no real content,
  no skeleton loading state (nothing to load yet).

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10 item 3: Orders tab redesign
(sections + countdown timer + status stepper) with its skeleton loading
state wired in the same pass, using the `ShimmerFrameLayout` +
`skeleton_order_card.xml` built in the prior session.

---

## 2026-08-15 — Shared skeleton/shimmer building block (this session)

Per doc 19 §10's build order, item 1 — the piece everything else in the
UI plan (bottom nav shell, Orders/Menu/Insights/Account tabs) depends on.

### ✅ Done
- **`ui/common/ShimmerFrameLayout.kt`** — reusable container that sweeps
  a light-gray → white → light-gray gradient across whatever skeleton
  shapes it wraps, using a `ValueAnimator` + `LinearGradient` composited
  with `PorterDuff.SRC_IN` (so the sheen only shows over the opaque
  skeleton shapes, not the gaps between them). Starts automatically on
  attach, stops on detach; `startShimmer()`/`stopShimmer()` exposed for
  screens that want manual control (e.g. pausing while off-screen in a
  RecyclerView). Written once, per §9.3, so Orders/Menu/Insights/Account
  skeletons all reuse this instead of duplicating animation code.
- **Two reusable skeleton row shapes** (§9.2), built as plain layouts
  (no shimmer baked in — wrap them in `ShimmerFrameLayout` at the call
  site):
  - `layout/skeleton_order_card.xml` — mirrors `item_order_card.xml`'s
    exact margins/padding/proportions: order # bar, status-chip blob,
    item-summary bar, stepper blob, total/payment bars.
  - `layout/skeleton_menu_item_row.xml` — mirrors `item_menu_food.xml`
    plus the not-yet-built §5 thumbnail slot: 44dp rounded-square
    placeholder, stacked name/price bars, switch-area blob.
- **New drawables:** `bg_skeleton_bar.xml` (4dp-radius bar),
  `bg_skeleton_blob.xml` (20dp pill, for chips/steppers),
  `bg_skeleton_thumb.xml` (8dp rounded square, for the photo thumbnail).
- **New color tokens** in `colors.xml`: `skeleton_base` (`#E8E8E8`),
  `skeleton_shimmer_highlight` (`#F5F5F5`) — match §9.4's visual tokens
  table exactly.

### 🟡 Known gaps / not done this session
- **No build/compile verification** — same standing limitation noted in
  every prior session (no Android SDK in this sandbox). First thing to
  do with a real toolchain: build the app, confirm the shimmer actually
  renders/animates on-device, tune `SHIMMER_DURATION_MS`/band width by
  eye if the sweep looks too fast/slow or too wide/narrow.
- **Not wired into any screen yet** — these are building blocks only.
  Next step (§10 item 2) is the bottom nav shell; the Orders tab
  skeleton (§10 item 3) is the first place these actually get used
  inside a real loading state.
- Only two row shapes built, per the plan's "a couple" — Insights'
  stat-chip-row and Account's form-skeleton shapes aren't built yet;
  those come with their own tabs per §9.5's "same PR as the real
  layout" rule.

### ⏭️ Next
Per `NEXT_SESSION_PROMPT.md` / doc 19 §10: bottom nav shell (item 2),
then Orders tab redesign with its skeleton wired up together (item 3).

---

## 2026-08-14 — Signup/Login entry flow (this session)

**Decision:** app owner chose to start with the Signup/Login entry point
(not Menu Management, which doc 18's own recommended order lists first) —
because Signup didn't exist at all yet, only a bare Login screen.

### ✅ Done
- **Splash screen** (`ui/splash/SplashActivity.kt`) — new launcher Activity.
  Animated logo (scale + overshoot) and fade-up title/tagline, same
  animation files the Customer app's splash already uses
  (`res/anim/splash_logo_in.xml`, `splash_text_in.xml` — copied as-is so
  both apps share one brand entrance). Routes to Dashboard (already
  logged in) or Login after ~0.9s.
- **Login screen redesign** (`ui/login/LoginActivity.kt` +
  `activity_login.xml`) — same fields as before (email/password), now
  with a cascading fade-up entrance for each field
  (`res/anim/form_field_in.xml`, staggered via `startOffset`) and a
  "New restaurant partner? Sign up" link.
- **Signup flow — full 3-step flow, new this session:**
  1. `ui/signup/SignupActivity.kt` — restaurant name, owner name, owner
     mobile, owner email, password, confirm password, address (optional).
     Client-side validation, then requests an email OTP.
  2. `ui/signup/OtpVerifyActivity.kt` — 6 individual auto-advancing digit
     boxes (Zomato/Swiggy-style OTP input), 30s resend countdown, shake
     animation on wrong code. On success, submits the account.
  3. `ui/signup/SignupSuccessActivity.kt` — "Application submitted, under
     review" screen with a pop-in checkmark, routes back to a clean Login.
- **Backend — 3 new endpoints** (`backend/api/v1/auth/`):
  - `restaurant-request-otp.php` — sends OTP (mirrors
    `customer-request-otp.php`'s cooldown/debug_otp pattern, reuses the
    same `email_otps` table).
  - `restaurant-verify-otp.php` — verifies only, does **not** create an
    account (unlike the customer flow) since the restaurant form needs
    more fields collected first.
  - `restaurant-signup.php` — creates the `restaurants` row
    (`status='pending'`) only after confirming a just-verified OTP exists
    for that email. No schema changes needed — every column
    (`name`, `owner_name`, `owner_mobile`, `owner_email`, `password_hash`,
    `address`, `status` default `'pending'`) already existed.
- **New animation resources** (`restaurant/app/.../res/anim/`):
  `form_field_in`, `slide_in_right/left`, `slide_out_left/right`,
  `shake_error`, `success_pop_in` (+ copies of the Customer app's
  `splash_logo_in`/`splash_text_in`).

### 🟡 Known gaps / not done this session
- **No real email delivery** — same limitation as the Customer app's OTP
  flow (`docs/19` §7 Email OTP multi-provider is planning-only). OTP is
  logged server-side only; visible in the app response solely when
  `debug_otp_enabled` app_setting is `'1'` on a dev/staging DB.
- **Logo upload during signup** — not included; restaurant logo/cover
  photo upload is separately scoped under Tier 1 "Restaurant Management"
  in doc 18 and wasn't pulled into this flow to keep the signup form
  short. Can be added post-approval, in the (not-yet-built) profile screen.
- **No build/compile verification** — same standing limitation as every
  other session per `Status.md` (no Android SDK in this environment).
  First thing next session on this: build the restaurant app, fix
  whatever the compiler catches, smoke-test signup → OTP → pending →
  (admin approves, not built yet) → login on an emulator.
- **Admin approval screen doesn't exist yet** — a restaurant can now
  self-signup into `status='pending'`, but nothing in the Admin Panel can
  approve it yet (that's doc 19 §3, planning-only). Until that's built,
  a pending signup has no way to reach `approved` except a manual
  `UPDATE restaurants SET status='approved'` on the DB.

### ⏭️ Next (per doc 18's own recommended order, resuming after this
detour)
1. Menu Management (Tier 1) — biggest remaining functional gap.
2. Order Management small additions (loud sound, prep-time select,
   cancel reason).
3. Restaurant Management profile screen (name/address/hours/logo, temp
   closure) — natural next stop after Signup, since a newly-approved
   restaurant needs this to actually set itself up.
4. Everything else per doc 18 §"Recommended build order" (coupons,
   notification bell, reviews reply, settings, payments, analytics,
   staff, then Rider App last).

Admin-side "Approve/Reject pending restaurants" screen should also move
up in priority now that self-signup exists and can actually produce
pending rows to approve — flag this to the app owner alongside item 1.

---

## 2026-08-14 — QA test restaurant account (later, same day)

Added `backend/sql/21_seed_test_restaurant_account.sql` — one
pre-approved (`status='approved'`) restaurant row so the new
signup/login flow can be tested end-to-end without the (not-yet-built)
admin approval screen blocking it.

- **Login:** `test@anydrop.com` / `test`
- Run this SQL file against the DB (phpMyAdmin on KS Web, or wherever
  `backend/sql/*.sql` files normally get run from — same as every other
  numbered migration in this folder) — it's idempotent, safe to re-run.
- This is a QA-only seed, not something to ship in production data —
  worth deleting (or at least rotating the password) before a real launch.
