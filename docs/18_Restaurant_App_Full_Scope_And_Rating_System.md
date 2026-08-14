# Restaurant App — Full Scope (Prioritized) + Pre-Order Star Rating

**Source:** app owner's full restaurant-app wishlist (2026-08-13) — organized
into priority tiers, checked against what already exists in the schema/
backend, and flagged where an item conflicts with an earlier decision
(Rider App deferred to last) or is genuinely a new, separate-phase-sized
piece of scope rather than a small addition.

**Legend:** ✅ already exists (schema/backend) · 🟢 small addition ·
🟡 medium — new table/screen · 🔴 large — own phase · ⛔ conflicts with
"Rider App last" decision, stays in Phase K

---

## Tier 1 — Core restaurant operations (build first, closes real gaps)

### Menu Management
- 🟢 Category add/edit/delete — `menu_categories` table already exists,
  only customer-facing read endpoint exists today. Needs
  `POST/PUT/DELETE /restaurant/categories`.
- 🟢 Food item add/edit/delete, price update — `menu_items` table already
  has everything needed. Needs `POST/PUT/DELETE /restaurant/menu-items`.
- 🟢 Photo upload — first real file-upload feature in the project (same
  infra note as banner uploads, §2.2 in the bug tracker — build once,
  reuse for both).
- ✅ Veg/Non-veg badge — `menu_items.is_veg` column **already exists**,
  this is just a form toggle, no schema change.
- 🟡 Customizations (Extra Cheese, Large Size) / Add-ons — `menu_item_addons`
  + `menu_item_addon_groups` (max-select cap) already exist and already
  power the Customer App's item-detail sheet (§2.6, done). **Missing:**
  restaurant-side UI to create/edit these groups — right now they only
  exist via seed data.
- 🟢 Out-of-stock toggle — `menu_items.is_available` already exists and is
  already read everywhere; just needs a restaurant-side switch.
- 🟡 Item availability timing (e.g. breakfast-only item) — new columns
  needed (`available_from`/`available_until` time-of-day on `menu_items`),
  small but not zero schema work.
- 🟢 Search menu (within the restaurant's own item list, for large menus) —
  client-side filter, no backend change.

### Order Management
- ✅ Accept/Reject, order detail, customer notes, order history — already
  built (`orders-accept.php`, `orders-reject.php`, `orders-detail.php`,
  `OrderDetailActivity.kt`).
- 🟢 **Loud sound on new order** — dashboard currently polls silently;
  needs a distinct notification sound (not the default Toast/system tone)
  fired when `loadOrders()`'s poll finds a new `pending` order.
- 🟢 Preparation-time select (10/15/20/30 min) — small addition to the
  accept flow; `estimated_prep_minutes` already exists on `orders`
  (already used by `track.php`'s ETA calc) but is currently set however
  `orders-accept.php` defaults it, not restaurant-chosen. Needs a
  quick-select UI on Accept + the value passed through.
- 🟢 "Ready for Pickup" — already covered by the existing status pipeline
  (`ready` is already a valid `orders.status` value per the schema) —
  just needs to be confirmed reachable as a button in `OrderDetailActivity`
  if not already.
- ⛔ **OTP Verification (Delivery Boy)** — this is Rider-App-side
  functionality (the rider enters the OTP, not the restaurant), stays in
  Phase K per the "Rider last" decision. The restaurant app itself has no
  reason to verify delivery OTP.
- 🟢 Cancel reason — needs a `cancellation_reason` column on `orders`
  (doesn't exist yet) + a reason-picker UI on reject/cancel.

### Restaurant Management
- 🟢 Name, Address, GPS location, Working hours — `restaurants` table
  already has `opening_time`/`closing_time`/`working_days`/lat-lng; this
  is a profile-edit screen, no new columns needed for these fields.
- 🟢 Logo & Cover photo upload — same upload infra as menu photos.
- ✅ Open/Close toggle — **already built** (Part B, `switchAcceptingOrders`
  in `DashboardActivity`, wired to `operational_status`).
- 🟡 Temporary closure with a reason/duration, Holiday schedule — the
  existing `operational_status` handles an immediate on/off, but "closed
  for 3 days starting tomorrow" or a recurring holiday calendar needs new
  fields (`temp_closed_until`, or a small `restaurant_closures` table for
  multiple future date ranges).

---

## Tier 2 — Money & growth (build after Tier 1)

### Offers (already scoped in earlier sessions, restated here for completeness)
- 🟡 Flat discount, Percentage discount, Coupon codes — this is exactly
  the coupon system already planned (Phase H from the previous roadmap
  update) — `coupons.discount_type` already supports `flat`/`percent`.
- 🟡 Free delivery (as a coupon effect) — `coupons` schema needs a
  `discount_type = 'free_delivery'` option added, or a boolean flag —
  small addition to the coupon work already planned.
- 🟡 Combo offers (bundle N items at a fixed price) — genuinely new,
  no existing table models a "bundle" — needs its own
  `combo_offers`/`combo_offer_items` tables. Bigger than a typical
  coupon, flagging separately.
- 🟡 Happy Hours (time-window discount, e.g. 3-5pm) — new
  `start_time`/`end_time` fields on either coupons or a small dedicated
  table; needs the pricing engine (`price_cart()`) to check current time
  against the window.

### Payments / Settlement
- 🔴 Today's/Weekly/Monthly earnings, Settlement history, Pending payout,
  Bank details — this is a real **financial ledger system**, not a small
  addition. Ties directly into `restaurants.current_due` (already exists
  but nothing writes to it — flagged in `bugs.md` §3.1) and needs: a
  `restaurant_settlements` table (payout records), a `bank_details`
  column set (account number, IFSC — needs encryption-at-rest
  consideration, not just a plain TEXT column), and real
  earnings-aggregation queries (`SUM(commission_amount)` etc. grouped by
  date). This is its own phase — recommend building alongside/after the
  Admin Panel's due-ledger view (already in the Phase H plan), since
  they're two views of the same underlying money.

### Analytics
- 🔴 Sales graph, Peak hours, Top-selling foods, Repeat customers, Order
  success/cancel rate, Revenue report (PDF/Excel export) — this is a
  reporting module. Most of the raw data already exists (`orders`,
  `order_items`, `order_status_history`), so it's aggregation queries +
  charting (MPAndroidChart or similar) + export (the project's `pdf`/
  `xlsx` generation approach), but it's real, separate-phase-sized scope,
  not a quick add. Recommend building after Tier 1 + coupons are live and
  there's actually meaningful order data to report on.

---

## Tier 3 — Engagement & trust

### Reviews
- ✅ Customer submits reviews — **already built** (`reviews` table,
  `customer/reviews.php`, `RateOrderDialog`).
- 🟡 Restaurant reply to review — explicitly deferred earlier
  (`07_Phase_3.7_Bug_Tracker.md` §2.6's resolution note: "no restaurant
  reply/view yet (future feature)"). Schema already has
  `reviews.restaurant_reply` column reserved for this — just needs the
  write endpoint + restaurant-side UI.
- 🟡 Report fake review — needs a `reviews.is_reported` flag (already
  exists in schema!) wired to a restaurant-facing "report" action +
  an admin queue to review reported items (ties to Admin Panel work).

### Notifications (restaurant side)
- 🟡 New orders / Payment received / Offers reminder / App updates — this
  is the same notification-bell system already planned for the customer
  app (previous session) — build once, wire into both apps. Restaurant
  app additionally needs its own splash/update-check screen (flagged as
  missing in `Status.md`'s Known Limitations already).

---

## Tier 4 — Staff & compliance (defer — genuinely separate feature set)

### Staff Management
- 🔴 Add staff, Cashier/Kitchen/Manager roles, permissions — this is a
  **multi-user-per-restaurant auth model**. Right now, `restaurants` auth
  is one login = one restaurant (`auth_tokens` keyed to a single
  `restaurant_id` owner). Real role-based staff access needs a new
  `restaurant_staff` table (staff belongs to a restaurant, has a role),
  changes to `require_auth()`'s restaurant-ownership checks throughout
  every `/restaurant/*` endpoint (currently all assume the token owner
  *is* the restaurant), and a permissions model (what can Kitchen role
  see vs Manager). **This is one of the biggest items on the whole list**
  — recommend its own phase, after everything else in Tiers 1-3, since
  every existing restaurant endpoint needs re-auditing once "the
  restaurant" can mean multiple logged-in people.

### Settings
- 🟢 GST details, FSSAI number — small addition, just new columns on
  `restaurants` (`gst_number`, `fssai_number`) + profile-edit fields.
  No validation logic needed beyond basic format checks.
- 🟡 Bank details — same caution as the Payments section above (should
  live with that work, not be a bare-text settings field, given it's
  sensitive financial data).
- 🟢 Notification settings (per-category on/off toggles) — small, once
  the notification-bell system exists.
- 🟢 Language — needs Android string resources in additional languages
  (translation work, not a code architecture problem) + a language
  picker; scope depends entirely on which languages are wanted.
- 🟢 Dark mode — **note: this applies equally to the Customer App**,
  should be built once as a shared theming pattern (`DayNight` theme +
  a settings toggle persisted locally), not restaurant-app-only. Flagging
  as a cross-app item.

---

## ⛔ Explicitly staying in Phase K (Rider App, deferred to last)
Per the "Rider App last" decision, these restaurant-app wishlist items
depend on a rider actually existing and are **not** buildable in
isolation — they'll land as part of Phase K, not before:
- Assign Delivery Partner
- Live Rider Tracking (restaurant's own view of where the rider is)
- Call Rider / Chat Rider
- Rider ETA
- OTP Verification (Delivery Boy side)

---

## Recommended build order for the Restaurant App (folds into the
existing Phase H/I/J/K roadmap in `Status.md`)

1. **Menu Management** (Tier 1) — biggest functional gap, blocks
   everything else feeling like a real app for a restaurant owner.
2. **Order Management small additions** (loud sound, prep-time select,
   cancel reason) — cheap, high-value, isolated.
3. **Restaurant Management profile screen** (name/address/hours/logo,
   temp closure, holiday schedule) — needed before a restaurant can
   really "set itself up."
4. **Coupon system** (already-planned Phase H) — extend to include free
   delivery + combo offers + happy hours while touching this code anyway.
5. **Notification bell** (already-planned Phase J) — build once, wire
   into both apps; restaurant app also gets its splash/update-check
   screen here.
6. **Reviews — restaurant reply + report** — small, self-contained,
   slot in whenever convenient after the above.
7. **GST/FSSAI/language/dark-mode settings** — small, low-risk, anytime
   filler work.
8. **Payments/Settlement ledger** (Tier 2, 🔴) — own phase, after the
   Admin Panel's due-ledger view exists (Phase H) since they share data.
9. **Analytics/Reporting** (Tier 2, 🔴) — own phase, after there's real
   order volume to report on.
10. **Staff Management** (Tier 4, 🔴) — own phase, last of the non-Rider
    items, since it touches every existing restaurant endpoint's auth.
11. **Phase K — Rider App** stays dead last, as already decided.

---

## New feature — Pre-order "impression" star rating (no order required)

**Requested behavior:** a customer can open a restaurant's page and tap a
star rating **without ever having ordered** — the star lights up/glows on
tap. These "impression" ratings carry **less weight** than a real
post-order review: the example given is 10 users each giving 5★ should
show as roughly 3★ in the aggregate, not 5★ — while a customer who has
actually completed an order can still give a full-weight 1-5★ review the
normal way (existing `reviews` flow, unchanged).

### Why this needs a separate table, not reusing `reviews`
`reviews` is tightly coupled to `order_id` (UNIQUE key, `require_ratable_order()`
requires a `delivered` order) — that's correct and shouldn't change,
since order-based reviews are the trustworthy signal and already work.
Impression ratings need their own lightweight table since they have no
order behind them at all.

### Schema
```sql
CREATE TABLE restaurant_impression_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    stars TINYINT UNSIGNED NOT NULL, -- 1-5, whatever they tapped
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_impression (restaurant_id, customer_id), -- one impression
                                                             -- rating per
                                                             -- customer per
                                                             -- restaurant;
                                                             -- re-tapping
                                                             -- updates it,
                                                             -- doesn't stack
    CONSTRAINT fk_impression_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id),
    CONSTRAINT fk_impression_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
The `UNIQUE` key matters — without it, one customer could tap the star
repeatedly to stack fake weight; capped to one impression per customer
per restaurant (updatable, not stackable) closes that loophole before it
ships, not after.

### Weighting formula (matches the "10× 5★ → shows as ~3★" example)
Real order-based reviews (`restaurants.rating_avg`/`rating_count`,
already computed by `recalc_restaurant_rating()`) stay the primary trust
signal. Impression ratings blend in at reduced weight using a simple
weighted average — e.g. **each impression counts as ~0.3 of a real
review** when computing the *displayed* aggregate (the underlying
`reviews` data and its own `rating_avg` stay untouched/pure for internal
use — this is a display-layer blend only):

```
displayed_rating = (real_rating_avg * real_rating_count * 1.0
                     + impression_avg * impression_count * 0.3)
                    / (real_rating_count * 1.0 + impression_count * 0.3)
```

With **zero real reviews yet** and 10 impressions all at 5★:
`(0 + 5*10*0.3) / (0 + 10*0.3) = 15/3 = 5.0` — that actually shows 5★,
not 3★, because with *no* real reviews to anchor against, pure impression
average has nothing pulling it down. To match the "10 users → 3★" example
literally, the intent is likely: **impressions alone (no real reviews at
all) should never show a high aggregate just from taps** — i.e. impressions
need their own dampening *before* they're allowed to look like a trusted
rating, e.g. a Bayesian/"add fake low-confidence prior" approach:

```
displayed_rating = (impression_avg * impression_count + PRIOR_RATING * PRIOR_WEIGHT)
                    / (impression_count + PRIOR_WEIGHT)
```
With `PRIOR_RATING = 3.0` (neutral starting point) and `PRIOR_WEIGHT = 20`
(a large "phantom" weight representing distrust of taps-only data):
10 users × 5★ → `(50 + 3*20) / (10+20) = 110/30 = 3.67` — close to the
"~3★" example. Once real `reviews` exist for that restaurant, blend real
reviews in at full weight on top of this dampened impression score, and
let real-review count gradually override the prior as it grows.
**This exact formula/prior needs the app owner's sign-off before building**
— the numbers above are a starting point matching the given example, not
a final spec; recommend picking a `PRIOR_WEIGHT` and testing it against a
few example scenarios (1 tap, 10 taps, 100 taps, mixed with 5 real
reviews) before committing.

### What this needs to build
- Backend: new table above, `POST /customer/restaurants/{id}/impression-rating`
  (upsert — insert or update the customer's existing row),
  `restaurants/list.php` and `restaurants/menu.php` (detail) both need
  their `rating_avg` calculation updated to blend per the agreed formula.
- Customer App: on Restaurant Detail (and possibly the restaurant card
  itself), a tappable 5-star row separate from any order-based review UI
  — tap → star glows/fills immediately (optimistic UI) → fires the upsert
  call → on failure, revert the glow. Needs to visually read as "your
  quick impression," not be confusable with a real review (e.g. small
  "Rate this restaurant" label vs the order-flow's "Rate Order" dialog).

---

## Restaurant-plan features with NO existing Customer App counterpart
Checked the app owner's full restaurant list against everything already
built in the Customer App — items below exist (or are planned) on the
restaurant side but currently have **no matching customer-facing piece**,
flagged so nothing gets silently one-sided:

- **Offers (flat/%/free-delivery/combo/happy-hours)** — restaurant will
  be able to create these, but the Customer App only has a plain
  "coupon code" entry at Checkout today (`coupons/list.php` "suggest a
  coupon" screen). Combo offers and Happy Hours specifically need their
  **own Customer-App display** (a combo needs to be browsable/addable as
  a bundle, not just a code — Happy Hours needs a "X% off right now"
  banner/badge that appears and disappears with the clock), not just
  a hidden backend rule the customer stumbles into at checkout.
- **Restaurant reply to review** — once built restaurant-side, the
  Customer App's Order History / Restaurant Detail review list needs to
  actually **show** the reply under the customer's own review (no such
  display exists in the Customer App's rating UI today — it only shows
  the customer's own star input, not any restaurant response).
- **Temporary closure / Holiday schedule with a reason** — the Customer
  App currently only shows a flat "Closed" or "Temporarily unavailable"
  label (`RestaurantAdapter`'s status text). If a restaurant sets "closed
  for Diwali, back on the 25th," the customer sees generic "Closed," not
  the reason/return date — worth showing that specific message once the
  restaurant side supports it.
- **GST/FSSAI display** — some customers expect to see a restaurant's
  FSSAI license number on the app (common in this category, e.g. shown
  in small print on the restaurant detail page). Not currently planned
  anywhere in the Customer App even though it'll now exist restaurant-side.
- **Pre-order impression star rating** (this doc, above) — customer-side
  UI is included in that section's own build list, noting here only for
  completeness since it's the one customer-side item in an otherwise
  restaurant-heavy request.
