# Handover — Admin "Rider list" columns added, deep-plan §25 (partial)

Session date: 06 Sep 2026, continuing from doc 107's "owner's choice"
fork. Same standing caveat as every prior handover: nothing this
session was compiled, run, or checked against a live backend/PHP CLI/
real device (no PHP CLI available in this sandbox). Manual brace/
paren-balance checks (Python) were run on every touched file.

## Why this section, and why only this slice

Doc 107 left three options open. Before picking, each was actually
checked against the code rather than trusted at face value:

- **Live Location System (§12-15)** — checked first, turned out to be
  substantially already built: `RiderDashboardActivity` already runs a
  two-tier location-poll interval, `backend/api/v1/rider/location.php`
  already exists, and the customer app's `OrderStatusActivity` already
  does full rider-marker animation + polling + route-deviation
  recalculation (docs 91-93). Doc 107's "still unbuilt" framing of this
  item was stale carry-forward text, not a real gap. Not picked.
- **Delivery OTP (§16) recheck** — a re-read task, not a build task;
  left for a session that wants to spend its time auditing rather than
  shipping.
- **Admin Rider Command Center (§25)** — checked and confirmed genuinely
  gapped: `backend/admin/riders.php` (the only rider-list admin screen)
  showed name/contact/status/documents/area/vehicle/applied-date only —
  none of online/offline, last-seen, current order, COD cash held, or
  earnings, despite every one of those columns already existing on the
  `riders` table (`is_online`, `last_lat`/`last_lng`, `last_location_at`
  — schema migration 1; `cod_cash_held` — migration 53;
  `earnings_balance` — migration 73) and already being read/written
  correctly by `location.php`, `rider_ledger.php`, and
  `earnings-summary.php`. This session only had to surface existing,
  already-correct data — no new column, no new migration, no new write
  path. **Picked.**

§25 has three sub-sections: **Rider list**, **Rider detail**, **Live
map**. Only **Rider list** was built this session — see "Deliberately
not built" below for why detail/map were left for later.

## What was verified before writing anything

- Grepped `riders` table schema (`01_schema.sql`) and the three later
  migrations to confirm `is_online`/`last_lat`/`last_lng`/
  `last_location_at`/`cod_cash_held`/`earnings_balance` all already
  exist — nothing new needed on the DB side.
- Grepped the whole codebase for an existing "time ago" / relative-
  timestamp helper before writing one — found none, so `admin_time_ago()`
  is genuinely new, not a duplicate of something already there.
- Read `backend/api/v1/rider/orders-current.php` and
  `backend/api/v1/rider/location.php` to confirm the
  `('rider_assigned','picked_up','out_for_delivery')` active-order
  status set and "ORDER BY id DESC LIMIT 1 = the current one"
  convention this session's new current-order subquery reuses exactly.
- Read `backend/admin/rider-settlements.php` and
  `backend/admin/rider-earnings.php` in full to reuse their exact
  existing conventions rather than inventing new ones:
  `rider_cod_settlement_limit()` helper, the "at/over limit → badge
  inactive" / "under limit → plain text" split, the "owed to rider →
  badge system, else plain" split, the `payouts_view` permission gate,
  and the `?rider_id=N` detail-mode query param both pages already
  accept for any rider ID (confirmed neither requires the rider to
  already have a positive balance/held amount to open the detail
  view).
- Confirmed `.badge.active/.inactive/.system` CSS classes already exist
  in `assets/admin.css` — no new CSS needed for any state used here.

## What was built this session

### `backend/admin/_bootstrap.php` (edited)
New `admin_time_ago(?string $timestamp): string` — coarse minute/hour/
day-bucket relative time, "Never" for null/empty/unparsable. Placed
here (not local to `riders.php`) since it's a generic admin-list need
with no prior helper, and deep-plan §25's own later "Live map" stale-
location flag will likely want the same function.

### `backend/admin/riders.php` (edited)
- Added `require_once .../lib/rider_ledger.php` for
  `rider_cod_settlement_limit()` (same key/default `dispatch.php`
  already enforces against).
- Added `$canViewPayouts` gated on the existing `payouts_view`
  permission key (already seeded, already used by both linked pages
  below) — deliberately not a new permission key for the same data.
- List query: aliased `riders` as `r` and added a `LEFT JOIN orders co`
  via a correlated subquery (`WHERE o.rider_id = r.id AND o.status IN
  (...) ORDER BY o.id DESC LIMIT 1`) rather than a plain join on
  `rider_id` + status, so a rider row can never silently duplicate if
  the "no conflicting active order" assignment rule is ever relaxed for
  batching later (deep-plan §4.1 flags that as a future possibility).
  The `$where` array's five conditions were prefixed `r.` (previously
  unprefixed) — required once `orders` is joined, since `orders` also
  has its own `restaurant_id`/`status` columns and an unqualified WHERE
  would otherwise throw an ambiguous-column error. Same `$whereSql`
  string is still shared between the count query and the list query,
  now with `riders` aliased `r` in both for consistency.
- New table columns: **Online** (badge + "Seen Xm/h/d ago" via
  `admin_time_ago($r['last_location_at'])`), **Current order** (order
  code + a status badge, or "—"), and — gated behind `$canViewPayouts`,
  same as the Documents column is gated behind `$canViewDocuments` —
  **COD held** (plain amount, or a red badge if at/over
  `rider_cod_settlement_limit()`, exactly mirroring
  `rider-settlements.php`'s own over/under styling) and **Earnings**
  (plain "₹0.00" if nothing owed, else a blue badge, exactly mirroring
  `rider-earnings.php`'s own "Nothing owed" / "owed to rider" styling).
- Manage dialog: gated behind `$canViewPayouts`, two new outbound links
  — "COD Settlement" → `rider-settlements.php?rider_id=N`, "Earnings
  Ledger" → `rider-earnings.php?rider_id=N` — reusing those two
  screens' existing detail views rather than building a third,
  duplicate "Rider detail" page this session (see below).

## Deliberately not built this session

- **Rider detail page** (§25's second sub-section: profile, documents,
  orders, location history, earnings, COD ledger, settlement, audit
  log, all on one screen). Most of that data already has its own
  existing admin screen (`riders.php` itself for profile/documents,
  `rider-earnings.php` for earnings, `rider-settlements.php` for COD) —
  this session linked out to the two money screens rather than
  building a fourth page that would just re-display what those already
  show. A true single-page rider detail (adding orders history +
  location history + a unified audit log, none of which exist as
  admin-viewable anywhere yet) is a real, separate, larger build — not
  assumed/started here.
- **Live map** (§25's third sub-section: online riders, active
  deliveries, last location, stale-location warning, on an actual map).
  Zero existing admin-side map infrastructure to build on (the
  project's only maps are in the Android apps) — a genuinely fresh
  build, not a small slice, and out of scope for this session.
- **Any change to how `is_online`/`cod_cash_held`/`earnings_balance`/
  location fields are written** — display-only, same as doc 107's own
  scoping. `location.php`, `rider_ledger.php`, and the accept/deliver
  flows that write these columns are all untouched.
- **A `.badge.warn` (amber) third state** for COD held — the rider
  app's own `EarningsActivity` card (doc 107) has a client-only
  "NEAR LIMIT" amber threshold at 80%, but no admin screen anywhere in
  this codebase has an amber badge state; this session matched
  `rider-settlements.php`'s existing two-state (over/under) convention
  exactly rather than introducing a new admin visual convention as a
  side effect of an unrelated list page.

## Still open

- **Nothing this session was run against a live DB/PHP CLI.** Brace/
  paren/bracket balance (Python) on `riders.php` and `_bootstrap.php`:
  both balanced. Cross-checked every new column/variable reference
  (`is_online`, `last_location_at`, `cod_cash_held`, `earnings_balance`,
  `current_order_code`, `current_order_status`, `$canViewPayouts`,
  `$settlementLimit`, `$currentOrderStatusLabels`, `admin_time_ago`)
  against the query that produces it and the file it's defined in —
  each resolves exactly once.
- **Recommended first live check**: load `riders.php` as an admin with
  `payouts_view` granted and one without it (confirm the two payout
  columns and the two dialog links both correctly disappear); load it
  for at least one rider currently online with an active order, one
  offline with none, and one at/over the COD settlement limit, and
  confirm each cell reads correctly. Then click through to both linked
  pages for a rider that has a COD/earnings history and confirm the
  `?rider_id=` links land correctly (both pages already support
  arbitrary rider IDs, confirmed by reading their detail-mode guard
  clauses, but never actually clicked through in this sandbox).
- Everything doc 107 already carried over unchanged and untouched this
  session: `php -l` still never run anywhere in this project,
  migrations 75/76 still never run, PENDING.md/recall.md still stale
  (confirmed again this session — its Rider App section still reads
  "NOT STARTED", which is many sessions out of date), the Rider Order
  Detail (doc 106) and COD Cash-Held card (doc 107) both still
  un-build-verified.

## Next step, owner's choice

Same fork as doc 106/107, now with a fourth session's worth of
untested backend+admin work stacked on top: a real build/DB pass
(would now also cover this session's admin query/columns), or continue
deep-plan §25 with the Rider detail page or the Live map, or a
different still-unbuilt section (Delivery OTP §16 double-check against
its full text, Live Location System customer-side re-verification now
that this session found it further along than doc 107 assumed).
