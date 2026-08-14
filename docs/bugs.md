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

### 1.3 🟡 Coupon usage-limit check has a race condition (TOCTOU)
**Where:** `price_cart()` checks `usage_limit_per_user` /
`usage_limit_total` via a `SELECT COUNT(*)` *before* the order transaction
opens, and `coupon_usages` is inserted later inside `orders/create.php`'s
transaction — but there's no `SELECT ... FOR UPDATE` lock and no unique
constraint on `coupon_usages(coupon_id, customer_id)`. Two near-simultaneous
requests (double-tap "Place Order", or the same user on two devices) can
both pass the count check before either insert lands, both succeed, and a
`usage_limit_per_user = 1` coupon gets used twice by the same customer.
Low real-world likelihood (needs near-exact timing) but a genuine gap.
**Fix needed:** add a `UNIQUE KEY (coupon_id, customer_id)` to
`coupon_usages` when `usage_limit_per_user = 1` is the common case, or
wrap the check+insert in `SELECT ... FOR UPDATE` inside the transaction.

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
| 1.3 | Coupon usage race condition (double redemption) | 🟡 Medium | Pricing |
| 2.1 | OTP request has no rate limit | 🔴 High (once SMTP live) | Security |
| 2.2 | `debug_otp` exposed in API response | 🔴 High (pre-launch blocker) | Security |
| 2.3 | GitHub PAT pasted in chat — confirm revoked | 🟡 Medium | Security |
| 2.4 | No idempotency on order creation (double order) | 🔴 High (once payments live) | Security/Money |
| 3.1 | No admin panel — due-limit/approval/settings unoperated | 🔴 High (structural) | Admin |
| 3.2 | No restaurant-side write path for discount/bestseller flags | 🟡 Medium | Restaurant App |
| 3.3 | Service-area check is city-wide, not radius-precise | 🟢 Low | UX accuracy |
| 4.1 | Notification system has no template pool / triggers yet | 🔴 (scope gap, not a bug) | Notifications |

**Priority for fixing, independent of new-feature work:** 1.1 and 2.2 are
the two that matter most before anything money- or auth-related ships
further (1.1 before the restaurant discount/coupon UI goes live, 2.2
before real users ever hit production). 1.2 matters before COD OTP is
ever turned on. The rest can ride alongside the new roadmap items below.
