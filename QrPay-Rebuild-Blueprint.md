# QrPay — Complete Build Plan
### (Rebuild of "UPI PE" → renamed **QrPay**)

**Owner login note:** current API-key-only login has a real risk — a leaked key lets
anyone into the dashboard and lets them change UPI ID / MID and redirect real money.
This plan replaces that with Email + OTP login, and separates *dashboard identity*
from *API authentication* so a leaked API key alone can no longer take over the account.

---

## 0. Ground Rules (apply to every phase)

- No hardcoded secrets in code. DB password, JWT/session secret, SMTP creds, admin
  UPI/MID all live in environment variables or an untracked `.env` — never in a
  committed file.
- SSL verification stays **ON** for every outbound cURL call (`CURLOPT_SSL_VERIFYPEER`
  / `VERIFYHOST` = true). No exceptions, including refund/status calls.
- Every write endpoint uses prepared statements (already the pattern in the old
  code — keep it).
- Every plan/price/limit lives in the database, editable by admin — nothing about
  pricing is hardcoded in PHP.
- Only **PAID** orders ever increment usage counters. PENDING / EXPIRED / REJECTED
  never count against a developer's quota.
- QR-only checkout: no `gpay://`, `phonepe://`, `paytmmp://` deep links anywhere in
  API responses or UI. Just `upi_id` + `qr_url`.

---

## Phase 1 — Database Schema

New/changed tables:

```
developers
  id, email (unique), email_verified, apikey (unique), status (active/suspended),
  created_at

otp_codes
  id, email, otp_hash, purpose (login/signup), expires_at, attempts, created_at

plans
  id, name (basic/pro/premium), monthly_price, yearly_price,
  yearly_discount_percent, payment_limit (NULL = unlimited), is_active

coupons
  id, code (unique), discount_type (flat/percent), discount_value,
  valid_from, valid_till, max_uses, used_count, applicable_plans (json/NULL=all), is_active

subscriptions
  id, developer_id, plan_id, billing_cycle (monthly/yearly),
  starts_at, expires_at, status (active/expired/cancelled)

usage_counters
  id, developer_id, cycle_start, cycle_end, verified_count

free_trial
  developer_id (PK), used_count, max_count DEFAULT 15, exhausted (bool)

admin_settings
  singleton row: owner_upi_id, owner_mid, owner_display_name
  (used only when a developer is paying QrPay itself for a plan)

user_settings   (existing, kept)
  developer_id, upi_id, mid, display_name

payment_orders  (existing, modified)
  + order_purpose ENUM('customer_payment','subscription_purchase')
  + subscription_ref (nullable FK to the (plan_id, billing_cycle, coupon) being purchased)
  (no schema field for deep links — they were never stored, just generated in response)
```

**Deliverable:** `config/schema.sql` (fresh) + a migration script for anyone
upgrading from the old `upi_id`/`mid`-only schema.

---

## Phase 2 — Core Config & Helpers

- `config/db.php` — PDO connection, credentials from environment variables only.
- `config/env.example` — template listing every required env var (DB, SMTP, session
  secret, admin UPI/MID) with no real values.
- `core/helpers.php` — kept (`success()`, `fail()`, `httpGet/Post` with SSL verify
  forced on).
- `core/plan_limits.php` — new. Given a `developer_id`, returns:
  - free trial remaining, active subscription + its limit, current cycle usage,
    and a single `can_accept_payment()` boolean used by `create_order.php`.
- `core/mailer.php` — new. Sends OTP emails (PHPMailer + SMTP, creds from env).

---

## Phase 3 — Developer Auth (Email + OTP, no password)

Endpoints:
- `auth/request_otp.php` — email in → generates 6-digit OTP, stores **hashed**,
  5-minute expiry, rate-limited (max N requests per email per hour) → emails it.
- `auth/verify_otp.php` — email + otp in → on match: creates developer row if new
  (first-time signup), issues session, grants **15 lifetime free payments**,
  clears OTP.
- `panel/logout.php` — kept as-is.
- Session cookie flags hardened: `HttpOnly`, `Secure`, `SameSite=Strict`.

API key is now shown **read-only** in the dashboard after login — it's used only
for `Authorization` on the `/api/*` endpoints, never for dashboard login itself.
Regenerating it is a dashboard action (requires an active session), so a leaked
key can be rotated without needing the old one.

---

## Phase 4 — Order Creation & Verification (QR-only, limit-enforced)

`api/create_order.php` changes:
- Before creating an order: call `can_accept_payment($developer_id)`.
  - Free trial has room → allow, tag order as trial-covered.
  - Else active subscription with room in current cycle → allow.
  - Else (limit hit / no active plan) → **hard block**, `fail('Payment limit reached. Upgrade your plan.', 403)`.
    No order row is created.
- Response payload: remove `deep_links` block entirely. Keep `upi_id`, `upi_link`
  (the raw `upi://pay...` string, needed to generate the QR), and `qr_url`.

`api/verify_payment.php` changes:
- Logic kept as-is (auto via Paytm `getTxnStatusNew`, manual UTR fallback after
  5 minutes) — this part already works per your confirmation.
- On transition to `PAID`: increment `usage_counters` for that developer's current
  billing cycle (or `free_trial.used_count` if the order was trial-covered).
- `PAYTM_MERCHANT_KEY` stops being a single hardcoded constant — pulled from
  `user_settings` per developer.

---

## Phase 5 — Plans, Coupons & Self-Referential Subscription Purchase

- `api/plans_list.php` — public: returns active plans with monthly/yearly price,
  yearly discount, and limits — dashboard renders pricing cards from this.
- `api/coupon_validate.php` — takes a code + plan + cycle, returns discounted price
  or an error (expired / max uses hit / not applicable to this plan).
- `api/subscribe.php` — developer picks plan + cycle (+ optional coupon) →
  server computes final price → creates a `payment_orders` row with
  `order_purpose = 'subscription_purchase'`, using **admin's own UPI ID/MID**
  from `admin_settings` (not the developer's own UPI ID) → returns QR like any
  other order.
- Verification reuses `verify_payment.php`, but on `PAID` for a
  `subscription_purchase` order: upsert the `subscriptions` row (extend
  `expires_at` by 1 month/1 year from now, or from current `expires_at` if
  renewing early), bump `coupons.used_count` if one was applied.
- A daily cron (`cron/expire_subscriptions.php`) flips `subscriptions.status`
  to `expired` past `expires_at`, and expired developers fall back to
  "no active plan" (blocked, unless free trial still has room — which it won't,
  since it's one-time).

---

## Phase 6 — New Dashboard UI (from scratch)

Screens:
1. **Login** — email input → OTP input (two-step, one page).
2. **Overview** — current plan, cycle usage (`X / limit` or `X / ∞`), free-trial
   status if still new, quick stats (today/this month PAID orders, amount collected).
3. **Settings** — set/update UPI ID, MID, display name; view (not edit) API key,
   button to regenerate it.
4. **Orders** — searchable/paginated list of `payment_orders`, manual-approve UI
   for `MANUAL_PENDING` rows (calls `api/manual_action.php`).
5. **Billing** — plan cards (Basic/Pro/Premium × Monthly/Yearly with discount
   shown), coupon input, "Pay with QR" → shows QR for the subscription order,
   polls `verify_payment.php` until `PAID`, then reflects the new plan instantly.
6. **Billing History** — past subscription purchases, amounts, coupons used.

Built as clean server-rendered PHP (matching the rest of the stack) with a
modern component style — no legacy dashboard code reused.

---

## Phase 7 — Admin Panel

Separate, more privileged login (kept simplest as a fixed admin email allow-list
checked at OTP-verify time, so it reuses the same OTP mechanism instead of a
second auth system):

- Manage `plans` (edit prices, limits, yearly discount, activate/deactivate).
- Manage `coupons` (create/edit/expire).
- Manage `admin_settings` (the UPI ID/MID QrPay itself receives subscription
  payments on).
- View all developers, suspend/reactivate, view usage.
- View all `MANUAL_PENDING` orders across every developer (support/oversight).

---

## Phase 8 — Cleanup & Hardening Pass

- Remove `sdk/examples/*` deep-link usage, update SDK to match the new
  QR-only response shape.
- Confirm every outbound cURL call (login-era `httpPost`, Paytm status/refund
  calls) has SSL verification on.
- Rate-limit `auth/request_otp.php` per email (not per IP, since developer
  traffic can share IPs).
- Enforce per-`apikey` request throttling on `api/*` (not IP-based, as agreed).
- Re-run through `create_order.php` / `verify_payment.php` / `manual_action.php`
  once more end-to-end after all changes, on a staging DB, before going live.

---

## Open Items for a Later Pass (not blocking the phases above)

- Refund API integration — your current `encdec_paytm.php` refund functions are
  written against Paytm's **legacy** PG format. Current Paytm Refund API uses a
  JSON `head`/`body` + `signature` structure and is fully async (status must be
  polled separately). This needs its own phase once the core rebuild is stable,
  and needs confirmation of which Paytm PG version your MID is actually on.

---

## Suggested Build Order

Phase 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8, each phase reviewed before moving to the
next so nothing breaks silently. Phase 4 (order creation/verification) is the
one that touches real money — it gets the most scrutiny and should be tested
against a staging DB before touching production data.
