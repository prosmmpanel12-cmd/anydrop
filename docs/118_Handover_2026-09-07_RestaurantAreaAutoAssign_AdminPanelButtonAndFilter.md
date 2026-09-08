# Handover — 2026-09-07 — Restaurant Area Auto-Assign Moved Into Admin Panel (Button + Filter, No Script)

**App owner correction of the previous session's approach:** "script mat
do, admin panel mai button de assign area ka, aur saath mai filter bhi
jese ki Osian mein aane wale restaurant ka area auto assign ho jaye."

The standalone `backend/scripts/backfill-restaurant-areas.php` (CLI)
and `backend/admin/run-restaurant-area-backfill.php` (one-time browser
wrapper) from the previous session are **deleted**. Everything now lives
inside `backend/admin/restaurants.php` itself — same page admins
already use to manage restaurants.

## What's new in `restaurants.php`

**1. "Unassigned" option in the existing Area filter dropdown.**
Selecting it shows every restaurant with `area_id IS NULL` (respecting
the Search/Status filters as always).

**2. In that view, the Area column becomes "Detected area"** — computed
live per restaurant via `resolve_service_area()` (the exact same
function `restaurant-signup.php`'s auto-assign-at-signup and the
customer-address backfill already use) from that restaurant's own
lat/lng. Three states per row:
- A detected area name + a small **"Assign"** button that sets it
  (one restaurant, one click — reuses the existing `assign_area` POST
  action, just pre-filled).
- "No coordinates set" — restaurant has no lat/lng to resolve from.
- "No service area covers this point" — has coordinates, but nothing
  in `service_areas` covers them yet.

**3. A second "Detected area" dropdown appears** (only in the
Unassigned view), populated with exactly the areas actually detected
among the currently-unassigned restaurants, each with a count — e.g.
"Osian (7)", "Jodhpur (23)". This is the literal "Osian mein aane wale
restaurant" case from the request: pick Unassigned + Osian, see just
those 7.

**4. "Auto-assign area — all N matching this filter" button.** Appears
above the table whenever the Unassigned filter is active. One click
assigns the detected area to every matching restaurant — not just the
current page of 20, the entire filtered set (re-derived server-side
from the same `$_GET` filters, never trusted from the client) — with a
confirm dialog stating the count and behaviour first. Each assignment
writes its own `restaurant_area_auto_assigned` audit log entry, same
as every other change on this page.

## How it's implemented

- New `admin_unassigned_restaurants_with_detected_area($db, $q,
  $statusFilter)` function (top of the file): fetches every
  non-deleted, unassigned restaurant matching search/status, and
  attaches a computed `detected_area_id` to each row. Pure read —
  never writes anything itself.
- The Unassigned branch of the filter section fetches ALL matching
  rows via that function (not SQL-paginated, since "detected area"
  isn't a real column to filter/paginate on in SQL), optionally narrows
  by the chosen detected-area, then paginates in PHP with
  `array_slice()`. Restaurant volumes here are small enough that this
  is simpler than adding a cached/denormalized column, and it self-
  corrects any time — no explicit re-sync step needed, unlike a cached
  column would need.
- The normal (non-Unassigned) branch is **completely unchanged** —
  still a plain SQL `WHERE`/`LIMIT`/`OFFSET` query, no behaviour
  regression for the common case.
- New `bulk_auto_assign` POST action, checked *before* the existing
  single-restaurant lookup (which would otherwise reject it for having
  no `restaurant_id`) — it re-derives the admin's current filter from
  `$_GET` (a plain `<form method="post">` with no `action=` attribute
  posts back to the same URL, so the query string survives), calls the
  same detection function, and updates every match.
- `$areaNodeById` / `$areaOptions` (needed for breadcrumb labels) got
  moved from just-before-the-display-section to the top of the file, so
  both the new lookup function and the bulk POST handler can use them
  too — no behaviour change, just relocated.

## Why this still doesn't run automatically anywhere

Same reasoning as the previous session's (now-deleted) standalone tool,
worth restating since it's the crux of the whole design: `area_id`
directly drives COD eligibility, payment restrictions, delivery fee,
and commission rate (today's earlier "combine both sides, strictest
wins" change). This auto-detect-and-assign only ever runs when an
admin is looking at this exact page and explicitly clicks a button —
never as a side effect of a restaurant editing its own location, and
never as a background job. That principle didn't change, only *where*
the admin-triggered action lives — inside the normal admin panel now,
not a separate one-off script.

## Not done / caveats

- **No live browser/DB test** — same standing sandbox limitation as
  every session in this project (no PHP interpreter or DB connection
  here). Verified via direct code reading: brace/paren balance on the
  whole file, `resolve_service_area()`'s signature and
  `restaurants.latitude`/`longitude` column names both confirmed
  against source, `admin_area_breadcrumb_compact()`'s signature checked
  for safety against the fallback array used in the detected-area
  dropdown.
- The per-page "Assign" button and the bulk button both write real
  `area_id` changes immediately (no separate preview/apply step) —
  same one-step-immediate pattern the rest of this page's actions
  (approve/reject/suspend/assign/commission) already use, kept
  consistent rather than introducing a two-step confirm flow unique to
  this one feature.
