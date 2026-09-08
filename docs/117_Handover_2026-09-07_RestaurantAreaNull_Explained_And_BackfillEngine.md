# Handover — 2026-09-07 — Restaurant area_id=NULL Explained + Auto-Resolve Backfill Engine

**App owner asked:** what happens across the system if a restaurant has
no area assigned, and build an engine that auto-assigns restaurants to
areas.

## Part 1 — every place restaurants.area_id=NULL matters (as of today's combined-rules change)

| Where | Effect when area_id is NULL |
|---|---|
| `restaurants/list.php` (customer discovery) | Area-match filter is **skipped entirely** for that restaurant — only its own `delivery_radius_km` (default 5km) geometric check decides visibility. |
| `get_effective_cod_rule()` (COD rules, today's combine change) | Restaurant side contributes **platform defaults only** — final rule is just whatever the customer's own delivery-address area requires, nothing extra. |
| `get_effective_payment_restrictions()` (UPI/COD area gate, today's combine change) | Same — restaurant side contributes platform defaults, doesn't add any extra restriction. |
| `calculate_delivery_fee()` (today's combine change) | Restaurant side computes its fee using the **platform default** rate/km + base fee — only wins the max() if that happens to exceed the customer-area fee (rare, since it's just the plain default). |
| `get_min_order_floor_for_area_id()` (restaurant/profile-update.php) | Floor = **platform-wide default** `min_order_amount` — the least restrictive floor possible, so an unassigned restaurant faces the loosest cap on what it can set. |
| `get_effective_commission_rate()` (lib/commission.php, per order line) | Tier 1 (category+area) and Tier 3 (area-only) commission rules are **skipped entirely** — falls to Tier 2 (category-only, area IS NULL rows), then Tier 4 (restaurant's own flat override), then Tier 5 (platform default %). Any area-specific commission an admin has set is simply never reached for this restaurant. |

**Net effect of area_id=NULL: the restaurant is treated the least
restrictively/most generically everywhere — it never benefits from OR
gets penalized by any area-specific rule, only ever platform-wide
defaults (on the restaurant side; the customer's own delivery-address
side still applies normally in the combined checks).**

## Part 2 — the auto-resolve engine

**Two new files, mirroring the existing `backfill-address-areas.php` /
`run-address-backfill.php` pair exactly** (same safety model, same
NULL-only-by-default + `--force` escape hatch):

- `backend/scripts/backfill-restaurant-areas.php` — CLI version.
- `backend/admin/run-restaurant-area-backfill.php` — browser-runnable
  version for hosts (InfinityFree etc.) with no SSH/PHP-CLI access,
  gated behind admin login, same as the address one.

Both: resolve every restaurant with a lat/lng and `area_id IS NULL`
(or every restaurant with a lat/lng, with `?force=1`/`--force`) via the
existing `resolve_service_area()`, and write the nearest match. Safe to
re-run any time — running it again after an admin adds new Areas (e.g.
today's Jodhpur sub-locality planning) picks up restaurants that
couldn't resolve to anything before, without touching ones that
already have a value.

### Why this is a deliberate admin-triggered tool, not automatic on every profile save

`restaurant/profile-update.php` lets a restaurant change its own
lat/lng (map-picker) but **deliberately does not** re-resolve
`area_id` when that happens — this was already true before today and
is intentionally left alone. Two reasons, now stronger than when that
code was first written:

1. `restaurants.area_id` is documented as **admin-assigned**
   (recall.md item 2) — `restaurant-signup.php`'s own auto-resolve at
   signup time explicitly says it only "saves the admin a manual
   lookup step," not that it replaces admin review.
2. As of today's session, `area_id` directly feeds **COD eligibility,
   payment restrictions, delivery fee, and commission rate** — real
   money and eligibility outcomes. If a restaurant's own pin edit
   silently re-triggered area resolution, a restaurant could nudge its
   marker toward whichever area currently has looser COD rules or
   lower commission and have that take effect immediately with zero
   admin review — exactly the bypass the admin-assigned design exists
   to prevent.

So the auto-resolve **engine** exists at exactly two moments, both
admin-visible: (a) automatically once, at signup (already existed,
2026-08-28), and (b) on demand via this new bulk backfill tool,
run by an admin who can see and choose to accept the results. A
restaurant editing its own location afterward never silently moves its
own area.

## Not done / caveats

- **No live DB/PHP run** — same standing sandbox limitation as every
  other backfill script in this project. Verified via direct code
  reading + brace/paren balance only; needs to actually be run once on
  the live DB (delete `run-restaurant-area-backfill.php` afterward, per
  its own header, same convention as the address one).
- Did **not** change `profile-update.php` — considered doing so
  (auto-reassign on lat/lng change) and deliberately decided against
  it for the reasons above. Worth a conversation with the app owner if
  they specifically want restaurant-initiated moves to also trigger
  review (e.g. flag for admin re-approval rather than silent
  reassignment) — that would be a different, larger feature than what
  was asked for here.
