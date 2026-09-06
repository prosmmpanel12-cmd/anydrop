# Handover — Admin "Rider detail" page built, deep-plan §25 (2 of 3)

Session date: 06 Sep 2026, continuing from doc 108's fork (owner picked
"Rider detail" explicitly). Same standing caveat as every prior
handover: nothing this session was compiled, run, or checked against a
live backend/PHP CLI/real device. Manual brace/paren/bracket balance
(Python) run on both touched/new files.

## What was verified before writing anything

- Grepped `orders` schema: `idx_orders_rider_status (rider_id, status)`
  already indexes a per-rider order list — no new index needed for the
  new Order History query.
- Grepped `01_schema.sql` for a location-history table before assuming
  one didn't exist — found `rider_locations` (rider_id, order_id, lat,
  lng, speed_kmh, recorded_at, indexed on `(rider_id, recorded_at DESC)`)
  already exists. Then read `api/v1/rider/location.php` in full to
  confirm it's actually being written to: its Phase 3 R4 addition
  inserts a row only when the location ping carries a valid `order_id`
  owned by this rider AND in an active-delivery status
  (`rider_assigned`/`picked_up`/`out_for_delivery`) — so this table is
  a "GPS breadcrumbs during deliveries" log, not a continuous 24/7
  trail. The new page's copy says this explicitly rather than implying
  a denser history exists than actually does.
- Grepped every `write_audit_log(` call in `riders.php`,
  `rider-settlements.php`, `rider-earnings.php` (the only three files
  that ever write an audit entry with a rider in scope) and confirmed
  the details array key is `rider_id` in every single call site except
  one (`rider_earning_rate_updated`, a global rate-setting change with
  no specific rider) — so a single `JSON_EXTRACT(details_json,
  '$.rider_id')` filter correctly covers every per-rider admin action
  with no per-action special-casing needed, and correctly excludes the
  one non-per-rider action without any extra WHERE clause for it.
- Checked for existing JSON-column-filter precedent before picking a
  comparison style — found migration 43 already uses
  `JSON_UNQUOTE(JSON_EXTRACT(...))` for a similar filter, so the new
  audit query matches that exact pairing rather than a bare
  `JSON_EXTRACT(...) = :id` (which can be quoting-sensitive on some
  MySQL/MariaDB versions).
- Confirmed `admins` has no `name` column (just `username`, `role_id`)
  before writing the audit table's "By" column — uses `username`, same
  as every other admin-attribution column in this codebase.

## What was built this session

### `backend/admin/rider-detail.php` (new)

Read-only consolidated rider view — deliberately read-only, since
every possible write action here already has an existing owner
(riders.php's Manage dialog for status/documents/area,
rider-settlements.php for COD, rider-earnings.php for payouts). This
page adds only the three things deep-plan §25's "Rider detail"
sub-section wanted that had genuinely nowhere to live yet:

- **Profile summary card** — name/contact/status/online/documents
  badges, area breadcrumb, vehicle, applied date, last-seen
  (`admin_time_ago`, reused from doc 108), last known lat/lng. Action
  row links to `riders.php?q=<mobile>` (for status/doc/area actions)
  and, gated on `payouts_view`, to `rider-settlements.php?rider_id=N`
  / `rider-earnings.php?rider_id=N` (each button's label includes the
  current held/owed amount so an admin doesn't have to click through
  just to see if there's anything to act on).
- **Order History** — plain `orders WHERE rider_id = :id`, paginated
  15/page, restaurant name via LEFT JOIN, same status-badge/label
  convention `orders.php` already uses (`$statusLabels` array
  copy-pasted from there — not factored into a shared helper yet,
  same as this codebase's existing history of small copy-pasted
  status-label maps before a shared one exists).
- **Location History** — most recent 100 `rider_locations` rows for
  this rider, plain table (time, order code, lat, lng, speed) — no map
  rendering, deliberately (deep-plan §25's "Live map" sub-section is
  its own separate scope, still entirely unbuilt, per doc 108).
- **Audit Trail** — every admin action logged against this rider
  (approve/reject/suspend, documents verify/reject, area assign, COD
  settlement recorded, earnings payout/adjustment recorded), newest
  first, with actor username and a generically-formatted detail string
  (every `details_json` key except `rider_id` itself, prettified).

### `backend/admin/riders.php` (edited)

- Rider name in the list table is now a link to
  `rider-detail.php?rider_id=N`.
- Manage dialog gained a "View full detail" link to the same page,
  above the existing status-lifecycle forms, so it's reachable
  regardless of which permissions a given admin has (unlike the two
  payout links further down, which stay gated on `payouts_view`).

## Deliberately not built this session

- **Live map** (§25's third sub-section) — still zero admin-side map
  infrastructure in this codebase; unchanged from doc 108's scoping.
- **Auto-opening the Manage dialog** from the detail page's "Manage
  status..." link — that link lands on a filtered `riders.php` list
  (narrowed to one row via `?q=`) rather than a dialog already open;
  there's no existing query-param-driven dialog-auto-open convention
  anywhere in `admin.js` to hook into, and adding one for this single
  link felt like scope beyond what this session was for. One extra
  click (press "Manage") versus zero — a real but minor gap, not a
  broken flow.
- **Any change to how rider_locations/audit_logs/orders are written**
  — display-only, same scoping as every prior session touching this
  area. Nothing in `location.php`, the order-status-transition code,
  or `write_audit_log()` callers was touched.
- **A shared `$statusLabels` helper** — copy-pasted from `orders.php`
  rather than factored out, consistent with this codebase's own
  established pattern of not factoring out a second/third copy until
  it recurs a third time (see Status.md's own 2026-08-20 entry making
  the same call for a timestamp-formatting duplicate).

## Still open

- **Nothing this session was run against a live DB/PHP CLI.**
  Brace/paren/bracket balance (Python) on both touched files: both
  balanced. Cross-checked every column referenced in the three new
  queries (`orders`, `rider_locations`, `audit_logs` + their joined
  tables) against `01_schema.sql`/the relevant migration — each
  exists exactly where expected.
- **Recommended first live check**: open `rider-detail.php?rider_id=N`
  for a rider with at least one completed delivery (confirms Order
  History + Location History both render with real rows, not just the
  empty-state copy), and for a rider an admin has recently
  approved/rejected/suspended (confirms the Audit Trail's
  `JSON_UNQUOTE(JSON_EXTRACT(...))` filter actually matches — this is
  the one query in this session with no existing runtime precedent to
  lean on, so it's the highest-risk untested piece here). Also confirm
  the two new entry points from `riders.php` (name link + Manage
  dialog's "View full detail" link) actually navigate correctly.
- Everything doc 108 already carried over unchanged: `php -l` still
  never run anywhere in this project, migrations 75/76 still never
  run, PENDING.md/recall.md still stale.

## Next step, owner's choice

Deep-plan §25 now has 2 of 3 sub-sections built (Rider list, Rider
detail) — only Live map remains, and it's a genuinely fresh build (no
existing admin-map infra to extend). Otherwise: a real build/DB pass
covering this and the prior several stacked untested sessions,
Delivery OTP §16's re-audit, or Live Location System customer-side
re-verification (still flagged from doc 108 as worth a second look
now that it turned out further along than doc 107 assumed).
