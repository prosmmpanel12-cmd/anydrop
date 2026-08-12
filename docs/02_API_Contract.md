# Anydrop — API Contract (PHP Backend)

**Version:** 1.0 · **Base URL (example):** `https://anydrop.infinityfreeapp.com/api/v1`

All endpoints return JSON: `{ "success": true|false, "data": {...}, "error": null|"message" }`.
Auth via `Authorization: Bearer <token>` header (JWT-style token, PHP-generated, stored in `auth_tokens` table — not shown in schema doc for brevity but follows same pattern as other tables: `id, owner_type, owner_id, token_hash, expires_at`).

---

## 1. Authentication

### `POST /auth/customer/google`
Login/register via Google Sign-In.
**Request:** `{ "id_token": "..." }` (Google ID token from Android app)
**Response:** `{ "customer": {...}, "token": "...", "is_new_user": true|false }`

### `POST /auth/customer/email/request-otp`
**Request:** `{ "email": "user@example.com" }`
**Response:** `{ "message": "OTP sent" }`
**Notes:** OTP generated server-side, emailed via SMTP (configured in `app_settings`), 6-digit, expires per `otp_expiry_minutes` setting.

### `POST /auth/customer/email/verify-otp`
**Request:** `{ "email": "...", "otp": "123456" }`
**Response:** `{ "customer": {...}, "token": "..." }`

### `POST /auth/restaurant/login`
**Request:** `{ "email": "...", "password": "..." }`
**Response:** `{ "restaurant": {...}, "token": "..." }`
**Errors:** `401 invalid_credentials`, `403 account_suspended`, `403 pending_approval`

### `POST /auth/rider/login`
Same pattern, username+password.

### `POST /auth/admin/login`
Same pattern.

---

## 2. Customer — Discovery

### `GET /restaurants?lat=&lng=&filter=&sort=&page=`
**Auth:** Customer token
**Query params:** `filter` (veg, open_now, free_delivery...), `sort` (rating, delivery_time, distance)
**Response:** Paginated list. Each item includes computed `distance_km`, `estimated_delivery_minutes`, `is_open_now` (computed server-side from `opening_time`/`closing_time`/`operational_status`, never trust client clock).
**Caching:** Response cacheable client-side for 60 seconds (restaurant list doesn't need to be instant-fresh).

### `GET /restaurants/{id}`
Full restaurant profile + offers + policies.

### `GET /restaurants/{id}/menu`
Returns categories with nested items. **Cache-Control: max-age=300** — menus change rarely, this is the single highest-traffic endpoint so caching here matters most for server cost.

### `GET /search?q=&lat=&lng=`
Searches restaurant names + item names. Returns matched restaurants ranked by relevance + distance.

---

## 3. Customer — Cart & Checkout

### `POST /cart/validate`
Client keeps cart locally; before checkout, sends full cart to server for authoritative price/availability check.
**Request:** `{ "restaurant_id": 1, "items": [{ "menu_item_id": 5, "variant_id": null, "addon_ids": [2,3], "quantity": 2 }], "coupon_code": "SAVE50" }`
**Response:** `{ "item_total": ..., "discount": ..., "delivery_charge": ..., "platform_fee": ..., "tax": ..., "grand_total": ..., "invalid_items": [] }`
**Why server-side:** prevents stale/tampered prices from a client that cached an old menu.

### `POST /orders`
Places the order.
**Request:** `{ "restaurant_id", "items": [...], "delivery_address_id", "payment_method": "upi"|"cod", "coupon_code", "delivery_instructions" }`
**Response:** `{ "order": {...}, "order_code": "QRX-..." }`
**Server logic:** re-validates cart server-side (never trusts client total), checks restaurant is open & not over due-limit, checks min order amount, writes `order_status_history` row, triggers restaurant push notification.

### `GET /orders/{id}` — full order detail + timeline
### `GET /orders/{id}/track` — polled every few seconds while active
**Response:** `{ "status", "rider": { "name","mobile","lat","lng" } | null, "eta_minutes", "otp": "1234" (only if status=rider_assigned/out_for_delivery and payment_method=upi) }`

### `POST /orders/{id}/cancel`
Allowed only in `pending`/`accepted` states (business rule — configurable window).

### `GET /coupons?restaurant_id=&item_total=` — H5, "View all offers & coupons" on Checkout
Lists every coupon usable for that order — platform-wide (`restaurant_id IS NULL`) +
that restaurant's own — active + in-date, same eligibility columns `price_cart()` already
checks. `item_total` is optional; when sent, each coupon is flagged `is_eligible` (false if
usage-limit-exhausted or the cart is still below its `min_order_amount`, with
`amount_needed_to_unlock` telling the UI how much more to add).
**Response:** `{ "coupons": [{ "code", "discount_type", "discount_value", "min_order_amount", "max_discount_amount", "valid_until", "is_restaurant_specific", "is_eligible", "ineligible_reason", "amount_needed_to_unlock" }] }`
**Android:** tapping an eligible row in the list fills `inputCouponCode` and calls the
existing `applyCoupon()` — no new apply logic, just a browse/pick UI in front of it.

---

## 4. Restaurant App

### `GET /restaurant/orders?status=&page=`
### `POST /restaurant/orders/{id}/accept`
### `POST /restaurant/orders/{id}/reject` — `{ "reason": "..." }`
### `POST /restaurant/orders/{id}/status` — `{ "status": "preparing"|"ready" }`
### `POST /restaurant/orders/{id}/assign-rider` — `{ "rider_id": 5 }` → generates delivery OTP if payment_method=upi, sends OTP via email to customer + stores it (never sent to rider).

### `GET /restaurant/menu` / `POST /restaurant/menu/items` / `PUT .../{id}` / `DELETE .../{id}`
Standard CRUD, restaurant-scoped (enforced server-side by token owner, never trust `restaurant_id` in body for writes).

### `GET /restaurant/riders` / `POST /restaurant/riders` (create) / `PUT /restaurant/riders/{id}` (edit/disable)
**Request (create):** `{ "name", "username", "password", "mobile" }` — server hashes password, checks username uniqueness.

### `GET /restaurant/dashboard` — today's orders/earnings summary, computed server-side (not client-aggregated, to avoid trusting client math)

### `GET /restaurant/due-ledger` — paginated ledger entries + current balance

---

## 5. Rider App

### `POST /rider/status` — `{ "is_online": true|false }`
### `GET /rider/current-order` — the order currently assigned, or null
### `POST /rider/orders/{id}/accept` / `/reject`
### `POST /rider/orders/{id}/picked-up`
### `POST /rider/orders/{id}/verify-otp` — `{ "otp": "1234" }`
**Response on success:** `{ "success": true, "order_status": "delivered" }`
**Response on failure:** `{ "success": false, "error": "invalid_otp", "attempts_remaining": 2 }`
**Server logic:** increments `otp_attempts`; at `otp_max_attempts` exceeded, flags order for restaurant/admin manual override, logs to `audit_logs`.

### `POST /rider/location` — **the highest-frequency endpoint in the whole system**
**Request:** `{ "lat": ..., "lng": ..., "order_id": 123 (nullable), "speed_kmh": ... }`
**Response:** `{ "success": true }` (deliberately tiny — this call happens every few seconds)
**Server logic:** inserts into `rider_locations`, updates `riders.last_lat/last_lng` (denormalized for fast reads by customer/restaurant polling). See `03_Live_Tracking.md` for interval strategy.

---

## 6. Admin Panel (web, session-auth instead of Bearer token since it's server-rendered)

### `POST /admin/restaurants/{id}/approve` / `/reject` / `/suspend` / `/activate`
### `PUT /admin/restaurants/{id}/settings` — override commission%, due limit for this restaurant
### `GET /admin/restaurants/{id}/due-ledger`
### `POST /admin/restaurants/{id}/payments/{payment_id}/verify` — marks payment verified, inserts negative ledger entry, auto-reactivates restaurant if now under due limit
### `GET /admin/dashboard` — platform-wide stats
### `GET|PUT /admin/settings` — reads/writes `app_settings` table directly (this is the "nothing hardcoded" control surface)
### `GET /admin/reports?type=&range=&format=csv`

---

## 7. System / Pseudo-Cron Endpoints

Since InfinityFree does not run real background jobs, these are **PHP endpoints hit by a free external scheduler** (e.g. cron-job.org, pinged every 5 minutes) instead of true cron:

### `POST /system/cron/check-due-limits`
Scans restaurants where `current_due >= restaurant_due_limit_setting`, sets `status='suspended'`, sends warning notification. Idempotent — safe to call repeatedly.

### `POST /system/cron/expire-old-otps`
Marks orders with expired unverified OTPs, flags for manual handling.

### `POST /system/cron/cleanup-rider-locations`
Deletes `rider_locations` rows older than 48 hours — keeps this hot table small.

**Security note:** these endpoints require a shared secret key (`?key=...` from `app_settings`), not open to the public internet without it.

---

## 8. Standard Error Codes (used across all endpoints)

| Code | Meaning |
|---|---|
| `401 unauthorized` | Missing/invalid/expired token |
| `403 forbidden` | Valid token, wrong role/ownership |
| `403 account_suspended` | Restaurant/customer/rider suspended |
| `404 not_found` | |
| `409 conflict` | e.g. duplicate username |
| `422 validation_error` | With `data.fields` map of field→message |
| `429 rate_limited` | |
| `500 server_error` | Logged internally, generic message to client |

## 9. Pagination Standard

All list endpoints: `?page=1&per_page=20`, response includes `{ "data": [...], "meta": { "page","per_page","total","total_pages" } }`.
