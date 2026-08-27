# AnyDrop — PENDING.md

**Purpose:** Current, source-checked list of features/work that are genuinely pending.  
**Rule:** Do not add a feature here merely because an old document says `PENDING`. It must still be missing, incomplete, or require a clearly defined remaining implementation/verification step in the current source.

---

# 🔴 P0 — Core Platform Pending

## 1. Admin Order Control

**Status:** PENDING

Admin needs a dedicated order-control module.

### Required
- [ ] Admin order listing
- [ ] Search by order ID
- [ ] Filter by area
- [ ] Filter by restaurant
- [ ] Filter by customer
- [ ] Filter by order status
- [ ] Filter by payment method/status
- [ ] Complete order detail view
- [ ] Full order timeline/status history
- [ ] Controlled manual status actions where allowed
- [ ] Admin actor/audit record for manual actions
- [ ] Verify financial impact of manual actions

### Main docs
- `recall.md` §14
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`

---

## 2. Admin Analytics & Reports

**Status:** 🟡 BUILT 2026-08-27 — NOT build/device-verified.
State/District/Restaurant/Category filters, Rider analytics, Payment
analytics, Coupon analytics, and CSV Export all added this session on
top of doc 44's original build. Full detail in
`docs/50_Handover_2026-08-27_Admin_Analytics_Filters_Riders_Payments_Coupons_Export_Built.md`.

### Required filters
- [x] Date range
- [x] State
- [x] District
- [x] City/Village — same dropdown as Area (see doc 50 for why these
      two levels share one filter, not two)
- [x] Area
- [x] Restaurant
- [x] Restaurant category
- [x] Order status — via Orders section's own Total/Completed/
      Cancelled/Rejected/Failed breakdown (doc 44)
- [x] Payment method — via new Payments section

### Reports
- [x] Order analytics
- [x] Revenue analytics
- [x] Commission analytics — inside Revenue's stat cards (doc 44), not
      a separate section (see doc 50, "what stays out of scope")
- [x] Area performance
- [x] Restaurant performance
- [x] Top restaurants
- [x] Top food/items
- [x] Customer analytics
- [x] Rider analytics
- [x] Payment analytics
- [x] Coupon analytics
- [x] Export/report generation — CSV, gated on `reports_export`

### Still open
- [ ] Build + device verification (no PHP CLI/live DB in the sandbox
      this was built in — see doc 50's checklist, items 1-10).

### Main docs
- `recall.md` §31
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/44_Handover_2026-08-26_Admin_Analytics_Built_Ledger_Comments_Fixed.md`
- `docs/50_Handover_2026-08-27_Admin_Analytics_Filters_Riders_Payments_Coupons_Export_Built.md`

---

## 3. Restaurant Insights

**Status:** 🟡 BUILT 2026-08-27 — NOT build/device-verified

Built end-to-end this session per docs/restorent/19 §6's actual spec:
`restaurant/insights.php` (new), date-range toggle (Today/Week/Month),
7-day orders bar chart, 4 stat cards (orders/earnings/AOV/cancellation
rate), top-5 best-selling items, repeat customers. Full detail in
`docs/49_Handover_2026-08-27_Restaurant_Insights_Tab_Built.md`.

### Still open (deliberately out of scope this session — see doc 49)
- [ ] Peak hours — in this item's original wishlist above but NOT in
      §6's actual UI spec; needs its own design decision before
      building (heatmap? single busiest-hour stat?).
- [ ] Export PDF/Excel — no export pattern exists anywhere else in the
      Restaurant App to extend; build only if the app owner confirms
      it's wanted.
- [ ] Build + device verification (no PHP CLI/Android SDK/live DB in
      the sandbox this was built in — see doc 49's checklist).

### Main docs
- `recall.md` §22, 2026-08-27 entry
- `docs/restorent/19_Restaurant_App_UI_Plan.md` §6
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/49_Handover_2026-08-27_Restaurant_Insights_Tab_Built.md`

---

# 🟠 P1 — Restaurant Operations Pending

## 4. Full Restaurant Offers Engine

**Status:** PENDING

Existing Coupons Manager is not the full Offers Engine.

### Required offer types
- [ ] Percentage discount
- [ ] Flat discount
- [ ] Item-specific offer
- [ ] Category-specific offer
- [ ] Combo/bundle
- [ ] Buy 2 Get 1
- [ ] Fixed-price quantity offer
- [ ] Free delivery above threshold
- [ ] Happy-hour/time-based offer
- [ ] Other structured promotional rules

### Rule engine
- [ ] Minimum order
- [ ] Maximum discount
- [ ] Total usage limit
- [ ] Per-customer usage limit
- [ ] Daily usage limit
- [ ] Start/end date-time
- [ ] Item/category eligibility
- [ ] Stacking rules
- [ ] Exclusive offer handling
- [ ] Server-authoritative discount calculation
- [ ] Checkout validation

### Main docs
- `recall.md` §9
- `docs/20_Offers_Pricing_UI_Polish_Notes.md`
- `docs/09_Auto_Bestseller_Discount_And_Git_Push.md`

---

## 5. Restaurant Delivery Responsibility / Self Delivery

**Status:** PENDING

Need to support:

```text
Anydrop Delivery
Restaurant Self Delivery
```

### Required
- [ ] Admin control for eligibility
- [ ] Restaurant delivery-mode setting
- [ ] Order-level delivery responsibility
- [ ] Rider assignment consequences
- [ ] Customer-facing delivery information
- [ ] Settlement/commission implications
- [ ] Delivery status handling
- [ ] Proper backend authorization

### Main docs
- `recall.md` §10
- `docs/20_Offers_Pricing_UI_Polish_Notes.md` §3

---

## 6. Temporary Closure / Holiday Scheduling

**Status:** PENDING

Basic temporary close/open functionality exists, but full scheduling is not complete.

### Required
- [ ] Closed today
- [ ] Closed until specific date/time
- [ ] Holiday date
- [ ] Multi-day closure
- [ ] Recurring weekly closure
- [ ] Server-authoritative operational state
- [ ] Customer-side visibility
- [ ] Order-placement blocking
- [ ] Restaurant-side schedule management

### Main docs
- `recall.md` §12
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`

---

## 7. Restaurant Staff / RBAC

**Status:** PENDING

Do not confuse this with Admin RBAC. Admin RBAC already exists.

### Required
- [ ] Restaurant staff table
- [ ] Staff accounts
- [ ] Owner role
- [ ] Manager role
- [ ] Kitchen role
- [ ] Cashier role
- [ ] Permission matrix
- [ ] Staff login/session handling
- [ ] Endpoint-level authorization
- [ ] Staff audit trail
- [ ] Add/remove/deactivate staff

### Main docs
- `recall.md` §23
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`

---

## 8. Review Reporting & Admin Moderation

**Status:** PARTIAL / PENDING

Restaurant review listing and restaurant reply are already implemented.

Remaining:

- [ ] Customer report-review action
- [ ] Report reason
- [ ] Admin reported-review queue
- [ ] Review moderation
- [ ] Hide/remove workflow
- [ ] Audit log
- [ ] Abuse protection

### Main docs
- `recall.md` §11
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`
- `docs/restorent/00_Status.md`

---

# 🟠 P1 — Customer Support / Trust Pending

## 9. Customer Support / Ticket System

**Status:** 🟡 ADMIN SIDE BUILT 2026-08-27, NOT device/build-verified.
See `docs/48_Handover_2026-08-27_Support_Ticket_System_Admin_Side_Built.md`.
Migration 52 + `lib/support.php` + `admin/support.php`.

### Required
- [x] Create ticket — admin-side "Log a Ticket" form (`create_ticket()`)
- [x] Ticket ID — `ticket_code`, e.g. `TKT-260827-A1B2C3`
- [x] Order-linked ticket — optional `order_id`
- [x] Issue categories
- [x] Missing item
- [x] Wrong item
- [x] Food quality
- [x] Delivery issue
- [x] Payment issue
- [x] Refund issue
- [x] Account issue
- [x] Coupon issue
- [x] General issue
- [x] Customer/admin conversation — `support_ticket_messages` thread
- [x] Attachments — one per message, JPG/PNG/WEBP, 5 MB cap
- [x] Ticket status — open/in_progress/resolved/closed
- [x] Admin assignment — assign-to-active-admin dropdown
- [x] Resolution — required note to mark Resolved
- [x] Close/reopen — resolved→closed, resolved→in_progress (reopen)
- [x] Audit history — every status change/assignment goes through `write_audit_log()`
- [ ] Customer/Restaurant/Rider App self-service creation — NOT built
      in any app; `create_ticket()` is raiser-type-generic so whichever
      app builds a "Help & Support" screen first can call it directly

### Main docs
- `recall.md` §20
- `docs/21_Production_Feature_Gap_Plan.md`
- `docs/48_Handover_2026-08-27_Support_Ticket_System_Admin_Side_Built.md`

---

## 10. Customer Self-Service Refund Flow

**Status:** PENDING

Refund foundation already exists, but complete customer self-service flow is not finished.

### Required
- [ ] Customer selects eligible order
- [ ] Select refund reason
- [ ] Attach evidence where applicable
- [ ] Create support/refund request
- [ ] Prevent duplicate refund requests
- [ ] Admin review
- [ ] Refund status tracking
- [ ] Wallet refund option where applicable
- [ ] Final refund reconciliation

### Dependency
Should be integrated with the Support/Ticket system rather than creating an uncontrolled standalone refund endpoint.

### Main docs
- `recall.md` §19
- `docs/21_Production_Feature_Gap_Plan.md`

---

## 11. Support AI

**Status:** PENDING

### Required architecture

```text
Customer
   ↓
Support AI
   ↓
Anydrop Backend Proxy
   ↓
Gemini / Claude / GPT
```

### Required
- [ ] Backend AI proxy
- [ ] No direct DB credentials to model
- [ ] Context filtering
- [ ] Order lookup through controlled APIs
- [ ] Refund/support escalation
- [ ] Human handoff
- [ ] Conversation history
- [ ] Abuse/rate limiting
- [ ] Cost controls
- [ ] Logging

### Main docs
- `recall.md` §21
- `docs/21_Production_Feature_Gap_Plan.md`

---

# 🟠 P1 — Wallet / Finance Pending

## 12. Customer Wallet Checkout Integration

**Status:** PENDING

Wallet balance/history screen already exists.

Remaining:

- [ ] Wallet payment option in checkout
- [ ] Wallet balance shown during checkout
- [ ] Wallet debit inside order transaction
- [ ] Insufficient-balance handling
- [ ] Atomic wallet/order transaction
- [ ] Idempotency
- [ ] Payment failure rollback

### Main docs
- `recall.md` §18
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`

---

## 13. Wallet Refund Integration

**Status:** PENDING

### Required
- [ ] Refund-to-wallet method
- [ ] Atomic refund ledger entry
- [ ] Wallet transaction reference
- [ ] Customer notification
- [ ] Duplicate refund protection
- [ ] Reconciliation

---

## 14. Cashback / Wallet Reward Engine

**Status:** PENDING

### Required
- [ ] Cashback rules
- [ ] Eligibility
- [ ] Maximum cashback
- [ ] Wallet credit
- [ ] Transaction reference
- [ ] Expiry
- [ ] Reversal on cancellation/refund
- [ ] Admin configuration
- [ ] Abuse prevention

---

## 15. Restaurant Bank Details Submission

**Status:** PENDING

Admin-side settlement/bank infrastructure exists.

Remaining:

- [ ] Restaurant-side bank details form
- [ ] Account-holder name
- [ ] Account number
- [ ] IFSC
- [ ] Validation
- [ ] Verification status
- [ ] Secure display/storage
- [ ] Admin verification
- [ ] Audit trail

---

## 16. Settlement Screenshot Upload

**Status:** 🟡 BUILT 2026-08-26, NOT device/build-verified. See
`docs/46_Handover_2026-08-26_Settlement_Screenshot_Upload_Built.md`.

- [x] Actual screenshot/file upload — `admin/settlements.php`'s Pay Now form
- [x] Secure storage — `backend/uploads/settlement_screenshots/`, finfo MIME-sniffed, 5MB cap
- [x] File validation
- [x] Settlement record attachment — `restaurant_payments.screenshot_url` (column already existed)
- [x] Admin view — Settlement History thumbnail/link
- [ ] Restaurant view — not built (admin-recorded flow only, per doc 19 §6's model)
- [x] Audit trail — `write_audit_log('settlement_recorded', ...)` now includes the path

Needs: PHP lint + live click-through per the handover doc's checklist.

---

## 17. Restaurant Finance / Payout Analytics

**Status:** 🟡 BUILT 2026-08-26, NOT device/build-verified. See
`docs/47_Handover_2026-08-26_Restaurant_Payout_Analytics_Built.md`.
`admin/settlements.php`'s per-restaurant Payout Analytics card, doc 19
§6's exact column list.

- [x] Total orders
- [x] Cash collected (COD)
- [x] Online collected (UPI)
- [x] Commission
- [x] GST (on commission, `app_settings.gst_percent`)
- [x] Restaurant payable/due — Net Payable + live `current_due` (Pending)
- [x] Today
- [x] Weekly (rolling 7 days, matches `analytics.php`'s convention)
- [x] Monthly (rolling 30 days, same convention)
- [x] Settlement history — already existed, unchanged by this item
- [ ] Download/export — not built; no export exists anywhere in admin
      yet (analytics.php flagged the same gap), follow-up not this item

Needs: PHP lint + a live page load across all three ranges, including a
restaurant with zero orders in range (confirm no divide-by-zero /
blank-state rendering issue).

---

# 🔴 P0/P1 — Delivery Network Pending

## 18. Rider App

**Status:** NOT STARTED / PENDING

### Required
- [ ] Rider signup
- [ ] Login
- [ ] Profile
- [ ] Documents
- [ ] Admin approval
- [ ] Online/offline
- [ ] Availability
- [ ] Order assignment
- [ ] Accept/reject
- [ ] Pickup
- [ ] Navigation
- [ ] Customer contact
- [ ] Restaurant contact
- [ ] Delivery OTP
- [ ] Order completion
- [ ] Earnings
- [ ] History
- [ ] Support

### Main docs
- `recall.md` §24
- `docs/03_Live_Tracking.md`
- `docs/18_Restaurant_App_Full_Scope_And_Rating_System.md`

---

## 19. Rider Assignment System

**Status:** PENDING

### Required
- [ ] Find eligible riders
- [ ] Distance/area matching
- [ ] Online status
- [ ] Assignment
- [ ] Accept/reject
- [ ] Reassignment
- [ ] Timeout
- [ ] Restaurant/customer notification
- [ ] Admin override
- [ ] Audit trail

---

## 20. Live Rider Tracking

**Status:** PENDING

### Required
- [ ] Rider GPS pings
- [ ] Customer map
- [ ] Restaurant map where required
- [ ] Admin map
- [ ] Smooth marker movement
- [ ] ETA
- [ ] Route
- [ ] Foreground location service
- [ ] Battery-aware interval
- [ ] Offline handling
- [ ] Location history
- [ ] Permission handling

### Main docs
- `recall.md` §25
- `docs/03_Live_Tracking.md`
- `docs/12_Handover_H6_Map_PinDrop_Photo.md`

---

## 21. Delivery OTP / Completion Flow

**Status:** PENDING

### Required
- [ ] Generate delivery OTP
- [ ] Show to customer
- [ ] Rider enters OTP
- [ ] Server validation
- [ ] Attempt limit
- [ ] Completion only after valid OTP where required
- [ ] Failed attempt handling
- [ ] Audit trail

---

## 22. Rider Earnings / Settlement

**Status:** PENDING

### Required
- [ ] Per-order earning
- [ ] Delivery fee split
- [ ] Incentives
- [ ] Penalties where applicable
- [ ] Daily/weekly/monthly earnings
- [ ] Rider ledger
- [ ] Settlement
- [ ] Payout history

---

# 🔴 P1 — Authentication / Production Pending

## 23. Google Login Backend Verification

**Status:** PENDING

Customer-side Google login flow still needs complete backend verification.

### Required
```text
Google Sign-In
    ↓
ID Token
    ↓
Backend Verification
    ↓
Customer Create/Login
    ↓
Session
```

### Required
- [ ] Backend token verification
- [ ] Account linking
- [ ] New-user creation
- [ ] Existing-user login
- [ ] Secure session generation
- [ ] Invalid token handling
- [ ] Replay protection where applicable

### Main docs
- `recall.md` §26
- `docs/02_API_Contract.md`
- `docs/Status.md`

---

## 24. Payment / Refund Reconciliation

**Status:** PENDING FOR PRODUCTION

Foundation exists, but final reconciliation layer is required.

### Required chain

```text
Order
 ↓
Payment Transaction
 ↓
Platform Ledger
 ↓
Restaurant Due Ledger
 ↓
Settlement
 ↓
Refund
```

### Required
- [ ] Authoritative payment state
- [ ] Provider transaction matching
- [ ] Reconciliation job/check
- [ ] Mismatch detection
- [ ] Duplicate transaction detection
- [ ] Refund reconciliation
- [ ] Settlement reconciliation
- [ ] Admin mismatch queue
- [ ] Audit trail

### Main docs
- `recall.md` §28
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`
- `docs/21_Production_Feature_Gap_Plan.md`
- `docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md`

---

## 25. Email OTP Provider Failover System

**Status:** PENDING / NEEDS FINAL VERIFICATION

### Required
- [ ] Provider abstraction
- [ ] Provider 1
- [ ] Provider 2
- [ ] Provider 3
- [ ] Priority
- [ ] Enable/disable
- [ ] Daily quota
- [ ] Monthly quota
- [ ] Automatic fallback
- [ ] Failure logging
- [ ] Test send
- [ ] Usage dashboard
- [ ] Admin configuration

### Main docs
- `recall.md` §30
- `docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_2026-08-14.md`

---

# 🔴 P1 — Production Security Pending

## 26. Security Hardening

**Status:** PENDING / FINAL AUDIT REQUIRED

### Required
- [ ] OTP rate limiting
- [ ] OTP abuse protection
- [ ] Order idempotency
- [ ] Coupon race-condition protection
- [ ] Server-side price validation
- [ ] Server-side operational-state validation
- [ ] Payment authorization
- [ ] Refund authorization
- [ ] Admin RBAC audit
- [ ] Financial-data access control
- [ ] Audit logging
- [ ] Production secret management
- [ ] Error logging
- [ ] Backup policy
- [ ] Restore testing

### Main docs
- `docs/security.md`
- `docs/bugs.md`
- `docs/21_Production_Feature_Gap_Plan.md`

---

# 🟡 P2 — Remaining Verification / Smaller Gaps

## 27. Restaurant Search Delivery-Radius Consistency

**Status:** PARTIAL

`restaurants/list.php` has delivery-radius logic, but `search.php` still needs the same server-side consistency.

- [ ] Apply/verify `delivery_radius_km` in search
- [ ] Test area + distance combination
- [ ] Test unresolved-location behavior

### Main doc
- `recall.md` §3

---

## 28. Area / Payment / Banner Live Verification

**Status:** IMPLEMENTED — VERIFICATION PENDING

- [ ] Test service-area resolution
- [ ] Test restaurant area matching
- [ ] Test delivery radius
- [ ] Test COD rules
- [ ] Test minimum order
- [ ] Test delivery fee
- [ ] Test payment restrictions
- [ ] Test area-targeted banners
- [ ] Test unresolved-location fallback
- [ ] Test real device build

---

## 29. Final Restaurant Finance Live Verification

**Status:** IMPLEMENTED FOUNDATION — VERIFICATION PENDING

- [ ] Commission calculation
- [ ] Restaurant due
- [ ] Paid order ledger
- [ ] Settlement
- [ ] Platform ledger
- [ ] UTR/reference
- [ ] Audit record
- [ ] Refund interaction
- [ ] Real DB/device test

---

## 30. Final Suspend / Session / Migration Verification

**Status:** IMPLEMENTATION PRESENT — FINAL VERIFICATION PENDING

Based on the latest handovers:

- [ ] Run required migration(s)
- [ ] Build Restaurant app
- [ ] Test suspended restaurant login
- [ ] Test already logged-in suspended restaurant
- [ ] Test API rejection after suspension
- [ ] Test customer visibility
- [ ] Test reactivation
- [ ] Test session/token behavior
- [ ] Verify Admin audit

### Main docs
- `docs/25_Handover_2026-08-24_Suspend_Banners_CodReason.md`
- `docs/26_Handover_2026-08-24_Suspend_MidSession_Backend_Done_Android_Partial.md`
- `docs/27_Handover_2026-08-24_Suspend_Android_Wiring_Done_Needs_Build_And_Migration.md`

---

# 31. Full Build / Device / Live DB Regression

**Status:** PENDING

Before marking the current development milestone as production-ready:

- [ ] Backend syntax check
- [ ] DB migration check
- [ ] Customer APK build
- [ ] Restaurant APK build
- [ ] Admin login test
- [ ] Customer login test
- [ ] Restaurant login test
- [ ] Order creation
- [ ] Restaurant accept/reject
- [ ] Status updates
- [ ] Payment
- [ ] Refund
- [ ] Wallet
- [ ] Coupon
- [ ] Banner
- [ ] Area rules
- [ ] Commission
- [ ] Settlement
- [ ] Suspend/reactivate
- [ ] Notification
- [ ] Review/reply
- [ ] Error handling
- [ ] Security regression

---

# 32. PENDING MASTER ORDER

Recommended implementation order:

### Phase A — Admin Control
- [ ] Admin Order Control
- [ ] Admin Analytics/Reports

### Phase B — Restaurant Operations
- [ ] Restaurant Insights
- [ ] Full Offers Engine
- [ ] Temporary Closure/Holiday Scheduling
- [ ] Self Delivery
- [ ] Review Reporting/Moderation
- [ ] Restaurant Staff/RBAC

### Phase C — Finance/Customer
- [ ] Wallet Checkout
- [ ] Wallet Refund
- [ ] Cashback
- [ ] Restaurant Bank Submission
- [ ] Settlement Screenshot
- [ ] Finance Analytics
- [ ] Support/Tickets
- [ ] Customer Refund Requests
- [ ] Support AI

### Phase D — Delivery
- [ ] Rider App
- [ ] Rider Assignment
- [ ] Delivery OTP
- [ ] Live Tracking
- [ ] Rider Earnings

### Phase E — Production
- [ ] Google Login backend
- [ ] Payment/refund reconciliation
- [ ] Email OTP provider failover
- [ ] Security hardening
- [ ] Full regression/build/device/live DB testing

---

# 33. Do NOT move these back into PENDING

The following already have current source implementation/foundation and should only be reopened for verification or specific remaining sub-features:

- Admin Dashboard
- Admin Restaurant Management foundation
- Admin Customer Management foundation
- Admin Areas
- Admin Categories
- Admin Banners
- Admin COD Rules
- Admin Payment Restrictions
- Admin Pricing Rules
- Admin Commission Rules
- Admin Settlements foundation
- Platform Ledger
- Admin Payment Gateway foundation
- Admin Pending UPI queue
- Refund foundation
- Customer Wallet screen/history
- Restaurant Review Reply
- Restaurant Account tab
- Restaurant Coupons Manager
- Restaurant Banners Manager
- Restaurant Item Tags
- Restaurant Notifications
- Restaurant Suspend flow foundation
- Restaurant OPEN/CLOSED foundation

---

# 34. Completion rule

A feature is only removed from this file after:

```text
SOURCE IMPLEMENTED
       ↓
DB/MIGRATION READY
       ↓
API VERIFIED
       ↓
ANDROID/ADMIN UI VERIFIED
       ↓
REAL DEVICE TEST
       ↓
LIVE DB / END-TO-END TEST
       ↓
SECURITY + EDGE CASE TEST
       ↓
DONE
```

**Implemented ≠ Tested**

**Tested ≠ Production-ready**

Only after the complete flow is verified should the item be moved to `done.md`.
