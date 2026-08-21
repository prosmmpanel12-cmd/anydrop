# Anydrop — REAL PENDING FEATURES / NEXT BUILD RECALL

> Purpose: This is the **current recall file for Claude Code**.
> It separates genuinely pending product/architecture work from old historical
> "test pending" notes in `docs/Status.md` and `docs/restorent/00_Status.md`.
>
> **Important:** Do NOT reopen already completed Customer/Restaurant core work
> merely because an old status entry says "pending". If a feature is already
> implemented and the owner has device-tested it, treat the old status as stale.

---

## 0. Current Reality

Anydrop's Customer App + Restaurant App + core backend are already substantially
built and tested by the owner.

The remaining work is primarily:

1. Admin Panel + platform control system
2. Area-wise service/availability rules
3. Restaurant Offers Engine
4. Financial / settlement / ledger completion
5. Customer support + refund system
6. Restaurant analytics / remaining restaurant operational features
7. Staff / RBAC
8. Rider App + live delivery tracking
9. Production security / payment hardening
10. Selected customer UX edge cases and future growth features

Do not confuse these with historical test notes.

---

# 1. ADMIN PANEL — MAJOR PENDING MODULE

**Status: 🔴 GENUINELY PENDING / PARTIALLY STARTED**

The Admin Panel must become the central control plane of Anydrop.

Reference:
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/21_Production_Feature_Gap_Plan.md`

Current admin implementation is much smaller than the documented full scope.
The next implementation phases should expand Admin into the following modules.

## 1.1 Admin Dashboard

Admin home should provide:

- Today's orders
- Revenue
- Active customers
- Active restaurants
- Online/active riders when Rider App exists
- Pending restaurant approvals
- Pending rider approvals
- Pending payouts
- Open support tickets
- Quick links to operational problems

Full analytics can remain a separate module.

**Reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Dashboard / Analytics sections.

---

# 2. AREA MANAGEMENT — CORE ADMIN CONTROL SYSTEM

**Status: 🟡 IMPLEMENTED 2026-08-21 — TEST PENDING (see done.md)**

`backend/sql/30_migration_service_areas.sql` + `backend/admin/areas.php`
now exist: the `service_areas` hierarchy table, additive `area_id` on
`restaurants`/`customer_addresses`, and a full admin CRUD screen
(add/edit/deactivate/delete/test-coordinates). Needs the migration run
on the live DB and a live click-through before this can move to ✅ DONE.

Still genuinely pending after that: everything in section 3-5 below
(area-wise restaurant visibility, COD rules, banner targeting) and
restaurant onboarding not yet writing `restaurants.area_id` — this
session only built the control table + admin CRUD, not the
consumption side.

This must be implemented early because multiple other features depend on area
resolution.

Reference:
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — `Area Management`
- `docs/21_Production_Feature_Gap_Plan.md` — area-wise analytics/operations

## 2.1 Hierarchy

Admin should be able to create/manage:

```text
State
  ↓
District
  ↓
City
  ↓
Area
```

Example:

```text
Rajasthan
  └── Jodhpur
       └── Osian
            ├── Main Osian
            ├── Teliya Mohalla
            └── Other local areas
```

The documented v1 architecture uses one `service_areas` adjacency-list table
with `parent_id`, `level`, `is_active`, center latitude/longitude and radius.

Restaurants should have an `area_id`.
Customer saved addresses should resolve to an `area_id` server-side.

---

# 3. AREA-WISE RESTAURANT VISIBILITY

**Status: 🔴 PENDING**

Customer restaurant discovery must become area-aware while retaining sensible
GPS/radius validation.

Example:

```text
Customer location
      ↓
Resolve service area
      ↓
Osian
      ↓
Fetch active restaurants in Osian
      ↓
Apply restaurant delivery-radius / operational checks
```

Recommended v1 rule from the existing Admin spec:

> Keep both area matching AND restaurant delivery-radius checks rather than
> replacing radius filtering with area-only filtering.

This prevents accidental visibility changes at area boundaries.

**Reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Area Management.

---

# 4. AREA-WISE COD / PAYMENT / ORDER RULES

**Status: 🔴 PENDING — ADMIN CONTROLLED**

This is an explicit product requirement and should NOT be hardcoded in the
Customer App.

Admin must be able to configure rules per service area.

### Example: Osian

Admin should be able to set:

```text
Area: Osian

COD: Enabled
Minimum prepaid orders before COD: 5
```

Meaning:

- A customer in Osian may have COD available only after the configured rule is satisfied.
- The exact business rule should be configurable from Admin.
- The Customer App only receives the server decision; it must not contain a
  hardcoded `if Osian => 5 orders` rule.

The system should support configurable conditions such as:

- COD enabled/disabled
- Minimum completed prepaid/online orders
- Minimum order amount
- Maximum COD order amount
- Maximum COD frequency / daily limit
- New-customer COD restriction
- Area-specific delivery fee
- Area-specific minimum order
- Area-specific service availability
- Area-specific payment restrictions

**Important:** exact final business rules should be stored in Admin-controlled
configuration, not Android constants.

---

# 5. AREA-WISE BANNERS / PROMOTIONS

**Status: 🔴 PENDING**

Admin must be able to target banners to specific service areas.

Example:

```text
Banner: Osian Independence Day Offer
Target Area: Osian
Active: Yes
```

Customer in Osian → sees it.
Customer outside Osian → does not receive it.

Platform-wide banners should use:

```text
area_id = NULL
```

Reference:
`docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Banner Manager.

---

# 6. NO RESTAURANT AVAILABLE STATE — IMPORTANT CUSTOMER UX

**Status: 🔴 PENDING**

This needs a dedicated, deliberate Customer Home state.

### Rule

If the resolved customer location has **no eligible restaurant within the
configured service/delivery radius** (example: no restaurant within 5 km), do
NOT show an empty/broken normal Home feed.

Instead show ONLY:

```text
[ No Restaurant Available illustration / screenshot ]

No restaurants available in your area

We currently don't have restaurants delivering to this location.

[ Change Location ]
```

### Must NOT show in this state

- Food categories
- Promotional banners
- Restaurant lists
- Popular dishes based on unavailable restaurants
- Fake/empty recommendation sections

The screen should clearly communicate the reason and give the user one primary
recovery action:

> **Change Location**

The backend should determine whether the feed is serviceable; the client should
render the correct state.

---

# 7. LOCATION OFF / LOCATION NOT AVAILABLE — ZOMATO-STYLE FALLBACK

**Status: 🔴 PENDING / NEEDS FINAL IMPLEMENTATION VERIFICATION**

Location behavior must be designed as a proper state machine.

## Case A — Location permission ON + GPS available

```text
GPS
 ↓
Resolve location
 ↓
Resolve service area
 ↓
Load restaurants/feed
```

## Case B — Location permission OFF

Do NOT keep asking for GPS indefinitely.

Check saved addresses first.

### If saved address exists

Use the user's selected/default saved address as the active delivery location.

```text
Location OFF
   ↓
Saved address exists?
   ↓ YES
Use saved address
   ↓
Resolve service area
   ↓
Load feed
```

### If NO saved address exists

Open the **Add Address / Location Picker flow**, using a Zomato-style UX.

```text
Location OFF
   ↓
No saved address
   ↓
Open Add Address / Location Picker
   ↓
User selects location / drops pin
   ↓
Address details
   ↓
Save address
   ↓
Use it as active location
```

The user should not be trapped on a blank Home screen merely because location
permission is disabled.

**Reference:** `docs/features.md` — Feature 7, Zomato-style location picker + map pin-drop address flow.

---

# 8. ACTIVE ADDRESS + LOCATION PICKER

**Status: 🟡 CORE FLOW EXISTS / EDGE-CASE HARDENING REQUIRED**

The final UX should support:

- Current location
- Saved addresses
- Default/selected address
- Change location
- Add new address
- Map pin-drop
- Reverse geocoded address
- House/flat/floor details
- Receiver name/phone
- Address type
- Distance from current location
- Nearby location suggestions where available

The Home location bar should open the same central location-selection flow.

Do not create multiple incompatible address/location flows.

**Reference:** `docs/features.md` — Feature 7; `docs/12_Handover_H6_Map_PinDrop_Photo.md`.

---

# 9. RESTAURANT OFFERS ENGINE

**Status: 🔴 GENUINELY PENDING**

Current coupons are NOT the same thing as the complete Restaurant Offers Engine.

The pending feature is a restaurant-created promotion system where restaurants
can create structured offers from the Restaurant App, while the backend pricing
engine validates and calculates the final price.

### Required offer types include:

- Quantity deal
- Buy X for ₹Y
- Buy X Get Y
- Percentage discount
- Flat discount
- Free delivery
- Combo/bundle offers
- Happy-hour/time-window offers
- Item-specific offers
- Category-specific offers
- Start/end validity
- Daily limits
- Total usage limits
- Minimum order
- Maximum discount
- Offer stacking/exclusivity rules

Example:

```text
3 Samosa @ ₹50
Buy 2 Burgers for ₹199
Buy 2 Get 1 Free
20% OFF up to ₹100
FREE DELIVERY above ₹299
4 PM – 6 PM Happy Hour
```

The pricing engine must remain server-authoritative.

Do not calculate different totals independently in Customer App, Restaurant App
and Admin Panel.

**Deep reference:** `docs/20_Offers_Pricing_UI_Polish_Notes.md` — Section 1 `Restaurant Offers System` and Section 2 `Free Delivery Offers`.

---

# 10. DELIVERY RESPONSIBILITY / SELF DELIVERY

**Status: 🔴 PENDING**

Restaurant delivery mode should support:

```text
Anydrop Delivery
Restaurant Self Delivery
```

Admin controls whether a restaurant is allowed to use self delivery.

This becomes important when Rider App is implemented.

**Deep reference:** `docs/20_Offers_Pricing_UI_Polish_Notes.md` — Section 3 `Delivery Responsibility`.

---

# 11. RESTAURANT REVIEW REPLY + REPORT

**Status: 🟡 PENDING**

Customer review submission already exists.

Remaining:

- Restaurant can view reviews
- Restaurant can reply
- Customer can see restaurant reply
- Restaurant/admin can report suspicious/fake review
- Admin gets reported-review queue

The schema already reserves `reviews.restaurant_reply` and `reviews.is_reported`.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 3 / Reviews.

---

# 12. RESTAURANT TEMPORARY CLOSURE / HOLIDAY

**Status: 🟡 PENDING**

Existing Open/Close is not enough for:

```text
Closed today
Closed until tomorrow 10 AM
Holiday: 15 Aug
Closed for 3 days
Recurring weekly closure
```

Need proper temporary closure / holiday scheduling.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 1 / Restaurant Management.

---

# 13. RESTAURANT FINANCE / SETTLEMENT

**Status: 🔴 MAJOR PENDING**

Need a proper financial module covering:

- Today's earnings
- Weekly earnings
- Monthly earnings
- Commission calculation
- Current due
- Settlement history
- Pending payout
- Bank details
- UTR/reference
- Settlement screenshot/receipt
- Admin payment verification
- Append-only restaurant due ledger
- Payment received ledger entries
- GST/commission calculation
- Financial audit trail

Do not implement this as a single mutable `due` number.

The existing architecture already points toward:

```text
restaurant_due_ledger
restaurant_payments
restaurant_bank_details
```

**Deep references:**
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 2 / Payments & Settlement
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Payout / Settlement System
- `docs/01_Database_Schema.md` — Financial / Ledger Tables

---

# 14. ADMIN ORDER CONTROL

**Status: 🟡 PENDING**

Admin should be able to:

- Search orders
- Filter by area
- Filter by restaurant
- Filter by customer
- Filter by payment method/status
- View complete order timeline
- View order status history
- Manually update order status where permitted
- Record admin actor in audit history

Reference:
`docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Order Control.

---

# 15. ADMIN RESTAURANT / CUSTOMER / RIDER MANAGEMENT

**Status: 🔴 PENDING / PARTIAL**

Admin must eventually support:

### Restaurants

- Search/filter
- Approve
- Reject
- Suspend
- Reactivate
- View profile
- View documents
- View orders
- View ratings
- View commission/due
- Set/override allowed operational options
- Area assignment

### Customers

- Search
- View profile
- View orders
- View addresses
- Suspend/ban
- Soft delete
- Wallet adjustment once wallet exists

### Riders

- Approve/reject
- Suspend
- View documents
- View orders
- View live status
- View earnings
- Assign/unassign when rider system exists

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Super Admin module sections.

---

# 16. ADMIN CATEGORY MANAGEMENT

**Status: 🔴 PENDING**

Important distinction:

### `restaurant_categories`
Business type:

```text
Cafe
Bakery
Sweet Shop
Pharmacy
Grocery
Restaurant
```

### `food_categories`
Customer Home / food type:

```text
Pizza
Burger
Biryani
Rolls
Samosa
```

`food_categories` are **admin-managed only**.
Restaurants may select existing tags for their menu items but must NOT create
new Home categories.

Admin needs:

- Add category
- Edit category
- Deactivate category
- Icon/image management
- Ordering/priority if needed

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Category Management.

---

# 17. ADMIN BANNER MANAGER

**Status: 🔴 PENDING**

Admin needs:

- Add banner
- Edit banner
- Delete/deactivate banner
- Banner type
- Image upload
- Deep link
- Priority
- Start/end date
- Area targeting
- Platform-wide banner

Area targeting is mandatory for the planned area-based platform.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Banner Manager.

---

# 18. CUSTOMER WALLET

**Status: 🔴 PENDING**

Need ledger-based wallet, not just a mutable balance.

Features:

- Wallet balance
- Refund to wallet
- Cashback
- Admin adjustment
- Wallet history
- Wallet payment
- Optional wallet + UPI
- Cashback expiry if required

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Wallet; `docs/21_Production_Feature_Gap_Plan.md` — Customer Wallet.

---

# 19. REFUND SYSTEM

**Status: 🔴 PENDING**

Required lifecycle:

```text
Refund Requested
      ↓
Under Review
      ↓
Approved
      ↓
Processing
      ↓
Refunded
```

Customer should see:

- Amount
- Reason
- Method
- Expected date
- Reference
- Timeline

Refunds must reconcile with payment transactions / platform ledger.

**Deep reference:** `docs/21_Production_Feature_Gap_Plan.md` — Customer Refund System and payment/reconciliation sections.

---

# 20. CUSTOMER SUPPORT / TICKETS

**Status: 🔴 PENDING**

Profile → Help & Support should support:

- Order issue
- Missing item
- Wrong item
- Food quality
- Delivery issue
- Payment issue
- Refund issue
- Account issue
- Coupon issue
- General issue

Ticket requirements:

- Ticket ID
- Order association
- Conversation/chat
- Attachment/photo
- Status
- Admin assignment
- Resolution/closure

**Deep reference:** `docs/21_Production_Feature_Gap_Plan.md` — Customer Support / Ticket System.

---

# 21. SUPPORT AI CHAT

**Status: 🔴 PENDING**

Future support layer:

```text
Customer
   ↓
AI Support Chat
   ↓
Anydrop backend proxy
   ↓
Gemini / Claude / GPT provider
```

AI must not directly receive unrestricted database credentials or private backend
secrets. The backend should expose only the data/tools required for support.

This should sit on top of the normal ticket/support architecture rather than
replacing it.

---

# 22. RESTAURANT ANALYTICS

**Status: 🔴 PENDING / INSIGHTS UI ≠ COMPLETE ANALYTICS**

Required reporting:

- Sales graph
- Peak hours
- Top-selling foods
- Repeat customers
- Order success/cancel rate
- Revenue report
- Export PDF/Excel
- Date filters
- Area filters where applicable

Raw order data already exists; this is a real reporting module, not just a
screen placeholder.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 2 / Analytics; `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Analytics & Reports Module.

---

# 23. RESTAURANT STAFF / RBAC

**Status: 🔴 PENDING — SEPARATE PHASE**

Support multiple users per restaurant:

```text
Owner
Manager
Kitchen
Cashier
```

with permissions.

This requires a proper `restaurant_staff` model and an audit of all restaurant
endpoints because the current architecture assumes one authenticated owner /
restaurant relationship.

**Deep reference:** `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Tier 4 / Staff Management.

---

# 24. RIDER APP

**Status: 🔴 NOT STARTED / DEFERRED TO LAST PHASE**

Rider system should eventually include:

- Rider signup/onboarding
- Admin approval
- Document verification
- Online/offline status
- Order assignment
- Accept/reject delivery
- Pickup navigation
- Customer navigation
- Live GPS updates
- Delivery OTP
- Pickup confirmation
- Delivery completion
- Rider earnings
- Rider history
- Rider support
- Restaurant/customer contact options

Do not build isolated restaurant-side rider features before the actual Rider App
architecture exists.

**Deep references:**
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` — Rider App deferred to Phase K
- `docs/03_Live_Tracking.md` — full live tracking architecture
- `docs/01_Database_Schema.md` — rider/order/location tables

---

# 25. LIVE RIDER TRACKING

**Status: 🔴 PENDING — PART OF RIDER PHASE**

Planned architecture:

```text
Rider App
   ↓ GPS ping every few seconds
Backend
   ↓
riders.last_lat / last_lng
   ↓
Customer tracking screen
```

Additional pieces:

- Smooth marker animation
- Route line
- ETA
- Battery-aware ping intervals
- Foreground location service
- Rider offline handling
- Location history/audit retention

**Deep reference:** `docs/03_Live_Tracking.md`.

---

# 26. GOOGLE LOGIN

**Status: 🔴 VERIFY / COMPLETE ONLY IF REAL BACKEND FLOW EXISTS**

Customer Google login must not be considered complete if the UI only shows a
button or Coming Soon state.

Required:

```text
Google Sign-In
   ↓
ID token / credential
   ↓
Backend verification
   ↓
Customer account create/login
   ↓
Session
```

If the current backend still contains the old "not implemented" path, this
feature remains pending.

---

# 27. PAYMENT PROVIDER ARCHITECTURE

**Status: 🟡 ARCHITECTURE / 🔴 REAL PROVIDER INTEGRATION PENDING**

The system should use a provider interface so UPI/payment provider changes do
not require rewriting order processing.

Required concepts:

- Payment provider registry
- Provider priority
- Enable/disable
- Initiate
- Verify
- Refund
- Transaction records
- Provider transaction ID
- Raw response storage
- Payment status
- Admin test mode

UPIPE can remain a stub until real SDK/API credentials/source are available,
but the order flow should depend on `PaymentService`, not directly on a
provider implementation.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Payment Provider Architecture.

---

# 28. PAYMENT / REFUND RECONCILIATION

**Status: 🔴 PENDING FOR PRODUCTION**

Need reconciliation between:

```text
Order
Payment Transaction
Platform Ledger
Restaurant Due Ledger
Settlement
Refund
```

Future provider webhooks should update the authoritative transaction state.

Do not mark a payment successful solely from a client-side success callback.

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` and `docs/21_Production_Feature_Gap_Plan.md`.

---

# 29. PRODUCTION SECURITY HARDENING

**Status: 🟡/🔴 PENDING**

Before real public scale, verify and harden:

- OTP rate limiting
- OTP abuse protection
- Order idempotency
- Coupon/discount race-condition protection
- Server-side price validation
- Restaurant operational-state validation
- Payment transaction safety
- Refund authorization
- Admin RBAC
- Audit logs
- Sensitive financial-data protection
- Backup strategy
- Restore testing
- Error logging
- Production secrets/configuration

Reference:
- `docs/security.md`
- `docs/21_Production_Feature_Gap_Plan.md`

---

# 30. EMAIL OTP PROVIDER SYSTEM

**Status: 🟡 ARCHITECTURE / IMPLEMENTATION VERIFY**

Planned provider registry:

```text
EmailOtpService
   ↓
Provider 1
Provider 2
Provider 3
...
```

Requirements:

- Provider priority
- Enable/disable
- Daily/monthly quota
- Automatic fallback
- Failure logging
- Test send button
- Usage dashboard

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Email OTP architecture.

---

# 31. ADMIN ANALYTICS / REPORTS

**Status: 🔴 PENDING**

Admin reports should support:

- Date range
- State
- District
- City
- Area
- Restaurant
- Restaurant category
- Order status
- Payment method

Reports:

- Order analytics
- Revenue
- Commission
- Area analytics
- Restaurant performance
- Top restaurants
- Top selling items
- Customer analytics
- Rider analytics
- Payment analytics
- Coupon analytics

**Deep reference:** `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` — Analytics & Reports Module.

---

# 32. FEATURES THAT SHOULD NOT BE REOPENED AS PENDING

If the owner has already built and device-tested the latest build, do NOT reopen
these merely because old status/history documents contain older TODO entries:

- Customer core login/OTP
- Home/feed core
- Restaurant listing/detail
- Menu browsing
- Item customization/add-ons
- Cart
- Checkout core
- Coupons core
- Scheduled orders
- Order history
- Reorder core
- Address core
- Restaurant menu CRUD
- Restaurant order management
- Restaurant Open/Close
- Preparation-time selection
- New-order alert sound
- Restaurant coupon management
- Banner/photo upload work already present
- Notification bell work already present
- Ratings/review submission

Always prefer the **latest source code + latest owner-confirmed test result** over
old historical status entries.

---

# 33. RECOMMENDED BUILD ORDER

Do NOT randomly pick features.

## Phase A — Admin Foundation

1. Admin authentication/session
2. RBAC/permissions
3. Dashboard
4. Service Area Management
5. Restaurant/Customer management
6. Food category management
7. Restaurant category management
8. Banner manager

## Phase B — Area-Based Platform Rules

9. Customer address → area resolution
10. Area-wise restaurant visibility
11. Area-wise delivery radius rules
12. Area-wise COD rules
13. Area-wise minimum order
14. Area-wise delivery fee
15. Area-wise payment restrictions
16. Area-wise banner targeting
17. No-restaurant state
18. Location-off + saved-address fallback
19. Add-address/location-picker fallback

## Phase C — Money

20. Platform ledger
21. Restaurant due ledger completion
22. Restaurant bank details
23. Settlement/payout UI
24. Payment transaction architecture
25. Refund system
26. Wallet
27. Reconciliation

## Phase D — Offers

28. Restaurant Offers Engine
29. Combo pricing
30. Free delivery offers
31. Happy hours
32. Offer eligibility/stacking rules
33. Central server-side pricing engine

## Phase E — Support / Trust

34. Support tickets
35. Order issue reporting
36. Review replies
37. Review reports
38. Optional AI support layer

## Phase F — Restaurant Operations

39. Temporary closure/holiday
40. Restaurant analytics
41. Staff/RBAC
42. GST/FSSAI/document management

## Phase G — Rider

43. Rider App
44. Admin rider approval
45. Assignment
46. Pickup/delivery workflow
47. Delivery OTP
48. Live tracking
49. Rider earnings
50. Rider analytics

## Phase H — Production Hardening

51. Security hardening
52. Payment webhook/reconciliation
53. Backup/restore
54. Load/performance checks
55. Full end-to-end production regression

---

# 34. RULE FOR CLAUDE CODE

Before starting any new task:

1. Read this `recall.md`.
2. Read the referenced deep `.md` document for that feature.
3. Inspect the current source code.
4. Determine whether the feature is actually missing, partially implemented,
   or already complete.
5. Do not trust an old `Status.md` TODO blindly.
6. Do not mark a feature "done" merely because a screen exists — verify the
   backend/data flow when the feature requires it.
7. Keep business rules in backend/Admin configuration wherever the requirement
   says "Admin controlled".
8. Never hardcode area-specific rules such as Osian/COD/minimum prepaid orders
   inside the Customer App.
9. For pricing, backend is the single source of truth.
10. After completing a feature, update the relevant deep reference document and
    this recall/status state so the same task does not return as "pending" in a
    future session.

---

# 35. DEEP REFERENCE INDEX

| Feature | Deep reference |
|---|---|
| Overall feature history | `docs/features.md` |
| Restaurant full scope | `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md` |
| Admin + Area + Banner + Payment architecture | `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md` |
| Restaurant Offers Engine | `docs/20_Offers_Pricing_UI_Polish_Notes.md` |
| Production gaps | `docs/21_Production_Feature_Gap_Plan.md` |
| Live Rider Tracking | `docs/03_Live_Tracking.md` |
| Database / ledger architecture | `docs/01_Database_Schema.md` |
| Security | `docs/security.md` |
| Location picker / map pin-drop | `docs/features.md` + `docs/12_Handover_H6_Map_PinDrop_Photo.md` |
| Restaurant known issues | `docs/restorent/20_Known_Issues_And_UX_Fixes.md` |
| Current restaurant status history | `docs/restorent/00_Status.md` |

---

## FINAL REMINDER

**"Test pending" is not automatically "feature pending".**

The owner has already tested the current core builds. The actual remaining
product work should now be tracked from this file and the deep reference docs,
with Admin + Area Control as the central next phase.
