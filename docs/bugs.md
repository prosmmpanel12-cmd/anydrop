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

### 1.1 🔴 `discount_percent` has no upper-bound clamp — can produce negative prices
**Where:** `backend/lib/orders.php`, `price_cart()`:
```php
if ((float) $item['discount_percent'] > 0) {
    $unitPrice = round($unitPrice * (1 - (float) $item['discount_percent'] / 100), 2);
}
```
No `min(100, ...)` anywhere — DB column, this function, and the (not yet
built) restaurant coupon/discount UI all lack a ceiling. A value like
`discount_percent = 150` (typo, or a restaurant owner trying to "look
generous") produces a **negative unit price**, which flows straight into
`item_total`, `discount_amount`, and `grand_total`. Right now this field
is only set via manual phpMyAdmin `UPDATE`, so the blast radius is small
— but this becomes a **real money-loss bug the moment a restaurant-side
"set a discount" UI ships** (which is now in scope, see updated roadmap).
**Fix needed:** clamp `discount_percent` to 0–100 both at write time
(API validation, whenever that endpoint is built) and as a defensive
`min(100, max(0, ...))` at read time in `price_cart()` regardless.

### 1.2 🔴 Delivery OTP generation and OTP display use different conditions — real orders may get an OTP nobody can see
**Where:** `orders/create.php` generates the OTP when:
```php
$otpRequired = $paymentMethod === 'upi' || (bool) get_setting('otp_required_for_cod', false);
```
But `orders/track.php` (the endpoint the Customer App actually polls to
*show* the OTP) only returns it when:
```php
$order['payment_method'] === 'upi'
```
If an admin ever flips `otp_required_for_cod` to true (a real
`app_settings` row that already exists for this purpose), COD orders
**will have an OTP generated and stored**, but the Customer App will
never receive it via `track.php` — the customer can't give the rider a
code they were never shown. Right now this is latent (the setting
defaults false and UPI isn't wired yet, so no live order has an OTP at
all today) — but it's a real bug waiting for either switch to flip.
**Fix needed:** `track.php`'s condition should check `$order['delivery_otp'] !== null`
(i.e., "was one actually generated for this order"), not re-derive
`payment_method === 'upi'` independently.

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

### 2.1 🔴 OTP request endpoint has no rate limiting
**Where:** `backend/api/v1/auth/customer-request-otp.php`. Any caller can
POST an email address repeatedly with no cooldown, no per-IP or
per-email throttle, no CAPTCHA. Combined with 2.2 below (OTP returned in
the response body), this isn't currently exploitable for OTP theft, but
once real SMTP sending replaces `debug_otp`, this becomes an open email-
bombing vector (attacker spams someone else's email with OTP mails) and,
separately, cheap DB-row spam (`email_otps` grows unbounded).
**Fix needed:** simple per-email cooldown (e.g. `WHERE email = :e AND
created_at > NOW() - INTERVAL 60 SECOND` check before inserting a new
row), same pattern most OTP systems use.

### 2.2 🔴 `debug_otp` is returned in the live API response
**Where:** same file — `respond_ok(['message' => 'OTP sent', 'debug_otp' => $otp])`.
Already flagged in `Status.md`'s Known Limitations as a temporary
testing aid, repeating it here so it's tracked as a security item, not
just a "TODO wire up SMTP" item: **this must be removed (or gated behind
an admin/debug-only flag) before this ever reaches real users**, since
anyone with the API reachable can log in as any email address with zero
possession-of-inbox proof today.

### 2.3 🟡 GitHub Personal Access Token was pasted into chat earlier in the project
Already flagged in `Status.md` — restating here because it's a real
credential-leak risk until confirmed revoked. **Action needed:** confirm
revoked/regenerated at github.com/settings/tokens if not already done.

### 2.4 🔴 No idempotency protection on `POST /orders`
Double-tapping "Place Order" (slow network, accidental double-tap, retry
after a timeout that actually succeeded server-side) has no client-side
button-disable-on-tap confirmed in `CheckoutActivity`, and the backend
has no idempotency key — two identical orders can be created and charged
(once real payments are wired). **Fix needed:** disable the place-order
button immediately on tap (client) **and** an idempotency key
(client-generated UUID sent with the request, server checks/stores it
for a short window) so a retried request can't double-create.

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
| 1.1 | `discount_percent` no upper clamp → negative price | 🔴 High (money) | Pricing |
| 1.2 | OTP generated for COD but never shown to customer | 🔴 High (ops) | Delivery OTP |
| 1.3 | Coupon usage race condition (double redemption) | 🟢 Fixed (2026-08-30) | Pricing |
| 2.1 | OTP request has no rate limit | 🔴 High (once SMTP live) | Security |
| 2.2 | `debug_otp` exposed in API response | 🔴 High (pre-launch blocker) | Security |
| 2.3 | GitHub PAT pasted in chat — confirm revoked | 🟡 Medium | Security |
| 2.4 | No idempotency on order creation (double order) | 🔴 High (once payments live) | Security/Money |
| 3.1 | No admin panel — due-limit/approval/settings unoperated | 🔴 High (structural) | Admin |
| 3.2 | No restaurant-side write path for discount/bestseller flags | 🟡 Medium | Restaurant App |
| 3.3 | Service-area check is city-wide, not radius-precise | 🟢 Low | UX accuracy |
| 4.1 | Notification system has no template pool / triggers yet | 🔴 (scope gap, not a bug) | Notifications |
| 6.1 | Home GPS-off banner (Zomato-style, dynamic text) — spec only, not built | 🆕 Feature, not yet built | Address/GPS |
| 6.2 | Address Book "set as default" — no such action exists in code | 🔴 High (missing feature) | Address Book |
| 6.3 | `orders/create.php` never checks restaurant operational_status | 🔴 High (ops/money) | Restaurant status |

**Priority for fixing, independent of new-feature work:** 1.1 and 2.2 are
the two that matter most before anything money- or auth-related ships
further (1.1 before the restaurant discount/coupon UI goes live, 2.2
before real users ever hit production). 1.2 matters before COD OTP is
ever turned on. The rest can ride alongside the new roadmap items below.

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

### 6.2 🔴 Address Book — "set as default" doesn't switch because no
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

