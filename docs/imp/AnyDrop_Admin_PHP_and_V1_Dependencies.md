# AnyDrop Admin Panel + PHP Strategy

## 1. Can the Admin Panel remain PHP?

Yes.

There is no requirement to migrate the Admin Panel from PHP to Node.js just because the mobile/customer APIs move from PHP V1 to Node.js V2.

PHP itself is not the bottleneck by default. Performance depends on:

- database query quality
- indexes
- N+1 queries
- PHP-FPM/web-server configuration
- caching
- request volume
- external API latency
- server CPU/RAM
- architecture

For an internal/admin dashboard, PHP can remain a practical and fast choice for a long time.

---

# 2. Current Admin Architecture

The current Admin Panel is primarily a server-rendered PHP application.

The important architecture is:

```text
Admin Browser
      |
      v
backend/admin/*.php
      |
      v
PHP Session Authentication
      |
      v
PDO / Database Layer
      |
      v
MySQL
```

The Admin Panel does NOT normally call the mobile `/api/v1/` JSON APIs over HTTP.

This is intentional: the PHP admin pages query the database directly instead of making an unnecessary HTTP request back to the same server.

---

# 3. Admin Authentication

The Admin Panel uses PHP session authentication.

The shared bootstrap contains:

```text
backend/admin/_bootstrap.php
```

It starts the PHP session and verifies that the admin account is still active.

The Admin Panel also uses the project's admin RBAC/permission system.

Therefore, migrating mobile APIs from PHP V1 to Node V2 does not automatically require changing Admin authentication.

---

# 4. Admin's Own Small API Endpoints

There IS a small Admin-specific API surface:

```text
backend/admin/api/
```

Current files:

```text
backend/admin/api/fetch-pincode.php
backend/admin/api/geocode-locality.php
```

These are used by the Areas page.

## fetch-pincode.php

Called from:

```text
backend/admin/areas.php
```

Browser request:

```text
api/fetch-pincode.php?pincode=XXXXXX
```

Purpose:

- India Post pincode lookup
- returns state/district/post-office suggestions
- optionally obtains a pincode coordinate suggestion

The endpoint runs server-side and proxies external services.

## geocode-locality.php

Called from:

```text
backend/admin/areas.php
```

Browser request:

```text
api/geocode-locality.php?...
```

Purpose:

- geocode a locality name
- uses OpenStreetMap/Nominatim
- returns coordinate suggestions

These are ADMIN WEB helper endpoints, not the mobile V1 API.

---

# 5. Rider Documents — Important Dependency

The Admin Panel currently has a direct link to:

```text
/api/v1/rider/documents-view.php
```

This is used by:

```text
backend/admin/riders.php
```

The page provides:

```text
View ID Doc
View Vehicle Doc
```

The current URL is constructed using:

```text
admin_base_url()
```

and then:

```text
/api/v1/rider/documents-view.php
```

This is a real dependency that must be considered before removing V1.

The document endpoint has special authentication behavior: it accepts the Admin Panel's PHP session in addition to the rider Bearer-token path.

Therefore, deleting the V1 endpoint while the Admin Panel still uses these links would break admin document viewing.

---

# 6. What Should Happen to Rider Documents During V2 Migration?

Do NOT make the Admin Panel depend on a mobile V1 endpoint forever.

Recommended future structure:

```text
Admin Panel
     |
     v
Admin document endpoint
     |
     v
Private document storage
```

For example:

```text
/admin/api/rider-document.php?rider_id=123&doc=id
```

or another clearly admin-scoped endpoint.

This endpoint should:

1. require an authenticated admin session
2. check `rider_documents_view`
3. validate the requested rider/document
4. securely locate the private document
5. stream the file
6. prevent arbitrary file access
7. log sensitive document access if required by the audit policy

The exact endpoint name can be chosen during implementation.

---

# 7. Recommended Separation

Instead of:

```text
Admin Panel
     |
     +----> /api/v1/rider/documents-view.php
```

prefer:

```text
Admin Panel
     |
     +----> /admin/api/rider-document.php
```

while mobile clients continue using their own API:

```text
Rider App
     |
     +----> /api/v1/rider/...
```

Later:

```text
Rider App
     |
     +----> /api/v2/rider/...
```

This separates Admin functionality from the mobile API lifecycle.

---

# 8. Other /api/v1 References Found in Admin Code

Several Admin pages mention V1 endpoints in comments, documentation, settings descriptions, or links.

Examples include:

```text
/api/v1/orders/route.php
/api/v1/customer/feedback.php
/api/v1/rider/location.php
/api/v1/auth/*-verify-otp.php
/api/v1/rider/orders-deliver.php
/api/v1/system/app-version.php
```

Important distinction:

Many of these are REFERENCES describing what the mobile API does or what settings affect. They are NOT necessarily HTTP requests made by the Admin browser.

Therefore, do not rewrite every occurrence of the string `/api/v1/`.

Each dependency should be classified as:

```text
1. Actual HTTP request
2. Link to an endpoint
3. Documentation/comment
4. Configuration dependency
5. Direct database implementation
```

Only the real runtime dependencies need migration work.

---

# 9. Admin Pages Mostly Use Direct Database Operations

The major Admin screens include:

```text
dashboard.php
orders.php
restaurants.php
riders.php
rider-detail.php
rider-earnings.php
rider-payouts.php
rider-settlements.php
customers.php
settlements.php
refunds.php
wallet-withdrawals.php
platform-ledger.php
reconciliation.php
analytics.php
banners.php
categories.php
offers.php
roles.php
support.php
...
```

These pages generally perform their work through the PHP database layer.

Therefore:

```text
PHP V1 -> Node V2
```

does NOT mean:

```text
Admin PHP -> Node V2
```

automatically.

The Admin Panel can remain:

```text
Admin Browser
      |
      v
PHP Admin
      |
      v
MySQL
```

while the mobile APIs become:

```text
Mobile Apps
      |
      v
API Gateway
      |
      v
Node V2
      |
      v
MySQL
```

---

# 10. Shared Database Consideration

If PHP Admin and Node V2 both operate on the same production database, database behavior must remain consistent.

Especially for:

- orders
- payments
- wallet balances
- ledger entries
- rider settlements
- refunds
- restaurant settlements

Node V2 must follow the same transactional rules and data invariants as the existing PHP system.

Do not let the Admin Panel directly modify sensitive financial data in a way that bypasses the rules implemented by the new service layer.

For critical operations, it is preferable to have a controlled application/service operation rather than arbitrary direct SQL updates.

---

# 11. Should Admin Eventually Move to Node.js?

Optional.

There are three reasonable choices.

## Option A — Keep Admin PHP

```text
Admin → PHP
Mobile APIs → Node.js
```

This is completely valid.

Advantages:

- minimal migration risk
- existing admin UI continues working
- no unnecessary rewrite
- PHP can be scaled independently
- easier transition

This is the recommended starting point.

## Option B — Build a Node.js Admin API later

```text
Admin Browser
      |
      v
Admin API
      |
      v
Node.js
      |
      v
MySQL
```

Useful if the Admin Panel becomes a large SPA or requires many real-time operations.

## Option C — Eventually rewrite the complete Admin UI

Only do this when there is a real product/engineering reason.

Do not rewrite it merely because Node.js is faster in some workloads.

---

# 12. PHP Performance

PHP can remain fast enough for the Admin Panel.

The main performance checks should be:

```text
Slow query?
   ↓
Add/fix index

Repeated query?
   ↓
Cache or consolidate

Large table?
   ↓
Pagination

N+1 query?
   ↓
JOIN/batched query

Heavy analytics?
   ↓
Precompute/cache/reporting tables

High traffic?
   ↓
Multiple PHP workers/servers
```

The programming language alone should not be treated as the scaling strategy.

---

# 13. Admin Scaling Later

If the Admin Panel becomes large enough to need multiple servers:

```text
admin.anydrop.in
       |
       v
Load Balancer
       |
   +---+---+
   |   |   |
  PHP PHP PHP
   |   |   |
   +---+---+
       |
      DB
```

The Admin Panel can scale horizontally too, provided it does not depend on local-only sessions/files.

Use shared/centralized mechanisms for:

- sessions if multiple servers require them
- uploaded files
- caches
- temporary state

---

# 14. Migration Checklist Before Removing V1

Before V1 is decommissioned:

### Mobile

- [ ] Customer App no longer requires V1
- [ ] Rider App no longer requires V1
- [ ] Restaurant App no longer requires V1
- [ ] Old app versions are handled
- [ ] API gateway shows zero V1 production traffic

### Admin

- [ ] Rider document viewing no longer depends on V1
- [ ] Any actual Admin HTTP dependency on V1 has been migrated
- [ ] Admin helper APIs remain functional
- [ ] Configuration/documentation references are reviewed
- [ ] No hidden browser links to V1 remain

### Web

- [ ] Existing web endpoints checked
- [ ] Any V1 dependency identified
- [ ] External integrations checked

### Backend

- [ ] No scheduled jobs call V1
- [ ] No cron/background worker calls V1
- [ ] No internal service calls V1
- [ ] Monitoring confirms zero V1 traffic

Only then should V1 be decommissioned.

---

# 15. Final Recommended Architecture

```text
                         AnyDrop
                            |
              +-------------+-------------+
              |                           |
              v                           v
       Mobile Applications           Admin Browser
              |                           |
              v                           v
       api.anydrop.in              admin.anydrop.in
              |                           |
              v                           v
       API Gateway                  PHP Admin
              |                           |
       +------+-------+                   |
       |              |                   |
      V1             V2                  MySQL
      PHP           Node
       |              |
       +------+-------+
              |
             DB
```

During migration:

```text
Mobile
  |
  v
Gateway
  |
  +---- V1 PHP
  |
  +---- V2 Node
```

After migration:

```text
Mobile
  |
  v
Gateway / Load Balancer
  |
  +---- V2 Node 1
  +---- V2 Node 2
  +---- V2 Node 3
```

Admin can remain:

```text
Admin Browser
      |
      v
PHP Admin
      |
      v
MySQL
```

with its own admin-specific document/helper endpoints.

---

# Final Decision

For AnyDrop:

**Keep the Admin Panel in PHP for now.**

Do not migrate the Admin Panel simply because the mobile API moves to Node.js.

**Separate the rider document endpoint from `/api/v1/` before V1 is finally removed.**

Keep the Admin Panel's small helper endpoints under its own:

```text
/admin/api/
```

surface.

When V1 reaches 0 traffic, verify Admin/website/internal dependencies first. Then decommission V1.

The key principle is:

> API version migration and Admin Panel migration are separate projects.
