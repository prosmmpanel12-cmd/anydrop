# AnyDrop Admin Panel --- Scalable Management Plan

**Project:** AnyDrop Food Delivery Platform\
**Based on:** `anydrop_project_v31.zip`\
**Date:** 2026-08-26\
**Purpose:** Define the long-term Admin Panel management architecture
for scaling AnyDrop from Osian to Jodhpur, Rajasthan, and eventually
multiple states/cities without requiring the owner to personally operate
every location.

------------------------------------------------------------------------

## 1. Executive Decision

AnyDrop should **not** use one giant admin account that manually manages
every city.

The Admin Panel should use two independent controls:

1.  **Role = WHAT the admin is allowed to do**
2.  **Scope = WHERE the admin is allowed to do it**

Example:

-   `Super Admin + India` → complete platform access
-   `State Admin + Rajasthan` → Rajasthan only
-   `City Admin + Jodhpur` → Jodhpur only
-   `Area Operations + Osian` → Osian only
-   `Finance Admin + Rajasthan` → financial permissions within Rajasthan
-   `Customer Support + Jodhpur` → support permissions within Jodhpur

This is the core architecture required for AnyDrop to scale.

------------------------------------------------------------------------

# 2. Target Organization

``` text
ANYDROP
│
└── SUPER ADMIN
    │
    ├── Rajasthan
    │   ├── State Admin
    │   ├── Jodhpur
    │   │   ├── City Admin
    │   │   ├── Area/Operations Manager
    │   │   └── Functional Staff
    │   │       ├── Restaurant Operations
    │   │       ├── Rider Operations
    │   │       ├── Customer Support
    │   │       └── Marketing
    │   │
    │   ├── Jaipur
    │   └── Other Cities
    │
    ├── Gujarat
    │   ├── State Admin
    │   └── Cities
    │
    ├── Maharashtra
    │   ├── State Admin
    │   └── Cities
    │
    └── Other States
```

Important: every level is optional except the Super Admin. AnyDrop
should not require a State Admin or City Admin just because a location
exists. These roles can be introduced when operational volume justifies
them.

------------------------------------------------------------------------

# 3. Current Project Situation

The current project already has a strong foundation:

-   Session-based web Admin Panel exists.
-   Admin RBAC tables and permission keys exist.
-   `roles.php` exists for creating roles and assigning permissions.
-   `admin_require_permission()` exists.
-   Service-area hierarchy exists.
-   State → District → City/Village → optional Area model exists.
-   Restaurant and address records can be linked to service areas.
-   Order Control exists.
-   Admin Analytics exists.
-   Platform Ledger and Settlements exist.
-   Commission Rules exist.
-   Pricing Rules exist.
-   COD Rules exist.
-   Payment Restrictions exist.
-   Payment Gateway management exists.
-   Banners, Offers, Categories, Customers and Restaurants have admin
    screens.
-   Audit logging is already used for several sensitive admin actions.

Therefore, the next major Admin Panel evolution is **not rebuilding the
panel**.

The main architectural gap is:

> **Current RBAC controls permissions, but there is no proper
> geographic/organizational scope system that restricts an admin to a
> State, City, Area, or set of locations.**

------------------------------------------------------------------------

# 4. Current RBAC --- Keep It

The existing RBAC foundation should remain.

Current architecture:

``` text
admins
   │
   └── role_id
          │
          ▼
     admin_roles
          │
          ▼
admin_role_permissions
          │
          ▼
admin_permissions
```

This is correct.

The existing permission model should continue to answer:

> "Can this admin approve a restaurant?"

Examples:

``` text
restaurants_view
restaurants_edit
restaurants_delete
restaurants_approve

orders_view
orders_manage
orders_export

customers_view
customers_edit
customers_suspend
customers_delete

payouts_view
payouts_manage
payouts_export

reports_view
reports_export

roles_manage
audit_logs_view
settings_manage
```

Do not replace this with a simple `admin_type` or `is_super_admin`
check.

------------------------------------------------------------------------

# 5. Required New Layer --- Admin Scope

Add a geographic scope layer above the existing permissions.

Recommended structure:

``` text
Admin
│
├── Role
│   └── WHAT can be done
│
└── Scope
    └── WHERE it can be done
```

Example:

``` text
Admin:
    Rahul

Role:
    Restaurant Manager

Scope:
    Rajasthan → Jodhpur
```

This means Rahul can perform restaurant-manager actions only for
Jodhpur.

------------------------------------------------------------------------

# 6. Scope Database Design

Recommended tables:

## `admin_scopes`

``` text
id
scope_type
scope_id
created_at
```

Possible scope types:

``` text
india
state
district
city_village
area
restaurant
```

For the first production version, geographic scopes are enough:

``` text
state
district
city_village
area
```

Restaurant-specific scope can be added when required.

## `admin_user_scopes`

``` text
admin_id
scope_id
created_at
```

This allows one admin to manage multiple locations.

Example:

``` text
Regional Operations Manager
    ├── Jodhpur
    ├── Pali
    └── Barmer
```

Do not store only one `city_id` directly on `admins`, because that will
become restrictive later.

------------------------------------------------------------------------

# 7. Scope Inheritance

A parent scope should automatically include its children.

Example:

``` text
Rajasthan
│
├── Jodhpur
│   ├── Osian
│   └── Sardarpura
│
└── Jaipur
```

A Rajasthan State Admin can see:

``` text
Rajasthan
Jodhpur
Osian
Sardarpura
Jaipur
```

A Jodhpur City Admin can see:

``` text
Jodhpur
Osian
Sardarpura
```

An Osian Area Manager can see:

``` text
Osian
```

This should be enforced server-side, not only through dropdowns.

------------------------------------------------------------------------

# 8. Critical Rule --- Never Trust UI Scope

Hiding a city from a dropdown is not security.

Bad:

``` text
Admin Panel hides Jaipur
but backend accepts:
?city_id=jaipur
```

Good:

``` text
Request
   ↓
Authentication
   ↓
Permission check
   ↓
Scope check
   ↓
Database query
   ↓
Response
```

Every sensitive query must apply the admin's effective scope.

------------------------------------------------------------------------

# 9. Scope Resolution

Because AnyDrop already has:

``` text
State
 ↓
District
 ↓
City/Village
 ↓
Area
```

most entities can inherit their scope through relationships.

Example:

``` text
Restaurant
   ↓
restaurant.area_id
   ↓
service_areas
   ↓
parent_id chain
   ↓
City
   ↓
District
   ↓
State
```

Order:

``` text
Order
 ↓
Restaurant / Delivery Address
 ↓
Service Area
 ↓
City / State
```

This means you should not duplicate `state_id`, `city_id`, etc. into
every table unless there is a strong performance reason.

Use the existing service-area hierarchy as the source of truth.

------------------------------------------------------------------------

# 10. Recommended Admin Roles

## 10.1 Super Admin

Scope:

``` text
India / Global
```

Can:

-   Manage all states
-   Manage all cities
-   Manage all restaurants
-   Manage riders
-   Manage customers
-   Manage payments
-   Manage settlements
-   Manage commission
-   Manage pricing
-   Manage offers
-   Manage banners
-   Manage roles
-   Manage admin users
-   Manage system settings
-   View all analytics
-   View audit logs
-   Perform emergency operations

Only trusted owner/core team members should have this role.

------------------------------------------------------------------------

## 10.2 State Admin

Example:

``` text
Role: State Admin
Scope: Rajasthan
```

Can manage:

-   Cities within Rajasthan
-   Restaurants
-   Riders
-   Customers
-   Orders
-   Local offers
-   Local banners
-   Local operational settings
-   Local analytics
-   Support

Cannot manage:

-   Other states
-   Global payment configuration
-   Gateway credentials
-   Global system settings
-   Super Admins
-   Global security settings

------------------------------------------------------------------------

## 10.3 District/Regional Admin

Optional.

Useful when a state becomes large.

Example:

``` text
Role: Regional Admin
Scope: Jodhpur District
```

Can manage cities/areas within the assigned district.

Do not create this role everywhere automatically.

------------------------------------------------------------------------

## 10.4 City Admin

Example:

``` text
Role: City Admin
Scope: Jodhpur
```

Can manage:

-   Restaurants
-   Riders
-   Orders
-   Customer support
-   Local campaigns
-   Local banners
-   Local operational issues
-   City analytics

Cannot manage:

-   Other cities
-   State-level system settings
-   Global payment settings
-   Global roles

------------------------------------------------------------------------

## 10.5 Area Operations Manager

Example:

``` text
Role: Area Operations
Scope: Osian
```

Useful when a city becomes large enough to need local operators.

------------------------------------------------------------------------

## 10.6 Restaurant Operations

Permissions:

``` text
restaurants_view
restaurants_edit
restaurants_approve
```

Scope can be:

``` text
State
City
Area
```

------------------------------------------------------------------------

## 10.7 Rider Operations

Recommended future permissions:

``` text
riders_view
riders_edit
riders_approve
riders_suspend
riders_assign
riders_export
```

Scope:

``` text
State / City / Area
```

------------------------------------------------------------------------

## 10.8 Customer Support

Recommended permissions:

``` text
customers_view
customers_edit
customers_suspend
orders_view
orders_manage
support_view
support_manage
```

Scope:

``` text
City / State
```

Important: `orders_manage` must not mean unlimited financial/order
modification. Sensitive actions should have their own permissions.

------------------------------------------------------------------------

## 10.9 Finance Admin

Recommended:

``` text
payouts_view
payouts_manage
payouts_export
reports_view
reports_export
refunds_view
refunds_manage
ledger_view
settlements_view
```

Do not give restaurant deletion or role-management permissions.

------------------------------------------------------------------------

## 10.10 Marketing Admin

Recommended:

``` text
banners_view
banners_edit
coupons_view
coupons_edit
notifications_view
notifications_send
reports_view
```

Scope:

``` text
State / City / Area
```

------------------------------------------------------------------------

## 10.11 Read Only Admin

Useful for:

-   Investors
-   Management
-   Auditors
-   Temporary observers

Permissions:

``` text
*_view
```

No mutation permissions.

------------------------------------------------------------------------

# 11. Permission Model Improvement

The current permission system is good, but some permissions are too
broad.

For example:

``` text
orders_manage
payouts_manage
settings_manage
```

can become dangerous as the platform grows.

Recommended future split:

``` text
orders_view
orders_assign
orders_cancel
orders_override
orders_refund_request
orders_refund_approve

payouts_view
payouts_approve
payouts_retry
payouts_export

settings_view
settings_edit
settings_global_edit

refunds_view
refunds_create
refunds_approve
```

Use high-risk permissions separately.

------------------------------------------------------------------------

# 12. Admin Panel Main Navigation

Recommended long-term navigation:

``` text
DASHBOARD
│
├── Overview
├── Live Operations
│
OPERATIONS
├── Orders
├── Restaurants
├── Riders
├── Customers
├── Support
│
LOCATION
├── States
├── Districts
├── Cities
├── Areas
├── Coverage / Service Areas
│
CATALOG
├── Categories
├── Menu Oversight
├── Offers
├── Coupons
├── Banners
│
FINANCE
├── Platform Ledger
├── Settlements
├── Payouts
├── Refunds
├── Commission Rules
├── Pricing Rules
├── COD Rules
├── Payment Restrictions
├── Payment Gateways
│
ANALYTICS
├── Analytics
├── Reports
├── Exports
│
COMMUNICATION
├── Notifications
├── Campaigns
├── Templates
│
ADMINISTRATION
├── Admin Users
├── Roles & Permissions
├── Audit Logs
├── Security
├── App Settings
├── App Version
│
SYSTEM
├── Payment Providers
├── Email Providers
├── Fraud
├── System Health
```

Do not show every module to every role.

------------------------------------------------------------------------

# 13. Global Location Selector

The Super Admin dashboard should have a global location selector:

``` text
Location: India ▼
```

Selecting:

``` text
India
Rajasthan
Jodhpur
Osian
```

should update:

-   Dashboard
-   Orders
-   Restaurants
-   Riders
-   Customers
-   Analytics
-   Offers
-   Banners
-   Operational data

This is much better than creating separate admin panels for every city.

------------------------------------------------------------------------

# 14. Example Super Admin Workflow

``` text
Super Admin
    ↓
Location = Rajasthan
    ↓
Location = Jodhpur
    ↓
Restaurants
    ↓
Pending Approvals
```

The Super Admin sees Jodhpur-specific data.

Then:

``` text
Location = Jaipur
```

The same screen now shows Jaipur.

No second login. No second admin panel. No duplicate code.

------------------------------------------------------------------------

# 15. City Admin Workflow

A Jodhpur City Admin should log in and immediately see:

``` text
Jodhpur Operations

Today's Orders
Today's GMV
Active Restaurants
Active Riders
Pending Restaurant Approvals
Pending Rider Approvals
Open Support Issues
Cancelled Orders
Operational Alerts
```

They should not even see unrelated Rajasthan/India data.

------------------------------------------------------------------------

# 16. Service Area Management

Current `service_areas` architecture should remain the source of truth.

Current direction:

``` text
State
 ↓
District
 ↓
City/Village
 ↓
Area (optional)
```

This is good.

Important improvements:

### Add validation

Prevent:

``` text
State
 └── State
```

or:

``` text
Area
 └── District
```

### Add safe merge

The existing area merge functionality is useful.

Keep:

``` text
Merge Area A → Area B
```

but require:

-   confirmation
-   impact preview
-   audit log
-   affected restaurants count
-   affected customers count
-   affected banners count
-   affected rules count

Never silently delete an area.

------------------------------------------------------------------------

# 17. Restaurant Management

Restaurant access should automatically follow location.

Example:

``` text
Restaurant
 ↓
Osian
 ↓
Jodhpur
 ↓
Rajasthan
```

Therefore:

``` text
Super Admin      → sees it
Rajasthan Admin  → sees it
Jodhpur Admin    → sees it
Osian Manager    → sees it
Jaipur Admin     → does NOT see it
```

Restaurant approval should also obey scope.

------------------------------------------------------------------------

# 18. Rider Management --- Important Future Gap

The project currently has rider data, but the full rider operational
system is not complete.

The current project documentation confirms a major limitation:

-   No complete rider-facing API/operational flow yet.
-   COD orders cannot currently reach the full delivered lifecycle
    through a rider flow.
-   Therefore COD commission cannot be fully realized in the ledger
    until rider delivery flow exists.

This should remain a separate Rider App/Operations phase.

Do not fake rider analytics or COD settlement numbers before real rider
lifecycle data exists.

------------------------------------------------------------------------

# 19. Orders Management

Current Order Control is already a strong addition.

Long-term order control should support:

``` text
View
Filter
Search
Location
Restaurant
Customer
Rider
Status
Payment Method
Date
```

High-risk actions:

``` text
Cancel
Override
Refund
Reassign
Manual status change
```

must:

1.  Require specific permission.
2.  Require reason.
3.  Create audit log.
4.  Record old value and new value.
5.  Record admin ID.
6.  Record timestamp.

------------------------------------------------------------------------

# 20. Analytics

Current Admin Analytics already covers:

-   Date ranges
-   Orders
-   Revenue
-   GMV
-   Platform Revenue
-   Commission
-   Discounts
-   Refunds
-   Top/Bottom Restaurants
-   Top Items
-   Customers
-   Areas

This should be extended with location filtering:

``` text
India
State
District
City
Area
Restaurant
```

Example:

``` text
Analytics
Location: Rajasthan
Period: Last 30 days
```

Then:

``` text
Orders
GMV
Commission
Refunds
Restaurants
Customers
AOV
```

are calculated only for Rajasthan.

------------------------------------------------------------------------

# 21. Financial Command Center

The current Platform Ledger + Settlements should remain the financial
source of truth.

Important rules:

-   Never calculate financial history from UI-only values.
-   Ledger entries should be immutable.
-   Corrections should create reversal/adjustment entries.
-   Settlement state should be auditable.
-   Every manual financial action requires an audit entry.

Current paid-order ledger wiring is already present.

Known limitation:

> COD commission remains incomplete until the rider delivery lifecycle
> can reliably mark COD orders as delivered.

Do not report COD commission as real revenue before that lifecycle
exists.

------------------------------------------------------------------------

# 22. Offers / Coupons / Banners

These must become location-aware.

Example:

``` text
Offer:
"Jodhpur Weekend ₹50 OFF"

Target:
Rajasthan → Jodhpur
```

Another:

``` text
Banner:
"Osian Free Delivery"

Target:
Rajasthan → Jodhpur → Osian
```

The admin should show:

``` text
Target:
India
State
District
City
Area
Restaurant
```

and display the full breadcrumb so duplicate names are never confusing.

------------------------------------------------------------------------

# 23. Customer Management

Customer records should include:

``` text
Customer
Orders
Addresses
Service Area
Wallet
Coupons
Complaints
Suspension
Risk/Fraud status
```

Customer support should only see records inside its scope.

Global customer search should be restricted to Super Admin or a
dedicated global support role.

------------------------------------------------------------------------

# 24. Audit Logs

Audit logging is mandatory for:

``` text
Admin login
Admin creation
Admin role change
Admin activation/deactivation
Permission change
Restaurant approval
Restaurant rejection
Restaurant suspension
Customer suspension
Refund
Settlement
Payout
Commission change
Pricing change
COD rule change
Payment restriction change
Offer changes
Banner changes
Location changes
Area merge/delete
Manual order override
Impersonation
```

Each log should ideally contain:

``` text
actor_admin_id
action
entity_type
entity_id
old_value
new_value
reason
ip_address
user_agent
created_at
```

------------------------------------------------------------------------

# 25. Impersonation / "View As"

Super Admin should be able to inspect the platform from another admin's
perspective.

Example:

``` text
Super Admin
    ↓
View As
    ↓
Jodhpur City Admin
```

But this must NOT silently become unrestricted account takeover.

Requirements:

-   explicit start
-   prominent "Viewing as..." indicator
-   audit log
-   explicit exit
-   high-risk actions should require confirmation
-   preferably read-only by default

------------------------------------------------------------------------

# 26. Current Admin Panel Problems / Improvements

## P0 --- Must Fix Before Multi-City Scale

### P0.1 Geographic Admin Scope Missing

Current RBAC answers:

> What can this admin do?

It does not yet fully answer:

> Where can this admin do it?

**Required:**

``` text
admin_scopes
admin_user_scopes
```

with inherited State → District → City/Village → Area access.

------------------------------------------------------------------------

### P0.2 Server-Side Scope Enforcement

Do not implement scope only in UI.

Every query must apply effective scope.

This is a security requirement, not a UI feature.

------------------------------------------------------------------------

### P0.3 Global vs Local Settings Separation

Current permissions contain broad settings such as:

``` text
settings_manage
payment_providers_manage
email_providers_manage
```

These should be explicitly classified as:

``` text
GLOBAL
STATE
CITY
AREA
```

A City Admin must never accidentally modify a global payment gateway
configuration.

------------------------------------------------------------------------

### P0.4 High-Risk Permission Granularity

Split broad permissions such as:

``` text
orders_manage
payouts_manage
settings_manage
```

into smaller actions before giving them to many staff members.

------------------------------------------------------------------------

# 27. P1 --- Strongly Recommended

## P1.1 Admin 2FA

Admin accounts should eventually support:

``` text
Password
+
2FA
```

At minimum for:

-   Super Admin
-   Finance Admin
-   Payment Admin
-   Security Admin

------------------------------------------------------------------------

## P1.2 Login Rate Limiting

Protect admin login from brute-force attempts.

Recommended:

-   failed-attempt counter
-   temporary lockout
-   IP throttling
-   audit log

------------------------------------------------------------------------

## P1.3 Admin Password Reset

Do not rely on manually changing passwords in the database.

Add:

``` text
Forgot Password
Email OTP / secure reset link
Password change
Session invalidation
```

------------------------------------------------------------------------

## P1.4 Session Hardening

Admin sessions should use:

``` text
Secure cookie
HttpOnly
SameSite
Session regeneration
Idle timeout
Absolute session timeout
Logout-all-sessions
```

------------------------------------------------------------------------

## P1.5 Remove Web-Based Seed Script From Production

Current project contains:

``` text
backend/scripts/seed-admin.php
```

It is documented as a one-time script and explicitly says it must be
deleted after use.

For production, the safer final design is:

``` text
CLI-only provisioning
```

or an authenticated setup process that is disabled permanently after
initialization.

Do not leave a password-creating endpoint publicly accessible.

------------------------------------------------------------------------

# 28. P1 --- Data Safety

Add soft-delete/archive behavior where appropriate.

Avoid:

``` text
DELETE restaurant
DELETE area
DELETE admin
```

for important entities.

Prefer:

``` text
is_active
status
deleted_at
archived_at
```

and preserve history.

------------------------------------------------------------------------

# 29. P1 --- Admin Action Reason

For sensitive actions, require:

``` text
Reason
```

Examples:

``` text
Restaurant rejected
Customer suspended
Order cancelled manually
Refund approved
Payout held
Commission changed
Area merged
```

This makes support and audit investigations much easier.

------------------------------------------------------------------------

# 30. P2 --- Better Admin UX

Current Admin Panel is a classic server-rendered PHP panel, which is
perfectly acceptable for launch.

Do not rewrite it into a React/SPA dashboard just for appearance.

Instead improve:

-   global search
-   filters
-   pagination
-   bulk actions
-   location breadcrumbs
-   confirmation dialogs
-   status badges
-   audit history
-   CSV exports
-   responsive tables
-   empty states
-   loading states
-   error messages

A SPA rewrite should only happen if operational scale proves it
necessary.

------------------------------------------------------------------------

# 31. Global Search

Add one Admin search:

``` text
Search AnyDrop...
```

Search:

``` text
Order ID
Customer
Restaurant
Rider
Admin
Coupon
Phone
Email
```

Results should be permission + scope filtered.

Example:

Jodhpur City Admin searches a customer from Jaipur:

``` text
No result
```

Super Admin:

``` text
Result available
```

------------------------------------------------------------------------

# 32. Bulk Operations

When AnyDrop becomes large, bulk actions become important.

Examples:

``` text
Select 50 restaurants
    ↓
Approve

Select 100 restaurants
    ↓
Suspend

Select cities
    ↓
Activate/Deactivate

Select customers
    ↓
Export
```

Bulk operations must:

-   validate permission
-   validate scope
-   show impact
-   require confirmation
-   audit the operation

------------------------------------------------------------------------

# 33. Location Expansion Workflow

When entering a new city:

``` text
1. Create/activate State
2. Create District
3. Create City/Village
4. Configure service coverage
5. Configure pricing
6. Configure COD rules
7. Configure payment restrictions
8. Configure commission
9. Add restaurants
10. Approve restaurants
11. Onboard riders
12. Activate customer availability
13. Launch banners/offers
```

The Admin Panel should eventually provide a:

> **Launch New City**

wizard that guides the operator through these steps.

------------------------------------------------------------------------

# 34. State Expansion Workflow

For a new state:

``` text
Super Admin
   ↓
Create State
   ↓
Assign State Admin
   ↓
Create/Import Cities
   ↓
Configure State Defaults
   ↓
City Launch
```

State defaults should be inherited by cities unless overridden.

Example:

``` text
Rajasthan
    Commission = 18%
       │
       ├── Jodhpur → inherits 18%
       ├── Jaipur  → inherits 18%
       └── Osian   → inherits 18%
```

If Jodhpur requires a special rule:

``` text
Jodhpur → override = 16%
```

This is the correct model for scalable configuration.

------------------------------------------------------------------------

# 35. Configuration Inheritance

For pricing, COD, commission, payment restrictions and similar settings:

``` text
GLOBAL
   ↓
STATE
   ↓
DISTRICT
   ↓
CITY
   ↓
AREA
   ↓
RESTAURANT
```

The most specific active rule wins.

Example:

``` text
Global delivery fee = ₹30

Rajasthan = ₹25

Jodhpur = ₹20

Osian = ₹15
```

Effective Osian fee:

``` text
₹15
```

This should be displayed clearly in Admin:

``` text
Effective value: ₹15
Source: Osian override
```

------------------------------------------------------------------------

# 36. Admin Dashboard by Scope

Super Admin:

``` text
India
```

State Admin:

``` text
Rajasthan
```

City Admin:

``` text
Jodhpur
```

Area Manager:

``` text
Osian
```

The same dashboard code should work everywhere.

Only the scope changes.

This prevents separate dashboard implementations.

------------------------------------------------------------------------

# 37. Recommended Technical Architecture

``` text
                    ADMIN USER
                        │
                        ▼
                Authentication
                        │
                        ▼
                    Role Check
                        │
                        ▼
                 Permission Check
                        │
                        ▼
                   Scope Check
                        │
                        ▼
               Entity Ownership/
                 Location Resolve
                        │
                        ▼
                  Database Query
                        │
                        ▼
                     Result
```

Every sensitive operation must follow this pipeline.

------------------------------------------------------------------------

# 38. Suggested Helper Functions

Backend should eventually provide centralized helpers such as:

``` php
admin_require_login();

admin_require_permission($admin, 'restaurants_view');

admin_require_scope($admin, $entityAreaId);

admin_get_effective_scope($admin);

admin_can_access_area($admin, $areaId);

admin_can_access_restaurant($admin, $restaurantId);

admin_can_access_order($admin, $orderId);

admin_get_scope_sql(...);
```

Do not duplicate location authorization logic inside 20+ PHP pages.

Centralize it.

------------------------------------------------------------------------

# 39. Recommended Admin Tables

Long-term:

``` text
admins

admin_roles

admin_permissions

admin_role_permissions

admin_scopes

admin_user_scopes

admin_sessions

admin_login_attempts

admin_audit_logs

admin_impersonation_sessions
```

Existing `service_areas` remains the geographic source of truth.

------------------------------------------------------------------------

# 40. Admin Security Model

Minimum production standard:

``` text
Password hash
+
Session regeneration
+
CSRF
+
RBAC
+
Scope authorization
+
Audit logs
+
Rate limiting
+
2FA for privileged roles
+
Secure cookies
+
Session timeout
+
Login monitoring
```

AnyDrop should treat Admin Panel security more seriously than the
customer-facing app because one compromised Super Admin account can
compromise the whole platform.

------------------------------------------------------------------------

# 41. Implementation Priority

## Phase A --- Foundation

``` text
[ ] Admin scope database tables
[ ] Admin scope assignment UI
[ ] Scope inheritance
[ ] Central scope authorization helper
[ ] Scope-aware restaurant queries
[ ] Scope-aware order queries
[ ] Scope-aware customer queries
[ ] Scope-aware rider queries
```

## Phase B --- Location-Aware Admin

``` text
[ ] Global location selector
[ ] State Admin
[ ] City Admin
[ ] Area Operations role
[ ] Scope-aware dashboard
[ ] Scope-aware analytics
[ ] Scope-aware exports
```

## Phase C --- Configuration Inheritance

``` text
[ ] Global settings
[ ] State overrides
[ ] City overrides
[ ] Area overrides
[ ] Effective-value display
```

## Phase D --- Security Hardening

``` text
[ ] Admin 2FA
[ ] Login rate limiting
[ ] Password reset
[ ] Session management
[ ] Logout all sessions
[ ] Remove public seed-admin mechanism
```

## Phase E --- Operations

``` text
[ ] Global search
[ ] Bulk operations
[ ] Better support tools
[ ] City launch wizard
[ ] Advanced audit viewer
[ ] Advanced reporting/export
```

------------------------------------------------------------------------

# 42. Rollout Strategy for AnyDrop

Do not create hundreds of admins now.

### Current stage

``` text
You
└── Super Admin
    └── Osian
```

### Jodhpur expansion

``` text
You
└── Super Admin
    └── Jodhpur City Admin
```

### Rajasthan expansion

``` text
You
└── Super Admin
    └── Rajasthan State Admin
        ├── Jodhpur City Admin
        ├── Jaipur City Admin
        └── Other City Admins
```

### Multi-state expansion

``` text
You
└── Super Admin
    ├── Rajasthan State Admin
    ├── Gujarat State Admin
    ├── Maharashtra State Admin
    ├── Madhya Pradesh State Admin
    └── Other State Admins
```

Only create operational roles when order volume and restaurant count
justify them.

------------------------------------------------------------------------

# 43. What You Should NOT Do

Do not:

-   Create a separate codebase for every city.
-   Create a separate database for every city at this stage.
-   Give every local operator Super Admin.
-   Put `city_id` as the only authorization mechanism.
-   Trust frontend dropdowns for security.
-   Give Finance staff full admin permissions.
-   Let City Admin modify global payment gateway settings.
-   Permanently delete operational history.
-   Build a React rewrite just because the current PHP panel is
    server-rendered.
-   Create 100+ admins before there is operational need.

------------------------------------------------------------------------

# 44. Final Target Architecture

``` text
                         ANYDROP
                            │
                      SUPER ADMIN
                            │
             ┌──────────────┼──────────────┐
             │              │              │
         Rajasthan       Gujarat       Maharashtra
             │              │              │
        State Admin     State Admin     State Admin
             │
      ┌──────┼─────────┐
      │      │         │
   Jodhpur Jaipur    Udaipur
      │
   City Admin
      │
 ┌────┼───────────┐
 │    │           │
Area  Area       Area
 │
Operations
 │
 ├── Restaurants
 ├── Riders
 ├── Customers
 ├── Orders
 └── Support
```

And technically:

``` text
ROLE
  ↓
Permissions
  ↓
SCOPE
  ↓
Location hierarchy
  ↓
Entity
  ↓
Action
  ↓
Audit Log
```

------------------------------------------------------------------------

# 45. Final Recommendation

The current Admin Panel is **not something that needs to be thrown
away**.

The existing project already has the important foundations:

-   RBAC
-   Service areas
-   Order Control
-   Analytics
-   Ledger
-   Settlements
-   Pricing
-   COD rules
-   Payment restrictions
-   Restaurant/customer management

The major missing architecture for national expansion is:

> **RBAC + Geographic Scope + Configuration Inheritance**

That should become the next major Admin Panel phase.

The single most important implementation is:

``` text
admin_roles
+
admin_permissions
+
admin_role_permissions
+
admin_scopes
+
admin_user_scopes
```

Then enforce:

``` text
WHAT → Role/Permission
WHERE → Scope
```

With this architecture, you can grow from:

``` text
Osian
   ↓
Jodhpur
   ↓
Rajasthan
   ↓
Multiple States
   ↓
Hundreds of Cities
```

without requiring the owner to personally operate every city.
