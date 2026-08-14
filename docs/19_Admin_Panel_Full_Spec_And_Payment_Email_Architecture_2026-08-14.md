# Admin Panel — Full Spec + Payment/Email-OTP Architecture + Analytics (2026-08-14)

**Status: planning only, nothing in this doc built yet.** Written per app
owner's full requirement dump this session, cross-checked against the
existing schema (`01_Database_Schema.md`) and API contract
(`02_API_Contract.md`) so nothing gets re-specified that already exists,
and every genuinely-new piece is flagged with what table/endpoint it
needs. Same `✅ exists · 🟢 small · 🟡 medium (new table/screen) ·
🔴 large (own phase)` legend as `18_Restaurant_App_Full_Scope...md`.

**Explicitly excluded per app owner's instruction:** Referral system —
removed from this plan entirely, not deferred, not mentioned again below.

---

## 0. How this doc is organized

1. Roles & Permissions (the foundation everything else sits on)
2. Area Management (State→District→City→Area — also foundational, since
   restaurant visibility, banners, notifications, and analytics all key
   off it)
3. Super Admin feature list (mapped module-by-module against schema)
4. Payout / Settlement system
5. Email OTP — multi-provider architecture
6. Payment — provider architecture (UPIPE first, pluggable)
7. Analytics & Reports module (the big one)
8. Impersonation ("login as restaurant")
9. Restaurant App — new items surfaced by this session
10. Customer App — new items surfaced by this session
11. Database schema additions (consolidated)
12. Build order recommendation

---

## 1. Roles & Permissions

### Why `admins.role ENUM('super_admin','staff')` isn't enough anymore
The current schema has a single flat enum. App owner's ask is a real
RBAC system: named roles (Finance Admin, Restaurant Manager, Customer
Support, Marketing Admin, ...) each with a **per-module, per-action**
checkbox grid (View/Add/Edit/Delete/Export/Approve/Reject) — not just a
fixed "staff" bucket. That needs three new tables, replacing the enum.

### Schema
```sql
-- Replaces admins.role ENUM
CREATE TABLE admin_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL,          -- 'Super Admin', 'Finance Admin', ...
    is_system_role TINYINT(1) DEFAULT 0,       -- Super Admin = 1, can't be deleted/edited
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(60) UNIQUE NOT NULL,   -- e.g. 'restaurants_edit', 'payouts_manage'
    module VARCHAR(40) NOT NULL,         -- 'restaurants', 'payouts', 'orders', ...
    action VARCHAR(20) NOT NULL          -- 'view','add','edit','delete','export','approve','reject','manage','send'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_arp_role FOREIGN KEY (role_id) REFERENCES admin_roles(id),
    CONSTRAINT fk_arp_perm FOREIGN KEY (permission_id) REFERENCES admin_permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- admins table changes:
--   DROP COLUMN role (the old ENUM)
--   ADD COLUMN role_id BIGINT UNSIGNED NOT NULL, FK -> admin_roles.id
--   ADD COLUMN name VARCHAR(100)
--   ADD COLUMN email VARCHAR(150) UNIQUE
--   ADD COLUMN is_active TINYINT(1) DEFAULT 1
--   ADD COLUMN last_login_at TIMESTAMP NULL
```

**Seed permission keys** (matches app owner's list, module-prefixed so
the same action name can exist per module without colliding):
```
dashboard_view
restaurants_view, restaurants_edit, restaurants_delete, restaurants_approve, restaurants_export
riders_view, riders_edit, riders_delete, riders_approve, riders_export
customers_view, customers_edit, customers_suspend, customers_delete, customers_export
areas_view, areas_edit, areas_delete
categories_view, categories_edit, categories_delete
coupons_view, coupons_edit, coupons_delete, coupons_export
banners_view, banners_edit, banners_delete
payouts_view, payouts_manage, payouts_export
orders_view, orders_manage, orders_export
reports_view, reports_export
notifications_send, notifications_view
settings_manage
roles_manage
audit_logs_view
app_version_manage
cms_manage
support_view, support_manage
fraud_view, fraud_manage
email_providers_manage
payment_providers_manage
```
**Super Admin role** gets every key (seeded at install, `is_system_role=1`,
can't be edited/deleted from the UI — prevents someone accidentally
locking themselves out). Every other role is just a subset, assigned
via the checkbox grid the app owner described.

### Enforcement pattern
Every `/admin/*` endpoint's `require_admin_auth()` (new, mirrors the
existing `require_auth()` pattern used by `/restaurant/*` and
`/customer/*`) additionally checks the calling admin's role has the
specific permission key needed for that endpoint+action — e.g.
`DELETE /admin/restaurants/{id}` requires `restaurants_delete`, `POST
/admin/restaurants/{id}/approve` requires `restaurants_approve`. A
403 with the missing permission key in the response (not just a bare
"forbidden") makes the frontend able to hide/disable the button
proactively instead of only failing server-side.

### Example role → permission mapping (matches the examples given)
| Role | Permissions |
|---|---|
| **Finance Admin** | `payouts_manage`, `payouts_view`, `reports_view`, `reports_export`, `orders_view` (refunds need order context) — explicitly **no** `restaurants_delete` |
| **Restaurant Manager** | `restaurants_view`, `restaurants_edit`, `restaurants_approve` — menu verify ties into the existing menu endpoints, scoped read/approve only |
| **Customer Support** | `orders_view`, `orders_manage` (limited — status view + refund-request flagging, not full override), `customers_suspend`, `support_manage` |
| **Marketing Admin** | `banners_edit`, `coupons_edit`, `notifications_send` |
| **Read Only Admin** | every `*_view` key, nothing else — useful for investors/stakeholders who need visibility without edit risk |

---

## 2. Area Management

This is the piece everything else (restaurant visibility, banner
targeting, notification targeting, analytics filters) depends on, so it
has to land early.

### Why a single self-referencing table, not four separate ones
InfinityFree's MySQL has no stored-procedure reliance and the schema
doc's own convention favors simple InnoDB tables. A 4-level rigid
State/District/City/Area hierarchy as **one adjacency-list table** (each
row points to its parent) is simpler to query and extend than four
separate tables with four separate FK chains, and matches how the
example was given (Rajasthan → Jodhpur → Osian, i.e. variable depth is
fine — some areas might not need a full 4-level chain).

```sql
CREATE TABLE service_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,           -- NULL = top level (State)
    level ENUM('state','district','city','area') NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,           -- can disable an area without deleting it
    center_lat DECIMAL(10,8) NULL,            -- for 'area' level: used to resolve
    center_lng DECIMAL(11,8) NULL,            -- which area a customer's GPS pin falls into
    radius_km DECIMAL(4,1) NULL,              -- simple radius-based resolution (v1) —
                                                -- polygon/geofence is a later upgrade
    created_at, updated_at TIMESTAMP,
    CONSTRAINT fk_area_parent FOREIGN KEY (parent_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**`restaurants.area_id`** — new FK column, set at restaurant creation
(State → District → City → Area picker, cascading dropdowns in the
Admin/Restaurant-onboarding form).

**Customer-side resolution:** `customer_addresses` gets a new
`area_id` column too, resolved server-side when an address is
saved/selected — nearest `service_areas` row at `level='area'` where
the address's lat/lng falls within `radius_km` of `center_lat/lng`.
Home-feed / restaurant-list queries then filter `WHERE
restaurants.area_id = :customer_area_id` instead of (or in addition to)
the existing pure-radius `delivery_radius_km` check — **this is a
behavior change worth the app owner's explicit sign-off**: right now
restaurant visibility is presumably pure GPS-radius; moving to
area-based visibility means a customer just outside an area's radius
but geographically close won't see that area's restaurants anymore.
Recommend keeping both checks (area match **and** radius) rather than
area-only, so it tightens rather than silently changes existing
behavior.

**v1 simplification, flagged explicitly:** radius-based area resolution
(a circle) is good enough to ship with and much cheaper to build than
true polygon geofencing. If area boundaries turn out to overlap or miss
real neighborhoods in practice, polygon support (`area_boundaries` table
storing a GeoJSON polygon per area, point-in-polygon check) is a
follow-up, not a v1 blocker.

---

## 3. Super Admin — module-by-module

### Dashboard
🟡 New — cards for Today's Orders, Revenue, Active Users (live counts,
mirrors "Live Dashboard" in the Analytics section §7 but this is the
lightweight always-visible version on Admin login, not the full
filterable analytics screen).

### Restaurant / Rider Approval
🟢 — `restaurants.status` already has `pending/approved/rejected/
suspended`; riders schema currently has no equivalent approval state —
**needs `riders.status ENUM('pending','approved','rejected','suspended')
DEFAULT 'pending'`** added (currently only `is_active`, which is a
restaurant-controlled toggle, not a platform approval gate). Admin
screens: list pending, view submitted docs (§6 below), Approve/Reject
with optional reason.

### Customer / Restaurant / Rider Management
🟢 mostly — list/search/filter screens over existing tables.
Customer: suspend (`customers.is_active` already exists) / ban / delete
(soft delete via existing `deleted_at`), view their orders/addresses
(existing tables) — **wallet** needs a new table (no `customer_wallet`
currently exists — flagging as new, see below).

### Category Management
✅ mostly — `menu_categories` exists per-restaurant already. If "Category
Management" here means **platform-level** cuisine/restaurant-type tags
(Veg/Non-Veg/Cafe/Bakery/Sweet Shop/Pharmacy/Grocery — used for the
Restaurant Filter in §5), that's a different, smaller new table:
```sql
CREATE TABLE restaurant_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) UNIQUE NOT NULL,   -- 'Cafe', 'Bakery', 'Pharmacy', 'Grocery', ...
    icon_url VARCHAR(255) NULL,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
`restaurants.category_id` FK added (separate from the existing
`cuisine_tags` free-text field, which stays as-is for menu-style
cuisine labels like "North Indian, Chinese" — category is the
higher-level business-type filter).

### Wallet
🟡 new — no wallet system exists anywhere in the current schema.
```sql
CREATE TABLE customer_wallets (
    customer_id BIGINT UNSIGNED PRIMARY KEY,
    balance DECIMAL(10,2) DEFAULT 0,
    updated_at TIMESTAMP,
    CONSTRAINT fk_wallet_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);
CREATE TABLE wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    type ENUM('credit','debit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(100),               -- 'refund', 'admin_adjustment', 'cashback'
    balance_after DECIMAL(10,2) NOT NULL,
    created_by ENUM('system','admin') NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```
Same append-only-ledger pattern as `restaurant_due_ledger` — consistent
with the schema doc's own stated reasoning (§11 of the schema doc) for
why a mutable balance alone isn't enough.

---

## 4. Restaurant Filter, Restaurant Details, Order Control

### Restaurant Filter (Admin restaurant list screen)
🟢 — all filterable fields already exist or are added above:
Type (`restaurant_categories`, new), Veg/Non-Veg (`is_veg_only`,
exists), Open/Closed/Busy/Temp-Closed (`operational_status`, exists —
reuses `compute_restaurant_status()` from doc 18), Verified/Not
Verified (`status = 'approved'` vs not, exists), Top Rated
(`rating_avg`, exists — sort, not a new field).

### Restaurant Details (Admin's per-restaurant view)
🟢 — every field named (GST, PAN, Owner, UPI, Bank, Opening/Closing
Time, FSSAI, Cancelled/Completed Orders, Rating, Commission) already
exists in `restaurants` **except**:
- **PAN** — not in current schema, needs `restaurants.pan_number
  VARCHAR(15) NULL` added
- **Bank details** (Account Holder Name, Bank Name, Account Number,
  IFSC) — not in current schema, see §6's `restaurant_bank_details`
  table (kept separate from `restaurants` deliberately — sensitive
  financial data, own table = easier to apply stricter access
  control/encryption-at-rest later without touching the main table)
- **Documents** (FSSAI certificate file, not just the number) — needs a
  small `restaurant_documents` table (`id, restaurant_id, doc_type
  ENUM('fssai','gst','pan','other'), file_url, uploaded_at,
  verified_by_admin_id NULL, verified_at NULL`) — same upload
  infrastructure already flagged as needed once (doc 18, Tier 1 photo
  upload) reused here.
- Cancelled/Completed Orders, Rating — computed from existing `orders`/
  `reviews` tables, no schema change, just aggregation queries.

### Order Control
✅ mostly — `orders.status` enum already covers every state named
(pending/accepted/preparing/picked_up/delivered/cancelled/refunded/
failed — `rider_assigned`/`out_for_delivery`/`ready`/`expired` also
already exist, superset of what was asked). "Admin can manually update
any order" — needs one new endpoint, `PUT /admin/orders/{id}/status`,
writing to `order_status_history` with `changed_by_type='admin'`
(column already supports this value) — no schema change, just the
endpoint + a permission gate (`orders_manage`).

---

## 5. Banner Manager

🟡 new table — nothing like this exists currently (only
`app_settings.home_promo_*` flat fields for a single promo banner,
per `Status.md`'s existing note).

```sql
CREATE TABLE banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150),
    image_url VARCHAR(255) NOT NULL,
    banner_type ENUM('home','offer','festival','popup') DEFAULT 'home',
    deep_link VARCHAR(255) NULL,        -- e.g. app-internal route or restaurant/coupon id
    area_id BIGINT UNSIGNED NULL,       -- NULL = shown to all areas; set = area-scoped
    priority INT DEFAULT 0,             -- higher shows first when multiple active
    start_date DATE NULL,
    end_date DATE NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at, updated_at TIMESTAMP,
    CONSTRAINT fk_banner_area FOREIGN KEY (area_id) REFERENCES service_areas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Area-scoping is exactly the app owner's explicit requirement** — "no
promotional posters ho wo us area ke customer ko hi dikhe": the
customer app's banner-fetch endpoint filters `WHERE area_id IS NULL OR
area_id = :customer_area_id`, so a banner set for Osian never reaches a
Jodhpur customer, and a `NULL` area_id banner (platform-wide, e.g. a
festival banner) reaches everyone.

---

## 6. Payout / Settlement System

The schema **already has the right shape** for this
(`restaurant_due_ledger`, `restaurant_payments`) — this section extends
`restaurant_payments` rather than replacing it, and adds bank details.

```sql
-- restaurant_payments gets these new columns:
ALTER TABLE restaurant_payments
    ADD COLUMN utr_number VARCHAR(30) NULL,
    ADD COLUMN screenshot_url VARCHAR(255) NULL,
    ADD COLUMN remarks VARCHAR(255) NULL,
    ADD COLUMN payment_date DATE NULL,
    ADD COLUMN settled_by_admin_id BIGINT UNSIGNED NULL;

CREATE TABLE restaurant_bank_details (
    restaurant_id BIGINT UNSIGNED PRIMARY KEY,
    account_holder_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL,
    account_number VARCHAR(30) NOT NULL,   -- consider app-level encryption before storing
    ifsc_code VARCHAR(15) NOT NULL,
    upi_id VARCHAR(100) NULL,
    updated_at TIMESTAMP,
    CONSTRAINT fk_bank_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Admin flow (per-restaurant Payout screen)
Computed from existing `restaurant_due_ledger` + `orders`, no new
aggregation tables needed for v1:
- Total Orders, Cash Collected (`SUM` where `payment_method='cod'`),
  Online Collected (`payment_method='upi'`), Commission
  (`SUM(commission_amount)`), GST (needs a GST-on-commission calc —
  ties to `app_settings.gst_percent`, new setting key), Net Payable,
  Already Paid (`SUM` of verified `restaurant_payments`), Pending
  (`current_due` cache, already exists)
- **"Pay Now" button** → opens the form: Upload Screenshot, Transaction
  ID, UTR Number, Amount, Date, Remarks → `Save` inserts a
  `restaurant_payments` row (`status='verified'` since admin is doing
  it directly, not restaurant self-reporting) **and** a
  `restaurant_due_ledger` row (`entry_type='payment_received'`, negative
  amount, updates `restaurants.current_due`) — both writes in one
  transaction so the ledger and the payment record never drift apart.
- On save, fires a notification to the restaurant: *"₹25,000 payout
  completed"* — reuses the existing `notifications` table
  (`recipient_type='restaurant'`, `type='system'`) and, once Phase J's
  FCM path is live, an actual push.

This is exactly the **Restaurant Settlement** flow described in the
Payment Architecture doc (§6 below) too — same feature, described twice
from two angles (Payout System + Settlement); this doc treats them as
one build, not two.

### A correction worth flagging before building this
The original §6 draft (and the "Pay Now" flow above) only describes
**admin → restaurant** money movement. That's only half the real
picture, because money flows **both directions** depending on payment
method:

- **Online/UPI orders** — customer pays into the **admin's UPIPE
  account** (per §8's Payment Architecture). Admin now owes the
  restaurant their share (`grand_total − commission_amount −
  platform_fee`). Until settled, **admin owes restaurant** — call this
  a payable, not a due.
- **COD orders** — customer pays the **restaurant directly**, in cash.
  The restaurant now owes the admin the commission on that order.
  Until settled, **restaurant owes admin** — this is the existing
  `restaurant_due_ledger`/`current_due` behavior exactly as already
  documented in the schema doc.

A restaurant with a mix of both order types has a single **net**
balance that can swing either way. `restaurants.current_due` needs to
be read as *signed*, not always "restaurant owes admin":

| Sign of `current_due` | Meaning |
|---|---|
| Positive | Restaurant owes admin (COD commissions not yet settled) |
| Negative | Admin owes restaurant (online-order payouts not yet paid out) |
| Zero | Fully settled |

**Ledger entry types, extended** (adds to the existing
`restaurant_due_ledger.entry_type` enum):
```sql
ALTER TABLE restaurant_due_ledger
    MODIFY entry_type ENUM(
        'commission_cod',        -- +amount: COD order, restaurant owes admin the commission
        'payout_payable',        -- -amount: online order, admin owes restaurant their share
        'platform_fee',          -- +amount: existing behavior, unchanged
        'settlement_to_restaurant', -- -amount: admin actually paid restaurant (brings due back toward 0 from negative... 
                                     -- i.e. reduces the amount admin owes)
        'settlement_from_restaurant', -- -amount: restaurant actually paid admin (reduces positive due)
        'manual_adjustment'
    ) NOT NULL;
```
(Signs above are relative to `current_due` — a `payout_payable` entry
makes `current_due` more negative; a `settlement_to_restaurant` entry
moves it back toward zero from negative; a `commission_cod` entry makes
`current_due` more positive; a `settlement_from_restaurant` entry moves
it back toward zero from positive. The **direction** of any given
"Pay Now" action is simply read off the current sign — the UI decides
"admin pays restaurant" vs "restaurant pays admin" automatically from
which way the balance is currently leaning, so the person clicking
Pay Now never has to manually pick a direction and risk getting it
backwards.)

`restaurant_payments` gets one more column to record which way a given
settlement went:
```sql
ALTER TABLE restaurant_payments
    ADD COLUMN direction ENUM('admin_to_restaurant','restaurant_to_admin') NOT NULL;
```

### Per-restaurant full statement (the "kitna aaya kitna gaya" view)
New Admin screen — **Restaurant Ledger Statement** — a plain
running-balance table, every row from `restaurant_due_ledger` for that
restaurant in chronological order: date, entry type, order reference
(if any), amount (shown as **+ green** for anything increasing what's
owed to admin / **− red** for anything reducing it or paid out),
running balance after that row. This is a direct read of the existing
append-only ledger — no new aggregation logic, just an unfiltered
chronological list with a running total column, exactly the "har entry
ka in/out dikhna chahiye" requirement. Filterable by date range,
exportable (reuses the Excel/PDF export already planned for Analytics).

---

## 6b. Platform Accounting Ledger — total money in / total money out

Everything in §6 above is **per-restaurant**. This section is the
**platform-wide** view — the actual answer to "total kitna aaya, kaha
kaha gaya" across the whole business, not just one restaurant at a
time. New table, separate from the per-restaurant ledger, since it's
tracking a different thing (the admin's own UPIPE merchant account
balance, not any single restaurant's due):

```sql
CREATE TABLE platform_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_type ENUM(
        'customer_payment_in',       -- +amount: online order paid into admin's UPIPE account
        'restaurant_settlement_in',  -- +amount: restaurant paid admin (COD commission settlement)
        'restaurant_payout_out',     -- -amount: admin paid a restaurant its online-order share
        'refund_out',                -- -amount: customer refund issued
        'platform_revenue',          -- informational only, see note below — does NOT move cash
        'manual_adjustment'          -- +/-amount: any correction, always requires `note`
    ) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,        -- signed: + = cash in, − = cash out
    running_balance DECIMAL(12,2) NOT NULL, -- balance in the UPIPE merchant account after this entry
    restaurant_id BIGINT UNSIGNED NULL,   -- which restaurant this entry relates to, if any
    order_id BIGINT UNSIGNED NULL,
    restaurant_payment_id BIGINT UNSIGNED NULL, -- links to the restaurant_payments row, if this entry is a settlement
    note VARCHAR(255) NULL,
    created_by ENUM('system','admin') NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Why `platform_revenue` doesn't move cash:** the commission/platform-fee
the admin keeps isn't a separate transfer — it's simply the gap between
what came in (`customer_payment_in`) and what eventually goes out
(`restaurant_payout_out`) for that same order. Logging it as its own
*informational* row (amount recorded, but excluded from the running
cash-balance calculation) means the platform's actual earned revenue is
directly queryable (`SUM(amount) WHERE entry_type='platform_revenue'`)
without it double-counting against the real cash balance.

**What writes to this table:**
- Every online order reaching `payment_status='paid'` → one
  `customer_payment_in` row (+`grand_total`) **and** one
  `platform_revenue` row (+`commission_amount + platform_fee`, same
  order, informational).
- Every "Pay Now" in §6 where direction is `admin_to_restaurant` → one
  `restaurant_payout_out` row (−amount).
- Every "Pay Now" where direction is `restaurant_to_admin` → one
  `restaurant_settlement_in` row (+amount).
- Every refund → one `refund_out` row (−amount).

### Admin screen — Platform Cash Flow (new Analytics category)
The actual "total kitna aaya, kaha kaha gaya" report:
- **Total Money In** — `SUM(amount)` where `entry_type IN
  ('customer_payment_in','restaurant_settlement_in')`
- **Total Money Out** — `SUM(amount)` where `entry_type IN
  ('restaurant_payout_out','refund_out')`
- **Net Balance Held** (= what should currently be sitting in the
  UPIPE merchant account, waiting to be paid out to restaurants) —
  `Total In − Total Out`, same number as the latest row's
  `running_balance`
- **Total Platform Revenue** (separate figure, not part of the cash
  balance) — `SUM(amount) WHERE entry_type='platform_revenue'`
- **Full entry list**, chronological, running balance per row —
  filterable by date range / area / restaurant, every row shows which
  restaurant/order it ties back to, exportable — this is the literal
  "sab ka option hona chahiye" ledger view, at the whole-platform level
  instead of per-restaurant.
- **Reconciliation check** — `Net Balance Held` should always equal
  `−1 × SUM(restaurants.current_due WHERE current_due < 0)` (i.e. the
  total the admin owes out across every restaurant should match what's
  sitting unspent in the merchant account). Worth a periodic automated
  check (same pseudo-cron pattern used elsewhere) that flags a mismatch
  to Super Admin rather than silently trusting the two numbers stay in
  sync — if they ever drift, that's a real bug worth catching early
  rather than discovering at audit time.

This ledger is what §9's existing "Payment Analytics" category should
actually read from, rather than recomputing from `orders` directly —
one source of truth for all money-in/money-out reporting.

---

## 7. Email OTP — Multi-Provider Architecture

No email-sending abstraction exists in the current schema/backend at
all today (customer login is `login_type ENUM('google','email')` but
the actual OTP-send mechanism isn't schema-tracked anywhere) — this is
fully new.

### Schema
```sql
CREATE TABLE email_otp_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,             -- 'Brevo', 'SendGrid', 'Mailgun', ...
    driver_key VARCHAR(50) NOT NULL,       -- maps to a PHP class, see below
    config_json TEXT NOT NULL,             -- API key, sender domain, etc. (per-provider shape)
    priority INT NOT NULL DEFAULT 0,       -- lower = tried first
    is_active TINYINT(1) DEFAULT 1,
    daily_quota INT NULL,
    monthly_quota INT NULL,
    daily_used INT DEFAULT 0,
    monthly_used INT DEFAULT 0,
    quota_reset_date DATE NULL,            -- daily_used resets when this rolls over
    created_at, updated_at TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_otp_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(150) NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error_reason VARCHAR(100) NULL,   -- 'quota_exceeded','rate_limit','timeout','invalid_response','service_unavailable','api_error'
    attempt_number TINYINT DEFAULT 1, -- which provider-in-sequence this was
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otplog_provider FOREIGN KEY (provider_id) REFERENCES email_otp_providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Backend design — interface + registry, not if/else chains
```
backend/lib/email_otp/
  EmailProviderInterface.php   -- send(string $to, string $subject, string $body): bool
  BrevoProvider.php
  SendGridProvider.php
  MailgunProvider.php
  ...
  EmailOtpService.php          -- the orchestrator
```
`EmailOtpService::send()`:
1. Loads active providers from `email_otp_providers`, ordered by
   `priority ASC`.
2. Skips any provider whose `daily_used >= daily_quota` or
   `monthly_used >= monthly_quota`.
3. Tries the next provider; on **any** of the listed failure types
   (quota, rate limit, timeout, invalid response, service unavailable,
   API error) — logs to `email_otp_logs` with the reason and falls
   through to the next provider in the list, **transparently to the
   caller** (the OTP-send code path calling `EmailOtpService::send()`
   never knows or cares which underlying provider succeeded).
4. On success, increments `daily_used`/`monthly_used` for that provider,
   logs `status='sent'`, returns.
5. If **every** active provider fails, the caller gets a hard failure
   (this is the one case the user-facing OTP request should show a
   real error, rather than silently pretending success).

**Adding a new provider later = one new class implementing
`EmailProviderInterface` + a new `email_otp_providers` row** — no
change to `EmailOtpService` or any calling code. This is the "modular,
no application-logic change" requirement satisfied directly by the
interface pattern.

### Admin screen (Email Providers)
List with priority (drag-to-reorder or numeric field), Enable/Disable
toggle, Add/Remove provider (config form varies per `driver_key` —
frontend renders fields based on a small per-driver schema), **Test**
button (fires a real test send to an admin-entered email, shows
success/failure immediately), and a usage dashboard: daily/monthly
quota bars per provider, recent failed requests (`email_otp_logs`
filtered `status='failed'`), success rate (`sent / (sent+failed)` over
a selectable window).

---

## 8. Payment — Provider Architecture

Same interface-and-registry pattern as Email OTP, deliberately, since
the requirement ("plug in later without changing order processing")
is structurally identical.

### Schema
```sql
CREATE TABLE payment_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,           -- 'UPIPE', 'Razorpay', 'Cashfree', ...
    driver_key VARCHAR(50) NOT NULL,
    config_json TEXT NOT NULL,           -- merchant ID, keys — UPIPE's real values added later
                                          -- when the SDK/API details are provided
    is_active TINYINT(1) DEFAULT 1,
    priority INT DEFAULT 0,              -- for future multi-gateway failover
    created_at, updated_at TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    provider_txn_id VARCHAR(100) NULL,   -- UPIPE's own reference once real integration lands
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('initiated','success','failed','refunded') DEFAULT 'initiated',
    raw_response_json TEXT NULL,         -- store the provider's full response for disputes
    created_at, updated_at TIMESTAMP,
    CONSTRAINT fk_ptxn_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_ptxn_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Backend design
```
backend/lib/payment/
  PaymentProviderInterface.php  -- initiate(), verify(), refund()
  UpipeProvider.php             -- stub now; real SDK/API wired in when provided
  PaymentService.php            -- picks the active provider, calls through the interface
```
`UpipeProvider.php` gets built as a **stub matching the interface
today** (so `price_cart()`/checkout code can be written and tested
against the interface right now), then swapped for the real
integration once the app owner provides the UPIPE SDK/source — no
change needed anywhere else in the codebase at that point, which is
the whole point of the interface. Order processing, cart pricing,
`orders.payment_status`, refund flows — all call `PaymentService`,
never a provider class directly.

### Flow (launch phase, matches the doc exactly)
1. Customer pays via UPIPE at checkout → money lands in the **admin's
   own UPIPE merchant account** (not the restaurant's).
2. `payment_transactions` row tracks it; `orders.payment_status`
   updates as today.
3. Restaurant does **not** need their own gateway account — this is
   already true of the current `payment_method ENUM('upi','cod')`
   design, just now formalized through the interface.
4. Settlement to the restaurant happens **manually**, T+1, only by
   Super Admin, via the exact same Payout flow already specced in §6
   (Pay Now → screenshot/UTR/amount/date/remarks → notification). One
   settlement mechanism serves both the "Payout System" and "Restaurant
   Settlement" requirements — not two separate builds.

### Future gateway additions (Razorpay, Cashfree, PhonePe, ...)
Each is a new class implementing `PaymentProviderInterface` + a new
`payment_providers` row — same "no app-logic change" guarantee. Admin
screen (Payment Providers) mirrors the Email Providers screen:
enable/disable, priority, test mode. Auto-commission-deduction,
auto-settlement, split payments, instant payouts, refund automation,
webhooks, auto-reconciliation, multi-gateway failover — all flagged as
explicitly **future**, each buildable independently once real gateway
webhooks exist to trigger them (webhooks specifically need a public
HTTPS endpoint, which depends on hosting — InfinityFree's suitability
for receiving webhooks should be checked before this phase, separate
open question from the app logic itself).

---

## 9. Analytics & Reports Module

Everything requested here is achievable as **aggregation queries over
existing tables** (`orders`, `order_items`, `restaurant_due_ledger`,
`reviews`, `coupon_usages`, plus the new `service_areas`) — no
speculative new tables needed for v1 beyond what's already listed
above, **except** a rollup table if/when raw aggregation gets too slow
at scale (flagged at the end, not needed to start).

### Filters (shared across every report below)
- **Date:** Today / Yesterday / Last 7 / Last 30 / This Month / Last
  Month / Custom range — standard `WHERE created_at BETWEEN` with
  presets computed server-side from the request.
- **Location:** State → District → City → Area, using `service_areas`
  from §2 — a report scoped to "Osian" filters
  `restaurants.area_id = :osian_id` (or all descendant `area_id`s if a
  higher level like "Jodhpur district" is picked — needs a small
  recursive/iterative "get all descendant area ids" helper given the
  adjacency-list design).
- **Restaurant:** single / multiple (`IN (...)`) / by
  `restaurant_categories` (§3) / Verified vs not / Active vs not.

### Report categories (each = one or a few grouped-aggregation
endpoints, `GET /admin/analytics/{category}?filters...`)
| Category | Source | Notes |
|---|---|---|
| Order Analytics | `orders.status` GROUP BY | counts per status, already-existing enum covers every state asked for |
| Revenue Analytics | `orders` (item_total, platform_fee, tax_amount, discount_amount, grand_total) | GMV = `SUM(grand_total)`; Net Revenue = GMV − discounts − refunds |
| Commission Analytics | `restaurant_due_ledger` WHERE `entry_type='commission'` | already the ledger's exact purpose; area-wise/restaurant-wise = GROUP BY through the `restaurants.area_id` join |
| Area Analytics | `restaurants`, `orders`, `customers`, `riders` joined via `area_id` | needs `customer_addresses.area_id` (added §2) as the join point for customer counts per area |
| Restaurant Performance | `orders`, `order_status_history` (accept timestamps), `reviews` | Acceptance rate = accepted / (accepted+rejected); needs `orders.accepted_at` vs `created_at` delta for avg prep time — both columns already exist |
| Top Performing Restaurants | same, `ORDER BY` per metric, `LIMIT n` | rankings are just sorted aggregates, no new logic |
| Top Selling Items | `order_items` GROUP BY `menu_item_id` | `item_name_snapshot` already preserves the name even if the item's since been edited/deleted |
| Customer Analytics | `customers`, `orders` | New vs Returning = first-order-date logic; LTV = `SUM(grand_total)` per customer lifetime |
| Rider Analytics | `orders` WHERE `rider_id`, `rider_locations` | Distance covered needs summing `rider_locations` consecutive-point deltas — flagging as the one genuinely non-trivial calc here, worth a dedicated small function rather than inline SQL |
| Payment Analytics | `platform_ledger` (§6b) primarily; `orders.payment_method/payment_status`, `payment_transactions` (§8) for per-order detail | Total In/Out/Net Balance reads from `platform_ledger`, not recomputed from `orders` — one source of truth, see §6b |
| Coupon Analytics | `coupons`, `coupon_usages` | Conversion rate needs a denominator (coupon views vs uses) — **views aren't currently tracked anywhere**, flagging as a gap: either skip "conversion rate" for v1 or add a lightweight `coupon_impressions` counter |
| Growth Analytics | any of the above, same query run for two adjacent periods, diffed | no new data, just a period-over-period comparison layer |

### Live Dashboard
Same categories as the lightweight Dashboard (§3) but this is the
full-screen real-time version: Live Orders (status IN pending/
accepted/preparing/etc., not yet terminal), Active Customers (had an
order or app-open event in last N minutes — **app-open events aren't
tracked today**, either approximate via "order placed today" or add a
lightweight `last_active_at` timestamp updated on any authenticated API
call), Online Riders (`riders.is_online`, exists), Open Restaurants
(`operational_status='open'`, exists via `compute_restaurant_status()`
from doc 18), Today's Revenue/Commission (today-scoped versions of the
aggregates above).

### Heatmap Dashboard
Map view, color-intensity per `service_areas` (area level) by orders/
revenue/commission — needs each area's `center_lat/lng` (already added
in §2's schema) plus the same aggregation queries scoped per-area,
rendered as intensity-colored map markers/circles (client-side map
library, e.g. Leaflet/Google Maps JS — no new backend beyond "area
analytics grouped by area" which the table above already covers).

### Export
Excel/CSV/PDF, with whatever filters were applied — reuses the
project's existing `xlsx`/`pdf` generation approach (already used
elsewhere per the project's doc conventions), applied to whichever
report's result set is currently on screen.

### Charts
Standard time-series/bar/pie over the same aggregation endpoints —
frontend charting library choice (Chart.js, ApexCharts, etc.) is an
Admin Panel frontend decision, not a backend one; every chart listed
maps directly to a category in the table above, no new data needed.

### Scale note (not a v1 blocker, flagging so it isn't a surprise later)
Running `SUM()`/`GROUP BY` over the full `orders` table for every
dashboard load is fine at current/early volume. If order volume grows
large enough that live aggregation gets slow, the standard fix is a
`daily_analytics_rollup` table (one row per restaurant/area/day,
pre-aggregated by a nightly job) that the dashboard reads instead of
raw `orders` — **not needed to start**, just flagging it as the known
next step if performance ever becomes the bottleneck.

---

## 8b. Fraud Detection, Support Panel, Notification Center, Logs

### Fraud Detection
🟡 new — flagging table + queries, not automatic blocking (v1 should
surface signals to a human admin, not auto-ban, given false-positive
risk):
```sql
CREATE TABLE fraud_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('customer','restaurant','coupon') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    flag_type VARCHAR(50) NOT NULL,   -- 'multiple_accounts','fake_orders','excess_cancellations','coupon_abuse'
    details_json TEXT NULL,
    status ENUM('open','reviewed','dismissed','actioned') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```
Detection heuristics (scheduled job, same pseudo-cron pattern already
used for the due-limit suspension check in `02_API_Contract.md`):
same-device/same-payment-method across multiple customer accounts
(needs a device-id or payment-fingerprint signal — **not currently
captured anywhere**, flagging as a real gap to close before this
feature has anything to detect on), cancellation-rate threshold per
customer/restaurant, coupon-usage-velocity threshold. Admin screen:
flag queue, Blacklist action (sets `is_active=0` / `status='suspended'`
on the flagged entity + logs to `audit_logs`).

### Support Tickets
🟡 new:
```sql
CREATE TABLE support_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    raised_by_type ENUM('customer','restaurant','rider') NOT NULL,
    raised_by_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(50),
    subject VARCHAR(150),
    description TEXT,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    assigned_admin_id BIGINT UNSIGNED NULL,
    attachments_json TEXT NULL,
    created_at, updated_at, resolved_at TIMESTAMP
);
CREATE TABLE support_ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('customer','restaurant','rider','admin') NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticketmsg FOREIGN KEY (ticket_id) REFERENCES support_tickets(id)
);
```
Assign-to-admin, priority, resolve — standard ticket queue, Admin
screen filterable by raised_by_type/status/priority.

### Notification Center
🟡 new campaign layer **on top of** the existing `notifications` table
(that table stays as the per-recipient delivered-notification record;
this adds the admin-authored "campaign" that fans out into many
`notifications` rows):
```sql
CREATE TABLE notification_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150),
    body TEXT,
    target_type ENUM('all','area','topic','segment') NOT NULL,
    target_value VARCHAR(100) NULL,   -- area_id, topic name, or segment key depending on target_type
    audience ENUM('customer','restaurant','rider') NOT NULL,
    scheduled_at TIMESTAMP NULL,      -- NULL = send immediately
    sent_at TIMESTAMP NULL,
    status ENUM('draft','scheduled','sent','failed') DEFAULT 'draft',
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```
**This is exactly the app owner's "area-specific ya sabko" requirement**
— `target_type='area'` + `target_value=<area_id>` fans out only to
customers whose `customer_addresses.area_id` matches (or whose
currently-selected address does); `target_type='all'` fans out to
everyone in the chosen `audience`. Scheduled sends use the same
pseudo-cron pattern as everything else server-triggered in this
project — a job picks up `status='scheduled' AND scheduled_at <= NOW()`
and executes. **FCM is the actual delivery mechanism** (both this
campaign system and Phase J's existing per-user engagement/cart-
abandonment notifications should share one FCM-sending helper — no
reason to build two separate push-sending code paths).

### Audit Logs / Impersonation
✅ `audit_logs` table already exists and already covers the general
case. Impersonation ("Super Admin logs into a restaurant account
without knowing the password, for support/debugging") needs:
- `POST /admin/restaurants/{id}/impersonate` — generates a short-lived
  `auth_tokens` row scoped to that restaurant (reuses the existing
  token pattern, just admin-issued instead of password-issued), **and
  writes an `audit_logs` row immediately** (`actor_type='admin',
  action='impersonate_start', details_json={restaurant_id}`) — not
  after the fact, so there's no window where an impersonation session
  exists without a log record.
- Session end (logout or token expiry) writes a matching
  `impersonate_end` row, so the full duration is reconstructable from
  the two log rows plus their timestamps — no new table needed, just a
  disciplined pair of `audit_logs` writes framing the session. IP
  address (`audit_logs.ip_address`, already a column) captured at
  start.
- Frontend: the restaurant app / restaurant-facing admin view should
  show a visible "You are viewing as [Restaurant Name] — Admin session"
  banner while impersonating, so it's never ambiguous which identity is
  active — a UX safeguard, not a schema item, but worth stating since
  the whole point of this feature is support/debugging, not silent
  access.

---

## 10. App Settings (extends existing `app_settings`)

All new rows in the existing `key/value/description` table, no schema
change — Delivery Charge, Platform Fee, GST %, Commission %, Minimum
Order, Maximum Distance, Support Number/Email, Google Maps API key,
Maintenance Mode (already listed as an example row in the schema doc),
Force Update (already has `app_versions.force_update` — this setting
would be the *default*, per-app-type override already covered by that
table). **Note:** no `referral_bonus` row per the app owner's explicit
exclusion.

---

## 11. Restaurant App — new items surfaced this session

Nothing customer-facing here, but the Admin Panel work above creates
new restaurant-side needs not previously scoped:
- **Document upload** (FSSAI certificate, GST certificate) at
  onboarding/profile — needs the `restaurant_documents` table (§4) and
  an upload screen in `restaurant/app/` — same upload infra already
  flagged once in doc 18 (Tier 1), now has a second consumer.
  Building it once, reusing for banner images, menu photos, and these
  documents, avoids three separate upload implementations.
- **Bank details form** — writes to `restaurant_bank_details` (§6),
  restaurant-side self-service entry (with an Admin-side view/edit
  override for support cases).
- **Payout history screen** — restaurant's own read-only view of
  `restaurant_payments` + `restaurant_due_ledger`, so a restaurant
  doesn't have to ask the admin "how much have I been paid" — purely
  additive, no new schema.
- **PAN number field** — added to the existing profile-edit screen
  already planned in doc 18 (Restaurant Management → Name/Address/
  GPS/Hours), one more field on the same form.

---

## 12. Customer App — new items surfaced this session

- **Wallet screen** — balance + transaction history, reads
  `customer_wallets`/`wallet_transactions` (§3). Checkout gets a "Pay
  with Wallet" option alongside the existing `upi`/`cod`
  `payment_method` enum — **this needs a third enum value,
  `orders.payment_method ENUM('upi','cod','wallet')`**, or a hybrid
  split-payment model (wallet covers part, UPI covers the rest) if
  partial wallet use is wanted — flagging as a decision needed before
  building, not assuming either way.
- **Area-scoped restaurant visibility** — a direct customer-facing
  consequence of §2's Area Management; the customer's effective
  "area" (derived from their selected/saved address) now gates which
  restaurants show, on top of the existing radius check.
- **Area-scoped banners** — customer only ever receives banners
  matching their `area_id` or platform-wide (`area_id IS NULL`) ones,
  per §5.
- **Impression star rating** — carried over unbuilt from doc 18 (still
  pending the app owner's sign-off on the weighting formula), noting
  here only because it's still open, not new to this session.

---

## 13. Database schema additions — consolidated list

New tables: `admin_roles`, `admin_permissions`, `admin_role_permissions`,
`service_areas`, `restaurant_categories`, `customer_wallets`,
`wallet_transactions`, `restaurant_bank_details`, `restaurant_documents`,
`email_otp_providers`, `email_otp_logs`, `payment_providers`,
`payment_transactions`, `banners`, `fraud_flags`, `support_tickets`,
`support_ticket_messages`, `notification_campaigns`, `platform_ledger`.

Altered tables: `admins` (role_id/name/email/is_active/last_login_at,
drop old role enum), `restaurants` (area_id, category_id, pan_number),
`riders` (status enum), `customer_addresses` (area_id),
`restaurant_due_ledger` (entry_type enum extended — commission_cod,
payout_payable, settlement_to_restaurant, settlement_from_restaurant —
see §6's correction note), `restaurant_payments` (utr_number,
screenshot_url, remarks, payment_date, settled_by_admin_id, direction),
`orders.payment_method` (add `'wallet'` — pending the split-payment
decision noted in §12).

**Nothing here has been run against any database yet** — this is the
consolidated list for review before any migration is written, same
"plan first, confirm, then build" pattern as every other doc in this
project.

---

## 14. Recommended build order

1. **Roles & Permissions (§1) + Area Management (§2)** — foundational,
   everything else either enforces against roles or filters by area.
2. **Admin Panel core** (login, dashboard, restaurant/rider approval,
   restaurant details, order control) — closes the biggest existing gap
   (bug 3.1, no approval workflow) fastest.
3. **Payout/Settlement (§6) + restaurant bank details** — directly
   needed before any real restaurant can be paid, and the app owner
   flagged this as launch-relevant.
4. **Payment Provider architecture (§8)** — build the interface + UPIPE
   stub now so checkout code has something real to call; swap in the
   real UPIPE integration whenever that's provided, no other code
   changes needed at that point.
5. **Email OTP multi-provider (§7)** — needed before real user
   signups/logins go live at any scale (single-provider email is a
   single point of failure otherwise).
6. **Banner Manager + Notification Center (§5, §8b)** — both depend on
   Area Management (step 1), build together since they share the
   area-targeting logic.
7. **Analytics & Reports (§7... err §9)** — biggest single module,
   build after there's real order/restaurant data from steps 1-3 to
   actually report on, same reasoning already used in doc 18 for why
   Analytics comes after Tier 1.
8. **Support Tickets, Fraud Detection, Impersonation, remaining Admin
   modules (Wallet, Category Management)** — fill in after the above,
   none of these block anything else in this list.

**Nothing in this document has been built yet — planning only, per this
session's request, mirroring the same pattern used for
`18_Restaurant_App_Full_Scope_And_Rating_System.md`.** Say which item
to start with.
