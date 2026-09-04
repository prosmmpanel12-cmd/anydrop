# Handover — 2026-08-29 (doc 69): Item Availability Timing — Display-Consistency Follow-up, Closed

## What was asked

Continuing from doc 68. PENDING.md's item #1 flagged four customer-facing
browse surfaces that still showed the raw `is_available` column only,
not the effective time-window check added in doc 68
(`is_menu_item_available_now()`, migration 62). Checkout was never at
risk (`price_cart()` is server-authoritative and already blocked these
correctly) — this was a display-only gap: an item outside its
`available_from`/`available_until` window could still appear as a
normal, orderable card on Search/Home, only to get rejected at
cart/checkout.

## Fixed this session

All four flagged files now exclude a currently-out-of-window item,
consistent with how each already excluded a manually `is_available = 0`
item — none of these four surfaces has a "greyed out" item state (that
treatment is `restaurants/menu.php`-only, per doc 68), so exclusion is
the correct fix here, not a badge/flag.

- **`backend/api/v1/search/search.php`** — the items block's per-item
  loop now skips any item where `is_menu_item_available_now()` is
  false, before it's added to the results array.
- **`backend/api/v1/home/popular-items.php`** — same skip, in its
  item-building loop.
- **`backend/api/v1/home/category-items.php`** — same skip, added
  alongside the existing `open_now`/`rating_4` post-query filters in
  its loop.
- **`backend/api/v1/home/offers-browse.php`** — needed one extra step
  first: its `menu_items` query only selected
  `id, name, image_url, price, is_veg, restaurant_id`, missing
  `is_available`/`available_from`/`available_until` entirely (the
  raw `is_available = 1` filter was in the SQL `WHERE`, but the columns
  themselves weren't fetched). Added those three columns to the
  SELECT, then applied the same skip in its per-item loop.

All four now `require_once '.../lib/menu_item_availability.php'`.

Not touched: `search.php`'s separate `matched_dish` subquery (the
`(SELECT mi2.name ... LIMIT 1)` used only to label *why* a restaurant
matched a dish search) still checks raw `is_available` only. This is
cosmetic (a match-reason string, not an orderable item card) and
wasn't among the four surfaces doc 68 flagged — left as-is to keep this
session scoped to the explicitly known gap.

## Verification done this session

Same standing constraint as doc 68: no PHP CLI in this sandbox.

- Comment-and-string-aware brace/paren balance check (Python, tracks
  `//`, `/* */`, and both quote types so nothing inside a string or
  comment is miscounted) on all four edited files — all balanced.
- Confirmed each file's existing SQL already SELECTs `mi.*` (search,
  popular-items, category-items) so `available_from`/`available_until`
  were already present on the row before this session — only
  `offers-browse.php` needed its SELECT list widened.
- Confirmed the skip is placed after any existing per-row filters
  that `continue` (e.g. category-items.php's `open_now`/`rating_4`)
  rather than before, so it composes with them the same way an
  `is_available = 0` exclusion already would.

## Genuinely still open

- [ ] `php -l` + a real end-to-end test (create a time-windowed item,
      confirm it disappears from Search/Popular/Category/Offers-browse
      results outside its window and reappears inside it) — standing
      container gap, same as every item in doc 68's own list.
  - [ ] Real Android Gradle build — unrelated to this session (no
      Android files touched), still outstanding from doc 68.
- [ ] Scheduled ("order for later") interaction with `price_cart()` —
      unchanged from doc 68, not touched this session.
- [ ] `search.php`'s `matched_dish` subquery cosmetic gap (see above) —
      not part of the four flagged surfaces, noted for completeness only.

## Files touched this session

**Backend:** `backend/api/v1/search/search.php` (edited),
`backend/api/v1/home/popular-items.php` (edited),
`backend/api/v1/home/category-items.php` (edited),
`backend/api/v1/home/offers-browse.php` (edited).

**Docs:** this file.

## Suggested next session

Per PENDING.md's own suggested order: item #1 is now closed. Next up
is #2 (Peak hours analytics) — needs a design decision (heatmap vs.
single "busiest hour" stat) before any code, then #3 or #4 (Staff/RBAC,
Rider App — both large, pick based on priority), then #5 (real
build/device verification) whenever a real toolchain is available.
