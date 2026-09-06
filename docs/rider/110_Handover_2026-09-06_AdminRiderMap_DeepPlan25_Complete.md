# Handover — Admin "Live map" built, deep-plan §25 COMPLETE (3 of 3)

Session date: 06 Sep 2026, continuing from doc 109's fork (owner said
"continue" — picked the one remaining §25 sub-section rather than
branching to a different deep-plan section, since it closes out §25
entirely). Same standing caveat as every prior handover: nothing this
session was compiled, run, or checked against a live backend/PHP CLI/
real device/browser. Manual brace/paren/bracket balance (Python,
full-file open/close counter, not just a same-line heuristic) run on
every touched/new file.

## What was verified before writing anything

- Confirmed (by reading `directions-settings.php` in full, not
  assuming) that `google_directions_api_key` is a server-side
  Directions API credential used only from `route.php`'s
  server-to-server call, with no confirmed HTTP-referrer restriction
  suited to being embedded in admin page source — decided against
  reusing it as a client-side Google Maps JavaScript API key for that
  reason, rather than risking exposing a key never scoped for browser
  use.
- Grepped the whole codebase for existing map rendering before
  deciding on a stack — found `areas.php` already uses Leaflet + OSM
  raster tiles (no API key) for its own single-point picker. Read that
  file's map JS in full and matched its conventions exactly: same
  Leaflet version/CDN URLs (`cdnjs.cloudflare.com/.../1.9.4/`), same
  regional default-center fallback coordinates (Osian, Jodhpur —
  26.7213, 72.9166), same "OSM tiles, no API key, low-traffic internal
  tool" reasoning restated in this file's own header rather than
  silently duplicating the choice unexplained.
- Deliberately did NOT use Leaflet's default `L.marker` — its default
  icon depends on `marker-icon.png`/`marker-icon-2x.png`/
  `marker-shadow.png` being resolved relative to Leaflet's own script
  path, which is not reliably auto-detected off a `leaflet.min.js` (vs
  `leaflet.js`) filename loaded from a CDN. Used `L.circleMarker`
  instead — needs no external image asset — specifically because this
  page's entire purpose is showing markers, so a silently-broken icon
  here would be a much worse failure mode than on `areas.php`'s
  single-point picker.
- Grepped `riders.php` for its current-order correlated-subquery
  pattern (doc 108) and reused it verbatim rather than a plain join,
  same "never silently duplicate a rider row" reasoning as before.

## What was built this session

### `backend/admin/rider-map.php` (new)

Live view of every online platform rider, plus any rider carrying an
active-delivery order even if their `is_online` flag is somehow
stale/off (surfaced as a real inconsistency, not filtered out) —
closing deep-plan §25's third and final sub-section verbatim against
its own wording ("online riders, active deliveries, last location,
stale-location warning"):

- **Colour-coded circle markers**: green (online & idle), orange
  (online with an active delivery), red (flagged online but
  `last_location_at` older than a 10-minute `STALE_MINUTES` constant —
  same coarse-bucket spirit as `admin_time_ago()`'s own hour bucket).
- **Popup per marker**: name, state, last-seen (`admin_time_ago`,
  reused from doc 108), current order code/status if any, and a link
  into `rider-detail.php?rider_id=N` (doc 109) — every rider surface
  now chains together rather than dead-ending.
- **"Online but no location yet" table** below the map — a rider
  matching the online/active-order filter but with no
  `last_lat`/`last_lng` yet can't be plotted; listed here instead of
  silently vanishing from the page.
- **Area filter** — same dropdown/`admin_area_breadcrumb_compact`
  pattern every other rider/restaurant admin page already uses.
- **Auto-refresh** — a plain `setTimeout(() => location.reload())` on
  a selectable interval (Off/30s/60s, defaulting to 60s to match the
  dropdown's own selected state) rather than a new polling JSON
  endpoint. See the file's own header for why: this page already
  re-runs its query on every load, so a timed reload costs the same as
  an admin manually refreshing, without standing up and maintaining a
  second data path.
- Legend card explaining the three marker colours.

Gated on `riders_view` — same base gate `riders.php`/
`rider-detail.php` use, since this is the same rider-location data
just plotted instead of tabulated, not a new data category.

### `backend/admin/_layout_head.php` (edited)

- Added a `rider_map` nav item (key/href/perm/icon), placed directly
  after `riders` in the same `operations` group — same
  `riders_view` permission gate, so it appears/disappears for exactly
  the same admins who can already see the Riders list.
- Added `'rider_map'` to the `$activeNav` docblock list (patched
  directly, same low-collision-risk call doc 89 made for its own nav
  addition, rather than a separate patch-instructions file).

### `backend/admin/riders.php` — no changes this session

(Already links to `rider-detail.php` from doc 109; that page now also
links onward to `rider-map.php` via the nav, so no direct
riders.php-to-map link was needed.)

## Deliberately not built this session

- **Route polylines** from each visible rider to their active
  delivery's destination — would mean fanning out a `route.php`
  Directions-API call per visible rider on every load/refresh of a
  page that can auto-refresh every 30s; a real, recurring cost against
  Google's API that shouldn't be added without the app owner
  explicitly wanting it and accepting that billing implication.
- **Marker clustering** for a large rider count — this platform's
  current rider volume doesn't warrant it, and premature clustering
  logic is its own bug surface to introduce speculatively.
- **Historical playback / a time scrubber** — that's what
  `rider-detail.php`'s Location History table (doc 109) already is;
  this page is live/current-state only, deliberately not overlapping
  scope with that one.
- **A real polling JSON endpoint** for finer-grained live updates
  without a full reload — the plain-reload auto-refresh above was
  judged sufficient for what the file's own header calls "a
  low-traffic internal tool"; a genuinely push-based live map would be
  a separate, larger build if the app owner wants one later.

## Still open

- **Nothing this session was run against a live DB/PHP CLI, and
  nothing here has ever been opened in an actual browser** — this
  page is flagged as the single highest-risk-of-a-silent-rendering-bug
  piece of work in this project's history so far, more than any prior
  session's PHP-only changes, since Leaflet + a CDN + a `<dialog>`-free
  full-page canvas is a meaningfully different rendering path than
  anything previously build-verified even once (unlike `areas.php`'s
  map, which has at least had its shape reviewed across several
  sessions before this one, even if never run).
- Full brace/paren/bracket balance (Python, whole-file open/close
  counter — caught and fixed one real prose-comment paren imbalance in
  this file's own header during this session, which a same-line-only
  heuristic would have missed) on `rider-map.php` and
  `_layout_head.php`: both balanced after the fix.
- Cross-checked every column referenced in the new query (`riders`,
  `orders`, `service_areas`) against `01_schema.sql`/the relevant
  migrations — each exists exactly where expected. `STALE_MINUTES`
  used as a top-level `const` (valid PHP outside a class since 5.3,
  confirmed rather than assumed) referenced both in PHP output and
  interpolated into the legend text.
- **Recommended first live check**: open `rider-map.php` as an admin
  with at least one currently-online rider who has sent a recent
  location ping (confirms a marker actually renders — the biggest
  unknown), one rider on an active delivery (confirms the orange
  state + popup order link), and, if reproducible, one rider whose
  `is_online` is 1 but `last_location_at` is old (confirms the red
  "stale" state and its 10-minute threshold). Then click a popup's
  "View rider detail" link and confirm it lands on `rider-detail.php`
  correctly, and toggle the auto-refresh dropdown to confirm the page
  actually reloads on schedule.
- Everything doc 109 already carried over unchanged: `php -l` still
  never run anywhere in this project, migrations 75/76 still never
  run, PENDING.md/recall.md still stale.

## Deep-plan §25 status: COMPLETE

All three sub-sections (Rider list — doc 108, Rider detail — doc 109,
Live map — this doc) are now built, chained together via links (list
→ detail → map → detail, and back), all reading existing tables with
zero new migrations across all three sessions.

## Next step, owner's choice

With §25 closed, the still-open items from doc 108's original fork are
the same as ever: a real build/DB/browser pass (now covering four
stacked untested rider-admin sessions plus this one — arguably the
strongest case yet for spending a session on verification rather than
more new surface area), Delivery OTP §16's re-audit, or Live Location
System customer-side re-verification (still flagged as worth a second
look). Any other still-unbuilt deep-plan section is also fair game if
none of those fit what's wanted next.
