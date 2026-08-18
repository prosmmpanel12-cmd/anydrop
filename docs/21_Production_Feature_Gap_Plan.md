# Anydrop — Production Feature & Gap Plan

## 1. Executive Summary

Anydrop already has a strong foundation across the Customer App, Restaurant App, Backend API, and Database architecture.

The main gap is **not a lack of features**. The biggest production gaps are:

1. Admin Panel implementation
2. Financial/ledger implementation
3. Payment and refund reconciliation
4. Production security hardening
5. Restaurant operational tools
6. Customer support system
7. Rider system

The priority should be to make Anydrop operationally and financially safe before adding large numbers of consumer-facing features.

---

# 2. Customer App

## Existing / Planned Strengths

The Customer side already covers:

- Login / OTP / Google
- Home
- Restaurant listing
- Location/address management
- Delivery-radius filtering
- Search
- Categories
- Restaurant detail
- Menu
- Veg/non-veg
- Item customization
- Add-ons
- Cart
- Cart persistence/sync
- Coupons
- Offers
- Checkout
- COD / UPI architecture
- Scheduled orders
- Order history
- Order tracking
- Delivery OTP architecture
- Reviews/rating
- Saved restaurants
- Saved dishes
- Favorites
- FAQ
- Feedback
- Notifications framework
- Cart abandonment framework
- Meal reminders
- Order status stepper
- App update checker

The Customer App is therefore already beyond a basic MVP.

---

## Customer — High Priority Additions

### 2.1 Customer Wallet

Add:

- Wallet balance
- Refund to wallet
- Cashback
- Admin adjustment
- Wallet transaction history
- Wallet payment option
- Wallet + UPI combination
- Optional cashback expiry

Use a **ledger-based wallet** rather than simply modifying a balance value.

---

### 2.2 Refund System

Implement a complete refund lifecycle:

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

- Refund amount
- Reason
- Refund method
- Expected date
- Refund reference
- Refund timeline

---

### 2.3 Customer Support / Ticket System

Profile → Help & Support:

```text
My Orders
 ├── Order issue
 ├── Missing item
 ├── Wrong item
 ├── Food quality
 ├── Delivery issue
 ├── Payment issue
 └── Refund issue

Other
 ├── Account
 ├── Coupon
 └── General
```

Requirements:

- Ticket ID
- Conversation/chat
- Attachments/photos
- Ticket status
- Admin assignment
- Order association

---

### 2.4 Restaurant Review Replies

Customer should be able to see restaurant responses below reviews.

Example:

```text
Customer:
⭐⭐⭐⭐⭐
"Good food."

Restaurant Response:
"Thank you ❤️"
```

Also provide a review-report mechanism.

---

### 2.5 Restaurant Information Transparency

Restaurant detail should expose relevant information such as:

- FSSAI
- GST/business information where appropriate
- Business type
- Opening hours
- Delivery radius
- Minimum order
- Average preparation time
- Cancellation policy
- Packaging charge
- Delivery charge
- Restaurant description

---

### 2.6 Proper Offer Discovery

Do not rely only on checkout coupons.

Support:

#### Normal Coupon
`SAVE50`

#### Free Delivery
`FREE DELIVERY`

#### Combo

```text
Burger + Fries + Coke
₹299 → ₹199
```

#### Happy Hour

```text
4 PM – 6 PM
20% OFF
```

Customers should be able to discover offers before checkout.

---

### 2.7 Reorder

Order History should provide:

> Reorder

The backend must revalidate:

- Current price
- Item availability
- Customizations
- Restaurant status
- Current offers

Do not blindly recreate the old order.

---

### 2.8 Order Issue Reporting

Order screen:

```text
Having an issue?

- Missing item
- Wrong item
- Food spilled
- Food quality
- Late delivery
- Payment problem
- Other
```

This should directly create a support ticket.

---

### 2.9 Referral System

Future growth feature:

```text
Invite Friend
      ↓
Friend signs up
      ↓
Friend completes first order
      ↓
Reward both users
```

Implement fraud protection before launching monetary referral rewards.

---

### 2.10 Loyalty / Rewards

Possible model:

```text
₹100 spent = 1 point
100 points = ₹10
```

Or cashback.

Do not launch wallet, loyalty, cashback, and referrals all at once. Validate the core ordering business first.

---

## Customer — Nice-to-Have / Later

- Dark mode
- Language selection
- Voice search
- Recently viewed restaurants
- Recently ordered
- Personalized recommendations
- Dietary preferences
- Allergens
- Calorie information
- Order note templates
- Share restaurant
- Share food item
- Gift orders
- Multiple delivery addresses
- Family accounts

---

# 3. Restaurant App

## Existing Strengths

The Restaurant side already has architecture/features around:

- Signup
- OTP
- Login
- Dashboard
- Orders
- Accept/reject
- Preparation time
- Order details
- Menu
- Categories
- Profile
- Location
- Banner manager
- Insights

The next priority is turning this into a complete restaurant operating system.

---

## Restaurant — High Priority

### 3.1 Complete Menu Management

#### Categories

- Add
- Edit
- Delete
- Reorder
- Hide/show
- Photo

#### Items

- Add
- Edit
- Delete
- Price
- Discount
- Veg/non-veg
- Bestseller
- Recommended
- Spicy
- Kids choice
- Availability
- Preparation time
- Photo

#### Customization

Example:

```text
Pizza
 ├── Size
 │    ├── Small +0
 │    ├── Medium +50
 │    └── Large +100
 │
 └── Add-ons
      ├── Cheese +30
      ├── Jalapeno +20
      └── Olives +20
```

Restaurant must be able to create and manage these customizations.

---

### 3.2 Out-of-Stock Management

Every item should support:

```text
Available
Out of Stock
```

Also support temporary unavailability:

```text
Unavailable until:
12:30 PM
```

---

### 3.3 Temporary Closure / Holiday

Support:

- Pause orders
- Reason
- Resume time
- Holiday schedule
- Scheduled closure

Example:

```text
Accepting Orders: OFF

Reason:
Kitchen maintenance

Resume:
Today 7:30 PM
```

---

### 3.4 Restaurant Offers Manager

Support:

- Percentage discount
- Flat discount
- Free delivery
- Combo
- Happy hour

Fields:

- Offer name
- Discount
- Minimum order
- Maximum discount
- Valid from
- Valid until
- Days
- Time
- Item/category restrictions
- Usage limit
- Customer limit

---

### 3.5 Earnings / Settlement

Restaurant dashboard should show:

```text
Today

Orders              32
Sales            ₹8,450
Commission         ₹845
Platform Fee       ₹160
Net              ₹7,445
```

Settlement section:

```text
Pending        ₹12,450
Paid           ₹35,600

Next Settlement
₹12,450
```

Ledger:

```text
Date       Order       Type             Amount
18 Aug     #AD1023     Commission       -₹40
18 Aug     #AD1024     Online sale      +₹280
17 Aug     SET001      Settlement       +₹5,000
```

---

### 3.6 Restaurant Analytics

Support:

- Today
- 7 days
- 30 days
- Custom date range
- Sales graph
- Top-selling food
- Least-selling food
- Peak hour
- Average order value
- Cancellation rate
- Acceptance rate
- Repeat customers
- Average preparation time

---

### 3.7 Reviews

Restaurant should see:

```text
⭐⭐⭐⭐⭐
4.6

Food       4.7
Delivery   4.4
```

Actions:

- Reply
- Report review

---

### 3.8 Notification Center

Notifications:

- New order
- Payment
- Settlement
- Restaurant approval
- Offer expiry
- App update
- Important announcement

Notification settings:

```text
New orders
Payments
Settlement
Marketing
System
```

---

### 3.9 Staff Management

Future feature:

```text
Owner
 ├── Manager
 ├── Cashier
 └── Kitchen
```

Suggested permissions:

#### Kitchen
- View orders
- Update preparation status

#### Cashier
- View orders
- Payment-related operations

#### Manager
- Most operational controls

Use proper RBAC rather than sharing owner credentials.

---

### 3.10 Compliance

Restaurant onboarding should support:

- FSSAI number
- FSSAI certificate
- GST
- PAN
- Bank account
- IFSC
- Business documents
- Verification status

Only appropriate verified information should be exposed to customers.

---

# 4. Admin Panel

## Critical Finding

The current project architecture contains a much larger Admin specification, but the actual Admin implementation is currently much smaller.

The current admin implementation is primarily:

```text
backend/admin/
 ├── login.php
 ├── index.php
 ├── logout.php
 └── _bootstrap.php
```

The existing implementation mainly covers login/session and restaurant approval/rejection.

Therefore, **Admin Panel should be the #1 development priority.**

---

## 4.1 Admin Dashboard

Show:

```text
Today's Orders
Revenue
Platform Revenue
Pending Restaurants
Active Restaurants
Online Riders
Active Customers
Pending Payouts
Open Support Tickets
```

Add live/near-real-time order counts where useful.

---

## 4.2 Restaurant Management

Admin table:

```text
Search
Filter
Status
Area
Category
Rating
Orders
Revenue
Due
```

Actions:

- View
- Approve
- Reject
- Suspend
- Activate
- Edit
- Disable
- View orders
- View ledger
- View documents

---

## 4.3 Restaurant Detail

### Business

- Name
- Owner
- Mobile
- Email
- Address
- GPS
- Category
- Cuisine

### Compliance

- GST
- PAN
- FSSAI
- Documents

### Financial

- Commission
- Current balance
- Total sales
- COD
- Online payments
- Payouts

### Performance

- Rating
- Orders
- Cancellation rate
- Acceptance rate
- Preparation time

---

## 4.4 Customer Management

Admin should support:

```text
Customers
 ├── Search
 ├── Filter
 ├── View profile
 ├── Orders
 ├── Wallet
 ├── Refunds
 ├── Tickets
 ├── Suspend
 └── Delete
```

---

## 4.5 Rider Management

The project does not currently contain a complete Rider App implementation.

Eventually Admin should support:

- Rider approval
- Rider list
- Online/offline
- Assigned orders
- Earnings
- Location
- Performance
- Suspend
- Documents
- Vehicle details

---

## 4.6 Order Control

Admin should see every order.

Filters:

```text
Order ID
Customer
Restaurant
Rider
Status
Payment
Date
Area
```

Order details:

- Customer
- Restaurant
- Items
- Pricing
- Payment
- Timeline
- Rider
- Location
- OTP
- Cancellation
- Refund

Admin override actions should be heavily permission-controlled and always logged.

---

## 4.7 Financial Command Center

This is one of the most important modules.

### Restaurant Ledger

Track:

```text
Restaurant owes Admin
Admin owes Restaurant
```

### Platform Ledger

Track:

```text
Customer payments IN
Restaurant settlements IN/OUT
Restaurant payouts OUT
Refunds OUT
Platform revenue
```

Example:

```text
TOTAL MONEY IN       ₹1,25,000
TOTAL MONEY OUT        ₹82,000
BALANCE HELD           ₹43,000
PLATFORM REVENUE       ₹18,500
```

Financial records should be immutable ledger entries rather than simply editable balances.

---

## 4.8 Payout / Settlement

Admin should be able to:

- Select restaurant
- View payable amount
- Initiate payout
- Record UTR
- Record payment method
- Add remarks
- Upload proof where appropriate
- Mark settlement status

Every payout must create an audit trail and ledger entry.

---

## 4.9 Coupon Management

Admin:

- Create
- Edit
- Disable
- Delete
- Usage statistics
- Expiry
- Restaurant-specific coupons
- Platform-wide coupons
- Minimum order
- Maximum discount

Analytics:

```text
Coupon
Uses
Discount Given
Orders Generated
```

---

## 4.10 Banner / CMS Manager

Admin should be able to create:

- Image
- Title
- Type
- Area
- Start date
- End date
- Priority
- Deep link

Example:

```text
Osian mein Anydrop aa gaya!
```

Area targeting is especially useful for local launch campaigns.

---

## 4.11 Service Area Management

Architecture:

```text
Rajasthan
 └── Jodhpur
      └── Osian
           ├── Area A
           ├── Area B
           └── Area C
```

Admin controls:

- Add area
- Disable area
- Center coordinates
- Radius
- Restaurant assignment
- Customer coverage

Area-level:

- Restaurants
- Banners
- Notifications
- Analytics

---

## 4.12 Global App Settings

Admin-configurable:

```text
Delivery charge
Platform fee
Commission
Tax
Minimum order
OTP expiry
OTP attempts
Maintenance mode
Minimum app version
Latest app version
Default delivery radius
```

These should not require code changes.

---

## 4.13 App Version Management

Support separate versions for:

```text
Customer
Restaurant
Rider
```

Example:

```text
Customer
Current: 1.0.4
Latest: 1.0.5

Restaurant
Current: 1.0.2
Latest: 1.0.3

Force Update: ON/OFF
```

---

## 4.14 Notification Campaign Manager

Target:

```text
All customers
Osian customers
Specific restaurant customers
New users
Inactive users
```

Support:

- Immediate send
- Scheduled send
- Title
- Body
- Deep link
- Target audience
- Delivery/open analytics

---

## 4.15 Support Center

Admin:

```text
Open
In Progress
Urgent
Resolved
```

Ticket:

```text
Customer
Order
Issue
Messages
Attachments
Admin
Status
```

Allow assignment:

```text
Assigned to: Support Staff
```

---

## 4.16 Fraud Detection

Flag:

- Multiple accounts
- Excessive cancellations
- Coupon abuse
- Fake orders
- Suspicious activity
- Device/account patterns

Do not auto-ban everything initially.

Better:

```text
⚠ Suspicious
      ↓
Admin Review
      ↓
Action
```

---

## 4.17 Audit Logs

Every important admin action should be logged.

Example:

```text
18 Aug 09:42
Admin: staff01
Action: Restaurant Approved
Restaurant: XYZ

18 Aug 10:15
Admin: finance01
Action: Payout ₹5,000
Restaurant: ABC
```

Filters:

- Admin
- Action
- Restaurant
- Customer
- Date

---

## 4.18 Admin RBAC

Recommended roles:

```text
Super Admin
Finance Admin
Support Admin
Marketing Admin
Restaurant Manager
Read Only
```

Example:

Finance Admin:

```text
Can:
✓ Payout
✓ Ledger
✓ Financial reports

Cannot:
✗ Delete restaurant
✗ Ban customer
✗ Change system security
```

---

## 4.19 Admin Analytics

### Orders

- Total
- Completed
- Cancelled
- Rejected
- Failed

### Revenue

- GMV
- Platform revenue
- Commission
- Discounts
- Refunds

### Restaurants

- Top restaurant
- Low-performing restaurant
- Acceptance rate

### Items

- Top-selling
- Most profitable
- Most cancelled

### Customers

- New
- Returning
- Average order value
- Customer LTV

### Areas

```text
Osian
Orders: 1,240
Revenue: ₹3.2L
Customers: 860
Restaurants: 32
```

---

# 5. Critical Backend / Security Gaps

These are more important than adding cosmetic features.

## 5.1 Order Idempotency — MUST FIX

Problem:

```text
User taps Place Order
       ↓
Network slow
       ↓
User taps again
       ↓
Two orders
```

Implement:

```text
Idempotency-Key: UUID
```

with a unique server-side constraint.

This should be completed before real-money production launch.

---

## 5.2 OTP Security — MUST FIX

Never expose OTP in production API responses.

Production response should be something like:

```json
{
  "message": "OTP sent"
}
```

Add:

```text
1 request / 60 seconds
5 requests / hour
IP throttling
Email/phone throttling
Attempt limits
```

Use proper server-side OTP storage/expiry.

---

## 5.3 Discount Validation

Clamp discount values:

```text
0–100%
```

Reject invalid values.

Never allow:

```text
150%
-20%
```

to reach pricing logic.

---

## 5.4 Coupon Race Conditions

Concurrent requests must not bypass:

```text
usage_limit
usage_limit_per_user
```

Use database transactions/locking/unique constraints where appropriate.

---

## 5.5 Restaurant Operational Guard

Order creation must revalidate restaurant state on the server.

Example:

```text
restaurant.status == approved
AND
operational_status == open
AND
within delivery radius
AND
not suspended
```

Do not rely only on what the Customer App displays.

---

## 5.6 Payment Webhook / Reconciliation

Correct architecture:

```text
Payment Initiated
       ↓
Payment Gateway
       ↓
Webhook
       ↓
Verify Signature
       ↓
payment_transactions
       ↓
orders.payment_status
       ↓
Platform Ledger
```

Never trust only the client-side "payment successful" response.

---

## 5.7 Refund Reconciliation

Use:

```text
Refund Requested
       ↓
Approved
       ↓
Gateway Refund
       ↓
Webhook / Verification
       ↓
Payment Transaction
       ↓
Wallet / Bank
       ↓
Platform Ledger
       ↓
Order Status
```

---

## 5.8 Database Backup

Production should have:

- Daily DB backup
- Weekly full backup
- Backup retention
- Off-server backup
- Restore testing

A backup that has never been restored/tested should not be considered reliable.

---

# 6. Recommended Development Roadmap

## Phase 1 — Production Safety 🔴

- [ ] Remove debug OTP
- [ ] OTP rate limiting
- [ ] Order idempotency
- [ ] Discount validation
- [ ] Coupon race-condition protection
- [ ] Restaurant operational-state validation
- [ ] Payment transaction safety
- [ ] Audit all financial actions

---

## Phase 2 — Admin Core 🔥

- [ ] Admin dashboard
- [ ] Restaurant management
- [ ] Customer management
- [ ] Order management
- [ ] Restaurant approval
- [ ] Restaurant suspension/activation
- [ ] Area management
- [ ] App settings
- [ ] Banner manager
- [ ] Admin RBAC

---

## Phase 3 — Money / Finance 💰

- [ ] Restaurant ledger
- [ ] Platform ledger
- [ ] Payouts
- [ ] Settlements
- [ ] Payment transactions
- [ ] Refund system
- [ ] Financial reports
- [ ] Reconciliation

---

## Phase 4 — Restaurant Operations 🍔

- [ ] Complete menu CRUD
- [ ] Add-ons/customizations
- [ ] Out-of-stock
- [ ] Offers
- [ ] Happy hours
- [ ] Combo
- [ ] Temporary closure
- [ ] Reviews/replies
- [ ] Earnings
- [ ] Analytics

---

## Phase 5 — Customer Growth 🚀

- [ ] Reorder
- [ ] Wallet
- [ ] Referral
- [ ] Cashback
- [ ] Loyalty
- [ ] Personalized offers
- [ ] Support tickets
- [ ] Better offer discovery

---

## Phase 6 — Rider 🚴

- [ ] Rider App
- [ ] Rider onboarding
- [ ] Rider verification
- [ ] Order assignment
- [ ] Accept order
- [ ] Pickup
- [ ] Live GPS
- [ ] Delivery OTP
- [ ] Delivery completion
- [ ] Rider earnings
- [ ] Rider analytics

---

# 7. Final Priority Matrix

| Module | Current State | Priority |
|---|---|---|
| Customer App | 🟢 Strong | Polish |
| Restaurant App | 🟡 Good foundation | Major operations |
| Backend API | 🟢 Good architecture | Security fixes |
| Database | 🟢 Strong foundation | Add business tables |
| Admin Panel | 🔴 Very incomplete | #1 Priority |
| Payment Architecture | 🟡 Designed | Implement/reconcile |
| Financial Ledger | 🟡 Designed | Implement before real money |
| Notifications | 🟡 Foundation | FCM + campaigns |
| Support | 🔴 Missing | High |
| Fraud | 🔴 Missing | Early/post-launch |
| Rider | 🔴 Not implemented | Later |

---

# 8. Final Recommendation

Do not spend the next development cycle adding 20 more Customer App features.

The correct order is:

```text
Security
   ↓
Admin Control
   ↓
Financial Control
   ↓
Restaurant Operations
   ↓
Payment/Reconciliation
   ↓
Customer Growth
   ↓
Rider System
```

The Admin Panel should become the **operating system of Anydrop**.

The financial ledger should become the **source of truth for money**.

The backend should become the **source of truth for order/payment state**.

Once these three foundations are solid, adding Customer, Restaurant, Rider, marketing, loyalty, and growth features becomes much safer and easier.
