# Anydrop — Offers, Pricing, Units, UX/UI Polish & Final Product Notes

## Purpose

This document is an additional product-design note for Anydrop.

It covers:

- Restaurant-created offers
- Bundle/combo pricing
- Free-delivery offers
- Delivery control
- Unit price and GM/weight-based pricing
- Sweet/shop-specific pricing
- Pending product features
- Restaurant App UI polish
- Admin Panel UI polish
- Icons
- Notes/comments
- Operational edge cases
- Final pre-launch checklist

---

# 1. Restaurant Offers System

Anydrop should support **restaurant-created offers**, not only admin-created coupons.

Restaurant owners should be able to create offers from the Restaurant App.

## 1.1 Quantity / Bundle Offer

Example:

> 3 Samosa @ ₹50

Restaurant creates:

```text
Offer Type:
Quantity Deal

Item:
Samosa

Quantity:
3

Offer Price:
₹50
```

Customer sees:

```text
🔥 3 Samosa
₹60 → ₹50
```

The backend should validate that the customer receives exactly the configured quantity.

---

## 1.2 Buy X for ₹Y

Examples:

```text
Buy 2 Burgers for ₹199
Buy 3 Samosa for ₹50
Buy 4 Gulab Jamun for ₹100
```

Fields:

```text
Item
Required Quantity
Offer Price
Start Date
End Date
Daily Limit
Total Limit
```

---

## 1.3 Buy X Get Y

Examples:

```text
Buy 2 Get 1 Free
Buy 3 Get 1 Free
```

This should be a separate promotion type because its pricing logic differs from a simple percentage discount.

---

## 1.4 Percentage Discount

Example:

```text
20% OFF
Minimum Order: ₹299
Maximum Discount: ₹100
```

Restaurant controls:

- Percentage
- Minimum order
- Maximum discount
- Validity
- Applicable items/categories
- Usage limits

---

## 1.5 Flat Discount

Example:

```text
₹50 OFF
Minimum Order: ₹299
```

---

# 2. Free Delivery Offers

Restaurant should be able to create:

```text
FREE DELIVERY
```

with conditions.

Example:

```text
Free Delivery on orders
₹299 and above
```

Customer sees:

```text
🚚 FREE DELIVERY
On orders above ₹299
```

## Important Pricing Rule

The backend must calculate:

```text
Subtotal
+ Packaging
+ Taxes
+ Delivery Fee
- Item Discount
- Coupon Discount
- Free Delivery Discount
= Final Payable
```

The exact order of operations must be defined centrally in the pricing engine.

Never calculate final totals separately in Customer App, Restaurant App, and Admin Panel.

The server should be the source of truth.

---

# 3. Delivery Responsibility

Anydrop should clearly distinguish between:

## Anydrop Delivery

```text
Restaurant
   ↓
Anydrop Rider
   ↓
Customer
```

## Restaurant Self Delivery

```text
Restaurant
   ↓
Restaurant's Delivery Person
   ↓
Customer
```

Restaurant profile should contain:

```text
Delivery Mode

○ Anydrop Delivery
○ Self Delivery
```

Admin should be able to control whether a restaurant is allowed to use self delivery.

---

# 4. Free Delivery Responsibility

A free-delivery offer should not create ambiguity about who pays the delivery cost.

Example:

```text
Order value: ₹399
Delivery fee: ₹30
Customer pays delivery: ₹0
```

The system must record:

```text
Original Delivery Fee: ₹30
Customer Delivery Fee: ₹0
Delivery Discount: ₹30
Discount Sponsor:
    Restaurant / Anydrop / Campaign
```

This is important for financial reconciliation.

---

# 5. Unit-Based Pricing

This is a major requirement for Anydrop.

Restaurants should not be forced to use only:

```text
₹100 per item
```

Different businesses require different units.

Support:

- Piece
- Kg
- Gram
- 100 Gram
- 500 Gram
- Litre
- 100 ml
- 250 ml
- Box
- Packet
- Plate
- Bowl
- Glass
- Dozen
- Custom unit

---

# 6. Unit Price + Selling Price

Every item should support a clear pricing model.

Example:

```text
Product:
Gulab Jamun

Unit:
Piece

Unit Price:
₹20

Selling Price:
₹15
```

Or:

```text
Product:
Kaju Katli

Unit:
Kg

Unit Price:
₹900/kg

Selling Price:
₹850/kg
```

Customer UI:

```text
Kaju Katli
₹850 / kg
```

---

# 7. GM / Weight-Based Pricing

For products sold by weight, support:

```text
Base Unit:
Gram

Base Price:
₹90 / 100g
```

Customer can choose:

```text
100g  ₹90
250g  ₹225
500g  ₹450
1kg   ₹900
```

Do not hardcode only kg pricing.

The pricing engine should support a base unit and calculate the final amount based on quantity.

---

# 8. Sweet Shop Example

For sweets:

```text
Kaju Katli
₹90 / 100g
```

Customer selects:

```text
100g
250g
500g
1kg
```

Backend stores quantity in a normalized unit, preferably grams.

Example:

```text
quantity_grams = 250
unit_price_per_100g = 90
```

Then:

```text
250g = ₹225
```

This avoids inconsistent calculations.

---

# 9. Variable Weight Orders

Some restaurants/sweet shops may not be able to provide exactly the requested weight.

Example:

Customer orders:

```text
500g Kaju Katli
```

Actual packed weight:

```text
530g
```

Anydrop should eventually support:

```text
Requested Weight: 500g
Actual Weight: 530g
Rate: ₹900/kg
Final Price: ₹477
```

This is an advanced feature and can be added later, but the database should not make it impossible.

---

# 10. Unit Display

Customer UI should never show confusing pricing.

Bad:

```text
Kaju Katli
₹900
```

Better:

```text
Kaju Katli
₹90 / 100g
```

or:

```text
Kaju Katli
₹900 / kg
```

For pieces:

```text
Samosa
₹15 / piece
```

For plates:

```text
Chole Bhature
₹120 / plate
```

---

# 11. Minimum / Maximum Quantity

Every item should optionally support:

```text
Minimum Quantity: 1
Maximum Quantity: 20
```

For weight:

```text
Minimum: 100g
Maximum: 5kg
Step: 100g
```

This is especially useful for sweets, bakery products, groceries, and custom-weight food.

---

# 12. Restaurant Offer Restrictions

Restaurant-created offers should support:

- Specific item
- Category
- Entire restaurant
- Minimum order
- Maximum discount
- Customer usage limit
- Total usage limit
- Daily usage limit
- Start date
- End date
- Specific weekdays
- Specific time range
- First-order-only
- Existing-customer-only
- New-customer-only

---

# 13. Offer Stacking Rules

This must be explicitly defined.

Example:

Customer has:

```text
20% Restaurant Offer
+
₹50 Coupon
+
Free Delivery
```

The system should decide whether all three can apply.

Recommended initial rule:

```text
1 Item/Restaurant Offer
+
1 Coupon
+
1 Delivery Offer
```

Do not allow unlimited stacking.

Admin should be able to configure stacking rules later.

---

# 14. Restaurant Offer UI

Restaurant App should have:

```text
Offers

+ Create Offer

Active
Scheduled
Expired
Paused
```

Each offer card:

```text
🔥 3 Samosa @ ₹50

Used:
42 / 100

Valid:
18 Aug – 25 Aug

Status:
ACTIVE

[Edit] [Pause] [View]
```

---

# 15. Admin Offer Control

Admin should see every restaurant offer.

Filters:

- Restaurant
- Offer type
- Active/paused/expired
- Date
- Area
- Usage

Admin actions:

- Approve
- Reject
- Pause
- Disable
- Edit if permission allows
- View usage
- View revenue impact

---

# 16. Offer Analytics

Restaurant:

```text
Offer:
3 Samosa @ ₹50

Views: 1,240
Orders: 180
Items Sold: 540
Revenue: ₹9,000
Discount Given: ₹1,800
```

Admin:

```text
Offer Cost
GMV Generated
Orders Generated
Average Order Value
Customer Acquisition
```

---

# 17. Restaurant App UI Polish

The Restaurant App should feel like a professional business dashboard, not a basic CRUD application.

## 17.1 Dashboard

Recommended structure:

```text
Good Morning 👋

Today's Sales
₹8,450

Orders
32

Pending
5

Completed
27

Quick Actions
[Orders]
[Add Item]
[Offers]
[Menu]
```

Keep the most important information above the fold.

---

# 18. Restaurant Bottom Navigation

Recommended:

```text
Home
Orders
Menu
Offers
Profile
```

Do not put 8–10 primary tabs in the bottom navigation.

Secondary tools should live inside relevant screens.

---

# 19. Restaurant Icons

Use one consistent icon family.

Recommended style:

- Rounded
- Simple
- Filled/outlined consistently
- 24dp primary size
- 20dp secondary size
- Clear meaning
- No random emoji as functional icons

Suggested icon mapping:

```text
Home       → home
Orders     → receipt/order
Menu       → restaurant/menu
Offers     → local_offer
Analytics  → bar_chart
Wallet     → account_balance_wallet
Settlement → payments
Profile    → person
Settings   → settings
Support    → support_agent
Notifications → notifications
```

Avoid mixing:

```text
Material icon
FontAwesome
Random SVG
Emoji
```

unless there is a strong reason.

---

# 20. Status Colors

Use a consistent semantic system.

```text
Success   → Green
Warning   → Amber
Error     → Red
Info      → Blue
Neutral   → Gray
Brand     → Anydrop primary
```

Do not use random colors for every card.

---

# 21. Restaurant Order Status UI

Recommended:

```text
NEW
↓
ACCEPTED
↓
PREPARING
↓
READY
↓
PICKED UP
↓
DELIVERED
```

Cancelled/rejected should be separate states.

Use clear status chips.

Example:

```text
● NEW
● PREPARING
● READY
✓ DELIVERED
✕ CANCELLED
```

---

# 22. Restaurant Order Card

Recommended information hierarchy:

```text
#AD1024
2 items · ₹320

Customer Name
Pickup/Delivery

Chicken Biryani × 1
Cold Drink × 1

Payment: PAID

[Accept]
```

Avoid filling the card with unnecessary information.

---

# 23. Restaurant Menu UI

Each item card:

```text
[IMAGE]

Chicken Biryani
₹180 / plate

⭐ Bestseller

Available ●

[Edit]
```

Use a clear availability switch.

---

# 24. Admin Panel UI Polish

Admin Panel should be desktop-first but responsive.

Recommended layout:

```text
┌────────────────────────────────────┐
│ Anydrop Admin       🔔   Admin     │
├──────────────┬─────────────────────┤
│ Dashboard    │                     │
│ Orders       │      Content        │
│ Restaurants  │                     │
│ Customers    │                     │
│ Riders       │                     │
│ Finance      │                     │
│ Offers       │                     │
│ Support      │                     │
│ Analytics    │                     │
│ Notifications│                     │
│ Settings     │                     │
└──────────────┴─────────────────────┘
```

---

# 25. Admin Sidebar

Recommended order:

```text
Dashboard

Operations
 ├── Orders
 ├── Restaurants
 ├── Customers
 └── Riders

Finance
 ├── Transactions
 ├── Restaurant Ledger
 ├── Payouts
 ├── Refunds
 └── Reports

Growth
 ├── Offers
 ├── Coupons
 ├── Banners
 └── Notifications

Support
 └── Tickets

Analytics

System
 ├── Service Areas
 ├── App Versions
 ├── Settings
 ├── Admin Users
 └── Audit Logs
```

---

# 26. Admin Tables

Use:

- Search
- Filters
- Pagination
- Sorting
- Column visibility
- Export where appropriate

Avoid displaying 20+ columns by default.

Example:

```text
Restaurant
Status
Orders
Revenue
Rating
Area
Actions
```

---

# 27. Admin Detail Drawer / Modal

For quick actions, use a side drawer or modal.

Example:

```text
Restaurant
ABC Sweets

Status: Approved
Rating: 4.7
Orders: 1,240
Revenue: ₹4.2L

[View Full Profile]
[View Orders]
[View Ledger]
[Suspend]
```

Do not force the admin to navigate through 5 pages for a simple review.

---

# 28. Notes / Internal Notes System

This should be added to Restaurant, Customer, Order, Ticket, and potentially Rider profiles.

Example:

```text
Internal Notes

18 Aug 09:20
Admin: Staff01

"Restaurant requested temporary closure
for kitchen maintenance."

[Add Note]
```

Important:

### Internal Notes

Visible only to authorized staff.

### Customer-facing Notes

Visible to customer where intentionally supported.

Never mix the two.

---

# 29. Note System Requirements

Each note should contain:

```text
id
entity_type
entity_id
author_id
note
created_at
updated_at
```

Optional:

```text
edited_at
deleted_at
```

Prefer soft deletion for audit-sensitive notes.

---

# 30. Order Internal Notes

Admin/support can add:

```text
Order #AD1024

Internal Note:
"Customer called about missing cold drink."
```

Customer should not automatically see this.

---

# 31. Restaurant Internal Notes

Example:

```text
Restaurant:
ABC Sweets

Note:
"Documents verified manually on 18 Aug."
```

Useful for:

- Verification
- Complaints
- Settlement issues
- Support history
- Operational warnings

---

# 32. Customer Internal Notes

Example:

```text
Customer:
XXXX

Note:
"Repeated cancellation pattern. Review before
manual compensation."
```

This should have strict permissions and audit logging.

---

# 33. Support Ticket Notes

Separate:

```text
Customer Conversation
```

from:

```text
Internal Support Notes
```

Example:

```text
Customer:
"I received the wrong item."

Internal Note:
"Restaurant contacted. Waiting for confirmation."
```

---

# 34. Confirmation Dialogs

Dangerous actions should always confirm.

Examples:

```text
Suspend Restaurant?

This will stop the restaurant from accepting
new orders.

[Cancel] [Suspend]
```

For financial actions:

```text
Confirm Payout

Restaurant: ABC Sweets
Amount: ₹5,240
Method: Bank Transfer

[Cancel] [Confirm Payout]
```

Do not use generic "Are you sure?" dialogs for important operations.

---

# 35. Empty States

Every major screen should have a proper empty state.

Bad:

```text
No data
```

Better:

```text
No active offers

Create an offer to attract more customers.

[Create Offer]
```

---

# 36. Loading States

Use skeleton loading where useful.

Avoid:

```text
Loading...
```

for every screen.

For buttons:

```text
Saving...
Processing...
Creating...
```

Disable duplicate taps during processing.

---

# 37. Error States

Errors should be actionable.

Bad:

```text
Something went wrong.
```

Better:

```text
Unable to save this offer.

Check the offer price and try again.

[Try Again]
```

Never expose raw SQL/PHP errors to users.

---

# 38. Search and Filter UX

Restaurant and Admin search should support:

- Debouncing
- Clear search button
- Recent search where useful
- Filters
- Sort
- Pagination

Do not send an API request for every single keystroke without debouncing.

---

# 39. Mobile UI Rules

Restaurant App:

- Minimum touch target around 44–48dp
- Large primary action buttons
- Bottom sheets for quick selection
- Sticky action button where appropriate
- Avoid tiny text
- Avoid crowded cards

Admin:

- Desktop optimized
- Responsive tablet layout
- Mobile fallback for urgent actions

---

# 40. Accessibility

Add:

- Readable contrast
- Clear focus states
- Content descriptions
- Accessible labels
- Do not rely only on color for status
- Reasonable font sizes

Example:

Do not show only:

```text
🟢
```

Show:

```text
● Active
```

---

# 41. Pricing Engine — Centralize Everything

This is a major architectural rule.

Customer App, Restaurant App, Admin Panel, and payment flow should never independently calculate final prices.

Use one backend pricing engine:

```text
Cart
 ↓
Item prices
 ↓
Unit/weight calculation
 ↓
Customization
 ↓
Item discounts
 ↓
Restaurant offers
 ↓
Coupon
 ↓
Delivery fee
 ↓
Free delivery discount
 ↓
Packaging
 ↓
Tax
 ↓
Final total
```

The server returns the authoritative breakdown.

---

# 42. Price Breakdown UI

Customer checkout should clearly show:

```text
Subtotal             ₹500
Item Discount        -₹50
Coupon               -₹30
Packaging             ₹20
Delivery Fee          ₹30
Free Delivery        -₹30
Tax                   ₹22
────────────────────────
Total                 ₹462
```

This reduces payment disputes.

---

# 43. Price Snapshot

At order creation, store a snapshot of:

- Item name
- Unit
- Quantity
- Unit price
- Discount
- Customization
- Tax
- Packaging
- Delivery fee
- Offer
- Coupon
- Final price

Changing the restaurant menu later must not change an old order's historical price.

---

# 44. Important Pending Business Rules

Before production, explicitly define:

- Who pays for free delivery?
- Can restaurant and admin offers stack?
- Can coupon + restaurant offer stack?
- Who funds cashback?
- Who funds refunds?
- COD commission rules
- Online-payment commission rules
- Self-delivery rules
- Restaurant cancellation rules
- Customer cancellation rules
- Late preparation compensation
- Missing item compensation
- Wrong item compensation
- Refund approval limits
- Payout schedule
- Minimum settlement amount
- Settlement failure handling
- Tax calculation
- Packaging charge rules
- Delivery charge calculation
- Surge/peak pricing if introduced
- Variable-weight product handling

These rules should be documented before implementing financial logic.

---

# 45. Additional Product Features Worth Considering

## Restaurant

- Scheduled menu availability
- Breakfast/lunch/dinner menus
- Item preparation time
- Kitchen capacity
- Auto-pause when overloaded
- Bulk menu upload
- CSV import/export
- Menu duplication
- Item cloning
- Image compression
- Bestseller badge
- Staff accounts

## Customer

- Reorder
- Favorites
- Recent orders
- Smart recommendations
- Gift orders
- Scheduled delivery
- Order notes
- Support tickets
- Refund tracking

## Admin

- Bulk restaurant actions
- Bulk notification
- Export reports
- Fraud flags
- Settlement reconciliation
- Staff permissions
- Audit logs
- System health dashboard

---

# 46. Final UI Design Direction

Anydrop should have a single visual language across Customer, Restaurant, and Admin.

## Brand

Use the existing Anydrop brand color as the primary accent.

Keep the rest of the UI:

- Clean
- White/dark neutral backgrounds
- Rounded cards
- Consistent spacing
- Clear typography
- Minimal shadows
- Consistent icon family

Do not make every component neon or overly colorful.

---

# 47. Icon Rule

Use icons for recognition, not decoration.

Recommended:

```text
Navigation:
Simple outlined/filled consistent set

Actions:
Edit
Delete
Pause
Play
Add
Search
Filter

Business:
Orders
Menu
Offers
Finance
Analytics
Support

Status:
Check
Clock
Warning
Error
```

Every icon should have a consistent stroke/weight.

---

# 48. Final "Pending" Checklist

## Customer

- [ ] Wallet
- [ ] Refund tracking
- [ ] Support tickets
- [ ] Reorder
- [ ] Restaurant review replies
- [ ] Better offer discovery
- [ ] Referral
- [ ] Loyalty
- [ ] Improved price breakdown

## Restaurant

- [ ] Full menu CRUD
- [ ] Unit-based pricing
- [ ] Weight-based pricing
- [ ] Variable quantity
- [ ] Restaurant offers
- [ ] Free delivery offers
- [ ] Combo offers
- [ ] Buy X Get Y
- [ ] Out-of-stock
- [ ] Temporary closure
- [ ] Earnings
- [ ] Settlement
- [ ] Analytics
- [ ] Reviews/replies
- [ ] Staff accounts

## Admin

- [ ] Dashboard
- [ ] Restaurant management
- [ ] Customer management
- [ ] Rider management
- [ ] Order control
- [ ] Finance
- [ ] Ledgers
- [ ] Payouts
- [ ] Refunds
- [ ] Offers
- [ ] Coupons
- [ ] CMS/Banners
- [ ] Service areas
- [ ] Notifications
- [ ] Support
- [ ] Analytics
- [ ] RBAC
- [ ] Audit logs
- [ ] Internal notes
- [ ] App version management

## Backend

- [ ] Idempotency
- [ ] OTP rate limiting
- [ ] Coupon race protection
- [ ] Pricing engine
- [ ] Payment reconciliation
- [ ] Refund reconciliation
- [ ] Financial ledger
- [ ] Audit logging
- [ ] Database backups
- [ ] Restore testing
- [ ] Security headers
- [ ] Input validation
- [ ] Rate limiting

---

# 49. Final Recommendation

The next major Anydrop development cycle should not simply be:

```text
Add more screens
```

It should be:

```text
Business Rules
       ↓
Pricing Engine
       ↓
Financial Ledger
       ↓
Admin Control
       ↓
Restaurant Operations
       ↓
Customer Experience
       ↓
UI Polish
       ↓
Rider System
```

The most important new requirements from this document are:

1. **Restaurant-created offers**
2. **Buy X for ₹Y**
3. **Buy X Get Y**
4. **Free delivery above ₹X**
5. **Clear delivery sponsorship**
6. **Unit-based pricing**
7. **Gram/kg/ml/litre/piece pricing**
8. **Variable-weight product support**
9. **Centralized pricing engine**
10. **Restaurant/Admin internal notes**
11. **Professional icon system**
12. **Restaurant App UI polish**
13. **Admin Panel UI polish**
14. **Explicit business-rule documentation**

These should be treated as product requirements rather than optional UI ideas.
