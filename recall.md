# Anydrop — REAL PENDING FEATURES / NEXT BUILD RECALL

> Purpose: This is the **current recall file for Claude Code**.
> It separates genuinely pending product/architecture work from old historical
> "test pending" notes in `docs/Status.md` and `docs/restorent/00_Status.md`.
>
> **Important:** Do NOT reopen already completed Customer/Restaurant core work
> merely because an old status entry says "pending". If a feature is already
> implemented and the owner has device-tested it, treat the old status as stale.

---

## 0. Current Reality

Anydrop's Customer App + Restaurant App + core backend are already substantially
built and tested by the owner.

The remaining work is primarily:

1. Admin Panel + platform control system
2. Area-wise service/availability rules
3. Restaurant Offers Engine
4. Financial / settlement / ledger completion
5. Customer support + refund system
6. Restaurant analytics / remaining restaurant operational features
7. Staff / RBAC
8. Rider App + live delivery tracking
9. Production security / payment hardening
10. Selected customer UX edge cases and future growth features

Do not confuse these with historical test notes.

**2026-08-22 (session continuing item 15) — re-check pass:** Item 15
(section 4b) is still 🟡 built/not device-verified — no evidence found
that the owner has run its 7-step verification checklist yet, so its
status was left untouched (not reopened, not marked done). Re-reading
recall.md fresh for the next item surfaced that sections 5 and 6 (Phase
B items 16 and 17) had stale 🔴 PENDING lines even though the actual
source (`backend/admin/banners.php`, `promo-banners.php`,
`HomeActivity.kt`, `activity_home.xml`) shows both already built —
corrected in place, see sections 5, 6, and 33. **Phase B now has no
remaining Claude-actionable pending code** — item 19 (section 8) is
blocked on the owner setting up a real Google Maps API key/billing, not
on missing code. Next session should either (a) run device/build
verification for items 13–17 once a PHP CLI/DB/Android build
environment is available, or (b) move to Phase C ("Money" — item 20,
Platform ledger) if the owner wants forward progress before
verification happens.

**2026-08-22 (same day, continuing into Phase C) — items 20-23 built.**
Owner resolved section 13's open commission question: category- AND
area-specific overrides on top of a flat default (most-specific-wins),
built as `commission_rules` (migration 38) + `lib/commission.php`.
Full settlement chain built together per owner's explicit request:
`lib/ledger.php` (due ledger + platform ledger writers + the Pay Now
transaction), `admin/commission-rules.php`, `admin/settlements.php`
(per-restaurant ledger statement + bank details + Pay Now),
`admin/platform-ledger.php` (doc 19 §6b's Cash Flow report +
reconciliation check). `price_cart()`/`orders/create.php` now compute
and snapshot commission per line item instead of one flat order-level
number — a restaurant with no commission_rules configured sees
identical numbers to before, so this is additive, not a behaviour
change for anyone not using the new rules.

**Deliberately NOT wired** (see section 13's "NOT WIRED" list, and
each function's own kdoc in `lib/ledger.php`): automatic ledger entry
on COD-order-delivery. Genuinely blocked on another unbuilt piece —
there is no 'delivered' order-status transition anywhere in the
codebase yet (Rider App, Phase G, items 43-48). The UPI side (the
other half of this "NOT WIRED" note as of 2026-08-22) is wired as of
2026-08-23 — see item 24's update and `docs/23_Native_UPI_Payment_
Gateway_Architecture_2026-08-23.md`. Manual "Pay Now" settlement is
fully live today, independent of the remaining COD gap.

Next session: either device/build-verify everything 🟡 across Phase B
+ Phase C once a PHP CLI/DB/Android environment exists, or continue
forward — items 24 (Payment transaction architecture) or 25 (Refund
system) are the next genuinely-buildable Phase C items; 21/23's
"NOT WIRED" gap will resolve naturally once Phase G (Rider) or item 24
lands and someone remembers to call `record_cod_order_ledger_entry()`
/ `record_paid_order_ledger_entries()` from those new trigger points.

**2026-08-23 (follow-up session, picking up the item-15 handover) —
re-check pass + one real fix.** The old `NEXT_SESSION_PROMPT_item15.md`
handover was stale: items 15/16/17 were already resolved per this
section's own 2026-08-22 notes above, so per the file's own rule that
was left untouched rather than reopened. Followed recall.md's own
current next-session prompt (bottom of this file) instead, which
pointed at item 24's follow-up list. Did the one genuinely
Claude-actionable item on that list that doesn't need a live DB/build
env: item 24's follow-up #4, the fake "Cancel and pay by COD" button
in `UpiPaymentActivity.kt` (it only showed a toast and never touched
the backend). Built a real endpoint,
`backend/api/v1/orders/payment-switch-cod.php`, reusing
`orders/create.php`'s exact payment-restriction + COD-eligibility
function pair, and wired the Android button to call it with a real
confirm dialog. Full detail in doc 23 §A4b and item 24's update in
section 33 below. Everything else on that follow-up list (migrations
40/41 on real MySQL, Android Studio/ZXing compile check, full live
click-through) still needs the same PHP CLI/live-DB/Android-build
environment every session here has lacked — genuinely not buildable
further in this sandbox.

**2026-08-23 (same day, follow-up session #2) — item 25, Refund
System, built.** Re-verified the previous session's file claims
against actual `ls`/timestamps first (rule 34) before trusting them —
all confirmed genuine, nothing stale to correct. Confirmed the
sandbox's standing limitation is unchanged (still no `php`/`mysql`/
Android SDK, network still disabled) before doing anything further.
Owner chose to build item 25 (Refund System) in parallel rather than
wait on device/build verification for item 24. Built: migration 42
(`refunds` table, admin-configurable `refund_expected_days` setting,
`refunds_view`/`refunds_manage` RBAC keys), `lib/refunds.php` (single
write-path, mirrors `lib/ledger.php`'s pattern — `create_refund_
request()` / `approve_refund()` / `mark_refund_processing()` /
`complete_refund()` / `reject_refund()`), `admin/refunds.php` (review
queue, same "admin does the manual transfer, this system tracks and
reconciles it" model as `payment-pending.php` — no gateway refund API
exists). Found and closed a real gap while wiring this in: neither
`orders/cancel.php` nor `restaurant/orders-reject.php` ever checked
`payment_status` before flipping an order to cancelled/rejected — a
UPI-paid order's money could get cancelled with zero record anything
was owed back. Both now auto-create a `requested` refund when
`payment_status === 'paid'`. `format_order()` now returns a `refund`
object inline on every order (recall.md section 19's required
Amount/Reason/Method/Expected date/Reference/Timeline fields) so no
new customer-facing endpoint was needed. Full detail in section 19
(updated above) and the NEXT SESSION PROMPT at the bottom. Caught one
real bug during a static self-review before finishing: `admin/refunds.php`
first draft assumed `admin_require_login()` returns a `'permissions'`
array — it doesn't (`admin_auth.php`'s actual pattern is
`admin_has_permission($adminId, $key)`, checked directly) — fixed
before ending the session. **Not device/build-verified** — same
standing sandbox limitation. Android/customer-app UI for viewing
refund status was NOT touched this session (backend-only, per the
owner's "build in parallel" framing) — flagged as the one remaining
customer-facing gap in the NEXT SESSION PROMPT.

**2026-08-23 (follow-up session #3) — item 25's flagged customer-app
gap closed: refund status now shows on OrderStatusActivity.** Re-ran
the standing environment check first — still no `php`/`mysql`/Android
SDK, network still disabled (confirmed with a live `curl` this time,
not just an assumption) — so A.1–A.4 (UPI gateway) and B.5–B.7
(Refund) verification checklists remain undoable in this sandbox, per
the NEXT SESSION PROMPT's own framing. Owner chose to build the
refund-status UI screen instead (the one remaining gap section 19 and
the NEXT SESSION PROMPT both flagged) rather than wait on device/build
verification. Verified the previous two sessions' file claims against
actual `ls`/timestamps and `format_order()`'s real `refund` shape in
`lib/orders.php` before writing any Kotlin — all confirmed genuine.
Built: `RefundInfo`/`RefundTimelineEntry` data classes in
`network/Models.kt` (mirrors `format_order()`'s `refund` object field-
for-field) + a `refund: RefundInfo?` field added to `Order`; a new
`refundCard` section in `activity_order_status.xml` (same
show/hide-on-null convention as `riderCard`/`otpCard`); render logic
in `OrderStatusActivity.kt` — `loadScheduledFor()` renamed
`loadOrderDetail()` and now also populates the refund card in the same
one-shot `GET /orders/{id}` call (no new endpoint, per doc 23/section
19's design), with status-colored label (info/success/error via
existing `info_fg`/`success_fg`/`error_fg` colors), amount, reason
(swaps to `reject_reason` when status is `rejected`), method, a
conditionally-shown expected-by date (SQL DATE format, hidden once
`refunded`/`rejected`), a conditionally-shown reference, and a
programmatic-TextView timeline list (server already trims it to only
passed stages via `array_filter`, so no placeholder rows needed).
Also wired `cancelOrder()`'s success path to re-fetch and re-render
the refund card immediately, since cancelling a paid order
auto-creates a `requested` refund server-side
(`orders/cancel.php`) — without this the card would only have
appeared on the *next* time the screen opens, not the same session.
New strings added to `strings.xml` (`refund_*` keys). **Not
device/build-verified** — same standing sandbox limitation; view-
binding IDs were hand-matched between the new XML and the Kotlin file
the same way UpiPaymentActivity's were (doc 23 §11 admits this same
gap), never compiler-checked. No live-DB/live-app data was available
to sanity-check real render output against, so this is a static
read-the-code-carefully build like every other session here, not a
click-through-tested one. Next session (once tooling exists, or if
the owner wants to keep building forward): add this file's Kotlin/XML
changes to A.2/A.4's "confirm it actually compiles" checklist item,
and B.6's live click-through should include *seeing this card render
correctly* at each refund lifecycle stage as an explicit new
sub-step.

**2026-08-23 (follow-up session #4) — item 26, Customer Wallet,
backend + admin built (checkout integration deliberately deferred).**
Re-checked the standing environment first (still no `php`/`mysql`/
Android SDK, live `curl` still failing) before starting. Owner chose
Wallet over Offers Engine / real-gateway prep. Read doc 19 §3's schema
sketch (`customer_wallets`/`wallet_transactions`) and §12's explicitly-
flagged-open "split payment vs wallet-only" question before designing
anything — resolved it the same way doc 23 §A5 and migration 42 both
already resolved their own "no partial" questions: wallet-only, no
split, consistent with the rest of this codebase's payment model.
Built: migration 43, `lib/wallet.php` (mirrors `lib/refunds.php`'s
"one write-path" shape, `SELECT ... FOR UPDATE` row-locking so
concurrent credits/debits on the same wallet can't race off a stale
balance read), `api/v1/customer/wallet.php` (read-only balance +
history), and a wallet section added into the *existing*
`admin/customers.php` customer-detail modal (balance, last 5
transactions, admin credit/debit adjustment form) rather than a new
standalone admin page — that file's own header comment had already
flagged exactly this as the reason no wallet action existed there yet.
Caught and fixed one real gap during a self-review before finishing:
`admin/customers.php`'s header kdoc still said "No wallet-adjustment
action here yet" after the section was added — corrected to describe
what's actually there now. **Explicitly NOT built this session, each
a real remaining gap, not an oversight:** checkout integration
(`orders/create.php` doesn't accept `payment_method='wallet'` yet,
`debit_wallet_for_order()` exists in `lib/wallet.php` but has no
caller); `CheckoutActivity`'s wallet radio option / `PaymentMethodsResult
.walletAllowed`; the customer-app Wallet balance/history screen
(Profile menu row) — no Android/Kotlin touched this session, backend +
admin only; `lib/refunds.php`'s reserved `method='wallet'` refund-to-
wallet path (`complete_refund()` still only handles the manual-bank-
transfer method); and cashback granting/expiry (the
`wallet_cashback_expiry_days` setting is stored but nothing computes,
grants, or expires a cashback credit — no trigger point or scheduler
exists anywhere in this codebase to hook into yet). Full detail in
section 18 (updated above) and the NEXT SESSION PROMPT at the bottom.
**Not device/build-verified** — same standing sandbox limitation.

---

# 1. ADMIN PANEL — MAJOR PENDING MODULE

**Status: 🔴 GENUINELY PENDING / PARTIALLY STARTED**

The Admin Panel must become the central control plane of Anydrop.

Reference:
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/21_Production_Feature_Gap_Plan.md`

Current admin implementation is much smaller than the documented full scope.
The next implementation phases should expand Admin into the following modules.

## 1.1 Admin Dashboard

Admin home should provide:

- Today's orders
- Revenue
- Active customers
- Active restaurants
- Online/active riders when Rider App exists
- Pending restaurant approvals
- Pending rider approvals
- Pending payouts
- Open support tickets
- Quick links to operational problems

Full analytics can remain a separate module.

**Reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Dashboard / Analytics sections.

---

# 2. AREA MANAGEMENT — CORE ADMIN CONTROL SYSTEM

**Status: ✅ DONE 2026-08-21 — verified live-tested by app owner, see done.md (includes a 2026-08-22 dropdown-breadcrumb bug fix, also verified)**

**⚠️ Live-DB lesson learned, applies project-wide, not just here:**
migration 30 used `CREATE TABLE IF NOT EXISTS service_areas`, then got
edited TWICE afterward to change the `level` ENUM's values (the
'area'->'village' rename, then the 'city'+'village'->'city_village'
merge). Once a DB has already run migration 30 once, `IF NOT EXISTS`
means those later file edits never reach that DB's actual table —
CREATE-IF-NOT-EXISTS is a no-op on an existing table, it doesn't apply
column changes. This is exactly what caused the live
`PDOException: ... Data truncated for column 'level'` the app owner hit
adding a City/Village node — the live table's ENUM was stuck on an
older set of values than what areas.php now sends it.
**`backend/sql/34_migration_fix_service_areas_level_enum.sql` is the
fix — run it once on any DB that hit this error.** Lesson for future
sessions: once a migration has plausibly already been run somewhere,
schema changes to that concept need a NEW numbered migration (ALTER),
never an edit to the original file's CREATE statement — same
convention this project already uses everywhere else (see how
migration 28 alters what migration 22 created, rather than editing 22).

`backend/sql/30_migration_service_areas.sql` + `backend/admin/areas.php`
now exist: the `service_areas` hierarchy table, additive `area_id` on
`restaurants`/`customer_addresses`, and a full admin CRUD screen
(add/edit/deactivate/delete/test-coordinates). Needs the migration run
on the live DB and a live click-through before this can move to ✅ DONE.

**2026-08-21 (later same day), per app owner:** the deepest hierarchy
level is called **Village**, not Area — so the chain is now
`State → District → City → Village` (e.g. Rajasthan → Jodhpur → Osian →
Village). Renamed in the still-unrun migration's `level` ENUM
(`'area'` → `'village'`) and in `backend/admin/areas.php` +
`backend/admin/restaurants.php` (labels, comparisons, the area-assign
dropdown). Safe to do as a straight rename since the migration has
never been run against the live DB yet. **Not** renamed: the
`service_areas` table name and the `area_id` FK columns — those refer
to the "service area" hierarchy concept as a whole, not specifically to
this leaf level, so they stay as-is.

**2026-08-21 (restructured again, same day), per app owner:** field
list is now `State → District → City/Village → Area (optional)`.
'city' and the 'village' rename above are merged into one level,
`city_village` (label "City/Village" — one node, urban or rural, no
separate levels for each). Below that, `area` is back — but now
genuinely **optional**: a City/Village node doesn't need an Area child
at all; add one only when a specific sub-locality needs its own rule
(see the chat's "10 areas in Jodhpur" explanation — most won't need
individual entries). Because Area is optional, whichever node ends up
deepest in a branch is the resolution-relevant one, so
`center_lat`/`center_lng`/`radius_km` can now be set on either
`city_village` or `area` (not restricted to one fixed level anymore) —
`areas.php`'s test-coordinates tool and `restaurants.php`'s area-assign
dropdown both updated to match on "has coordinates set" rather than a
specific level name.

**2026-08-21 (Fetch by Pincode confirmed working live by app owner) —
Add form reworked again, same day:** the Add form is no longer
"pick a parent, add one child." It's now a single form with **State,
District, City/Village (all required), Area (optional)** — submit
creates whichever of those don't already exist and reuses whichever do
(matched case-insensitively, correctly scoped so a same-named District
under a different State is never confused with it). "Fetch by Pincode"
now auto-fills State + District directly (those are reliable at
pincode level) and offers City/Village and Area as separate
click-to-fill suggestions per post-office name (since one pincode's
post offices can be either, per the chat explanation this session).
Each field also has a datalist of existing names for autocomplete/reuse
even without using Pincode. The Edit form is unchanged — it still edits
one existing node's own name/coordinates.

**2026-08-22 — Fetch by Pincode now also suggests center_lat/center_lng:**
`backend/admin/api/fetch-pincode.php` additionally geocodes the pincode
via OpenStreetMap's free Nominatim API (server-side proxy, same
CORS/key-hiding reasoning as the existing India Post call) and returns
`center_lat`/`center_lng`. `areas.php`'s Add form pre-fills the Center
latitude/longitude fields from this (still fully editable) alongside
the existing State/District auto-fill. **Important caveat, stated
explicitly to the app owner:** this is the PINCODE's centroid, not the
true center of whichever City/Village/Area actually gets created — a
pincode can span several villages — so treat it as a starting point
only and use the existing "test coordinates" tool to sanity-check
before relying on it for real area resolution. Geocoding failure never
blocks the rest of the pincode lookup (state/district/suggestions still
come back). Not yet live-tested (same sandbox-has-no-outbound-network
caveat as the original India Post integration) — needs a live check:
fetch a pincode, confirm lat/lng populate, confirm they're roughly in
the right place, confirm add-area still works if geocoding fails.

**2026-08-22 (same day) — separate "Get coordinates" button for a
specific Area/City-Village name, not just the pincode centroid:**
app owner's follow-up question — if Osian's pincode is entered but the
node being created is "Neora" (an Area *inside* Osian), the pincode-
centroid coordinate above is Osian's average point, not Neora's real
location, since one pincode commonly covers several distinct
localities. New endpoint `backend/admin/api/geocode-locality.php`
geocodes by NAME instead of pincode — proxies Nominatim's free-text
`search` endpoint with the fullest context available
(`name, city_village, district, state, India`) so a same-named place
elsewhere in India isn't matched by mistake. New "📍 Get coordinates
for this Area/City-Village name" button on the Add form (right under
the Area field) calls it — reads whichever of Area/City-Village is
currently filled in (Area takes priority, being more specific), fills
Center lat/lng, and shows Nominatim's own matched place name back to
the admin so they can sanity-check it's really the right place before
trusting it. Falls back cleanly with a clear message ("look it up on
Google Maps and enter manually") when OSM doesn't have that locality
mapped — rural India coverage in OSM is inconsistent, a miss is
expected sometimes, not a bug. Same 1-req/sec Nominatim usage-policy
constraint as the pincode geocoding — this is only ever a single
admin-initiated click, never call it in a loop.

**2026-08-22 (later same day) — same "Get coordinates" button added to
the Edit form, for already-created areas:** app owner asked for a way
to re-geocode an *existing* node's lat/lng by name, not just when
first adding one. The Edit form only has a single Name field (not
separate State/District/City-Village inputs like Add does), so a new
`area_ancestor_names_by_level()` helper walks the node's own
`parent_id` chain server-side to recover its District/State/
City-Village context, embedded as `data-*` attributes on a
"📍 Re-fetch coordinates by name" button next to the lat/lng fields.
Click fills the two input boxes only — same as Add, this never
auto-saves; the admin still has to hit Save, so a bad match can be
undone by just not saving / re-fetching again.

Not yet live-tested (either geocode button) — needs: a name Nominatim
can find (confirm accurate match + label), a name it can't find
(confirm the clean fallback message), Area-vs-City/Village priority on
Add picks the right field, and the Edit button's recovered ancestor
context is actually correct for a couple of real existing nodes.

Still genuinely pending after that: everything in section 3-5 below
(area-wise restaurant visibility, COD rules, banner targeting) and
restaurant onboarding not yet writing `restaurants.area_id` — this
session only built the control table + admin CRUD, not the
consumption side.

This must be implemented early because multiple other features depend on area
resolution.

Reference:
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — `Area Management`
- `docs/21_Production_Feature_Gap_Plan.md` — area-wise analytics/operations

## 2.1 Hierarchy

Admin should be able to create/manage:

```text
State
  ↓
District
  ↓
City
  ↓
Area
```

Example:

```text
Rajasthan
  └── Jodhpur
       └── Osian
            ├── Main Osian
            ├── Teliya Mohalla
            └── Other local areas
```

The documented v1 architecture uses one `service_areas` adjacency-list table
with `parent_id`, `level`, `is_active`, center latitude/longitude and radius.

Restaurants should have an `area_id`.
Customer saved addresses should resolve to an `area_id` server-side.

**2026-08-21 (same day) — duplicate-node bug reported and fixed:** app
owner added Osian (City/Village) once, then later added an Area
("Neora") under State=Rajasthan/District=Jodhpur/City-Village=Osian
again — and got a SECOND "Osian" row instead of the first one being
reused. Root cause: `find_or_create_area_node()` matches City/Village
scoped to its District's exact `parent_id` — so if the District typed
or pincode-fetched the second time didn't resolve to the exact same
existing District row (e.g. a trailing-space/spelling difference, or a
different pincode reporting a slightly different official District
name like "Jodhpur" vs "Jodhpur Rural"), a brand-new District gets
created too, and Osian gets created fresh under THAT one — two
legitimately-different rows that happen to share a name, not a
same-parent collision. **Fix shipped, two parts:**
1. A duplicate-detection banner at the top of the Hierarchy list
   (same level + same name, case-insensitive, regardless of parent) —
   shows each match's full breadcrumb path so it's obvious whether
   they're a true same-parent double-add or a different-parent split.
2. A **Merge duplicate nodes** tool (needs `areas_delete` permission) —
   pick "Duplicate" and "Keep" (same level required), submit: the
   duplicate's children, and any restaurants/customer_addresses/banners
   assigned to it, all get moved onto "Keep" (one transaction — no
   half-merged state), then the duplicate row is deleted.
This does NOT prevent every possible future duplicate (a genuinely
different District name is a legitimate difference, not a bug) — it
gives the admin a safe way to fix one after the fact, since the tool
can't know in advance which near-duplicates are "the same place" vs.
actually different.

**Also this session, same request:** any dropdown elsewhere where an
admin picks a service_areas node (`restaurants.php`'s area-assign,
`banners.php`'s area targeting) now shows the full breadcrumb —
"Neora, Osian, Jodhpur, Rajasthan" style, most-specific-first,
comma-separated — instead of just the bare node name, via a new shared
`admin_area_breadcrumb_compact()` helper in `_bootstrap.php` (loaded by
every admin page already). This is the same duplicate-distinguishing
problem as the merge tool above, just for assignment dropdowns instead
of the Hierarchy list — with two same-named nodes possible, "Osian"
alone in a dropdown was ambiguous; the full path isn't.

---

# 3. AREA-WISE RESTAURANT VISIBILITY

**Status: 🟡 BUILT 2026-08-22, NOT build/device-verified**

**2026-08-22 session note:** Most of the infrastructure this item needs
already existed unused — `lib/geo.php`'s `resolve_service_area()`,
`restaurants.area_id`/`customer_addresses.area_id` (migration 30),
`home/promo-banners.php` already consuming `resolve_service_area()` for
area-targeted banners (recall.md item 5 below is also actually already
built, despite its own status line), and `admin/restaurants.php`
already having the area-assign dropdown. The one real gap — this
endpoint itself never applying the area match — is now closed:
`backend/api/v1/restaurants/list.php` resolves the customer's lat/lng
to their nearest service area (same eligible-set rule as
promo-banners.php: nearest node + its parent when the nearest is
level='area') and excludes a restaurant whose `area_id` doesn't match,
UNLESS either side is unresolved (`area_id` still NULL, or customer has
no area match) — same "don't hide behind unresolved data" stance the
existing radius check already takes. Layered strictly on top of the
existing per-restaurant `delivery_radius_km` haversine check, never
replacing it, per the ⚠️ clarification below.

Also found: recall.md item 6 ("No Restaurant Available State") was
**already fully built** in `HomeActivity.kt`/`activity_home.xml`
(`setServiceAreaUnavailable()`) — full-screen state replacing all
scrolling content on an unfiltered empty Home, matching this doc's
"must NOT show" list exactly. Only gap found there: the screen's only
button was "Try again" (re-run same load), with no way to actually
change location — recall.md's own spec calls for "Change Location" as
the primary action. Added `btnServiceAreaChangeLocation` as a new
primary button (routes through the existing `openLocationPicker()`,
same as the GPS-off banner), kept "Try again" as a secondary action
for its own legitimate case (arriving in an area that got approved or
just launched since last check).

**Not build/device-verified** — no PHP CLI / Android SDK in this
sandbox, same standing limitation as every other session. Needs: a
live area-mismatch case (restaurant assigned to a different area than
the resolved customer, confirm it's excluded), an unassigned-restaurant
case (area_id NULL, confirm it's still shown), and the new Change
Location button end-to-end.

**Explicitly out of scope, noted not touched:** `search.php` has no
`delivery_radius_km` enforcement at all (unlike list.php) and
duplicates its own local `haversine_km()` instead of using
`lib/geo.php` — pre-existing gap, separate from this item.

Customer restaurant discovery must become area-aware while retaining sensible
GPS/radius validation.

Example:

```text
Customer location
      ↓
Resolve service area
      ↓
Osian
      ↓
Fetch active restaurants in Osian
      ↓
Apply restaurant delivery-radius / operational checks
```

Recommended v1 rule from the existing Admin spec:

> Keep both area matching AND restaurant delivery-radius checks rather than
> replacing radius filtering with area-only filtering.

This prevents accidental visibility changes at area boundaries.

## ⚠️ IMPORTANT CLARIFICATION (app owner, 2026-08-21) — do not get this wrong

There are **two different radii** in this system and they must never be
conflated:

1. `service_areas.radius_km` (migration 30, level='area' only) — used ONLY
   to resolve which service area a customer's GPS point / saved address
   falls into (recall.md item 2's "test coordinates" tool). Measured from
   the **area's own center point** (`center_lat`/`center_lng`).
2. `restaurants.delivery_radius_km` (already exists, `01_schema.sql`,
   default 5.0) — this is the ACTUAL delivery-eligibility radius, and it is
   measured from **that specific restaurant's own location**
   (`restaurants.latitude`/`longitude`) to the **customer's delivery
   address**, per restaurant, independently of the area boundary.

Example (owner's own words): if Osian's delivery radius is 5 km, that means
**5 km from the restaurant to the delivery location** — NOT 5 km from
Osian's area center. Two restaurants inside the same Osian service area can
sit at different points inside it and therefore have different actual
delivery-eligible zones, even with the same 5 km setting. **Never implement
this as "customer inside area X's center-radius circle ⇒ eligible for every
restaurant in area X."** The correct check per candidate restaurant is
always: `haversine_km(restaurant.lat, restaurant.lng, delivery.lat,
delivery.lng) <= restaurant.delivery_radius_km` (reuses `lib/geo.php`'s
`haversine_km()`, same helper areas.php's test-coordinates tool already
uses) — the service-area match from item 2 is an *additional* filter on top
of this, not a replacement for it.

Also pending: Admin needs a way to set a **default / max delivery radius
per city** (e.g. a city-level setting under Service Areas so a restaurant
onboarding in a small town can't be silently defaulted to the same 5 km
that makes sense in a bigger city) — this is a distinct, separate config
from both radii above and does not exist yet. It would live as a new
per-city field/setting, NOT by repurposing `service_areas.radius_km`
(which must stay dedicated to area-resolution only, per point 1 above).

**Reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Area Management.

---

# 4. AREA-WISE COD / PAYMENT / ORDER RULES

**Status: 🟡 BUILT 2026-08-22, NOT build/device-verified**

**2026-08-22 session note:** Full v1 scope built (app owner confirmed:
core + max COD amount + daily COD limit + new-customer restriction —
delivery fee/min order/general payment restrictions per area are
separate, still-pending items, not folded into this).

- `backend/sql/35_migration_area_cod_rules.sql` — new `area_cod_rules`
  table, one row per `service_areas` node (city_village or area level).
  Platform-wide fallback defaults added to `app_settings`
  (`default_cod_enabled`, `default_cod_min_prepaid_orders`,
  `default_cod_max_order_amount`, `default_cod_max_orders_per_day`,
  `default_cod_new_customer_blocked`) for any area with no rule row, or
  any customer whose location doesn't resolve to an area at all.
- `backend/lib/cod_rules.php` — `get_effective_cod_rule()` (resolves
  lat/lng → nearest service area → its rule or the platform default,
  same eligible-set walk as `resolve_service_area()`'s other callers)
  and `evaluate_cod_eligibility()` (the actual yes/no + reason, given a
  resolved rule + customer + optional order amount). Single shared
  function so enforcement and the pre-check below can never drift apart.
- `backend/api/v1/orders/create.php` — enforces the rule server-side,
  two passes: before `price_cart()` (enabled/count-based checks, fails
  fast) and after (amount cap, needs `grand_total`). The Customer App
  never evaluates this itself — server decision only, per the item's
  explicit requirement.
- `backend/api/v1/customer/cod-eligibility.php` — new pre-check endpoint
  so checkout UI can grey out COD with a reason before the customer
  even tries, instead of only finding out from a 422 on submit. Calls
  the same shared functions — not a second implementation of the rule.
- `backend/admin/cod-rules.php` — new admin page (platform defaults
  card + per-area rule table), reachable from a new "COD Rules" nav
  item. Gated on `areas_view`/`areas_edit` (no dedicated `cod_rules_*`
  permission key exists in migration 29's seed; reused the closest
  existing one rather than adding a migration just for RBAC seeding).

**Not build/device-verified** — no PHP CLI in this sandbox, same
standing limitation as every other session. Needs: migration 35 run on
the live DB, then a live click-through — set platform defaults, add an
area rule, place a COD order that should be blocked by each rule type
in turn (disabled, under min-prepaid-orders, over max-amount, over
daily limit, new-customer-blocked), confirm `orders/create.php`'s 422
and `cod-eligibility.php`'s pre-check agree with each other and with
what the admin page shows.

**Genuinely still pending after this:** area-specific delivery fee,
area-specific minimum order, area-specific general service
availability, area-specific broader payment restrictions (this session
only covered COD specifically, not payment methods generally).

**Update 2026-08-22 (later session):** delivery fee and minimum order
are no longer pending — see new section 4a below. Service availability
(item 10, area-wise restaurant visibility) was already built earlier
the same day. Broader payment-method restrictions beyond COD remain
genuinely unbuilt.

---

# 4b. AREA-WISE PAYMENT RESTRICTIONS (GENERAL) — Phase B item 15

**Status: 🟡 BUILT 2026-08-22, NOT build/device-verified**

Coarse "is this payment method allowed in this area at all" gate —
deliberately separate from section 4's `area_cod_rules` (fine-grained
COD-specific eligibility: min prepaid orders, max amount, daily cap,
new-customer block). Section 4's rules only ever run for a customer
whose method has already cleared this gate. Composes as: payment
method → this gate first → if 'cod', then also section 4's finer
checks.

**What was built:**
- `backend/sql/37_migration_area_payment_restrictions.sql` — new
  `area_payment_restrictions` table, one row per `service_areas` node
  (city_village or area level), `upi_allowed`/`cod_allowed` flags. A
  DB-level `CHECK` constraint blocks a row with both off (an area with
  no way to ever place an order is a misconfiguration, not a valid
  state) — form-level validation in the admin page gives a friendlier
  message before that constraint would even fire. New `app_settings`
  keys: `default_upi_allowed`, `default_cod_allowed` (platform-wide
  fallback for any area with no row, or an unresolved customer
  location).
- `backend/lib/payment_restrictions.php` — `get_effective_payment_restrictions()`
  (same nearest-area resolution + eligible-set walk as
  `get_effective_cod_rule()`) and `is_payment_method_allowed_in_area()`
  (yes/no + reason for one specific `payment_method` value). Single
  shared pair so enforcement and the pre-check endpoint can't drift
  apart, same reasoning as `cod_rules.php`.
- `backend/api/v1/orders/create.php` — new check inserted BEFORE the
  existing COD-specific check (section 4): a method that fails this
  general gate never reaches `evaluate_cod_eligibility()` at all.
  Returns `payment_method_not_allowed` (422) with a `reason`.
- `backend/api/v1/customer/payment-methods.php` — new pre-check
  endpoint (`GET ?delivery_address_id=`), same "let the UI grey things
  out before submit" pattern as `cod-eligibility.php`. Returns
  `{upi_allowed, cod_allowed}` for the resolved area.
- `backend/admin/payment-restrictions.php` — new admin page (platform
  defaults card + add/edit/toggle/delete per-area rule table), new
  "Payment Restrictions" nav item in `_layout_head.php`. Same
  `areas_view`/`areas_edit` permission gate as `cod-rules.php`/
  `pricing-rules.php` (no dedicated permission key exists for this
  either — reusing the closest existing one, same reasoning those two
  pages already used).
- Customer App: `PaymentMethodsResult` model +
  `ApiService.getPaymentMethods()`. `CheckoutActivity.kt` —
  `loadPaymentMethods()` fires whenever the selected delivery address
  changes (initial load, address switch, new address saved);
  `applyPaymentMethodRestrictions()` disables (greys out, doesn't hide)
  whichever radio the resolved area doesn't allow, auto-hops the
  selection to the other method if the currently-checked one just
  became unavailable, and appends "— Not available for this address"
  to the disabled option's label. `placeOrder()` has a defensive
  client-side re-check against the last-loaded restriction (avoids an
  avoidable round-trip), and the 422 path now maps
  `payment_method_not_allowed` to the same user-facing string as a
  fallback if that pre-check was stale/skipped. New string resource
  `payment_method_unavailable_here`.

**Deliberately NOT done:** wiring section 4's COD-specific
`cod-eligibility.php` pre-check into the app — it still isn't consumed
anywhere in `CheckoutActivity.kt`, same standing gap noted in section
4's own status. This session only wired the NEW general-gate endpoint,
since that's what item 15 asked for; the COD sub-rule UI wiring remains
a separate, still-open task if wanted later (the COD radio, once
enabled by this general gate, still has no UI indication of section 4's
finer per-customer eligibility — a customer under `min_prepaid_orders`
would currently only find out via the plain `orders/create.php` 422
with no special message for that code in `CheckoutActivity.kt` yet).

**Not build/device-verified** — no PHP CLI, live DB, or Android build
environment in this sandbox, same standing limitation as every other
session. Needs, in order: (1) migration 37 run on the live DB, (2)
Customer App rebuild, (3) set platform defaults + one area rule from
the new admin page (try saving both methods off, confirm the friendly
rejection message), (4) in the Customer App, pick a delivery address
resolving into a COD-blocked area and confirm the COD radio greys out
with the "Not available" label and auto-selects UPI, (5) attempt to
force-place a COD order there anyway (e.g. stale app state) and confirm
`orders/create.php` still rejects it with `payment_method_not_allowed`,
(6) confirm a UPI-blocked area behaves the same in reverse, (7) confirm
an address with no lat/lng, or a location outside every service area,
falls back to the platform defaults rather than blocking everything.

---

# 4a. AREA-WISE MINIMUM ORDER FLOOR + DISTANCE-BASED DELIVERY FEE

**Status: 🟡 BUILT 2026-08-22, NOT build/device-verified**

Phase B items 13/14. App owner's explicit v1 scope, confirmed this
session:

- **Minimum order** — the restaurant sets its OWN minimum order amount
  (Restaurant App → Edit Profile, new field). Admin can set a per-area
  FLOOR that restaurant's value can never go below — "floor, restaurant
  can go higher", explicitly NOT "admin's number always wins" (that
  would make the restaurant's own field pointless). No area floor
  configured → platform-wide default is the floor.
- **Delivery fee** — real distance (haversine, restaurant ↔ delivery
  address) × an admin-set ₹/km rate + a flat base fee, area-wise
  overridable, platform default otherwise. Rounded UP to the nearest
  ₹5, always — app owner's explicit rule: *"mera paisa minus nahi hona
  chahiye"* (16→20, 17→20, 18→20, 19→20, 20→20 — confirmed exact
  mapping in-session, ceiling not nearest-neighbor rounding).

**What was built:**
- `backend/sql/36_migration_area_pricing_rules.sql` — new
  `area_pricing_rules` table (one row per `service_areas` node,
  min_order_amount / delivery_rate_per_km / delivery_base_fee, each
  independently nullable = "use platform default"). New `app_settings`
  keys: `default_min_order_amount`, `default_delivery_rate_per_km`,
  `default_delivery_base_fee`.
- `backend/lib/delivery_pricing.php` — new shared lib:
  `ceil_to_nearest_5()` (the rounding rule above),
  `get_min_order_floor_for_area_id()` (walks a restaurant's own
  `area_id` up its parent chain, same pattern as
  `get_effective_cod_rule()`), `calculate_delivery_fee()` (resolves the
  rate/base from the DELIVERY ADDRESS's lat/lng — fee is about "cost to
  deliver into this area", not the restaurant's own area — falls back
  to the pre-existing flat `delivery_charge_flat` setting whenever
  either endpoint's lat/lng is missing, so nothing regresses for a
  caller that can't supply them).
- `backend/lib/orders.php` — `price_cart()` now takes optional
  `$deliveryLat`/`$deliveryLng` and uses `calculate_delivery_fee()`
  instead of always reading the flat setting; exposes
  `delivery_distance_km` alongside `delivery_charge` for display.
- `backend/api/v1/orders/create.php` — passes the order's resolved
  `addressLat`/`addressLng` through (already had them for other checks).
- `backend/api/v1/cart/validate.php` — now accepts an optional
  `delivery_address_id`, resolves that address's lat/lng (ownership
  checked), passes through — so the checkout preview's delivery charge
  matches what `orders/create.php` will actually charge instead of
  jumping at place-order time. Omitting it still works exactly as
  before (flat estimate).
- `backend/api/v1/restaurant/profile-update.php` — new
  `min_order_amount` field, rejected with `min_order_below_area_floor`
  (+ the actual floor value in the error's `data.area_floor`) if it's
  below the restaurant's resolved area floor. Never trusts the app —
  floor is recomputed server-side from `restaurants.area_id` every
  save.
- `backend/admin/pricing-rules.php` — new admin page (platform defaults
  card + add/edit/toggle/delete per-area rule table), new "Pricing
  Rules" nav item. Same `areas_view`/`areas_edit` permission gate as
  `cod-rules.php` (no dedicated permission key exists for this either).
- Restaurant App: `Models.kt` (`minOrderAmount` on both
  `RestaurantProfileDetail` and `ProfileUpdateBody`),
  `activity_edit_profile.xml` (new field, right after cuisine tags),
  `EditProfileActivity.kt` (populate on load, blank if unset rather
  than showing a misleading "0"; include in save only if the owner
  typed something; on a `min_order_below_area_floor` error, parses
  `data.area_floor` out of the error body and shows the actual number
  instead of a generic failure toast).

**Deliberately NOT done:** a delivery-fee preview/breakdown UI on
either app (Customer checkout showing "Delivery fee (2.3 km)" or
Restaurant App showing its area's current rate) — `delivery_distance_km`
is returned by the API for this but no screen consumes it yet. Same for
an admin-side "which restaurants are currently below today's area
floor" report, in case an admin lowers the platform default after
restaurants already saved higher values (not possible going forward
since floor is enforced on every save, but a historical edge case if
`min_order_amount` was set before this session).

**Not build/device-verified** — no PHP CLI, live DB, or Android build
environment in this sandbox, same standing limitation as every other
session. Needs, in order: (1) migration 36 run on the live DB, (2)
Restaurant App rebuild, (3) set platform defaults + one area rule from
the new admin page, (4) in the Restaurant App, try saving a
min_order_amount below that area's floor and confirm the exact floor
value shows in the error toast, then save one above it and confirm it
persists, (5) place (or `cart/validate.php` preview) an order between a
restaurant and delivery address with known real coordinates and hand-
verify the delivery charge equals `ceil_to_nearest_5(base + distance ×
rate)` for whichever rule (area or platform default) should apply.

---

This is an explicit product requirement and should NOT be hardcoded in the
Customer App.

Admin must be able to configure rules per service area.

### Example: Osian

Admin should be able to set:

```text
Area: Osian

COD: Enabled
Minimum prepaid orders before COD: 5
```

Meaning:

- A customer in Osian may have COD available only after the configured rule is satisfied.
- The exact business rule should be configurable from Admin.
- The Customer App only receives the server decision; it must not contain a
  hardcoded `if Osian => 5 orders` rule.

The system should support configurable conditions such as:

- COD enabled/disabled
- Minimum completed prepaid/online orders
- Minimum order amount
- Maximum COD order amount
- Maximum COD frequency / daily limit
- New-customer COD restriction
- Area-specific delivery fee
- Area-specific minimum order
- Area-specific service availability
- Area-specific payment restrictions

**Important:** exact final business rules should be stored in Admin-controlled
configuration, not Android constants.

---

# 5. AREA-WISE BANNERS / PROMOTIONS

**Status: 🟡 BUILT, NOT device/build-verified (2026-08-22 session re-check)**
> This status line was stale — it still read 🔴 PENDING even though
> section 17's own log shows the work landing across two 2026-08-22
> passes. Corrected after re-reading actual source, per section 34's
> "don't trust an old status line" rule: `backend/admin/banners.php`
> has a real area picker (`<select name="area_id">`, empty = all
> areas), `banners.area_id` (migration 33) has an FK into
> `service_areas`, and `backend/api/v1/home/promo-banners.php` resolves
> the customer's `lat`/`lng` via `resolve_service_area()` and filters
> `WHERE area_id IS NULL OR area_id IN (...)` before merging into the
> carousel. `ApiService.getPromoBanners(lat, lng)` in the Customer App
> already passes coordinates through (`ApiService.kt`), so this is
> wired end-to-end, not just backend-only. See section 17 for the full
> build log — that is now the canonical status for this feature;
> treat this section as the product-spec description, not the source
> of truth for status.
>
> **Still not build/device-verified** (no PHP CLI, live DB, or Android
> build environment in this sandbox): needs migration 33 run live, one
> banner saved scoped to a specific area vs one left platform-wide, and
> a device/emulator check that a customer resolved into that area sees
> the scoped banner while a customer elsewhere doesn't.

Admin must be able to target banners to specific service areas.

Example:

```text
Banner: Osian Independence Day Offer
Target Area: Osian
Active: Yes
```

Customer in Osian → sees it.
Customer outside Osian → does not receive it.

Platform-wide banners should use:

```text
area_id = NULL
```

Reference:
`docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Banner Manager.

---

# 6. NO RESTAURANT AVAILABLE STATE — IMPORTANT CUSTOMER UX

**Status: 🟡 BUILT, NOT device/build-verified (2026-08-22 session re-check)**
> Also stale at 🔴 PENDING. Source confirms this is built:
> `activity_home.xml`'s `serviceAreaUnavailable` block fully replaces
> `swipeRefresh` (banners, categories, filters, restaurant list all
> disappear together, not layered) with the icon/title/message + two
> buttons (`btnServiceAreaChangeLocation` → `openLocationPicker()`,
> `btnServiceAreaRetry` → `loadRestaurants()`), both wired in
> `HomeActivity.kt`. `HomeActivity.loadRestaurants()` flips it on only
> for a genuinely unfiltered empty result (`isUnfilteredDefaultView`),
> not for a filtered/searched/veg-only empty result, matching this
> section's "no restaurant in range" framing rather than a generic
> empty state. `backend/api/v1/restaurants/list.php` also returns
> `out_of_range_count` to support this distinction server-side.
>
> **Still not build/device-verified**: needs an Android build and a
> device/emulator test from an address with zero restaurants in range —
> confirm only the dedicated state renders (no banners/categories/list
> underneath), Change Location opens the picker, and Retry re-queries.

This needs a dedicated, deliberate Customer Home state.

### Rule

If the resolved customer location has **no eligible restaurant within the
configured service/delivery radius** (example: no restaurant within 5 km), do
NOT show an empty/broken normal Home feed.

Instead show ONLY:

```text
[ No Restaurant Available illustration / screenshot ]

No restaurants available in your area

We currently don't have restaurants delivering to this location.

[ Change Location ]
```

### Must NOT show in this state

- Food categories
- Promotional banners
- Restaurant lists
- Popular dishes based on unavailable restaurants
- Fake/empty recommendation sections

The screen should clearly communicate the reason and give the user one primary
recovery action:

> **Change Location**

The backend should determine whether the feed is serviceable; the client should
render the correct state.

---

# 7. LOCATION OFF / LOCATION NOT AVAILABLE — ZOMATO-STYLE FALLBACK

**Status: 🟡 Case B built 2026-08-22, NOT build/device-verified**

Location behavior must be designed as a proper state machine.

## Case A — Location permission ON + GPS available

```text
GPS
 ↓
Resolve location
 ↓
Resolve service area
 ↓
Load restaurants/feed
```

**Status: already covered before this session** — the explicit "Use
current location" row (Location Picker / AddressEditorBottomSheet) plus
the Bug 6.1 GPS-off banner (re-checked every `onResume`) already handle
this path when the user has a live-location active address. This
session did NOT add a silent auto-GPS-grab on every Home open (i.e.
Home does not override an already-resolved saved/default address with
a fresh live fix just because permission happens to be granted) — that
would fight the "use the saved/default address" behaviour Case B
already relies on and wasn't part of what this item asked for.

## Case B — Location permission OFF

Do NOT keep asking for GPS indefinitely.

Check saved addresses first.

### If saved address exists

Use the user's selected/default saved address as the active delivery location.

```text
Location OFF
   ↓
Saved address exists?
   ↓ YES
Use saved address
   ↓
Resolve service area
   ↓
Load feed
```

**Status: already existed** (`HomeActivity.resolveActiveAddressThenLoad()`
already picked the server's `is_default` address, or the first one, when
no cached active address existed).

### If NO saved address exists

Open the **Add Address / Location Picker flow**, using a Zomato-style UX.

```text
Location OFF
   ↓
No saved address
   ↓
Open Add Address / Location Picker
   ↓
User selects location / drops pin
   ↓
Address details
   ↓
Save address
   ↓
Use it as active location
```

**2026-08-22 — this branch is what was actually missing, and is what
got built this session.** Before this change, zero saved addresses
meant `resolveActiveAddressThenLoad()` silently fell back to an
unfiltered feed forever, with no prompt at all — the exact "trapped on
a blank Home screen" outcome this section explicitly warns against.

**What changed (`customer/app/src/main/java/com/anydrop/food/ui/home/HomeActivity.kt`):**
- `resolveActiveAddressThenLoad()` now auto-launches the existing
  `LocationPickerActivity` (same destination "Change Location"/the
  GPS-off banner already use — no second flow created, per section 8's
  "do not create multiple incompatible address/location flows" rule)
  the moment it confirms zero saved addresses (not on a network error
  fetching them — that falls back to the unfiltered feed exactly as
  before, since a transient fetch failure isn't the same thing as "this
  account genuinely has no address").
- Guarded by a new `hasAutoPromptedForMissingLocation` instance flag so
  a user who backs out of the picker without picking anything isn't
  immediately re-prompted by the very re-resolve their cancel triggers
  (the existing `locationPickerLauncher` callback always calls
  `resolveActiveAddressThenLoad(forceRefresh = true)`, which would
  otherwise see "still zero saved addresses" and loop forever). Once
  they dismiss it, Home falls back to the unfiltered feed for the rest
  of that Home instance — the flag resets on a fresh Activity (e.g.
  relaunching the app), so it isn't suppressed forever either.
- No new Activity, no new layout, no backend changes — this was purely
  wiring an existing, already-built flow (item 8's "core flow exists")
  into the one case that used to silently do nothing.

The user should not be trapped on a blank Home screen merely because location
permission is disabled.

**Not build/device-verified** — no Android build environment in this
sandbox, same standing limitation as every session touching the apps.
Needs: fresh install (or clear app data) with location permission
denied/GPS off and zero saved addresses → confirm the picker
auto-opens on Home's first load; back out without picking → confirm
Home falls back to the unfiltered feed and does NOT re-prompt on
subsequent resumes within that same app open; add an address via the
picker → confirm Home picks it up as the active address and the feed
filters by it; force-close and reopen the app → confirm the prompt can
fire again if still no address (not permanently suppressed).

**Reference:** `docs/features.md` — Feature 7, Zomato-style location picker + map pin-drop address flow.

---

# 8. ACTIVE ADDRESS + LOCATION PICKER

**Status: 🟡 CORE FLOW EXISTS / EDGE-CASE HARDENING REQUIRED**

The final UX should support:

- Current location
- Saved addresses
- Default/selected address
- Change location
- Add new address
- Map pin-drop
- Reverse geocoded address
- House/flat/floor details
- Receiver name/phone
- Address type
- Distance from current location
- Nearby location suggestions where available

The Home location bar should open the same central location-selection flow.

Do not create multiple incompatible address/location flows.

**Reference:** `docs/features.md` — Feature 7; `docs/12_Handover_H6_Map_PinDrop_Photo.md`.

---

# 9. RESTAURANT OFFERS ENGINE

**Status: 🔴 GENUINELY PENDING**

Current coupons are NOT the same thing as the complete Restaurant Offers Engine.

The pending feature is a restaurant-created promotion system where restaurants
can create structured offers from the Restaurant App, while the backend pricing
engine validates and calculates the final price.

### Required offer types include:

- Quantity deal
- Buy X for ₹Y
- Buy X Get Y
- Percentage discount
- Flat discount
- Free delivery
- Combo/bundle offers
- Happy-hour/time-window offers
- Item-specific offers
- Category-specific offers
- Start/end validity
- Daily limits
- Total usage limits
- Minimum order
- Maximum discount
- Offer stacking/exclusivity rules

Example:

```text
3 Samosa @ ₹50
Buy 2 Burgers for ₹199
Buy 2 Get 1 Free
20% OFF up to ₹100
FREE DELIVERY above ₹299
4 PM – 6 PM Happy Hour
```

The pricing engine must remain server-authoritative.

Do not calculate different totals independently in Customer App, Restaurant App
and Admin Panel.

**See also recall.md item 13's 2026-08-21 clarification:** commission must
support a per-food-category override (not one flat restaurant-level %) —
whatever pricing engine gets built here needs to compute commission per
line item, not per order, once that lands.

**Deep reference:** `docs/20_Offers_Pricing_UI_Polish_Notes.md` — Section 1 `Restaurant Offers System` and Section 2 `Free Delivery Offers`.

---

# 10. DELIVERY RESPONSIBILITY / SELF DELIVERY

**Status: 🔴 PENDING**

Restaurant delivery mode should support:

```text
Anydrop Delivery
Restaurant Self Delivery
```

Admin controls whether a restaurant is allowed to use self delivery.

This becomes important when Rider App is implemented.

**Deep reference:** `docs/20_Offers_Pricing_UI_Polish_Notes.md` — Section 3 `Delivery Responsibility`.

---

# 11. RESTAURANT REVIEW REPLY + REPORT

**Status: 🟡 PENDING**

Customer review submission already exists.

Remaining:

- Restaurant can view reviews
- Restaurant can reply
- Customer can see restaurant reply
- Restaurant/admin can report suspicious/fake review
- Admin gets reported-review queue

The schema already reserves `reviews.restaurant_reply` and `reviews.is_reported`.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 3 / Reviews.

---

# 12. RESTAURANT TEMPORARY CLOSURE / HOLIDAY

**Status: 🟡 PENDING**

Existing Open/Close is not enough for:

```text
Closed today
Closed until tomorrow 10 AM
Holiday: 15 Aug
Closed for 3 days
Recurring weekly closure
```

Need proper temporary closure / holiday scheduling.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 1 / Restaurant Management.

---

# 13. RESTAURANT FINANCE / SETTLEMENT

**Status: 🟡 BUILT 2026-08-22 (Phase C items 20-23), NOT build/device-verified**
> Corrected from 🔴 MAJOR PENDING per section 34's rule — re-check
> actual source before trusting an old status line. Migration 38 +
> `lib/commission.php` + `lib/ledger.php` +
> `backend/admin/{commission-rules,settlements,platform-ledger}.php`
> now cover everything below EXCEPT the two items marked "not wired"
> at the end of this list. See each file's own kdoc for exact scope.

Financial module now covers:

- Commission calculation — ✅ category+area rules (`commission_rules`
  table) with restaurant-flat/platform-default fallback, resolved
  per-order-line by `get_effective_commission_rate()`, per this
  section's own commission-clarification note below (now resolved).
- Current due — ✅ `restaurants.current_due`, signed (see doc 19 §6's
  correction: positive = restaurant owes admin, negative = admin owes
  restaurant), updated by `lib/ledger.php::write_due_ledger_entry()`.
- Append-only restaurant due ledger — ✅ `restaurant_due_ledger`
  (already existed in `01_schema.sql`, nothing wrote to it before this
  session) — now written to via `write_due_ledger_entry()`.
- Payment received ledger entries / settlement history — ✅
  `restaurant_payments` extended (direction, UTR, screenshot, remarks,
  payment_date, settled_by_admin_id) + `admin/settlements.php`'s
  "Settlement History" table.
- Bank details — ✅ `restaurant_bank_details` + admin-editable section
  on `admin/settlements.php`'s detail view (restaurant self-submission
  from the Restaurant App itself was NOT built — admin enters/edits on
  the restaurant's behalf for now).
- UTR/reference, settlement screenshot/receipt — ✅ columns exist and
  the Pay Now form captures UTR/remarks/date; screenshot upload itself
  isn't wired to actual file storage yet (`screenshot_url` column
  exists, form doesn't yet have a file input — small follow-up).
- Admin payment verification — ✅ every Pay Now write goes through
  `record_settlement()`, `status='verified'` since admin enters it
  directly (matches doc 19 §6 — no separate restaurant-self-report →
  admin-approve flow exists, by design, same as the doc specs it).
- Today/weekly/monthly earnings, GST/commission analytics breakdown —
  🔴 still not built — `admin/settlements.php`'s per-restaurant Payout
  screen doesn't yet compute the Total Orders/Cash Collected/Online
  Collected/GST columns doc 19 §6 describes; only the ledger statement
  + Pay Now + bank details landed this session.
- Financial audit trail — ✅ every write in this session's new files
  goes through the existing `write_audit_log()`.
- Platform-wide cash ledger ("total kitna aaya, kaha kaha gaya") — ✅
  `platform_ledger` table + `admin/platform-ledger.php` (doc 19 §6b's
  Total In/Out/Net/Revenue + reconciliation check + full entry list).

**NOT WIRED (two items, both genuinely blocked on other unbuilt
pieces, not on anything in this section):**
1. `lib/ledger.php::record_cod_order_ledger_entry()` — ready, but not
   called anywhere. The codebase has no order status transition past
   `'ready'` yet (rider assignment/delivery is Phase G, recall.md
   items 43-48, not built) — writing a `commission_cod` entry at
   ORDER CREATION instead would be wrong (a placed COD order can still
   be rejected/cancelled before any cash changes hands).
2. `lib/ledger.php::record_paid_order_ledger_entries()` — 🟢 now wired,
   2026-08-23. Called from `PaymentService::promoteOrderIfNeeded()`
   (`lib/payment/PaymentService.php`) the moment an admin approves a
   UPI payment on `admin/payment-pending.php` (or a customer's next
   10-second poll observes an already-approved transaction) — see
   `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md`.
   Not device-verified yet (no PHP CLI/live DB in this sandbox).

Until those two land, every restaurant's ledger will show nothing
except manually-recorded Pay Now settlements — that part IS fully
live today, independent of both gaps above.

Do not implement this as a single mutable `due` number. ✅ Followed —
`current_due` is a cache kept in sync by ledger writes, never written
to directly by anything except `write_due_ledger_entry()`.

The existing architecture already points toward:

```text
restaurant_due_ledger        ✅ now written to (this session)
restaurant_payments          ✅ now written to (this session)
restaurant_bank_details      ✅ created + admin UI (this session)
platform_ledger              ✅ created + admin UI (this session)
```

## ⚠️ IMPORTANT CLARIFICATION (app owner, 2026-08-21) — commission is NOT one flat number

**RESOLVED 2026-08-22.** Owner's follow-up decision (2026-08-22): make
it **category-specific AND area-specific**, filter-style — a flat
default that admin can override for a specific category, a specific
area, or both together, "jese big apps karte hain". Built exactly as
`commission_rules` (migration 38): optional `food_category_id` +
optional `area_id` (at least one required), most-specific-wins
priority (category+area → category-only → area-only → restaurant's
existing flat `commission_percent` → platform default). Managed at
`admin/commission-rules.php`. `order_items.commission_percent` /
`commission_amount` now exist and are populated per-line by
`price_cart()` — the exact two gaps this note originally flagged.

One judgement call made without an explicit owner spec: a menu item
can carry more than one `food_categories` tag (many-to-many via
`menu_item_categories`) — when two matching rules tie at the same
specificity, `get_effective_commission_rate()` picks the **higher**
rate. Flag to the owner if a different tie-break (lowest, or requiring
single-category-per-item) is actually wanted.

**Deep references:**
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 2 / Payments & Settlement
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Payout / Settlement System, §6b Platform Ledger
- `docs/01_Database_Schema.md` — Financial / Ledger Tables
- `backend/sql/38_migration_commission_rules_and_settlement.sql`,
  `backend/lib/commission.php`, `backend/lib/ledger.php` — this
  session's build

---

# 14. ADMIN ORDER CONTROL

**Status: 🟡 PENDING**

Admin should be able to:

- Search orders
- Filter by area
- Filter by restaurant
- Filter by customer
- Filter by payment method/status
- View complete order timeline
- View order status history
- Manually update order status where permitted
- Record admin actor in audit history

Reference:
`docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Order Control.

---

# 15. ADMIN RESTAURANT / CUSTOMER / RIDER MANAGEMENT

**Status: ✅ DONE 2026-08-21 (Restaurant + Customer halves) — verified live-tested by app owner, see done.md**

`backend/admin/restaurants.php` (search/filter/full status lifecycle/
area assignment/commission override/soft delete) and
`backend/admin/customers.php` (search/filter/view profile+addresses+
orders/suspend/soft delete) now exist. Needs a live click-through
before this can move to ✅ DONE — see done.md's 2026-08-21 entry for the
exact test checklist.

Still genuinely pending: everything Rider-side below (deferred to
Phase G per item 24), CSV export, wallet adjustment (blocked on item 18
not existing yet), and bulk actions.

Admin must eventually support:

### Restaurants

- Search/filter
- Approve
- Reject
- Suspend
- Reactivate
- View profile
- View documents
- View orders
- View ratings
- View commission/due
- Set/override allowed operational options
- Area assignment

### Customers

- Search
- View profile
- View orders
- View addresses
- Suspend/ban
- Soft delete
- Wallet adjustment once wallet exists

### Riders

- Approve/reject
- Suspend
- View documents
- View orders
- View live status
- View earnings
- Assign/unassign when rider system exists

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Super Admin module sections.

---

# 16. ADMIN CATEGORY MANAGEMENT

**Status: ✅ DONE 2026-08-21 — verified live-tested by app owner, see done.md**

`backend/sql/32_migration_restaurant_categories.sql` + `backend/admin/
categories.php` now exist. Two tabs, kept deliberately separate per the
distinction this section already drew:

- **Restaurant Types** tab — new `restaurant_categories` table (Cafe/
  Bakery/Sweet Shop/Pharmacy/Grocery/Restaurant, seeded) + new additive
  `restaurants.restaurant_category_id` FK. Nothing yet actually sets
  this on existing restaurants or surfaces it in `restaurants.php`'s
  own list/filter UI — that's a small follow-up, not done here.
- **Food Categories** tab — CRUD on top of the *existing* `food_categories`
  table (migration 05), which until now was DB-seeded only with no admin
  UI. Schema itself unchanged.

Both: add/edit/deactivate/hard-delete (delete only when nothing
references the row — menu items for Food Categories, restaurants for
Restaurant Types — otherwise blocked with a count, same pattern as
areas.php's delete guard). No new RBAC migration needed — `categories_view`/
`categories_edit`/`categories_delete` were already seeded in migration 29,
just unused until now.

Needs migration 32 run on the live DB and a live click-through (both
tabs: add/edit/deactivate/delete-blocked/delete-empty) before this can
move to ✅ DONE.

Important distinction:

### `restaurant_categories`
Business type:

```text
Cafe
Bakery
Sweet Shop
Pharmacy
Grocery
Restaurant
```

### `food_categories`
Customer Home / food type:

```text
Pizza
Burger
Biryani
Rolls
Samosa
```

`food_categories` are **admin-managed only**.
Restaurants may select existing tags for their menu items but must NOT create
new Home categories.

Admin needs:

- Add category
- Edit category
- Deactivate category
- Icon/image management
- Ordering/priority if needed

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Category Management.

---

# 17. ADMIN BANNER MANAGER

**Status: ✅ DONE 2026-08-21 — verified live-tested by app owner, see done.md**

`backend/sql/33_migration_banners.sql` (new `banners` table, exact
schema from doc 19 §5) + `backend/admin/banners.php` now exist:

- Add/edit banner with image upload (5 MB cap, jpg/png/webp, same
  validation as `backend/api/v1/restaurant/banner-upload.php`)
- Banner type (Home/Offer/Festival/Popup), deep link, priority
- Start/end date (validated start ≤ end)
- **Area targeting** — empty = platform-wide, or scoped to one
  City/Village or Area node (same "both levels assignable" reasoning as
  `restaurants.php`'s area-assign dropdown)
- Deactivate, and hard delete (also best-effort removes the image file
  from disk)

**Explicitly NOT this session's scope:** the actual customer-facing
banner-fetch endpoint that would filter `WHERE area_id IS NULL OR
area_id = :customer_area_id` — this page only manages the `banners`
table. Wiring that into the customer app depends on item 3 (address →
area resolution), which is also still pending. Until that exists,
banners created here aren't actually served to any app yet — this is
admin-side management only.

**2026-08-22 — customer-facing wiring done**, WITHOUT waiting on full
item 3 (which is still pending as a proper `customer_addresses.area_id`
column + resolution job). Instead, `home/promo-banners.php` now resolves
area on the fly per-request from whatever `lat`/`lng` the Customer App's
currently-active address already carries (`ActiveAddressManager`),
using the same `resolve_service_area()` nearest-within-radius rule
areas.php's "Test coordinates" tool uses (now pulled into
`lib/geo.php` as a shared helper) — no schema change needed for this
piece. `banners` rows (`area_id IS NULL` or matching the resolved node
or its immediate parent) are merged into the existing promo carousel
response alongside the legacy `promo_banners` rows; the Customer App's
`PromoBannerAdapter`/ViewPager2 carousel needed no changes since both
sources are mapped onto the same existing `PromoBanner` shape.
`banners.deep_link` (previously undefined free text) now has an actual
convention — `restaurant:<id>`, `category:<slug>`, or a full `https://`
URL — documented on the field in `banners.php` and implemented in
`deep_link_to_target()` inside `promo-banners.php`. Coupon deep-links
are NOT supported by this convention yet (flagged in the field's own
hint text) — the carousel's target_type contract only has
none/restaurant/category/url, adding a coupon type would need Kotlin-
side changes too, out of scope for this pass.

**2026-08-22 (later) — `customer_addresses.area_id` now actually
populated:** `backend/api/v1/customer/addresses.php` resolves and
stores `area_id` (nearest `resolve_service_area()` match, or NULL if
unresolved) on every address add/edit, using the address's own
lat/lng — no schema change needed since the column has existed since
migration 30. `backend/scripts/backfill-address-areas.php` (new,
`--force` flag re-resolves everything, default only fills existing
NULLs) backfills addresses saved before this change. `admin/
customers.php`'s address list now shows each saved address's resolved
area breadcrumb (or "area: unresolved"). **This is deliberately
additive, not a replacement** for the per-request on-the-fly resolves
in `list.php`/`promo-banners.php`/`cod_rules.php` — those resolve
whatever the customer's currently-ACTIVE location is (which may be
live GPS, not a saved address), so they keep working exactly as
before. The persisted column exists for admin/support/analytics
lookups against a specific saved address, not as the new source of
truth for live serviceability checks.

**Not yet run/verified:** the backfill script (no PHP CLI or live DB in
this sandbox, same standing limitation as every other session) — needs
running once on the live DB, then a spot-check of a few addresses with
known real-world locations against the breadcrumb `customers.php` now
shows for them. Also needs one live add/edit-address test to confirm
`area_id` gets set (and correctly NULL for a location with no
service_areas coverage yet).

Needs migration 33 run on the live DB and a live click-through (add
with image, edit, replace image, area-scoped vs platform-wide,
deactivate, delete) before this can move to ✅ DONE.

**2026-08-21 (same day), app owner request — crop preview added:** the
Add/Edit form now shows a draggable 3:1 crop-preview frame right under
the file picker as soon as an image is chosen — whatever's inside the
frame is what actually gets saved/shown, whatever's outside is cropped
away (dragging repositions which part that is). 3:1 was chosen to match
the customer app's existing home promo-banner slot
(`activity_home.xml`'s `promoBannerImage`, 120dp tall inside a
match_parent-wide container) since that's the closest real reference
point for what a platform banner will eventually render into — **not
confirmed against an exact design spec**, flag if the app owner has a
different target ratio in mind.

Implementation: pure client-side JS computes the crop rectangle (in the
original image's own pixel coordinates) as the admin drags; on submit,
`save_banner_image()` in banners.php uses GD (`imagecreatetruecolor` +
`imagecopyresampled`) to actually crop server-side before saving —
never trusts the browser's numbers blind, clamps them against the
real uploaded file's actual dimensions first. Falls back to saving the
untouched original if GD isn't available on the server, a specific
format has no GD decode function there (WebP GD support isn't
universal), or no crop rect was sent at all (e.g. editing without
replacing the image) — a banner without a perfect crop beats a failed
upload.

---

# 18. CUSTOMER WALLET

**Status: 🟡 BACKEND + ADMIN BUILT 2026-08-23, NOT device/build-
verified. Checkout/wallet-payment integration deliberately NOT
built — see below.** Migration 43 (`customer_wallets` +
`wallet_transactions` append-only ledger — same pattern as
`restaurant_due_ledger`/`refunds`, `balance_after` snapshotted per row
so the balance is always reconstructable, not just trusted), RBAC keys
(`wallets_view`/`wallets_manage`), `orders.payment_method` widened to
accept `'wallet'`, `notifications.type` widened to accept `'wallet'`.
`backend/lib/wallet.php` — single write-path (`credit_wallet()`,
`debit_wallet()`, both row-locking via `SELECT ... FOR UPDATE` so two
concurrent balance changes on the same wallet serialize instead of
racing off a stale read, same race class as bugs.md #1.3's coupon fix)
+ `get_wallet_balance()`/`list_wallet_transactions()` reads +
`debit_wallet_for_order()` — that last one is written but has **no
caller yet**, see below. `backend/api/v1/customer/wallet.php` — read-
only `GET` (balance + last 50 transactions), no customer-initiated
top-up in v1 (nothing in this section's own feature list asks for
one). `backend/admin/customers.php` — each customer's existing detail
modal now shows their wallet balance + last 5 transactions, and (gated
on `wallets_manage`) a credit/debit adjustment form (the "Admin
adjustment" feature) — reused that file's existing modal-per-row UI
rather than building a separate wallet-lookup screen from scratch,
since it already anticipated this ("nothing to adjust until that
table exists" — now it does).

**v1 scope decision, made explicit since doc 19 §12 flagged it as an
open, unresolved question:** wallet-as-full-payment-method only, no
split/hybrid payment — mirrors the same "doesn't model partial
payments" constraint this codebase already applies twice (doc 23 §A5
for UPI, migration 42 for refunds). A wallet order would need to be
covered entirely by wallet balance or not placed at all.

**Deliberately NOT built this session — checkout integration:**
`debit_wallet_for_order()` exists in `lib/wallet.php` but nothing
calls it. To actually let a customer pay with wallet at checkout, a
follow-up session needs: `orders/create.php` accepting `'wallet'` as
`$paymentMethod` and calling `debit_wallet_for_order()` inside the
same insert transaction (refusing the order on insufficient balance,
same shape as the existing COD-eligibility check); `PaymentMethodsResult`
gaining a `walletAllowed`/balance-check field so `CheckoutActivity`
can show/hide a wallet radio option; and a customer-app Wallet
balance/history screen (Profile → menu row, same data-driven list
`ProfileActivity.kt` already uses) that also doesn't exist yet — no
Android/Kotlin was touched this session, backend + admin only, same
"build in parallel, flag the UI gap" framing as item 25's first
session. **Also not built:** `lib/refunds.php`'s reserved
`method='wallet'` path (refund straight to wallet instead of manual
bank transfer) — `credit_wallet()` now exists and is what that hookup
would call, but `complete_refund()` still only handles
`manual_upi_bank_transfer`; wiring that is a small, well-scoped
follow-up once wallet is otherwise verified. **Cashback**: the
`wallet_cashback_expiry_days` setting is stored (migration 43) but
nothing computes or grants cashback yet, and nothing expires a credit
— no cashback-granting trigger point or expiry job exists anywhere in
this codebase to hook into.

**Not device/build-verified** — same standing sandbox limitation (no
PHP CLI/live MySQL here). See the NEXT SESSION PROMPT at the bottom of
this file for the verification checklist.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Wallet; `docs/21_Production_Feature_Gap_Plan.md` — Customer Wallet.

---

# 19. REFUND SYSTEM

**Status: 🟡 BUILT 2026-08-23, NOT device/build-verified.** Migration
42 (`refunds` table + `refunds_view`/`refunds_manage` RBAC keys),
`backend/lib/refunds.php` (single write-path: `create_refund_request()`,
`approve_refund()`, `mark_refund_processing()`, `complete_refund()`,
`reject_refund()`), `backend/admin/refunds.php` (the review queue,
same "admin does the manual transfer, this system tracks it" model as
`admin/payment-pending.php` — there is no gateway refund API;
`UpipeProvider::refund()` already documents that). Wired into the two
real trigger points that previously left a UPI-paid order's money
unaccounted for on cancellation: `orders/cancel.php` and
`restaurant/orders-reject.php` now auto-create a `requested` refund
whenever `payment_status === 'paid'` at cancel/reject time.
`format_order()` (`lib/orders.php`) now returns a `refund` object
(amount/reason/status/method/reference/expected date/timeline) inline
on every order so the customer app doesn't need a separate endpoint.
**v1 scope decision, made explicit since nothing upstream pinned it
down:** full-refund-only (one `refunds` row per order,
`UNIQUE(order_id)`) — mirrors doc 23 §A5's "this design doesn't model
partial payments" on the payment side. `method` only ever writes
`manual_upi_bank_transfer` today; `wallet` is reserved in the ENUM for
when item 26 (Customer Wallet) lands, nothing writes it yet.
**Not wired:** a customer-initiated "request a refund" self-service
button — that needs Support/Ticket System (item 20, still 🔴) as a
guard against abuse first; v1 only auto-creates a refund from the two
trigger points above. **Not device/build-verified** — same standing
sandbox limitation (no PHP CLI/live MySQL here) as everything else in
this repo's recent sessions. See the NEXT SESSION PROMPT at the bottom
of this file for the exact verification checklist.

Required lifecycle:

```text
Refund Requested
      ↓
Under Review
      ↓
Approved
      ↓
Processing
      ↓
Refunded
```

Customer should see:

- Amount
- Reason
- Method
- Expected date
- Reference
- Timeline

Refunds must reconcile with payment transactions / platform ledger.

**Deep reference:** `docs/21_Production_Feature_Gap_Plan.md` — Customer Refund System and payment/reconciliation sections.

---

# 20. CUSTOMER SUPPORT / TICKETS

**Status: 🔴 PENDING**

Profile → Help & Support should support:

- Order issue
- Missing item
- Wrong item
- Food quality
- Delivery issue
- Payment issue
- Refund issue
- Account issue
- Coupon issue
- General issue

Ticket requirements:

- Ticket ID
- Order association
- Conversation/chat
- Attachment/photo
- Status
- Admin assignment
- Resolution/closure

**Deep reference:** `docs/21_Production_Feature_Gap_Plan.md` — Customer Support / Ticket System.

---

# 21. SUPPORT AI CHAT

**Status: 🔴 PENDING**

Future support layer:

```text
Customer
   ↓
AI Support Chat
   ↓
Anydrop backend proxy
   ↓
Gemini / Claude / GPT provider
```

AI must not directly receive unrestricted database credentials or private backend
secrets. The backend should expose only the data/tools required for support.

This should sit on top of the normal ticket/support architecture rather than
replacing it.

---

# 22. RESTAURANT ANALYTICS

**Status: 🔴 PENDING / INSIGHTS UI ≠ COMPLETE ANALYTICS**

Required reporting:

- Sales graph
- Peak hours
- Top-selling foods
- Repeat customers
- Order success/cancel rate
- Revenue report
- Export PDF/Excel
- Date filters
- Area filters where applicable

Raw order data already exists; this is a real reporting module, not just a
screen placeholder.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 2 / Analytics; `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Analytics & Reports Module.

---

# 23. RESTAURANT STAFF / RBAC

**Status: 🔴 PENDING — SEPARATE PHASE**

Support multiple users per restaurant:

```text
Owner
Manager
Kitchen
Cashier
```

with permissions.

This requires a proper `restaurant_staff` model and an audit of all restaurant
endpoints because the current architecture assumes one authenticated owner /
restaurant relationship.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 4 / Staff Management.

---

# 24. RIDER APP

**Status: 🔴 NOT STARTED / DEFERRED TO LAST PHASE**

Rider system should eventually include:

- Rider signup/onboarding
- Admin approval
- Document verification
- Online/offline status
- Order assignment
- Accept/reject delivery
- Pickup navigation
- Customer navigation
- Live GPS updates
- Delivery OTP
- Pickup confirmation
- Delivery completion
- Rider earnings
- Rider history
- Rider support
- Restaurant/customer contact options

Do not build isolated restaurant-side rider features before the actual Rider App
architecture exists.

**Deep references:**
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Rider App deferred to Phase K
- `docs/03_Live_Tracking.md` — full live tracking architecture
- `docs/01_Database_Schema.md` — rider/order/location tables

---

# 25. LIVE RIDER TRACKING

**Status: 🔴 PENDING — PART OF RIDER PHASE**

Planned architecture:

```text
Rider App
   ↓ GPS ping every few seconds
Backend
   ↓
riders.last_lat / last_lng
   ↓
Customer tracking screen
```

Additional pieces:

- Smooth marker animation
- Route line
- ETA
- Battery-aware ping intervals
- Foreground location service
- Rider offline handling
- Location history/audit retention

**Deep reference:** `docs/03_Live_Tracking.md`.

---

# 26. GOOGLE LOGIN

**Status: 🔴 VERIFY / COMPLETE ONLY IF REAL BACKEND FLOW EXISTS**

Customer Google login must not be considered complete if the UI only shows a
button or Coming Soon state.

Required:

```text
Google Sign-In
   ↓
ID token / credential
   ↓
Backend verification
   ↓
Customer account create/login
   ↓
Session
```

If the current backend still contains the old "not implemented" path, this
feature remains pending.

---

# 27. PAYMENT PROVIDER ARCHITECTURE

**Status: 🟡 ARCHITECTURE / 🔴 REAL PROVIDER INTEGRATION PENDING**

The system should use a provider interface so UPI/payment provider changes do
not require rewriting order processing.

Required concepts:

- Payment provider registry
- Provider priority
- Enable/disable
- Initiate
- Verify
- Refund
- Transaction records
- Provider transaction ID
- Raw response storage
- Payment status
- Admin test mode

UPIPE can remain a stub until real SDK/API credentials/source are available,
but the order flow should depend on `PaymentService`, not directly on a
provider implementation.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Payment Provider Architecture.

---

# 28. PAYMENT / REFUND RECONCILIATION

**Status: 🔴 PENDING FOR PRODUCTION**

Need reconciliation between:

```text
Order
Payment Transaction
Platform Ledger
Restaurant Due Ledger
Settlement
Refund
```

Future provider webhooks should update the authoritative transaction state.

Do not mark a payment successful solely from a client-side success callback.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` and `docs/21_Production_Feature_Gap_Plan.md`.

---

# 29. PRODUCTION SECURITY HARDENING

**Status: 🟡/🔴 PENDING**

Before real public scale, verify and harden:

- OTP rate limiting
- OTP abuse protection
- Order idempotency
- Coupon/discount race-condition protection
- Server-side price validation
- Restaurant operational-state validation
- Payment transaction safety
- Refund authorization
- Admin RBAC
- Audit logs
- Sensitive financial-data protection
- Backup strategy
- Restore testing
- Error logging
- Production secrets/configuration

Reference:
- `docs/security.md`
- `docs/21_Production_Feature_Gap_Plan.md`

---

# 30. EMAIL OTP PROVIDER SYSTEM

**Status: 🟡 ARCHITECTURE / IMPLEMENTATION VERIFY**

Planned provider registry:

```text
EmailOtpService
   ↓
Provider 1
Provider 2
Provider 3
...
```

Requirements:

- Provider priority
- Enable/disable
- Daily/monthly quota
- Automatic fallback
- Failure logging
- Test send button
- Usage dashboard

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Email OTP architecture.

---

# 31. ADMIN ANALYTICS / REPORTS

**Status: 🔴 PENDING**

Admin reports should support:

- Date range
- State
- District
- City
- Area
- Restaurant
- Restaurant category
- Order status
- Payment method

Reports:

- Order analytics
- Revenue
- Commission
- Area analytics
- Restaurant performance
- Top restaurants
- Top selling items
- Customer analytics
- Rider analytics
- Payment analytics
- Coupon analytics

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Analytics & Reports Module.

---

# 32. FEATURES THAT SHOULD NOT BE REOPENED AS PENDING

If the owner has already built and device-tested the latest build, do NOT reopen
these merely because old status/history documents contain older TODO entries:

- Customer core login/OTP
- Home/feed core
- Restaurant listing/detail
- Menu browsing
- Item customization/add-ons
- Cart
- Checkout core
- Coupons core
- Scheduled orders
- Order history
- Reorder core
- Address core
- Restaurant menu CRUD
- Restaurant order management
- Restaurant Open/Close
- Preparation-time selection
- New-order alert sound
- Restaurant coupon management
- Banner/photo upload work already present
- Notification bell work already present
- Ratings/review submission

Always prefer the **latest source code + latest owner-confirmed test result** over
old historical status entries.

---

# 33. RECOMMENDED BUILD ORDER

Do NOT randomly pick features.

## Phase A — Admin Foundation

1. Admin authentication/session
2. RBAC/permissions
3. Dashboard
4. Service Area Management
5. Restaurant/Customer management
6. Food category management
7. Restaurant category management
8. Banner manager

## Phase B — Area-Based Platform Rules

9. Customer address → area resolution — 🟡 done 2026-08-22 (persisted `area_id` column now populated; live consumers still resolve on the fly by design)
10. Area-wise restaurant visibility
11. Area-wise delivery radius rules
12. Area-wise COD rules
13. Area-wise minimum order — 🟡 built 2026-08-22, not device-verified (section 4a)
14. Area-wise delivery fee — 🟡 built 2026-08-22, not device-verified (section 4a)
15. Area-wise payment restrictions — 🟡 built 2026-08-22, not device-verified (section 4b)
16. Area-wise banner targeting — 🟡 built, not device-verified (section 5/17 — status line corrected 2026-08-22, was wrongly showing PENDING)
17. No-restaurant state — 🟡 built, not device-verified (section 6 — status line corrected 2026-08-22, was wrongly showing PENDING)
18. Location-off + saved-address fallback — 🟡 done 2026-08-22 (section 7, Case B — not device-verified)
19. Add-address/location-picker fallback — 🟡 core flow + map pin-drop wiring exist (section 8); genuinely blocked on the app owner setting up a real Google Maps API key/billing (`google_maps_key` in strings.xml is still a placeholder) — not Claude-buildable code work until that exists. Backend-proxied reverse geocoding (vs. current on-device Geocoder) is the one remaining code gap, but it needs the same server-side Maps key, so building it now would be untestable. **Phase B has no further Claude-actionable pending code item as of 2026-08-22** — everything left in Phase B is either (a) device/build verification only, or (b) blocked on the owner's Maps billing setup.

## Phase C — Money

20. Platform ledger — 🟡 built 2026-08-22, not device-verified (section 13 — `platform_ledger` table + `admin/platform-ledger.php`)
21. Restaurant due ledger completion — 🟡 built 2026-08-22, not device-verified (section 13 — `write_due_ledger_entry()`, Pay Now flow live; the two order-triggered auto-writers exist but aren't called anywhere yet, see section 13's "NOT WIRED" list)
22. Restaurant bank details — 🟡 built 2026-08-22, not device-verified (section 13 — `restaurant_bank_details` table + admin-editable UI on `settlements.php`; restaurant App self-submission not built)
23. Settlement/payout UI — 🟡 built 2026-08-22, not device-verified (section 13 — `admin/settlements.php`: ledger statement + Pay Now + settlement history; the Total Orders/Cash Collected/GST analytics columns doc 19 §6 describes are NOT built yet)
24. Payment transaction architecture — 🟡 built 2026-08-23, not device-verified (`docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md` — migration 40, `lib/payment/PaymentService.php` + rewritten `UpipeProvider.php` (native UPI-QR, manual-admin verification, no external gateway call), 3 customer APIs under `api/v1/orders/payment-upi-*.php`, `admin/payment-gateways.php` + `admin/payment-pending.php`. `record_paid_order_ledger_entries()` is now actually called — see the item-21 update below.) **Update (same day, follow-up session):** the Android payment screen (`UpiPaymentActivity.kt`) and its "Cancel and pay by COD" button turned out to already be written (contradicting the doc's own §A4 note that customer-app UI wasn't built yet — doc corrected). That button was found to be fake (toast only, never touched the backend) and is now real: new `backend/api/v1/orders/payment-switch-cod.php` (`POST /orders/{id}/payment/switch-to-cod`), reusing `orders/create.php`'s exact payment-restriction + COD-eligibility function pair so the same rules apply. See doc 23's new §A4b for the full detail. Still 🟡 not device/build-verified.
25. Refund system — 🟡 built 2026-08-23, not device-verified (section 19 — migration 42, `lib/refunds.php`, `admin/refunds.php`; wired into `orders/cancel.php` + `restaurant/orders-reject.php`'s payment_status='paid' gap)
26. Wallet — 🔴 pending
27. Reconciliation — 🟡 partially built 2026-08-22 — `platform-ledger.php`'s reconciliation check (Net Balance Held vs `-1×SUM(current_due<0)`) is live per doc 19 §6b, but there's no periodic automated check yet (doc calls for one flagging Super Admin on drift) — only the on-page manual check exists.

## Phase D — Offers

28. Restaurant Offers Engine — 🟡 backend + admin built 2026-08-24, NOT
    device/build-verified; Android (Restaurant App create/manage screen,
    Customer App offer display) NOT built yet. See docs/29 for full
    scope/handover.
29. Combo pricing — 🔴 explicitly deferred (see docs/29 — needs a
    multi-item bundle model item 28's schema doesn't cover)
30. Free delivery offers — 🟡 built as part of item 28 (offer_type='free_delivery')
31. Happy hours — 🟡 built as part of item 28 (start_time/end_time + weekdays on any offer type, not a separate mechanism)
32. Offer eligibility/stacking rules — 🟡 built as part of item 28 (doc 20 §13's "1 item/restaurant offer + 1 coupon + 1 delivery offer" rule, hardcoded not admin-configurable yet)
33. Central server-side pricing engine — 🟡 lib/offers.php + price_cart() integration built as part of item 28; still the same "not device-verified" status as every other pricing path in this codebase

## Phase E — Support / Trust

34. Support tickets
35. Order issue reporting
36. Review replies
37. Review reports
38. Optional AI support layer

## Phase F — Restaurant Operations

39. Temporary closure/holiday
40. Restaurant analytics
41. Staff/RBAC
42. GST/FSSAI/document management

## Phase G — Rider

43. Rider App
44. Admin rider approval
45. Assignment
46. Pickup/delivery workflow
47. Delivery OTP
48. Live tracking
49. Rider earnings
50. Rider analytics

## Phase H — Production Hardening

51. Security hardening
52. Payment webhook/reconciliation
53. Backup/restore
54. Load/performance checks
55. Full end-to-end production regression

---

# 34. RULE FOR CLAUDE CODE

Before starting any new task:

1. Read this `recall.md`.
2. Read the referenced deep `.md` document for that feature.
3. Inspect the current source code.
4. Determine whether the feature is actually missing, partially implemented,
   or already complete.
5. Do not trust an old `Status.md` TODO blindly.
6. Do not mark a feature "done" merely because a screen exists — verify the
   backend/data flow when the feature requires it.
7. Keep business rules in backend/Admin configuration wherever the requirement
   says "Admin controlled".
8. Never hardcode area-specific rules such as Osian/COD/minimum prepaid orders
   inside the Customer App.
9. For pricing, backend is the single source of truth.
10. After completing a feature, update the relevant deep reference document and
    this recall/status state so the same task does not return as "pending" in a
    future session.

---

# 35. DEEP REFERENCE INDEX

| Feature | Deep reference |
|---|---|
| Overall feature history | `docs/features.md` |
| Restaurant full scope | `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` |
| Admin + Area + Banner + Payment architecture | `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` |
| Restaurant Offers Engine | `docs/20_Offers_Pricing_UI_Polish_Notes.md` (product spec) + `docs/29_Handover_2026-08-24_Offers_Engine_Backend_Built.md` (what's actually built vs pending) |
| Production gaps | `docs/21_Production_Feature_Gap_Plan.md` |
| Live Rider Tracking | `docs/03_Live_Tracking.md` |
| Database / ledger architecture | `docs/01_Database_Schema.md` |
| Security | `docs/security.md` |
| Location picker / map pin-drop | `docs/features.md` + `docs/12_Handover_H6_Map_PinDrop_Photo.md` |
| Restaurant known issues | `docs/restorent/20_Known_Issues_And_UX_Fixes.md` |
| Current restaurant status history | `docs/restorent/00_Status.md` |
| Native UPI Payment Gateway | `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md` (incl. Addendum + §A5 anti-fraud hardening) |

---

## NEXT SESSION PROMPT — Native UPI Payment Gateway + Refund System + Customer Wallet (2026-08-23, updated same day → next)

Paste this at the start of the next session to pick up exactly where
this one left off:

> Three Phase C features are now built and all are 🟡 **NOT
> device/build-verified** — no PHP CLI, live MySQL, or Android
> Studio/emulator exists in this sandbox, same standing limitation
> every session here has had. Read
> `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md`
> (Addendum, §A4b, §A5) + recall.md item 24 for the UPI gateway,
> recall.md section 19 (item 25) for the Refund System, and recall.md
> section 18 (item 26) for the Customer Wallet, before touching any
> of them.
>
> **A. Native UPI Payment Gateway (item 24) — unchanged from last
> session, still needs:**
> 1. Run migrations 40 and 41 on a real DB, fix any SQL errors that
>    surface (ENUM widening + the two ALTER TABLEs haven't been
>    executed against real MySQL yet).
> 2. Open the project in real Android Studio, resolve the ZXing
>    Gradle dependency, and confirm `UpiPaymentActivity` actually
>    compiles against the generated `ActivityUpiPaymentBinding` (view
>    IDs were hand-matched between the XML and the Kotlin file but
>    never compiler-checked).
> 3. Full live click-through per doc 23 §11 / the Addendum: place a
>    UPI order → confirm the QR that renders is genuinely scannable
>    by a real UPI app → submit a UTR after the window opens → approve
>    it from `admin/payment-pending.php` (including a deliberate
>    amount-mismatch attempt, to confirm §A5's block actually fires)
>    → confirm the order flips to paid, the ledger entries land, and
>    the customer's poll reaches `success` and navigates to
>    `OrderStatusActivity`.
> 4. Live click-through the switch-to-COD flow too (doc 23 §A4b):
>    place a UPI order, tap "Cancel and pay by Cash on Delivery
>    instead," confirm the dialog, confirm the order flips to
>    `payment_method='cod'` and any outstanding `payment_transactions`
>    row is voided out of `admin/payment-pending.php`'s queue — then
>    separately confirm the rejection path by testing it against an
>    area/customer that fails the COD gate (area COD disabled, or a
>    new-customer COD block) and confirming the on-screen reason
>    matches `cod_rules.php`'s reason codes.
>
> **B. Refund System (item 25) — new this session, needs:**
> 5. Run migration 42 on a real DB (after 40/41 — it doesn't depend on
>    them, but keep migrations in numeric order), fix any SQL errors
>    that surface. Confirm the `refunds_view`/`refunds_manage` grant
>    to Super Admin actually landed (`admin_role_permissions` join in
>    the migration's last statement — the only part of this migration
>    with a join instead of a plain insert, worth eyeballing first).
> 6. Live click-through: place + pay a UPI order (needs A.3 done
>    first, or manually flip `payment_status='paid'` on a test row to
>    unblock this independently) → cancel it as the customer within
>    the cancel window → confirm a `requested` row appears in
>    `admin/refunds.php` → Approve → Mark Processing (enter a test
>    UTR) → Mark Refunded → confirm `orders.payment_status` flips to
>    `'refunded'`, `payment_transactions.status` flips to `'refunded'`,
>    and a `platform_ledger` `refund_out` row lands with the right
>    sign. Also test: Reject from the `requested` state, Reject from
>    the `approved` state, and the restaurant-reject trigger path
>    (`restaurant/orders-reject.php` on a pending-but-already-paid
>    order) separately from the customer-cancel trigger path.
> 7. Confirm `format_order()`'s new `refund` object actually appears
>    correctly in the order-status API response at each lifecycle
>    stage, AND — updated 2026-08-23 follow-up #3 — confirm
>    `OrderStatusActivity`'s new `refundCard` (see `renderRefund()`/
>    `renderRefundTimeline()`) actually renders it correctly on-device
>    at each stage: label+color (requested/under_review/approved/
>    processing all info-blue, refunded green, rejected red), amount,
>    reason vs. `reject_reason` swap, expected-by date hiding once
>    refunded/rejected, reference showing only once set, and the
>    timeline list growing one row at a time. Also confirm it appears
>    live (not just on next screen open) right after cancelling a paid
>    order — `cancelOrder()`'s success path now re-fetches and
>    re-renders it inline. This Kotlin/XML was never compiler-checked
>    (view-binding IDs hand-matched, same gap as `UpiPaymentActivity`)
>    — fold into A.2's Android Studio compile-check step.
>
> **C. Customer Wallet (item 26) — new this session, needs:**
> 8. Run migration 43 on a real DB (after 40/41/42 — doesn't depend on
>    them, keep numeric order), fix any SQL errors. Confirm the
>    `wallets_view`/`wallets_manage` grant to Super Admin landed (same
>    `admin_role_permissions` join pattern as migration 42's grant —
>    this migration's is keyed off `refunds_manage` instead of
>    `payment_providers_manage`, worth eyeballing that join condition
>    specifically). Confirm the `orders.payment_method` and
>    `notifications.type` ENUM widenings applied cleanly against a DB
>    that already has real rows in both tables (pure ALTER, should be
>    safe, but this is the first migration in this repo widening an
>    ENUM on a table this central — worth extra care).
> 9. Live click-through on `admin/customers.php`'s new wallet section:
>    open a customer's detail modal → confirm balance shows ₹0.00 for
>    a fresh customer → submit a Credit adjustment (amount + required
>    note) → confirm the balance updates in the modal and a new
>    transaction row appears → submit a Debit larger than the current
>    balance → confirm it's refused with the insufficient-balance
>    message and the balance is unchanged (this is `debit_wallet()`'s
>    own guard, not a UI-side check — confirm the guard actually
>    fires against a real DB, not just that the PHP reads correctly).
> 10. Hit `GET /api/v1/customer/wallet` as an authenticated customer
>     (curl/Postman is enough, no Android app needed for this one) and
>     confirm the response balance matches what step 9 set, and the
>     transactions array matches admin/customers.php's view of the
>     same history.
> 11. Confirm `credit_wallet()`'s notification actually lands in
>     `notifications` with `type='wallet'` (the ENUM widening from
>     step 8) and shows up via the existing notification-bell endpoint
>     — first real test of a notification type added specifically for
>     this feature, not reused from an existing type.
>
> **D. Wallet checkout integration — NOT built this session, real
> follow-up work, not just verification:**
> 12. `orders/create.php` needs a `'wallet'` branch: validate balance
>     via `get_wallet_balance()` covers `$priced['grand_total']`
>     *before* opening the insert transaction (fail fast, same shape
>     as the existing COD-eligibility pre-check), then call
>     `debit_wallet_for_order()` (already written, unused) *inside*
>     the same transaction as the order insert so a debit can never
>     succeed against an order that then fails to insert, or vice
>     versa.
> 13. `PaymentMethodsResult` (customer app) needs a `walletAllowed`/
>     balance field so `CheckoutActivity` can show a wallet radio
>     option only when there's enough balance — mirrors how
>     `codAllowed`/`upiAllowed` already gate the existing two options.
> 14. A customer-app Wallet screen (Profile → menu row, `ProfileActivity
>     .kt`'s existing data-driven `MenuRow` list is the natural spot)
>     reading `GET /customer/wallet` — balance + history, no write
>     actions needed since there's still no customer-initiated top-up.
> 15. Once 12-14 are live: wire `lib/refunds.php`'s reserved
>     `method='wallet'` path — `complete_refund()` should offer a
>     "refund to wallet instead of bank transfer" option that calls
>     `credit_wallet()` (reason='refund', order_id set) instead of
>     writing a `platform_ledger` `refund_out` row, since money moving
>     into the customer's own wallet balance isn't leaving the
>     platform's account the way a real bank transfer does.
>
> **E. Only after A, B, C above (D is a separate, larger follow-up):**
> decide whether to build real-gateway support (Razorpay/Cashfree, doc
> 23 §9), Phase D's Offers Engine, or continue D above.

---

## FINAL REMINDER

**"Test pending" is not automatically "feature pending".**

The owner has already tested the current core builds. The actual remaining
product work should now be tracked from this file and the deep reference docs,
with Admin + Area Control as the central next phase.

**2026-08-23 (follow-up session #5, in progress) — item 26 §D, Wallet
checkout integration, PARTIALLY built: D.12 (orders/create.php wallet
branch) done, D.13/D.14/D.15 NOT done yet.** Re-confirmed the standing
sandbox limitation first (no php/mysql/Android SDK, network egress
403 on both live curl and apt-get install — confirmed fresh this
session, not assumed from prior notes). Owner explicitly chose to
continue Section D over verification or other Phase C/D work.

Built: `backend/api/v1/orders/create.php` now accepts
`payment_method: "wallet"` end to end —
- `in_array` validation widened to include 'wallet'.
- Area-wide payment-restriction gate (migration 37) deliberately left
  NOT gating wallet — commented in place explaining why (a wallet
  debit is the customer's own already-verified balance, not a new
  payment rail the area needs to vet; `area_payment_restrictions` has
  no wallet column so `is_payment_method_allowed_in_area()` already
  returns allowed=true for it by default — verified this is real
  behavior, not an assumption, by reading that function's body).
- New fail-fast pre-check after `price_cart()` succeeds, before the
  insert transaction opens: `get_wallet_balance()` vs
  `grand_total`, `wallet_insufficient_balance` 422 (with balance +
  required amounts) on failure — same shape/placement as the existing
  COD checks.
- Inside the same transaction as the order+items insert:
  `debit_wallet_for_order()` (already existed, unused until now) is
  called right after `insert_status_history('pending', ...)`. This is
  the authoritative row-locked check (its `SELECT ... FOR UPDATE`)
  that actually prevents two concurrent wallet orders from both
  passing the cheap pre-check off the same stale balance — the
  pre-check only avoids the common-case round trip. A debit failure
  here throws `RuntimeException('wallet_insufficient_balance_race')`,
  caught below and mapped to the same `wallet_insufficient_balance`
  422 code the pre-check uses (no second error code for the app to
  learn).
- On successful debit: `orders.payment_status` flipped straight to
  `'paid'` (a wallet order is paid the instant the debit succeeds —
  no separate admin-confirmation step the way UPI needs one), a
  second status-history row ("Paid via Anydrop Wallet"), and
  `record_paid_order_ledger_entries($db, [...])` called inline with a
  hand-built minimal order array (id/order_code/restaurant_id/
  grand_total/commission_amount/platform_fee — the only fields that
  function actually reads, confirmed by reading its body first rather
  than assumed) — same ledger call `PaymentService::
  promoteOrderIfNeeded()` already makes for a confirmed UPI payment,
  now reused for the wallet path so commission/settlement accounting
  doesn't silently skip wallet orders.
- `require_once` added for `lib/wallet.php` and `lib/ledger.php` at
  the top of the file (ledger.php wasn't previously required here —
  `record_paid_order_ledger_entries()` lives there).

**Read but NOT yet touched this session** (needed for D.13-D.15,
next up): `backend/api/v1/customer/payment-methods.php` +
`PaymentMethodsResult` (Models.kt) — need `wallet_allowed`/
`wallet_balance` fields; `CheckoutActivity.kt` +
`activity_checkout.xml` — need a third `radioWallet` option wired
into `applyPaymentMethodRestrictions()`/`placeOrder()`'s existing
cod/upi pattern; `ProfileActivity.kt`'s `MenuRow` list — natural spot
for a new Wallet screen entry (no `WalletActivity.kt` exists yet);
`backend/lib/refunds.php`'s `complete_refund()` / `admin/refunds.php`
— the reserved `method='wallet'` refund-to-wallet path (recall.md
item 15 in the NEXT SESSION PROMPT) still needs its own
`complete_refund_to_wallet()`-shaped function and an admin-UI button,
neither written yet. `OrderStatusActivity.kt`'s existing
`renderRefund()` already handles a `method === "wallet"` label
correctly (built in follow-up session #3, before this refund-to-
wallet backend path exists) — confirmed by re-reading it this
session, nothing further needed there once the backend path is wired.

**Not device/build-verified** — same standing sandbox limitation.
`orders/create.php`'s new wallet branch has not been run against a
real DB; the debit/insert/ledger-write ordering was checked by
careful static reading of `lib/wallet.php` and `lib/ledger.php`'s
actual function bodies (not assumed from their names), same
read-the-code-carefully discipline every other session here has used,
but a live click-through (place a wallet order against a real
`customer_wallets` balance, confirm the debit lands, confirm
`platform_ledger`/`restaurant_due_ledger` rows appear with the right
signs) still needs the same PHP CLI/live-DB environment noted in
every prior session's handover.

**NEXT SESSION should continue with D.13, D.14, D.15 in that order**
(payment-methods.php + PaymentMethodsResult wallet fields first, since
CheckoutActivity's radio option depends on knowing wallet_allowed/
wallet_balance before it can gate anything) — then fold in the item 15
refund-to-wallet wiring (E's admonition to finish D fully before E
still applies) once D itself is complete.

**2026-08-23 (follow-up session #6) — item 26 §D.13 done.** Re-ran the
standing environment check first (no `php`/`mysql`/Android SDK; live
`curl` to an external host returned `403 host_not_allowed` — confirmed
fresh, not assumed). Verified follow-up session #5's D.12 claim
against the actual file before trusting it (`grep` for
`debit_wallet_for_order`/`wallet_insufficient_balance` in
`orders/create.php` — all present exactly as described) before
building on top of it.

Built: `backend/api/v1/customer/payment-methods.php` now also returns
`wallet_allowed` + `wallet_balance` (calls `get_wallet_balance()` from
`lib/wallet.php`, newly `require_once`d there). Documented in-file why
`wallet_allowed` means something different from `upi_allowed`/
`cod_allowed` — wallet has no area-restriction concept
(`orders/create.php`'s own §D.12 comment already established this), so
the flag is really "does this customer have a positive balance worth
showing a radio for," not "is this rail enabled here." A zero-balance
wallet still exists (`get_or_create_wallet()` creates it lazily on
first touch) but `wallet_allowed` stays false until there's something
to spend. `customer/app/.../network/Models.kt`'s `PaymentMethodsResult`
now has matching `walletAllowed: Boolean = false` /
`walletBalance: Double = 0.0` fields (defaults false/0.0 so a stale
cached response or a bare `PaymentMethodsResult()` never shows a
wallet option it can't back up) — mirrors the existing
`upiAllowed`/`codAllowed` `@SerializedName` pattern exactly, ready for
`CheckoutActivity`'s `applyPaymentMethodRestrictions()` to consume in
D.14.

Also fixed one stale doc comment found while in `lib/wallet.php`:
`debit_wallet_for_order()`'s kdoc still said "NOT WIRED — no caller
yet" even though D.12 (previous session) already wired it into
`orders/create.php` — corrected to describe the real current caller
and confirm which check (this function's row-locked one, not
`orders/create.php`'s pre-check) is authoritative.

**Not device/build-verified** — same standing sandbox limitation; the
new PHP was read-checked against `lib/wallet.php`'s actual function
signature (not assumed), and the new Kotlin fields follow the
project's established Gson `@SerializedName` convention exactly, but
neither has been run/compiled.

**NEXT SESSION should continue with D.14** — wire `CheckoutActivity`'s
third `radioWallet` option into `applyPaymentMethodRestrictions()`/
`placeOrder()`'s existing cod/upi pattern (see that file's lines ~379–
398 and ~578 for the two spots the wallet branch needs to mirror), now
that `walletAllowed`/`walletBalance` exist on `PaymentMethodsResult`
for it to read. Then D.15 (Profile → Wallet screen), then fold in the
item 15 refund-to-wallet wiring once D is fully complete.

**2026-08-23 (follow-up session #7) — item 26 §D.14 done.** Re-ran the
standing environment check first — unchanged (no `php`/`mysql`/Android
SDK, network egress `403 host_not_allowed`). Owner said they'd test
later rather than requesting verification this session, so continued
straight to the next item per the file's own queue.

Built: `activity_checkout.xml` gets a third `radioWallet` RadioButton
in the existing `paymentGroup`, starting `visibility="gone"` (XML
comment explains why wallet is hide-when-unavailable rather than
disabled-with-reason like Cod/Upi — a zero-balance wallet isn't a
temporary area restriction the way cod/upi unavailability is, it's "no
balance to show"). `CheckoutActivity.kt`:
- `applyPaymentMethodRestrictions()` now also toggles `radioWallet`'s
  visibility off `paymentMethods.walletAllowed` and, when visible,
  sets its label to a new `pay_wallet_with_balance_format` string
  showing the live balance. If the radio was checked but the balance
  has since dropped to zero (e.g. address switch re-resolved a fresher
  `PaymentMethodsResult`), falls back to Cod-then-Upi rather than
  leaving a hidden radio checked with no fallback.
- New `lastKnownGrandTotal` field, captured in `renderBill()` — same
  "captured from renderBill(), otherwise discarded" shape as the
  existing `lastKnownItemTotal` (H5) — so `placeOrder()`'s wallet
  pre-check can compare the live grand total against
  `paymentMethods.walletBalance` without an extra round trip. Null
  (bill not loaded yet) just skips the amount comparison.
- `placeOrder()`'s `paymentMethod` resolution now checks `radioWallet`
  first (`when` block: wallet → upi → cod). Its defensive re-check
  (recall.md Phase B item 15's existing pattern) grew a wallet branch
  that's amount-aware, not just an allowed-flag check — `walletAllowed`
  alone only means "balance > 0", not "balance covers this order", so
  a mismatched grand total also blocks locally with the new
  `wallet_insufficient_balance_error` string. This stays a fast local
  rejection only — `orders/create.php`'s pre-check + row-locked
  `debit_wallet_for_order()` (§D.12) remain the real guard.
- New `"wallet_insufficient_balance"` branch added to the server-error
  `when` block in the failure path, mapped to the same
  `wallet_insufficient_balance_error` string, for the case where the
  local pre-check was stale (e.g. a second wallet debit landed
  elsewhere between `loadPaymentMethods()` resolving and the tap).
- Post-success navigation needed no change — the existing
  `if (paymentMethod == "upi") { ...UpiPaymentActivity... }` branch
  already falls through to `OrderStatusActivity` for any other
  `paymentMethod` value, which is correct for wallet: per §D.12 a
  wallet order is paid instantly on debit success, no polling/QR step
  needed the way UPI has.
- New strings: `pay_wallet`, `pay_wallet_with_balance_format`,
  `wallet_insufficient_balance_error` in `strings.xml`, next to the
  existing `pay_cod`/`pay_upi`/`payment_method_unavailable_here`.

**Not device/build-verified** — same standing sandbox limitation. XML
view-binding IDs (`radioWallet`) were hand-matched the same way
`UpiPaymentActivity`'s and `OrderStatusActivity`'s were in prior
sessions (doc 23 §11's admitted gap), never compiler-checked. No
live-DB data to sanity-check a real balance render against.

**NEXT SESSION should continue with D.15** — the customer-app Wallet
screen itself (Profile → menu row, per the NEXT SESSION PROMPT's own
D.14 line: `ProfileActivity.kt`'s existing data-driven `MenuRow` list
is the natural spot), reading `GET /customer/wallet` for balance +
history, no write actions needed. After D.15, D is complete and the
item 15 refund-to-wallet wiring (`complete_refund()` /
`admin/refunds.php`'s reserved `method='wallet'` path) is next, per
section E's existing note.

**2026-08-23 (follow-up session #8) — item 26 §D.15 done. Section D
(Wallet checkout integration) is now fully complete.** Re-confirmed
the standing sandbox limitation first (no `php`/`mysql`/Android SDK).
Owner said they'd test everything later rather than requesting
verification, so continued straight through the queue.

Built the customer-app Wallet screen:
- `ApiService.kt` — new `getWallet()` GET against
  `customer/wallet.php`, same convention as every other endpoint here.
- `Models.kt` — new `WalletTransaction`/`WalletResult` data classes,
  field-for-field mirror of `wallet.php`'s actual response shape
  (confirmed by reading that file first, not assumed) — same
  discipline as `RefundInfo`/`RefundTimelineEntry`'s prior mirroring
  of `format_order()`'s refund object. `type`/`reason` kept as raw
  String rather than a Kotlin enum since both ENUMs could grow
  server-side.
- `activity_wallet.xml` — new dedicated layout (not the shared
  `activity_simple_list.xml` `OrderHistoryActivity`/`FaqsActivity`
  reuse, since this screen needs a balance card above the list that
  the generic layout has no room for); header row + swipe-refresh +
  RecyclerView + empty-state below are otherwise the same shape as
  the shared layout, kept consistent on purpose.
- `item_wallet_transaction.xml` — one row per transaction, same
  CardView-row shape as `item_notification.xml`. Credit rows render
  success_fg/+prefix/up-arrow, debit rows error_fg/-prefix/down-arrow
  (`ic_arrow_up` rotated 180° — no separate down-arrow drawable exists
  in this project).
- `WalletTransactionAdapter.kt` — plain read-only list adapter, no
  click action (a transaction row has nowhere to drill into yet).
  Reason ENUM mapped to display strings with a defensive raw-string
  fallback; date reformatted with the same
  "YYYY-MM-DD HH:MM:SS" → "12 Jul, 8:42 PM" logic
  `OrderHistoryAdapter.formatOrderDate()` already uses (duplicated,
  not shared — that one's private to its own file).
- `WalletActivity.kt` — loads balance + transactions in one call, no
  pagination (the backend endpoint itself caps at 50 rows and has no
  `page` param yet, per `wallet.php`'s own kdoc — flagged in this
  file's kdoc as something to revisit if that ever changes).
- `ProfileActivity.kt` — new Wallet row inserted between Saved and
  FAQs, `ic_bolt` reused as the icon (no purpose-built wallet icon
  exists; `ic_star` was ruled out since it's already used two rows
  down for Rate Us). Header kdoc's icon-reuse list updated to match.
- `AndroidManifest.xml` — `WalletActivity` registered
  (`exported="false"`, same as every other internal screen).
- New strings: `wallet_title`, `wallet_balance_label`,
  `wallet_history_label`, `wallet_empty_history`,
  `wallet_load_failed`, `wallet_reason_*` (4 reason labels),
  `menu_wallet`.

**Not device/build-verified** — same standing sandbox limitation. All
view-binding IDs were hand-matched between each new XML file and its
Kotlin counterpart and cross-checked with `grep` this session (both
`activity_wallet.xml`↔`WalletActivity.kt` and
`item_wallet_transaction.xml`↔`WalletTransactionAdapter.kt` — every ID
referenced in Kotlin has a matching `android:id` in the XML, and vice
versa) but never compiler-checked, same gap as every prior session's
new screens.

**Section D (Wallet checkout integration, item 26) is now fully
built** — D.12 (orders/create.php wallet branch), D.13
(payment-methods.php + PaymentMethodsResult wallet fields), D.14
(CheckoutActivity radioWallet option), and D.15 (Wallet screen) are
all done. **NEXT SESSION should move to either:**
(a) device/build verification of everything in sections A–D once a
PHP CLI/live-MySQL/Android Studio environment exists — the full
checklist accumulated across this file's last several sessions is
long at this point and worth doing as one pass rather than piecemeal, or
(b) if the owner wants to keep building forward: item 15's
refund-to-wallet wiring (`lib/refunds.php`'s reserved `method='wallet'`
path — `complete_refund()` needs a `complete_refund_to_wallet()`-shaped
function calling `credit_wallet()` instead of writing a manual-transfer
`platform_ledger` row, plus an admin-UI button in `admin/refunds.php`;
`OrderStatusActivity.kt`'s `renderRefund()` already handles a
`method === "wallet"` label correctly, confirmed again this session,
nothing further needed there), per section E's original note — or
Phase D's Offers Engine / real-gateway support (Razorpay/Cashfree, doc
23 §9) as separate, larger next choices.

**2026-08-23 (follow-up session #9) — item 15's refund-to-wallet
wiring built. This closes out the entire Wallet + Refund cross-over
work list.** Re-confirmed the standing sandbox limitation first (no
`php`/`mysql`/Android SDK). Owner chose to keep building forward
rather than pause for verification.

Built:
- `lib/refunds.php` — new `complete_refund_to_wallet()`, mirroring
  `complete_refund()`'s shape but for the `method='wallet'` path:
  callable only from `status='approved'` (skips `processing` entirely
  — a wallet credit is instant, unlike an external bank/UPI transfer
  that needs a reference captured first while "in flight"). Calls
  `credit_wallet()` (reason='refund', order_id set) instead of
  `write_platform_ledger_entry()` — documented in the function's own
  kdoc why no ledger entry is correct here: the money never leaves the
  platform's account, it just moves from "owed to the customer in
  cash" to "sitting in their wallet balance," and `credit_wallet()`'s
  own `wallet_transactions` row is the complete record of that.
  `refund_reference` gets a synthetic `WALLET-CREDIT-{txn_id}` marker
  so the admin UI's existing reference column stays meaningful.
  Deliberately sends no notification of its own (`credit_wallet()`
  already sends "Wallet credited" — a second "Refund completed"
  message for the same money movement would be redundant).
- `approve_refund()` extended with an optional `$method` param (null
  = leave the row's existing method column untouched, so every
  existing caller keeps working unchanged) — approve time is the
  natural point for an admin to choose wallet vs. manual transfer,
  same moment they're already setting the expected-by date.
- `admin/refunds.php` — new Method column in the table; the Approve
  button's `promptApprove()` JS now also asks for a method choice
  (blank = default manual transfer); a new "Credit to Wallet" button
  appears only for `approved` rows where `method === 'wallet'`,
  calling the new `complete_to_wallet` form action ->
  `complete_refund_to_wallet()`. Header kdoc, intro paragraph, and the
  STATUS verification checklist all updated to describe both paths.
- Confirmed (re-read, not assumed) that `OrderStatusActivity.kt`'s
  `renderRefund()` already maps `method === "wallet"` to the
  `refund_method_wallet` string correctly — built two sessions ago,
  before this backend path existed, still correct now that it does.
  Nothing further needed on the customer-app side.

**Not device/build-verified** — same standing sandbox limitation.
`complete_refund_to_wallet()`'s transaction/locking behavior was
checked by carefully re-reading `credit_wallet()`'s actual body
(confirmed it acquires its own row lock and participates correctly in
an already-open transaction via `$db->inTransaction()`), same
read-the-code-carefully discipline as every other session here, but
never run against a real DB.

**This closes recall.md's item 15/item 25/item 26 cross-over list —
every follow-up flagged across sessions #4 through #9 for the
Wallet/Refund/UPI trio is now either built or explicitly listed as a
separate future item (real-gateway support, cashback granting/expiry,
Offers Engine).** NEXT SESSION should be either (a) the first full
device/build verification pass across sections A–D of the NEXT SESSION
PROMPT plus this session's wallet-refund path, now that the backlog of
buildable-without-tooling work here is genuinely exhausted, or (b) a
fresh forward-progress choice: Phase D's Offers Engine, real-gateway
prep (Razorpay/Cashfree, doc 23 §9), or cashback granting/expiry
(needs a scheduler/cron entry point that doesn't exist anywhere in
this codebase yet — flagged in `lib/wallet.php`'s own header comment
as blocked on that, not on wallet logic itself).
