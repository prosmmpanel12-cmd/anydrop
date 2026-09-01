# Anydrop — Bugs, Loopholes & Edge-Case Tracker

**Created:** 2026-08-13, from a full source-code re-audit (not just doc
claims — every item below was traced through actual `.php`/`.kt` code).
This doc is **additive** to `07_Phase_3.7_Bug_Tracker.md` (which covers
UI/UX bugs already found via live testing) — this one focuses on
**money, security, and race-condition risks** found by reading the
backend logic directly, plus scenarios nobody has hit yet in testing but
that the code allows.

**Status legend:** 🔴 Open (not fixed) · 🟡 Partially mitigated · ✅ Fixed

---

## 1. Money / pricing bugs

### 1.1 🟢 FIXED — `discount_percent` upper-bound clamp
**Doc-audit correction (2026-08-31 session):** this doc still showed 🔴,
but `backend/lib/orders.php`'s `price_cart()` already reads:
```php
$discountPercent = min(100, max(0, (float) $item['discount_percent']));
```
— confirmed directly in the current source, not re-derived from an older
handover doc. A negative unit price is no longer reachable through this
path regardless of what garbage value ends up in the column. No write-time
clamp exists yet on a restaurant-side discount-setting UI, but none of
that UI exists yet either (still manual phpMyAdmin `UPDATE` only, see
`Status.md`'s Known Limitations) — add one alongside whenever that UI is
actually built.

### 1.2 🟢 FIXED (2026-08-31) — Delivery OTP generation/display condition mismatch
**Was:** `orders/create.php` generates the OTP when
`payment_method === 'upi' || otp_required_for_cod`, but `orders/track.php`
(the endpoint the Customer App polls to *show* the OTP) only returned it
when `payment_method === 'upi'` — independently re-derived instead of
checking whether an OTP actually existed. If an admin ever flipped
`otp_required_for_cod` on, a COD order would get a real `delivery_otp`
written to the DB that the customer could never see, with no way to give
the rider a code they were never shown.

**Fix:** `track.php`'s condition now checks `$order['delivery_otp'] !== null`
directly — "was one actually generated for this order" — instead of
re-deriving `payment_method === 'upi'`. Stays correct regardless of which
condition governs generation in `create.php` in the future; no change was
needed on the generation side, only the display side was out of sync.

Previously deferred to "fix as part of Phase K (Rider App)" on the
reasoning that it's meaningless to fix in isolation before a rider flow
exists to use the OTP — but the fix itself is a one-line, self-contained
condition change with no rider-flow dependency, so it was safe to land
now rather than wait. Not build/device-verified — no PHP CLI in this
sandbox, same standing limitation as every prior session; balance-checked
only. Still needs a live test once a rider flow exists: an order sitting
at `rider_assigned`/`out_for_delivery` with `otp_required_for_cod` on and
`payment_method = 'cod'` should now return a real `otp` from `track.php`.

### 1.3 🟢 FIXED — Coupon usage-limit check race condition (TOCTOU)
**Was:** `price_cart()` checks `usage_limit_per_user` / `usage_limit_total`
via a `SELECT COUNT(*)` *before* the order transaction opens, and
`coupon_usages` is inserted later inside `orders/create.php`'s
transaction, with no lock and no unique constraint in between — two
near-simultaneous requests (double-tap "Place Order", same user on two
devices) could both pass the count check before either insert landed,
both succeed, and a `usage_limit_per_user = 1` coupon get used twice.

**Fix (already in the code, this doc just wasn't updated to say so):**
`api/v1/orders/create.php`'s coupon block re-checks both limits *inside*
the transaction, immediately before the `coupon_usages` insert, guarded
by `SELECT ... FROM coupons WHERE id = :cid FOR UPDATE` — locking the
`coupons` row (not `coupon_usages`) serializes any two concurrent orders
against the *same coupon*, so the second one to reach the lock sees the
first's already-committed-or-pending usage and fails cleanly with
`coupon_usage_limit_reached` instead of both slipping through. A blanket
`UNIQUE KEY (coupon_id, customer_id)` was deliberately not used since
`usage_limit_per_user` can legitimately be `>1` or `NULL` (unlimited).
The restaurant Offers Engine's `promo_offers`/`offer_usages` path
(migration 47) uses the identical lock-recheck-insert shape via the same
file's `$recordOfferUsage` closure — built with this protection from day
one rather than retrofitted.

Verified by manual read (2026-08-30 session) — no PHP CLI/live DB in
this sandbox, so this hasn't been exercised under real concurrent
requests, but the lock semantics are correct as written.

### 1.4 🟢 Verified safe — coupon discount capping
`price_cart()` correctly does `$discount = min($discount, $itemTotal)`
and separately caps against `max_discount_amount`, so a coupon can never
push `grand_total` negative on its own. `quantity` is also floored at
`max(1, qty)`, so a zero/negative-quantity line item can't be submitted.
No fix needed — noted here so it's not re-flagged in a future audit.

---

## 2. Security bugs

### 2.1 🟢 FIXED — OTP request rate limiting
**Doc-audit correction (2026-08-31 session):** already fixed (Phase J,
2026-08-14) and confirmed again directly in current source —
`customer-request-otp.php` now checks a per-email cooldown
(`app_settings.otp_request_cooldown_seconds`, default 60s) against
`email_otps`'s most recent row for that address before inserting a new
one, returning `429 otp_request_cooldown` with `retry_after_seconds`
when hit. This doc had simply gone stale; no code change needed here.

### 2.2 🟢 FIXED — `debug_otp` gated behind a settings flag, real email delivery now wired
**Doc-audit correction (2026-08-31 session):** confirmed directly in
current source, not just an older doc's claim. `debug_otp` is now only
included in the response when `app_settings.debug_otp_enabled === '1'`
(defaults off). The endpoint also no longer unconditionally claims
success — it now calls a real `EmailOtpService` (multi-provider
failover, per `AnyDrop_Email_OTP_MultiProvider_Plan.md`) and returns a
genuine `503 email_delivery_unavailable` if every provider fails and
debug mode is off, rather than pretending an OTP was sent. **Action
still needed, unchanged:** confirm `debug_otp_enabled` is `'0'`/absent
on whatever DB actually goes to production — this is safe by default but
worth a manual check before launch, same note as before. item: **this must be removed (or gated behind
an admin/debug-only flag) before this ever reaches real users**, since
anyone with the API reachable can log in as any email address with zero
possession-of-inbox proof today.

### 2.3 🟡 GitHub Personal Access Token was pasted into chat earlier in the project
Already flagged in `Status.md` — restating here because it's a real
credential-leak risk until confirmed revoked. **Action needed:** confirm
revoked/regenerated at github.com/settings/tokens if not already done.

### 2.4 🟢 FIXED — Idempotency protection on `POST /orders`
**Doc-audit correction (2026-08-31 session):** already fixed (Phase J,
2026-08-14) and confirmed directly in current source —
`orders/create.php` accepts an optional `idempotency_key`, looks up an
existing order by `(customer_id, idempotency_key)` before creating a new
one, and has a race-safe fallback in its transaction's catch block
keyed off the `uniq_customer_idempotency_key` constraint (the concurrent
double-submit case, same shape as bug 1.3's coupon-lock fix). Client
side, `CheckoutActivity` generates one UUID per place-order attempt,
keeps it across a network-exception retry, and clears it on a clean
error response. This doc had simply gone stale; no code change needed.

---

## 3. Logic / data-integrity gaps

### 3.1 🔴 No admin panel exists — several "already has a DB column" features have no way to be operated
Several columns/flags already exist in the schema but have **zero UI or
endpoint to control them**, meaning they only work if someone edits
phpMyAdmin directly:
- `restaurants.is_approved` / restaurant approval workflow — no admin
  screen, so every restaurant that signs up is presumably auto-visible
  (needs re-checking once admin panel work starts — worth confirming
  restaurant registration doesn't currently default to publicly visible
  before it's been approved by anyone).
- `restaurants.current_due` / due-limit auto-suspend — the *check* exists
  in `price_cart()` (`restaurant_unavailable` if `current_due >= due limit`),
  but nothing ever *writes* to `current_due` on a real settlement/payment
  cycle, and there's no admin view of restaurant dues at all.
- `app_settings` (delivery charge, platform fee, OTP rules, tax percent,
  etc.) — every value is a real DB row already read via `get_setting()`
  everywhere in the backend, but there's no way to change any of them
  except direct SQL.
This isn't a "bug" in the sense of broken code — it's a **structural gap**
where the backend already assumes an admin will operate these controls,
but nothing lets a human do that yet. Flagging as its own category since
it affects the money side of the business, not just UX polish.

### 3.2 🔴 `discount_percent` / `is_bestseller` / `is_spicy` / `is_kids_choice` — same "no write path" issue as above, restaurant-side
Already noted in `Status.md`'s Known Limitations, restated here as a
tracked item since restaurant coupon/discount UI is now in scope — this
is the natural place to also close this gap (a restaurant owner UI to
set `discount_percent`/`is_bestseller` on their own menu items).

### 3.3 🟡 Service-area check is "any restaurants at all," not "does this exact point have coverage"
`HomeActivity.setServiceAreaUnavailable()` fires off a **plain empty
restaurant list** on the default (unfiltered) Home feed. That's correct
for "we haven't launched in this city at all," but doesn't distinguish
it from "there are restaurants in this city, just none whose
`delivery_radius_km` happens to reach this exact pin" — both currently
show the same "not available in your area yet" message, which is fine
messaging-wise but worth knowing isn't literally checking radius overlap
today, just a raw zero-results count.

### 3.4 🟢 Verified safe — SQL injection surface
Every query reviewed across `orders.php`, `create.php`, `track.php`,
`coupons/list.php`, and `auth.php` uses PDO prepared statements with
bound parameters — no string-concatenated SQL found anywhere in scope.
No fix needed — noted so it isn't re-flagged.

---

## 4. Notification-system gaps (relevant to the new 40-50 template request)

### 4.1 🔴 Only 2 fixed local notifications exist today, no template pool, no cart-abandonment trigger
`MealReminderScheduler`/`MealReminderWorker` fire exactly two
notifications a day (13:30, 20:30), same copy every time, scheduled
purely client-side via `WorkManager` — no backend involvement, no FCM, no
variation, no behavioral triggers (cart state, order history, etc.). This
isn't a "bug" so much as confirming the gap precisely before the new
40-50-template + cart-abandonment work starts (see updated roadmap) —
recorded here so the before/after is unambiguous once that work lands.

### 4.2 🔴 No de-duplication/rotation logic exists yet for a future template pool
Flagging ahead of building it: once 40-50 templates exist and ~4-5/day
get sent, there's currently no mechanism anywhere in the codebase for
"don't repeat the same template within N days" — worth building in from
the start rather than bolting on later, since a template pool that can
show the same line two days running defeats the point of having 40-50.

---

## 5. Summary table

| # | Item | Severity | Category |
|---|---|---|---|
| 1.1 | `discount_percent` no upper clamp → negative price | 🟢 Fixed (verified 2026-08-31) | Pricing |
| 1.2 | OTP generated for COD but never shown to customer | 🟢 Fixed (2026-08-31) | Delivery OTP |
| 1.3 | Coupon usage race condition (double redemption) | 🟢 Fixed (2026-08-30) | Pricing |
| 2.1 | OTP request has no rate limit | 🟢 Fixed (verified 2026-08-31) | Security |
| 2.2 | `debug_otp` exposed in API response | 🟢 Fixed (verified 2026-08-31) | Security |
| 2.3 | GitHub PAT pasted in chat — confirm revoked | 🟡 Medium | Security |
| 2.4 | No idempotency on order creation (double order) | 🟢 Fixed (verified 2026-08-31) | Security/Money |
| 3.1 | No admin panel — due-limit/approval/settings unoperated | 🟡 Largely built, see PENDING.md | Admin |
| 3.2 | No restaurant-side write path for discount/bestseller flags | 🟡 Medium | Restaurant App |
| 3.3 | Service-area check is city-wide, not radius-precise | 🟢 Low | UX accuracy |
| 4.1 | Notification system has no template pool / triggers yet | 🟢 Fixed (2026-08-14, template pool + rotation shipped) | Notifications |
| 6.1 | Home GPS-off banner (Zomato-style, dynamic text) — spec only, not built | 🆕 Feature, not yet built | Address/GPS |
| 6.2 | Address Book "set as default" — no such action exists in code | 🟢 Fixed (verified 2026-08-31) | Address Book |
| 6.3 | `orders/create.php` never checks restaurant operational_status | 🟢 Fixed (2026-08-13, verified 2026-08-31) | Restaurant status |

**Doc-audit note (2026-08-31 session):** this table had gone significantly
stale — six items (1.1, 1.2, 2.1, 2.2, 2.4, 4.1) were still marked 🔴 even
though the underlying code had already been fixed in earlier sessions
(mostly Phase J, 2026-08-14) or, for 1.2, fixed this session. Each was
re-verified against actual current source before being marked 🟢 here —
see each item's own entry above for the exact line(s) checked. **3.1's
severity was also downgraded** — the Admin Panel now covers order
control, analytics, restaurant approval, settlements, refunds, and more
(see `PENDING.md` for the current per-module status); it is no longer
accurate to say "no admin panel exists."

**Genuinely still open, in priority order:** 2.3 (confirm the GitHub PAT
is revoked — an action item, not a code fix), 3.2 (no restaurant-side UI
to set discount/bestseller flags), 6.1 (the GPS-off Home banner is still
spec-only, not built). 6.2 and 6.3 were also re-checked directly against
current source this session and are already fixed (see their entries
above) — this doc had simply never been updated after either landed.

---

## 6. Reported 2026-08-14 (from live device screenshots + follow-up clarification)

### 6.1 🆕 Feature spec — Home screen GPS-off banner (Zomato used only
as a visual reference, not literal copy)
Clarified 2026-08-14: the earlier screenshot was from **Zomato**, shown
only as a style/behaviour reference — nothing from it should be copied
into Anydrop as-is (colours, copy, or literal layout). What's wanted is
the same *behaviour*, rebuilt with Anydrop's own UI. This replaces the
original 6.1 entry above (which was written before this was clarified —
searching the zip for Zomato's exact on-screen strings was, correctly,
never going to find anything, since that screen was never part of this
app to begin with).

**What to build, on `HomeActivity`** (not `LocationPickerActivity` —
this is a persistent Home-screen banner, not a bottom sheet):

1. A dismissible-but-reappearing top banner shown on Home whenever device
   location (GPS + network provider, same `isProviderEnabled` check
   `LocationPickerActivity.fetchCurrentLocation()` already does) is off
   **and** the active address is a live-location one, or there's no
   active address at all yet. If the active address is a saved address
   (`ActiveAddressManager.get()?.isLiveLocation == false`), the banner
   should NOT show — a saved address doesn't need live GPS to keep
   working, so there's nothing to warn about.
2. The banner's text must be **dynamic based on whether Anydrop
   currently serves the resolved area**, not a single static message:
   - If a location can still be resolved somehow (e.g. last-known fix,
     or the active saved address's lat/lng) and `restaurants/list.php`
     for that lat/lng returns at least one restaurant → banner reads
     something like "Turn on location for a better experience" (soft
     nudge, not blocking — matches `setServiceAreaUnavailable`'s existing
     "restaurants exist, just help us find you" tone).
   - If nothing can be resolved at all, or the resolved/last-known area
     has zero restaurants (same emptiness check `loadRestaurants()`
     already does for `setServiceAreaUnavailable`) → banner reads
     something like "We're not available in this area yet — try a
     different address" — i.e. reuse the existing
     `setServiceAreaUnavailable(true)` state/copy rather than inventing
     a second, slightly-different "not available" message. **Don't
     build a second unavailable-area banner that says something
     different from the one that already exists** — that's exactly the
     "client gets confused by two different messages for the same
     situation" risk called out below.
3. Tapping the banner (or a location icon on it) opens
   `LocationPickerActivity` — same destination `deliveryLocationText`'s
   tap already opens, don't build a second location-selection flow.
4. **Explicit "Change address" button** on the banner (separate tap
   target from the banner body, per the request) — same destination as
   point 3. The point of a dedicated button rather than only relying on
   "tap the banner text" is discoverability: a plain text banner without
   an obvious button reads as informational-only to a lot of users, who
   then don't realize tapping it does anything.

**Consistency requirement — this is the "client should have zero
confusion" part of the ask, and it's the part most likely to get missed
building this incrementally:** once this banner exists, there are now
*three* places a user can end up being told "no service here" —
(a) this new banner, (b) `setServiceAreaUnavailable`'s existing
restaurant-list empty-state, (c) whatever the person eventually resolves
bug 6.3's badge/booking-block work into. All three must use the **same**
copy and the **same** "figure out if this area has restaurants" check
(`restaurants/list.php`'s existing result set, not a separately-invented
availability check) — otherwise it's entirely possible for a user to see
one screen say "available" and another say "not available" for the exact
same location, which is a worse experience than any single version of
this banner alone. Whoever builds this should grep for
`setServiceAreaUnavailable` and `empty_restaurants` first and reuse
those strings/that state rather than writing new ones.

**Not yet built. No code written for this in this session** — this
entry exists so the spec is captured precisely before the next session
starts implementing it, rather than starting from the screenshot again.

### 6.2 🟢 RESOLVED (verified 2026-08-31) — "Set as default" action built,
exactly per this entry's own spec below
Confirmed directly in current source: `AddressAdapter.kt` now takes an
`onSetDefault` callback (kept deliberately separate from `onActivate`,
per the client-confusion note below), `item_address_card.xml` has a
`btnSetDefaultAddress` row hidden on whichever address is already
default, and `AddressBookActivity.setDefaultAddress()` sends the full
existing address payload through `PUT` with `is_default = true` (not a
bare `{is_default: true}`) — matching this entry's own "one thing to
watch" warning about `require_fields`. This entry's original write-up is
kept below for history/context, not because the gap still exists.

### 6.2 (original write-up, kept for history — see ✅ resolution above)
Address Book — "set as default" doesn't switch because no
client-side action exists yet (backend already supports it — confirmed)
Checked `AddressAdapter.kt`, `AddressBookActivity.kt`, and
`backend/api/v1/customer/addresses.php` directly. The gap is narrower
than it first looks:

- **Backend is already fully ready** — `addresses.php`'s `PUT` (edit) and
  `POST` (create) both already accept `is_default` in the body, and both
  already correctly clear every other address's `is_default` first
  (`UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid`)
  before setting the new one — so there's no double-default risk, that
  logic is already correct. **No new backend endpoint needs to be
  built.**
- **The gap is entirely client-side**: `AddressAdapter` only *reads*
  `address.isDefault` to show/hide a badge — there's no tap handler on
  any row, no "Set as default" button anywhere, and no code calling the
  `PUT` endpoint with `is_default: true`. The UI simply never asks the
  backend to change it.

**One thing to watch when wiring this up** — `PUT`'s handler calls
`require_fields($body, ['full_address'])` before touching anything else,
meaning a bare `{"is_default": true}` request **will be rejected** with
`validation_error`. A "Set as default" tap must send the *entire*
existing address payload (same fields `AddressEditorBottomSheet`'s save
already sends) with `is_default` flipped to `true`, not just that one
field on its own — otherwise this looks like it works in a quick test
and then fails the first time it's tried on an address missing some
optional field the request happened to include by accident.

**What to build**: a "Set as default" tap target per row in
`AddressAdapter`/the address list layout (icon or text button, not the
whole row — the row already has its own tap-to-select behaviour
elsewhere in the app, e.g. picking delivery address at Checkout, so
overloading the same tap for two different actions would itself be a
confusion risk) → calls the existing `PUT` endpoint with the full
address payload + `is_default: true` → re-fetches/re-renders the list on
success so the badge moves immediately, no stale state until the next
manual refresh.

**Client-confusion note, since this was specifically asked for**: once
this exists, `ActiveAddressManager`'s "currently selected delivery
address for this session" and `customer_addresses.is_default` (the
"default/preferred address across sessions") are now two related but
separate concepts — setting a new default should NOT silently also
switch what's active on Home right this moment (that's a separate,
deliberate action via the Location Picker), and picking a different
address at checkout for one order should NOT silently change the
account's default either. Keep those two flows from bleeding into each
other, or the "which address is really mine" question gets more
confusing, not less.

### 6.3 ✅ Orders can still be placed on a closed/paused restaurant —
**resolved, see `docs/17_Handover_Bugs_6.1_6.2_2026-08-14.md` update below**
(2026-08-14 session #2). The `orders/create.php` gap described below was
already fixed in the I4 followups session (`price_cart()` in
`backend/lib/orders.php`, dated 2026-08-13) — this entry just hadn't been
updated to reflect that. The clarifying question below **is now
answered**: the restaurant detail page was the surface with no badge at
all (Home cards and search results already had one) — fixed this
session by adding `is_open_now`/`is_paused` to `menu.php` and a status
label to `RestaurantDetailActivity`, reusing the same shared
`compute_restaurant_status()` helper (new, `backend/lib/`) that
`list.php` and `search.php` now also call instead of each having their
own copy. Out-of-stock (the other open question in this entry) was also
built this session — see the new entry below.

### 6.3 🔴 Orders can still be placed on a closed/paused restaurant
(ORIGINAL — kept for history, see ✅ update above)
(badge itself already exists — don't rebuild it)
Checked thoroughly before writing this up, because the first read of the
bug report turned out to be wrong: **a status badge already exists and
already works.** `restaurants/list.php` computes `is_open_now` /
`is_paused` from the schema's `operational_status` column and returns
both; `RestaurantAdapter.kt` (customer app, Home restaurant cards)
already renders "Open" (green), "Temporarily unavailable" (amber,
dimmed) for `busy`/`temp_closed`, or "Closed" (red, dimmed) — so if a
restaurant's `operational_status` is genuinely set to something other
than `open`, the card already shows that correctly. **Two narrower real
gaps, confirmed by reading the actual files:**

- **`orders/create.php` never checks `operational_status` (or `status`)
  at all before accepting an order** — grepped the whole file, no
  restaurant-status condition exists anywhere in it. So even though the
  Home screen badge might correctly show a restaurant as closed/paused,
  nothing server-side stops an order from being placed on it anyway —
  e.g. via a stale cached menu screen, a deep link, or simply the
  request being sent directly. This is the one that actually needs a
  code fix: fetch `operational_status`/`status` in `create.php` before
  pricing/inserting, reject with `restaurant_not_accepting_orders` if
  not `open`/`approved`.
- **No "out of stock" state exists in the schema at all** —
  `operational_status`'s ENUM is
  `open, closed, busy, vacation, temp_closed, admin_disabled`. There's
  no restaurant-wide "out of stock" value, only per-item stock (if that
  even exists yet — needs checking against the menu-items table
  separately). If the person's asking for a distinct "Out of stock"
  badge specifically (not just closed/paused), that's a schema +
  UI addition, not something the existing badge logic already covers.

**Needs clarifying from the person**: was the restaurant in question
actually showing as "Open" on its card while still being unable to
fulfil orders (confirms the `orders/create.php` gap above is the real
issue), or was there no badge at all on that particular screen (would
point somewhere the existing `RestaurantAdapter` logic doesn't reach —
e.g. the restaurant detail page, or search results, worth checking those
render the same badge before assuming they do)?

### 6.4 🟡 Out of stock (per-item) — customer-side done, restaurant-side
NOT started (2026-08-14 session #2)
Turned out `menu_items.is_available` (TINYINT, schema already had it) was
already fully enforced everywhere on the read/order path — `menu.php`,
`search.php`, `category-items.php`, `popular-items.php` all filtered
`is_available = 1`, and `price_cart()` already rejected an unavailable
item at order time with reason `unavailable`. The only gaps: nothing
ever showed an unavailable item to the customer (it was just silently
hidden), and **nothing in the restaurant app can set `is_available` at
all** — no toggle exists anywhere, so right now it can only be flipped
directly in the DB.

**Done this session (customer side only):**
- `menu.php` — no longer filters `is_available = 1` out of the query;
  now returns every item (in-stock ones first) with an `is_available`
  flag.
- `MenuItem` model, `MenuAdapter.kt`, `item_menu_item.xml` — unavailable
  items now show an "Out of stock" pill, dimmed row, and the ADD
  button/qty stepper are hidden and unwired (not just disabled).

**NOT started — restaurant app toggle.** Explicitly deferred by the
person this session ("restaurant app is being built later") — the
restaurant app itself needs building out first before this specific
toggle makes sense to add. When that work starts, this is the one
concrete backend question to check first: **does a PUT/PATCH endpoint
for `menu_items` (analogous to `customer/addresses.php`'s PUT) already
exist for the restaurant side, or does one need to be built from
scratch?** — wasn't checked this session, don't assume either way.

