# AnyDrop --- Scalable Architecture & Migration Plan

**Project:** AnyDrop\
**Current backend:** PHP + MySQL\
**Planned backend:** PHP V1 + Node.js V2\
**Current hosting:** InfinityFree Free Hosting\
**Current storage:** InfinityFree web storage\
**Future storage:** Object Storage + CDN\
**Goal:** Start cheaply, keep the code easy to migrate, and make future
multi-API / multi-server scaling possible without rewriting the whole
application.

------------------------------------------------------------------------

## 1. Main Architecture Goal

The most important rule for AnyDrop is:

> **Do not design today's application around today's hosting provider.
> Design the application around clean interfaces so the hosting provider
> can be replaced later.**

Current:

``` text
Customer App
Rider App
Restaurant App
Admin Panel
Web/Landing Pages
        |
        v
   InfinityFree
        |
   PHP API V1
        |
      MySQL
```

Future:

``` text
                         Users
                           |
                    Load Balancer
                           |
             +-------------+-------------+
             |             |             |
          API #1         API #2        API #3
             |             |             |
             +-------------+-------------+
                           |
                    Redis / Cache
                           |
                    Database Layer
                           |
                 +---------+---------+
                 |                   |
             Primary DB          Read Replicas
                 |
                 +----------------------+
                                        |
                               Object Storage
                                        |
                                       CDN
```

**Do not build the future architecture now. Build the boundaries now.**

------------------------------------------------------------------------

# 2. Current InfinityFree Reality

InfinityFree currently provides free PHP/MySQL hosting, 5 GB disk space,
PHP 8.3, MySQL 8.0 / MariaDB 11.4, SSL, FTP/file management and free
subdomains. It also advertises unlimited bandwidth, but the free service
has fair-use/server-resource limits, including a 50,000 daily hits limit
on the current comparison page. citeturn0search0turn0search1

InfinityFree also documents limits around entry processes, CPU, RAM, IO,
MySQL load and similar shared-hosting resources.
citeturn0search2turn0search12

### Therefore:

InfinityFree should be treated as:

-   development/testing hosting
-   early demo
-   landing pages
-   small beta
-   temporary production while traffic is low

It should **not** be treated as the final infrastructure for a large
food-delivery platform.

Also, the current free hosting environment is not a VPS:

-   no root server control
-   no Docker deployment
-   no Node.js server process
-   no Redis server
-   no custom background worker infrastructure
-   no proper load-balancer setup

Recent InfinityFree documentation/forum reports also show restrictions
around API-style access and outbound connections on free hosting, so
test the actual AnyDrop Android/API traffic on the chosen account before
treating it as production infrastructure.
citeturn0search3turn0search5turn0search11

**Important:** Do not build an architecture that requires Node.js to run
on InfinityFree Free Hosting.

------------------------------------------------------------------------

# 3. What We Do NOW

While using InfinityFree:

``` text
                  AnyDrop
                     |
        +------------+-------------+
        |            |             |
     Customer      Rider       Restaurant
       App           App           App
        |            |             |
        +------------+-------------+
                     |
                     v
              PHP API V1
                     |
                     v
                MySQL
                     |
              Local Web Storage
```

For now:

-   PHP API V1 remains the production/test backend.
-   MySQL remains the single source of truth.
-   Images can temporarily live in the hosting account.
-   Redis is not required yet.
-   CDN is not required yet.
-   Node.js V2 is developed separately.
-   V2 should use the same database schema/contracts where possible.
-   All code must be written so storage/database hosts can change later.

------------------------------------------------------------------------

# 4. Critical Rule: One Source of Truth

AnyDrop must never have separate independent copies of user balances,
orders or wallets.

For example:

``` text
User 123
Balance = ₹500
```

There must be one authoritative record.

Bad:

``` text
DB1 -> User 123 -> ₹500
DB2 -> User 123 -> ₹700
```

Good:

``` text
Primary DB
User 123 -> ₹500
```

Future read replicas are copies:

``` text
Primary DB  -> ₹500
Replica 1   -> ₹500
Replica 2   -> ₹500
```

Replicas are for availability/read scaling. They are not independent
wallet databases.

------------------------------------------------------------------------

# 5. Database Architecture --- NOW

For the current stage:

``` text
PHP API
   |
   v
MySQL
```

Do not create multiple databases just because you expect growth.

A properly indexed MySQL database can handle a large amount of data.

The correct approach is:

1.  Keep one logical database.
2.  Keep proper indexes.
3.  Use transactions.
4.  Avoid unnecessary queries.
5.  Paginate lists.
6.  Avoid `SELECT *` where possible.
7.  Keep financial operations atomic.
8.  Keep migrations versioned.

Only introduce read replicas/sharding when actual database workload
requires them.

------------------------------------------------------------------------

# 6. Database Configuration Must Be Environment-Based

The current project has a `backend/config/config.php` with local
development values such as:

``` text
DB_HOST=localhost
DB_NAME=anydrop
DB_USER=root
DB_PASS=
```

This is acceptable for local development but should not be the
production configuration.

Change the architecture to:

``` text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD

APP_SECRET
CRON_SECRET

REDIS_HOST
REDIS_PORT

STORAGE_DRIVER
STORAGE_BUCKET
STORAGE_ENDPOINT
STORAGE_PUBLIC_BASE_URL
```

Never hard-code production passwords or secrets in source code.

### Local:

``` text
DB_HOST=localhost
```

### InfinityFree:

``` text
DB_HOST=<InfinityFree MySQL hostname>
```

### Future VPS:

``` text
DB_HOST=<private/internal database hostname>
```

The PHP/Node application code should not care which provider is being
used.

------------------------------------------------------------------------

# 7. Database Access Layer

Current PHP already has a `Database` class using PDO.

Keep this abstraction.

Recommended future structure:

``` text
Database
 |
 +-- connection()
 +-- transaction()
 +-- beginTransaction()
 +-- commit()
 +-- rollback()
```

Future architecture can later add:

``` text
Database
 |
 +-- primary()
 +-- readReplica()
```

But **do not implement replicas now**.

The important thing is that application code should not contain database
hostnames everywhere.

Bad:

``` php
new PDO("mysql:host=some-server...");
```

inside random API files.

Good:

``` php
$db = Database::get();
```

------------------------------------------------------------------------

# 8. API Versioning

The current project already uses:

``` text
/api/v1/
```

Keep this.

Do not break V1 while developing V2.

Future:

``` text
/api/v1/
    PHP

/api/v2/
    Node.js
```

Both can temporarily use the same MySQL database.

``` text
             MySQL
             /   \
            /     \
       PHP V1    Node V2
```

This allows gradual migration.

Example:

``` text
V1 -> 90% traffic
V2 -> 10% traffic
```

Then:

``` text
V1 -> 50%
V2 -> 50%
```

Then:

``` text
V1 -> 10%
V2 -> 90%
```

Finally:

``` text
V1 -> deprecated
V2 -> 100%
```

Do not migrate the whole application in one huge rewrite.

------------------------------------------------------------------------

# 9. Node.js V2 Must Not Be a Line-by-Line PHP Translation

Do not do this:

``` text
PHP file:
orders/create.php

        ↓

Node file:
orders/create.js
```

just because the languages are different.

Instead, define modules/business domains:

``` text
src/
  modules/
    auth/
    customers/
    restaurants/
    riders/
    orders/
    payments/
    wallet/
    coupons/
    offers/
    notifications/
    admin/
```

Inside each module:

``` text
controller
service
repository
validation
```

Conceptually:

``` text
HTTP Request
     |
     v
Controller
     |
     v
Service
     |
     v
Repository
     |
     v
MySQL
```

This makes Node V2 easier to maintain and makes future service
separation possible.

------------------------------------------------------------------------

# 10. API Contract Is More Important Than Programming Language

PHP V1 and Node V2 must follow the same API rules.

For example:

``` text
POST /api/v1/orders
POST /api/v2/orders
```

Both should have predictable:

-   authentication
-   validation
-   HTTP status codes
-   error format
-   pagination
-   idempotency
-   transaction behavior

The mobile apps should not care whether the backend is PHP or Node.

------------------------------------------------------------------------

# 11. Standard API Response

Keep one consistent format.

Success:

``` json
{
  "success": true,
  "data": {},
  "error": null
}
```

Error:

``` json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_error",
    "message": "Invalid request",
    "fields": {}
  }
}
```

Do not make every endpoint invent its own response format.

------------------------------------------------------------------------

# 12. Authentication

The current project uses hashed authentication tokens stored in the
database.

Keep this concept if it is working correctly.

The important requirement is:

``` text
PHP V1
   |
   +---- auth_tokens
   |
Node V2
   |
   +---- auth_tokens
```

Both versions must understand the same authentication model during
migration.

Do not force a JWT migration only because Node.js is being introduced.

If JWT is introduced later, do it as a deliberate architecture change.

------------------------------------------------------------------------

# 13. Sessions Must Not Depend on One Server

Never design future APIs around:

``` text
API Server 1 local session
API Server 2 local session
```

because:

``` text
User -> API 1
session exists on API 1

Next request -> API 2
session missing
```

For a multi-server future architecture, shared state should live in:

``` text
Redis
or
Database
```

depending on the data.

------------------------------------------------------------------------

# 14. Redis --- Future, But Prepare the Code Now

Redis will eventually be useful for:

-   rate limiting
-   cache
-   short-lived OTP state
-   temporary locks
-   session/shared state
-   frequently requested data
-   queues in a more advanced architecture

Current InfinityFree free hosting is not the place to depend on Redis.

Therefore:

### NOW

Keep a clean abstraction:

``` text
CacheInterface
RateLimiterInterface
```

Possible implementations:

``` text
MySQLRateLimiter
RedisRateLimiter
```

### FUTURE

``` text
API #1 ----\
API #2 -----+---- Redis
API #3 ----/
```

This lets you move from MySQL-based rate limiting to Redis later without
rewriting every endpoint.

------------------------------------------------------------------------

# 15. Rate Limiting

The project already has rate-limiting functionality.

Keep it centralized.

Do not copy/paste rate-limit code into every API file.

Recommended:

``` text
Request
  |
  v
RateLimiter
  |
  +-- MySQL now
  |
  +-- Redis later
```

For example:

``` text
POST /auth/request-otp
```

could have a stricter limit than:

``` text
GET /restaurants
```

Eventually use multiple dimensions:

``` text
IP
User ID
Device/session
Endpoint
```

But tune limits using real traffic instead of blindly choosing numbers.

------------------------------------------------------------------------

# 16. Wallet and Money Architecture

This is one of the most important parts of AnyDrop.

Wallet operations must be transactional.

Do not rely on:

``` text
read balance
calculate
write balance
```

without protection.

Preferred flow:

``` text
BEGIN TRANSACTION

Lock wallet row

Check current balance

Validate operation

Insert ledger transaction

Update wallet balance

Commit
```

Every financial operation should have:

``` text
transaction_id
reference_id
idempotency_key
amount
type
status
created_at
```

Examples:

``` text
wallet credit
wallet debit
refund
withdrawal
payment
commission
rider settlement
restaurant settlement
```

The ledger should be auditable.

------------------------------------------------------------------------

# 17. Idempotency

The current project already uses idempotency for important order
creation flows.

Expand this principle to all operations where a retry could cause
duplicate money/order effects.

Examples:

``` text
Create order
Create payment
Refund
Wallet credit
Wallet debit
Withdrawal
Payout
Webhook processing
```

Example:

``` text
Request
Idempotency-Key: abc123
```

If the same request arrives again:

``` text
abc123 already processed
        |
        v
Return previous result
```

This is extremely important when mobile networks retry requests.

------------------------------------------------------------------------

# 18. Images --- NOW

Because you are using InfinityFree initially, do not introduce paid
object storage/CDN yet.

For now:

``` text
Restaurant App
      |
      v
PHP upload endpoint
      |
      v
InfinityFree storage
      |
      v
Database stores file path/key
```

Example database value:

``` text
uploads/restaurants/25/menu/abc123.webp
```

Do not store the actual image binary in MySQL unless there is a strong
reason.

------------------------------------------------------------------------

# 19. Important: Do Not Couple Database to Image Location

Bad:

``` text
image_url =
https://some-infinityfree-domain.com/uploads/food.jpg
```

everywhere in application logic.

Better:

``` text
image_key =
restaurants/25/menu/food-123.webp
```

Then a centralized URL builder creates the actual URL:

``` text
Storage::url($imageKey)
```

NOW:

``` text
Storage::url(...)
        |
        v
InfinityFree URL
```

FUTURE:

``` text
Storage::url(...)
        |
        v
R2/S3/Object Storage + CDN URL
```

This single abstraction will make future migration much easier.

------------------------------------------------------------------------

# 20. Future Object Storage

When AnyDrop grows, move images/files to object storage.

Future:

``` text
App
 |
 v
Object Storage
 |
 +-- restaurant images
 +-- menu images
 +-- banners
 +-- documents
 +-- settlement screenshots
 +-- other media
 |
 v
CDN
 |
 v
Users
```

The database stores metadata:

``` text
storage_key
mime_type
size
width
height
created_at
```

The actual file lives in object storage.

------------------------------------------------------------------------

# 21. CDN --- NOT NOW

Do not force CDN into the InfinityFree stage.

Current:

``` text
User
 |
 v
InfinityFree
 |
 +-- PHP
 +-- images
```

Future:

``` text
User
 |
 v
CDN
 |
 +-- cached images
 +-- static assets
 |
 v
Object Storage
```

When CDN is introduced, the application should not need a major rewrite
because image URLs already come through the storage abstraction.

------------------------------------------------------------------------

# 22. File Upload Rules

Even while using InfinityFree, make uploads future-safe.

For every upload:

1.  Validate MIME type.
2.  Validate extension.
3.  Validate file size.
4.  Generate a random server-side filename.
5.  Never trust the original filename.
6.  Prevent executable uploads.
7.  Store outside sensitive PHP execution paths where possible.
8.  Store only the file key/path in MySQL.
9.  Compress/resize images where appropriate.

Example:

``` text
Original:
Biryani Final!!!.jpg

Stored:
restaurants/25/menu/8f2c9d1a.webp
```

Do not use:

``` text
Biryani Final!!!.jpg
```

as the server filename.

------------------------------------------------------------------------

# 23. Avoid Local Server Dependency

Future multi-server deployment will fail if the application assumes:

``` text
/local/uploads
/local/cache
/local/session
/local/logs
```

are shared.

Use:

``` text
Database -> persistent application data
Redis -> temporary/shared fast state
Object Storage -> files
Central logging -> production logs
```

For InfinityFree, local storage is temporarily acceptable, but keep it
behind a storage abstraction.

------------------------------------------------------------------------

# 24. Background Jobs

The current InfinityFree free environment should not be treated as a
permanent worker platform.

Do not make important business operations depend on long-running PHP
processes.

Future architecture:

``` text
API
 |
 v
Queue
 |
 +-- notifications
 +-- rider dispatch
 +-- email
 +-- analytics
 +-- webhook processing
 +-- background reconciliation
```

Node.js workers can later process these jobs.

For now, use simple PHP request/response logic where appropriate and
keep heavy/long-running work limited.

InfinityFree has historically disabled its native cron feature, so do
not make the free plan's cron behavior a core architectural dependency.
citeturn0search13

------------------------------------------------------------------------

# 25. PHP V1 + Node V2 Shared Database

During migration:

``` text
                  MySQL
                 /     \
                /       \
           PHP V1      Node V2
```

Both must respect:

-   same schema
-   same transactions
-   same IDs
-   same wallet rules
-   same order state rules
-   same authentication rules
-   same financial ledger rules

Do not let PHP and Node implement conflicting business rules.

Example:

Bad:

``` text
PHP:
cancelled -> refund 100%

Node:
cancelled -> refund 80%
```

Good:

``` text
Business rule
     |
     +---- PHP V1
     |
     +---- Node V2
```

The business rule must be documented independently of the programming
language.

------------------------------------------------------------------------

# 26. Database Migrations

The current project already has a migration history.

Keep using migrations.

Never manually change production schema and forget to document it.

Recommended:

``` text
migrations/
  001_initial_schema
  002_wallet_ledger
  003_orders
  004_rider_cod_limits
  ...
```

Every schema change should be reproducible.

Example:

``` text
Development DB
      |
      v
Migration
      |
      v
Production DB
```

Future Node V2 must use the same migration system or a compatible
migration history.

------------------------------------------------------------------------

# 27. Indexing

Before thinking about database sharding, make MySQL efficient.

Important fields usually need indexes:

``` text
user_id
restaurant_id
rider_id
order_id
status
created_at
updated_at
token hashes
idempotency keys
transaction IDs
```

But do not blindly index every column.

Indexes should follow actual queries.

Use:

``` text
EXPLAIN
```

to investigate slow queries.

------------------------------------------------------------------------

# 28. Pagination

The project already defines pagination standards.

Keep:

``` text
?page=1&per_page=20
```

Do not return thousands of rows to mobile clients.

Future large datasets may require cursor pagination:

``` text
?cursor=abc123&limit=20
```

This can be introduced for high-volume endpoints later.

------------------------------------------------------------------------

# 29. Avoid N+1 Queries

Bad:

``` text
Get 100 restaurants
   |
   +-- query menu for restaurant 1
   +-- query menu for restaurant 2
   +-- query menu for restaurant 3
   ...
```

This can become hundreds of DB queries.

Prefer:

``` text
1 optimized query
or
a small number of batched queries
```

This matters more than adding servers prematurely.

------------------------------------------------------------------------

# 30. API Statelessness

Future API servers should be stateless.

Meaning:

``` text
API #1
API #2
API #3
```

should all be interchangeable.

Any request can go to any API server.

Do not store important user state only in API #1's RAM or filesystem.

This is what makes:

``` text
Load Balancer
       |
 +-----+-----+
 |     |     |
API1 API2 API3
```

possible.

------------------------------------------------------------------------

# 31. Future Load Balancer

When traffic becomes large:

``` text
Users
  |
  v
Load Balancer
  |
  +---- API #1
  |
  +---- API #2
  |
  +---- API #3
```

The load balancer does NOT replace the database.

It distributes incoming HTTP/API requests between API servers.

All API servers can then access the same authoritative database layer.

------------------------------------------------------------------------

# 32. Future Database Scaling

Do not start with sharding.

First:

``` text
One MySQL Primary
```

Then, if necessary:

``` text
MySQL Primary
     |
     +-- Read Replica 1
     +-- Read Replica 2
```

Writes:

``` text
API -> Primary
```

Read-heavy workloads:

``` text
API -> Read Replica
```

Critical wallet/payment reads should remain authoritative against the
primary where required by the consistency model.

Only at much larger scale should sharding be considered.

------------------------------------------------------------------------

# 33. Sharding --- Future Only

If AnyDrop eventually becomes extremely large, data can be partitioned.

Example:

``` text
Shard 1
User range/partition A

Shard 2
User range/partition B

Shard 3
User range/partition C
```

The application/router determines which shard owns a user.

Do not implement this now.

Do not create:

``` text
DB1
DB2
DB3
```

just because you expect millions of users.

Premature sharding makes development harder.

------------------------------------------------------------------------

# 34. Service Boundaries for Future

Do not immediately create 20 microservices.

Start as a modular monolith:

``` text
AnyDrop Backend
 |
 +-- Auth
 +-- Customer
 +-- Restaurant
 +-- Rider
 +-- Orders
 +-- Payments
 +-- Wallet
 +-- Offers
 +-- Notifications
 +-- Admin
```

Later, if one area needs independent scaling:

``` text
AnyDrop
 |
 +-- API
 |
 +-- Order Service
 |
 +-- Dispatch Service
 |
 +-- Notification Worker
 |
 +-- Payment Service
```

Extract services only when there is a real operational reason.

------------------------------------------------------------------------

# 35. Recommended Node.js V2 Stack

Recommended structure:

``` text
Node.js
   |
   +-- TypeScript
   +-- NestJS / Fastify-based structured API
   +-- MySQL
   +-- Redis
   +-- Queue/worker system later
   +-- Docker later
```

The exact framework can be chosen later, but the important part is:

-   typed DTOs
-   validation
-   centralized errors
-   modules
-   services
-   repositories
-   database transactions
-   structured logging
-   configuration through environment variables

Do not introduce a second database technology just because Node.js is
being introduced.

PHP V1 and Node V2 can both use MySQL.

------------------------------------------------------------------------

# 36. Recommended Current Folder Direction

Keep the existing PHP V1 structure stable, but gradually organize new
code around modules.

Conceptually:

``` text
backend/
├── api/
│   └── v1/
│
├── admin/
│
├── config/
│
├── lib/
│   ├── auth/
│   ├── wallet/
│   ├── orders/
│   ├── payments/
│   ├── rate_limit/
│   ├── storage/
│   ├── cache/
│   └── database/
│
├── sql/
│   └── migrations/
│
└── uploads/
```

Do not perform a giant folder rewrite if the existing application is
stable. Refactor gradually.

------------------------------------------------------------------------

# 37. New Storage Abstraction

Create one logical interface:

``` text
Storage
 |
 +-- put()
 +-- delete()
 +-- exists()
 +-- url()
```

Current implementation:

``` text
InfinityFreeStorage
```

Future implementation:

``` text
R2Storage
S3Storage
```

The API code should call:

``` text
Storage::put(...)
Storage::url(...)
```

not:

``` text
move_uploaded_file(..., "/specific/infinityfree/path")
```

everywhere.

This is one of the highest-value changes for future migration.

------------------------------------------------------------------------

# 38. New Cache Abstraction

Create:

``` text
Cache
 |
 +-- get()
 +-- set()
 +-- delete()
 +-- increment()
```

Current:

``` text
No external cache / DB-backed fallback
```

Future:

``` text
RedisCache
```

This lets the application adopt Redis later without changing business
logic.

------------------------------------------------------------------------

# 39. New Queue Abstraction

Future:

``` text
Queue
 |
 +-- dispatch()
 +-- retry()
```

Current:

``` text
Simple synchronous execution
```

Future:

``` text
Redis Queue / dedicated worker
```

Do not make the current InfinityFree deployment dependent on a queue
server.

------------------------------------------------------------------------

# 40. Secrets

Never commit:

``` text
DB password
APP secret
payment secret
FCM server secret
Google server key
webhook secret
```

to GitHub.

Use environment variables or a secret-management solution when
available.

The Android app must never contain server-only secrets.

------------------------------------------------------------------------

# 41. Security Rules

Minimum requirements:

``` text
HTTPS
Prepared SQL statements
Input validation
Authorization on every protected resource
RBAC
Rate limiting
Secure password hashing
Token hashing
Webhook verification
Idempotency
Transaction protection
Generic production errors
No stack traces to users
No secret leakage
```

The existing security roadmap already identifies HMAC, certificate
pinning, server-side API keys, Play Integrity and rate limiting as
future hardening items.

Do not treat HMAC or certificate pinning as a replacement for
server-side authorization. The server must always verify whether the
authenticated user is actually allowed to perform the requested
operation.

------------------------------------------------------------------------

# 42. Google / Paid API Keys

Never put expensive server-side Google API keys in the Android APK.

Future flow:

``` text
Android
   |
   v
AnyDrop API
   |
   v
Google API
```

Server-only key:

``` text
Google API key
       |
       v
Backend environment variable
```

Not:

``` text
Android APK
   |
   v
Secret Google key
```

------------------------------------------------------------------------

# 43. Logs

Do not depend permanently on local log files.

Current:

``` text
PHP logs
```

Future:

``` text
API
 |
 v
Central logging
```

Every important error should have:

``` text
request_id
user_id (when safe)
endpoint
timestamp
error code
```

Never log:

``` text
passwords
OTP
auth tokens
payment secrets
full card data
```

------------------------------------------------------------------------

# 44. Request IDs

Every API request should eventually receive:

``` text
X-Request-ID
```

Example:

``` text
Request:
X-Request-ID: 7c5a9f...
```

That ID should appear in server logs.

Then a support issue can be traced:

``` text
Customer says:
"Order failed."

Admin finds:
Request ID = 7c5a9f

Logs show:
Order validation failed
```

This becomes extremely valuable when multiple API servers exist.

------------------------------------------------------------------------

# 45. Health Endpoint

Create a lightweight health endpoint.

Example:

``` text
GET /health
```

Response:

``` json
{
  "status": "ok"
}
```

Later:

``` text
GET /health/ready
```

can check:

-   application
-   database
-   Redis
-   queue

But keep public health responses minimal; do not expose secrets or
infrastructure details.

------------------------------------------------------------------------

# 46. Deployment Strategy

### NOW --- InfinityFree

``` text
GitHub
   |
   v
Build/test locally
   |
   v
Upload PHP backend
   |
   v
InfinityFree
```

Keep database migrations separately documented.

Do not make InfinityFree the only copy of the source code.

------------------------------------------------------------------------

# 47. Future --- VPS

``` text
GitHub
   |
   v
CI/CD
   |
   v
Docker
   |
   +-- Nginx
   +-- PHP/Node API
   +-- Worker
```

Database can be moved separately.

------------------------------------------------------------------------

# 48. Future Migration Without Rewriting the App

Example migration:

### Step 1

Current:

``` text
InfinityFree
 |
 +-- PHP V1
 +-- MySQL
 +-- images
```

### Step 2

Move PHP V1 to VPS:

``` text
VPS
 |
 +-- PHP V1
 |
 +-- MySQL
```

### Step 3

Add Node V2:

``` text
VPS
 |
 +-- PHP V1
 +-- Node V2
 |
 +-- MySQL
```

### Step 4

Move images:

``` text
Object Storage
```

### Step 5

Add Redis:

``` text
Redis
```

### Step 6

Scale APIs:

``` text
Load Balancer
 |
 +-- API 1
 +-- API 2
 +-- API 3
```

### Step 7

Scale database:

``` text
Primary
 |
 +-- Replica
 +-- Replica
```

The application does not need to be rewritten at every stage.

------------------------------------------------------------------------

# 49. Migration Checklist

Before moving from InfinityFree:

``` text
[ ] Export complete MySQL database
[ ] Verify migration scripts
[ ] Backup uploaded files
[ ] Verify image paths
[ ] Change DB environment variables
[ ] Change storage configuration
[ ] Change API base URL if required
[ ] Enable HTTPS
[ ] Test authentication
[ ] Test wallet
[ ] Test orders
[ ] Test payments
[ ] Test refunds
[ ] Test rider dispatch
[ ] Test restaurant flows
[ ] Test admin panel
[ ] Test webhooks
[ ] Test notifications
[ ] Load test
[ ] Monitor logs
```

------------------------------------------------------------------------

# 50. What NOT To Do

Do not:

``` text
❌ Start with microservices
❌ Start with database sharding
❌ Create 3 databases for 100k users
❌ Store images inside MySQL
❌ Hard-code InfinityFree URLs everywhere
❌ Hard-code DB credentials
❌ Depend on local server sessions
❌ Depend on local server cache
❌ Run long background processes on free hosting
❌ Rewrite all PHP code into Node immediately
❌ Make Node and PHP use different business rules
❌ Trust client-provided prices/totals
❌ Update wallet without transactions
❌ Ignore idempotency
```

------------------------------------------------------------------------

# 51. What TO Do Now

Priority order:

## P0 --- Do immediately

``` text
1. Environment-based configuration
2. Keep DB abstraction
3. Keep API V1 stable
4. Keep migrations
5. Centralize authentication
6. Centralize authorization
7. Centralize error handling
8. Protect wallet/payment transactions
9. Consistent API response format
10. Pagination
```

## P1 --- Do while preparing V2

``` text
11. Storage abstraction
12. Cache abstraction
13. Rate limiter abstraction
14. Service/repository boundaries
15. Request IDs
16. Health endpoint
17. Better logging
18. Idempotency across financial operations
```

## P2 --- Future

``` text
19. Node.js V2
20. Redis
21. Object Storage
22. CDN
23. Background workers
24. Load balancer
25. Multiple API servers
26. Read replicas
27. Database sharding only if actually required
```

------------------------------------------------------------------------

# 52. Final Target Architecture

## Current

``` text
                  AnyDrop Apps
                       |
                       v
                 InfinityFree
                       |
                    PHP V1
                       |
                    MySQL
                       |
              InfinityFree uploads
```

## First migration

``` text
                  AnyDrop Apps
                       |
                       v
                     VPS
                 /           \
            PHP V1          Node V2
                 \           /
                  \         /
                    MySQL
```

## Growing production

``` text
                    Users
                      |
               Load Balancer
                      |
          +-----------+-----------+
          |           |           |
        API #1      API #2      API #3
          |           |           |
          +-----------+-----------+
                      |
                    Redis
                      |
                 MySQL Primary
                  /         \
                 /           \
          Read Replica    Read Replica

                      +

               Object Storage
                      |
                     CDN
```

## Very large scale

``` text
                         AnyDrop
                            |
                     Global/Regional LB
                            |
                +-----------+-----------+
                |           |           |
             API Pool    API Pool    API Pool
                |           |           |
                +-----------+-----------+
                            |
                 +----------+----------+
                 |                     |
               Redis              Queue/Workers
                 |
                 v
           Database Router
          /       |        \
       Shard 1  Shard 2   Shard 3
         |         |         |
      Replicas  Replicas  Replicas

                            +
                     Object Storage
                            |
                           CDN
```

------------------------------------------------------------------------

# 53. The Main Principle

AnyDrop should evolve like this:

``` text
Simple
  ↓
Modular
  ↓
Scalable
  ↓
Distributed
```

**Not:**

``` text
Simple
  ↓
Over-engineered
  ↓
Impossible to maintain
```

At the current stage, **one PHP API + one MySQL database on InfinityFree
is enough for development/early testing**.

The real work now is not creating 10 servers.

The real work is creating clean boundaries:

``` text
API
 |
 +-- Auth
 +-- Business Logic
 +-- Database abstraction
 +-- Storage abstraction
 +-- Cache abstraction
 +-- Rate-limit abstraction
 +-- Idempotency
 +-- Transactions
```

If these boundaries are correct, moving from:

``` text
InfinityFree
```

to:

``` text
VPS
```

and later:

``` text
multiple API servers + Redis + Object Storage + CDN + DB replicas
```

becomes an infrastructure evolution instead of a complete rewrite.

------------------------------------------------------------------------

# 54. AnyDrop Architecture Rule --- One Sentence

> **Build the code so that PHP, Node.js, InfinityFree, VPS, Redis,
> Object Storage, CDN and multiple API servers are replaceable
> infrastructure components---not assumptions hard-coded into business
> logic.**
