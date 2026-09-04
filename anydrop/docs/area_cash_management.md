# Anydrop — Advanced Cash Flow, Area Accounts & Permission Management

## Purpose

This document extends `accounte.md` with the operational system required to maintain restaurant dues, partial payments, settlement proof, area-wise payment management, and restricted area-admin access.

The target is:

> Every rupee must remain traceable, every restaurant payment must reduce the correct due automatically, and an area admin must see/action only what the super admin allows.

---

# 1. Core Example — Restaurant Due and Partial Payment

Suppose:

```text
Restaurant: Restaurant X
Area: Osian

Previous / Opening Due: ₹5,000
```

Admin opens:

```text
Accounts
→ Restaurant X
→ Settlement
```

System shows:

```text
Current Due        ₹5,000

Enter Payment      ₹3,000

Remaining Due      ₹2,000
```

Admin selects:

```text
Payment Method: UPI
Paid From: Anydrop UPI Account 1
UTR: ABC123456
Transaction Date: 24 Aug 2026
Transaction Time: 11:30 AM
Remarks: Partial settlement
Screenshot: uploaded
```

Then clicks:

```text
PAY & SETTLE
```

System creates an immutable settlement:

```text
Settlement ID: SET-20260824-001
Restaurant: Restaurant X
Area: Osian

Previous Due: ₹5,000
Paid:         ₹3,000
Remaining:    ₹2,000

Method: UPI
UTR: ABC123456
Proof: attached

Status: Completed
```

The remaining ₹2,000 must stay outstanding automatically.

Do NOT mark the restaurant fully paid just because a payment was made.

---

# 2. Restaurant Account States

Each restaurant should have a financial state:

```text
SETTLED
PARTIALLY_DUE
DUE
RECEIVABLE
PAYMENT_PENDING
PAYMENT_VERIFICATION_REQUIRED
DISPUTED
SUSPENDED
```

Example:

```text
Due to restaurant: ₹2,000
```

UI:

```text
PARTIALLY SETTLED
₹2,000 remaining
```

---

# 3. Separate Due Direction

Do not represent everything as just "due".

Use:

```text
RESTAURANT_RECEIVABLE
```

when restaurant owes Anydrop.

Use:

```text
RESTAURANT_PAYABLE
```

when Anydrop owes restaurant.

Example:

```text
Restaurant X

Restaurant payable:   ₹5,000
Paid:                 ₹3,000
Remaining payable:    ₹2,000
```

This avoids confusion.

---

# 4. Payment Allocation

Every settlement must specify what it is paying.

Options:

```text
AUTO ALLOCATE
SPECIFIC ORDERS
SPECIFIC SETTLEMENT BATCH
OLDEST DUE FIRST
CUSTOM ALLOCATION
```

Recommended default:

```text
Oldest due first
```

Example:

```text
Order AD1001      ₹1,000
Order AD1002      ₹2,000
Order AD1003      ₹3,000

Restaurant pays ₹3,000

System allocates:

AD1001 → ₹1,000
AD1002 → ₹2,000
AD1003 → ₹0
```

Remaining AD1003 payable stays open.

---

# 5. Unallocated Restaurant Payment

Sometimes admin receives/pays money without immediately knowing the exact orders.

Allow:

```text
UNALLOCATED PAYMENT
```

But it must not disappear into a generic balance.

Store:

```text
payment_id
restaurant_id
area_id
amount
method
UTR
proof
created_by
```

Status:

```text
UNALLOCATED
```

Later admin can allocate it.

Dashboard must show:

```text
Unallocated Payments: 2
Amount: ₹8,500
```

---

# 6. Partial Payment Rules

If:

```text
Due = ₹5,000
Payment = ₹3,000
```

then:

```text
Due before:  ₹5,000
Settlement:  ₹3,000
Due after:   ₹2,000
```

If:

```text
Due = ₹5,000
Payment = ₹5,000
```

then:

```text
Due after = ₹0
Status = SETTLED
```

If:

```text
Due = ₹5,000
Payment = ₹6,000
```

system must not silently make the accounting incorrect.

Show:

```text
Due: ₹5,000
Payment: ₹6,000
Excess: ₹1,000
```

Then require:

```text
Store ₹1,000 as restaurant advance/credit
```

or reject the payment.

---

# 7. Settlement Proof

Every manual bank/UPI settlement should support:

```text
Payment Method
Amount
UTR
Transaction ID
UPI Reference
Bank
Paid From Account
Beneficiary
Payment Date
Payment Time
Screenshot
Receipt
Remarks
```

Attachment metadata:

```text
file_id
original_filename
mime_type
size
hash
uploaded_by
uploaded_at
```

Old proof must never be silently overwritten.

---

# 8. Payment Status

Use:

```text
DRAFT
PENDING_APPROVAL
APPROVED
PROCESSING
COMPLETED
FAILED
REJECTED
REVERSED
DISPUTED
```

If an area admin is only allowed to create payments but not approve them:

```text
Area Admin → PENDING_APPROVAL
Super Admin → APPROVE
System → COMPLETED
```

If Super Admin allows direct completion:

```text
Area Admin → COMPLETED
```

The permission controls this.

---

# 9. Area Management

Every restaurant must belong to an operational area.

Example:

```text
Area
├── Osian
├── Jodhpur
├── Mathania
├── Balesar
└── Other
```

Restaurant:

```text
restaurant_id
area_id
```

Do not depend only on a text field like:

```text
area = "Osian"
```

Use a proper `areas` table.

---

# 10. Area Table

Recommended:

```text
areas
```

Fields:

```text
id
name
code
city
district
state
status
created_at
updated_at
```

Example:

```text
id: 1
name: Osian
code: OSN
status: active
```

---

# 11. Restaurant Area Assignment

Restaurant:

```text
restaurant_id
area_id
```

Admin must be able to:

```text
Change Area
View Area History
```

Area changes should be audited.

Example:

```text
ABC Restaurant

Old Area: Osian
New Area: Jodhpur

Changed By: Super Admin
Changed At: ...
Reason: Operational territory changed
```

Historical accounting must remain attached to the original area where appropriate.

---

# 12. Area Filter Everywhere

Area filter should exist in:

```text
Accounts Dashboard
Cash Flow
Restaurants
Restaurant Due
Settlements
COD Collection
Rider Cash
Payments
Refunds
Expenses
Reports
Reconciliation
Audit
```

Example:

```text
Area: Osian
Date: Last 7 Days
Status: Due
```

Result:

```text
12 Restaurants
Total Payable: ₹84,500
Total Receivable: ₹12,300
Pending Settlement: ₹31,000
```

---

# 13. Area Summary Dashboard

For each area:

```text
OSIAN

Restaurants:             42
Active Restaurants:      39

Gross Orders:            820
GMV:                  ₹3,25,000

Restaurant Payable:   ₹1,10,500
Restaurant Paid:        ₹75,000
Restaurant Due:          ₹35,500

COD Collected:          ₹92,000
COD Pending:              ₹8,500

Online Payments:       ₹2,33,000

Refunds:                  ₹6,500
Platform Revenue:       ₹42,000
```

Every figure must drill down.

---

# 14. Area-wise Restaurant Due List

Example:

```text
OSIAN — RESTAURANT PAYABLES

Restaurant       Due       Last Payment
----------------------------------------
ABC Food        ₹8,500      ₹3,000
XYZ Cafe        ₹5,200      ₹5,000
Royal Hotel     ₹2,000      ₹0
Food Point      ₹0          ₹7,500
```

Filters:

```text
Due > 0
Partially Paid
No Payment
Paid Today
Overdue
```

---

# 15. Area-wise Settlement

Area admin opens:

```text
Accounts
→ Settlements
→ Area: Osian
```

They see only restaurants assigned to Osian if their permission is scoped that way.

They can:

```text
View
Create Settlement
Upload Proof
Verify
Approve
Reject
Export
```

Only if those permissions are granted.

---

# 16. Area Admin System

Create role:

```text
AREA_ADMIN
```

But role alone is not enough.

Every admin needs a scope:

```text
scope_type
scope_id
```

Example:

```text
User: Imran
Role: AREA_ADMIN
Scope: AREA
Scope ID: Osian
```

This means:

```text
Can access Osian
Cannot access Jodhpur
Cannot access Mathania
```

---

# 17. Multiple Area Admins

Possible:

```text
Osian Admin 1
Osian Admin 2
Jodhpur Admin 1
Mathania Admin 1
```

Each admin can have different permissions.

Example:

```text
Osian Admin A
```

Can:

```text
view_accounts
view_restaurant_due
create_settlement
upload_proof
```

Cannot:

```text
approve_settlement
change_bank_details
manual_adjustment
delete_transaction
```

---

# 18. Permission Matrix

Recommended permissions:

```text
accounts.view
accounts.export

restaurant.view
restaurant.ledger.view
restaurant.due.view

settlement.view
settlement.create
settlement.edit_draft
settlement.submit
settlement.approve
settlement.reject
settlement.complete
settlement.reverse

payment.view
payment.verify

proof.upload
proof.view
proof.delete

rider_cash.view
rider_cash.verify

refund.view
refund.create
refund.approve

adjustment.view
adjustment.create
adjustment.approve

expense.view
expense.create
expense.approve

bank_details.view
bank_details.edit

reconciliation.view
reconciliation.run

reports.view
reports.export

audit.view
```

---

# 19. Scope + Permission

Final authorization should be:

```text
ALLOW =
Role Permission
AND
Area Scope
AND
Resource Scope
```

Example:

```text
Admin has:
settlement.create = YES

Admin scope:
Osian

Restaurant:
Osian

→ ALLOW
```

Restaurant:

```text
Jodhpur
```

→ DENY.

---

# 20. Action-Level Control

Super Admin should be able to configure exactly what area admins can do.

Example:

```text
OSIAN PAYMENT ADMIN

View Accounts             ON
View Restaurant Due       ON
Create Settlement         ON
Upload Screenshot         ON
Mark Payment Completed    OFF
Approve Settlement        OFF
Refund                    OFF
Manual Adjustment         OFF
Bank Details Edit         OFF
Export                    ON
```

This is much safer than giving a generic "Area Admin" role.

---

# 21. Admin Assignment UI

Super Admin:

```text
Admin Management
→ Create Admin
```

Fields:

```text
Name
Email / Mobile
Login
Password/Auth Method
Role
Area
Permissions
Status
```

Example:

```text
Name: Imran
Role: Area Payment Manager
Area: Osian

Permissions:
✓ View Accounts
✓ View Due
✓ Create Settlement
✓ Upload Proof
✓ View Statements
✓ Export
✗ Approve
✗ Refund
✗ Adjustments
✗ Bank Changes
```

---

# 22. Area Admin Dashboard

When Imran logs in:

```text
OSIAN PAYMENT MANAGEMENT

Restaurants       42
Pending Due       ₹35,500
Today's Payments  ₹12,000
Pending Approval   ₹4,500

COD Pending        ₹8,500

Recent Settlements
...
```

He should not even see unrelated areas if his scope is restricted.

---

# 23. API-Level Enforcement

Very important:

Do NOT rely only on frontend filtering.

Bad:

```text
Frontend hides Jodhpur
Backend still returns Jodhpur data
```

Correct:

```text
API receives request
↓
Authenticate user
↓
Load permissions
↓
Load area scope
↓
Add area_id condition
↓
Return authorized data only
```

Every sensitive endpoint must enforce scope.

---

# 24. Backend Example Logic

Conceptually:

```text
current_user
    ↓
permissions
    ↓
scope
    ↓
authorized_area_ids
    ↓
query restaurants/payments
```

For Osian admin:

```text
authorized_area_ids = [OSIAN_ID]
```

Query must effectively become:

```text
WHERE restaurant.area_id IN (OSIAN_ID)
```

Never trust:

```text
area_id
```

sent from the client.

---

# 25. Prevent Cross-Area Settlement

If Osian admin sends:

```text
restaurant_id = JODHPUR_RESTAURANT
```

backend must reject:

```text
403 FORBIDDEN
```

Even if the API request is manually modified.

---

# 26. Bank Details Protection

Area admin should normally not see full bank account numbers.

Show:

```text
XXXX XXXX 1234
```

Only users with:

```text
bank_details.view_full
```

can access full data.

Changing bank details should be Super Admin/authorized permission only.

---

# 27. Settlement Approval Workflow

Recommended for area admins:

```text
Area Admin creates settlement
        ↓
PENDING APPROVAL
        ↓
Super Admin reviews
        ↓
UTR + screenshot checked
        ↓
APPROVE
        ↓
System posts final settlement
        ↓
Restaurant due reduces
```

This gives you operational staff without giving them unlimited financial control.

---

# 28. Optional Direct-Payment Mode

Super Admin can configure:

```text
Area Admin Direct Completion = ON/OFF
```

If OFF:

```text
Area Admin → Create only
```

If ON:

```text
Area Admin → Create + Complete
```

This setting should itself be audited.

---

# 29. Payment Limits

You can configure:

```text
Area Admin max settlement without approval:
₹5,000
```

If admin tries:

```text
₹8,000
```

system automatically requires approval.

Other limits:

```text
Daily settlement limit
Single transaction limit
Monthly limit
```

---

# 30. Area Admin Cash Management

If area admin physically handles cash:

```text
Area Cash Account
```

Track:

```text
Opening Cash
COD Handover
Restaurant Collections
Expenses
Bank Deposit
Cash Withdrawal
Closing Cash
```

But do not mix:

```text
Restaurant payable
```

with:

```text
Physical cash
```

They are different accounting dimensions.

---

# 31. Area Cash Closing

Example:

```text
OSIAN CASH CLOSING

Opening Cash          ₹10,000

COD Received           ₹8,500
Other Cash In          ₹1,000

Expenses              -₹2,000
Bank Deposit          -₹5,000

Expected Closing      ₹12,500
Actual Closing        ₹12,500

Difference                 ₹0
```

If mismatch:

```text
Difference: -₹500
```

Require explanation.

---

# 32. Restaurant Payment Calendar

Useful feature:

```text
Due Today
Due Tomorrow
Overdue
Paid Today
Partially Paid
Never Paid
```

Restaurant detail:

```text
Current Due: ₹5,000
Due Age: 1 day
Last Payment: yesterday
Last Payment Amount: ₹3,000
```

---

# 33. Due Aging

Add aging buckets:

```text
0–1 Days
2–3 Days
4–7 Days
8–15 Days
16–30 Days
30+ Days
```

Example:

```text
0–1 Days      ₹25,000
2–3 Days      ₹18,000
4–7 Days      ₹10,500
8–15 Days      ₹4,000
30+ Days       ₹2,000
```

This makes overdue settlement obvious.

---

# 34. Settlement History

Restaurant:

```text
Current Due: ₹2,000

Settlement History

23 Aug
Paid ₹3,000
UTR ABC123
Due after ₹2,000
Proof attached

20 Aug
Paid ₹2,000
UTR XYZ456
Due after ₹5,000
Proof attached
```

Never replace old settlement data with the latest payment.

---

# 35. Restaurant Statement Running Balance

Example:

```text
Date    Description       Debit   Credit  Balance

20 Aug  Order payable              ₹5,000  ₹5,000
22 Aug  Settlement         ₹2,000          ₹3,000
23 Aug  New payable                 ₹4,000  ₹7,000
23 Aug  Settlement         ₹3,000          ₹4,000
```

The exact debit/credit direction should follow the finalized ledger convention, but it must remain consistent everywhere.

---

# 36. Payment Search

Global search should support:

```text
UTR
Settlement ID
Transaction ID
Order ID
Restaurant
Rider
Area
Amount
Date
Admin
```

Example:

```text
Search: ABC123
```

returns:

```text
Settlement SET-100
Restaurant X
Osian
₹3,000
UPI
ABC123
Completed by Imran
```

---

# 37. Proof Verification

When payment proof is uploaded:

```text
Proof Status:
UNVERIFIED
VERIFIED
REJECTED
```

Verifier:

```text
verified_by
verified_at
verification_note
```

Area admin may upload but another admin may verify.

---

# 38. Payment Reversal

If a payment was marked completed incorrectly:

Never delete it.

Use:

```text
Original Settlement:
₹3,000

Reversal:
-₹3,000

Reason:
Wrong UTR / wrong beneficiary
```

Then create corrected payment.

---

# 39. Audit Example

```text
24 Aug 11:30

Admin: Imran
Action: Created Settlement

Restaurant: Restaurant X
Area: Osian
Amount: ₹3,000
Method: UPI
UTR: ABC123

24 Aug 11:35

Super Admin
Action: Approved

24 Aug 11:36

System
Action: Due reduced

Before: ₹5,000
After:  ₹2,000
```

Every financial state change should be explainable like this.

---

# 40. Area-Level Reports

Each area admin can export only authorized data.

Reports:

```text
Area Cash Flow
Area Restaurant Due
Area Settlement
Area COD
Area Rider Cash
Area Refunds
Area Expenses
Area Reconciliation
```

If admin has only `accounts.view`, export should be denied unless:

```text
accounts.export
```

is granted.

---

# 41. Super Admin Global View

Super Admin:

```text
ALL AREAS

Osian
Jodhpur
Mathania
Balesar
...
```

Can filter:

```text
Area
Restaurant
Rider
Payment Manager
Date
Status
```

Can compare:

```text
Osian Payable
Jodhpur Payable
Total Payable
```

---

# 42. Area Manager vs Area Payment Admin

Do not make every area staff member a full admin.

Recommended roles:

```text
AREA_VIEWER
AREA_PAYMENT_OPERATOR
AREA_PAYMENT_VERIFIER
AREA_MANAGER
SUPER_ADMIN
```

Example:

### AREA_VIEWER

Can:

```text
View restaurants
View due
View reports
```

Cannot:

```text
Create payment
Approve payment
Change bank
Adjust ledger
```

### AREA_PAYMENT_OPERATOR

Can:

```text
View
Create settlement
Upload proof
```

Cannot:

```text
Approve
Reverse
Adjust
```

### AREA_PAYMENT_VERIFIER

Can:

```text
View
Verify proof
Approve settlement
```

### AREA_MANAGER

Can have broader operational permissions, configured explicitly.

---

# 43. No Global Admin Leakage

An area admin must not be able to access another area's data through:

```text
URL ID
API parameter
hidden field
mobile app request
export endpoint
search endpoint
report endpoint
```

Scope enforcement must exist server-side everywhere.

---

# 44. Multi-Area Access

One employee may manage multiple areas.

Example:

```text
User: Imran
Role: AREA_PAYMENT_OPERATOR
Areas:
- Osian
- Mathania
```

Then:

```text
authorized_area_ids = [OSIAN, MATHANIA]
```

UI can provide:

```text
Area:
[All Assigned Areas]
[Osian]
[Mathania]
```

---

# 45. Temporary Area Access

Useful for replacement staff.

Allow:

```text
Access Start
Access End
```

Example:

```text
Osian access:
24 Aug → 31 Aug
```

After expiry:

```text
ACCESS DENIED
```

Audit everything.

---

# 46. Emergency Disable

Super Admin should be able to:

```text
Disable admin
Revoke sessions
Remove area
Remove payment permissions
```

Immediately.

---

# 47. Session Security

For financial admins:

```text
last_login
last_activity
active_sessions
device/session identifier
```

Support:

```text
Logout all sessions
```

for suspicious activity.

---

# 48. High-Risk Actions

Require confirmation for:

```text
Settlement > configured limit
Bank account change
Refund
Manual adjustment
Ledger reversal
Cash shortage write-off
Large expense
```

Optional OTP/2FA for Super Admin.

---

# 49. Immutable Financial Record

Once:

```text
Settlement = COMPLETED
```

normal editing should be disabled.

Only:

```text
Reverse
```

or:

```text
Correction
```

through a new transaction.

---

# 50. Dashboard Alerts

Area admin:

```text
🔴 4 payments awaiting proof
🟠 3 settlements awaiting approval
🟠 ₹12,500 overdue
🟡 2 rider cash handovers pending
```

Super Admin:

```text
🔴 2 reconciliation mismatches
🔴 1 duplicate UTR
🟠 8 pending settlements
🟠 ₹45,000 total area payables
```

---

# 51. Cash Flow Graph

Dashboard should optionally show:

```text
Money In
Money Out
Restaurant Payables
COD Pending
Bank Balance
Cash Balance
```

by:

```text
Day
Week
Month
Area
```

But graphs are derived reports, never the accounting source.

---

# 52. Exact Cash Trail

For any amount, system should support:

```text
TRACE MONEY
```

Example:

```text
₹3,000 Settlement
        ↓
Settlement SET100
        ↓
Restaurant X
        ↓
Osian
        ↓
Payment Account: Anydrop UPI 1
        ↓
UTR ABC123
        ↓
Proof screenshot
        ↓
Admin Imran
        ↓
Previous Due ₹5,000
        ↓
Remaining Due ₹2,000
```

---

# 53. Exact Due Trail

For Restaurant X:

```text
Current Due ₹2,000
```

Click:

```text
WHY?
```

System shows:

```text
Opening Due              ₹5,000
Settlement               -₹3,000
--------------------------------
Current Due               ₹2,000
```

If new order payable is added:

```text
Opening Due               ₹2,000
New payable               +₹1,500
Settlement                -₹500
--------------------------------
Current Due               ₹3,000
```

This makes disputes easy to solve.

---

# 54. No Manual Balance Editing

Super Admin should also not have a simple:

```text
Edit Current Due
```

button.

Instead:

```text
Create Adjustment
```

with:

```text
reason
amount
direction
evidence
approval
audit
```

This protects accounting integrity.

---

# 55. Area Assignment History

Store:

```text
restaurant_area_history
```

Fields:

```text
restaurant_id
old_area_id
new_area_id
effective_from
effective_to
changed_by
reason
```

This prevents historical reporting problems when a restaurant moves from Osian to another area.

---

# 56. Effective-Date Rules

If restaurant changes area on:

```text
25 Aug
```

orders before 25 Aug remain associated with their historical operational area for reporting.

Future orders use new area.

Do not rewrite historical accounting just because the restaurant's current area changed.

---

# 57. Area-Based Commission/Payment Rules

If business rules differ by area, support:

```text
area_id
commission
minimum_prepaid_orders
COD availability
payment rules
settlement rules
```

But order-level financial snapshots must preserve the actual rule used at the time.

---

# 58. Area Admin Configuration

Super Admin should control:

```text
Area
Admin
Role
Permissions
Payment limits
Approval requirement
Visible reports
Export permission
Bank-detail visibility
Settlement modes
```

All changes audited.

---

# 59. Accounting Notifications

Restaurant:

```text
Payment received
Settlement processed
Remaining due
```

Area admin:

```text
Settlement approved
Settlement rejected
Proof rejected
```

Super Admin:

```text
Large payment
Mismatch
Duplicate UTR
Manual adjustment
```

Notifications should link directly to the transaction.

---

# 60. Final Acceptance Test

Before marking this system DONE, test:

### Test 1 — Partial Settlement

```text
Due ₹5,000
Pay ₹3,000
Expected remaining ₹2,000
```

### Test 2 — Full Settlement

```text
Due ₹5,000
Pay ₹5,000
Expected remaining ₹0
```

### Test 3 — Overpayment

```text
Due ₹5,000
Attempt ₹6,000
Expected warning/advance flow
```

### Test 4 — Wrong Area Access

```text
Osian Admin requests Jodhpur restaurant
Expected: 403
```

### Test 5 — Permission

```text
Operator without approve permission
tries approve
Expected: 403
```

### Test 6 — Proof

```text
Create settlement
Upload screenshot
Verify
Approve
Complete
```

### Test 7 — Duplicate UTR

```text
Same UTR twice
Expected: duplicate warning/rejection
```

### Test 8 — Reversal

```text
Completed ₹3,000 settlement
Reverse
Expected original remains + reversal entry
```

### Test 9 — Area Transfer

```text
Restaurant moves Osian → Jodhpur
Historical reports remain correct
```

### Test 10 — Concurrent Settlement

```text
Two admins attempt to pay same due simultaneously
Expected: one transaction succeeds according to locking/validation
No double payment
```

---

# 61. Definition of Done

The advanced Accounts/Cash Management system is DONE only when:

- [ ] Partial restaurant payment works.
- [ ] Remaining due auto-calculates.
- [ ] Full payment changes status to settled.
- [ ] Overpayment is protected.
- [ ] UTR is stored.
- [ ] Transaction/reference IDs are stored.
- [ ] Payment screenshot is stored.
- [ ] Settlement cannot be silently edited after completion.
- [ ] Restaurant settlement history is permanent.
- [ ] Payment can be allocated to orders/batches.
- [ ] Unallocated payments are visible.
- [ ] Area filtering works everywhere.
- [ ] Restaurant belongs to a proper area.
- [ ] Area history is preserved.
- [ ] Area admins have explicit scopes.
- [ ] Permissions are action-specific.
- [ ] Backend enforces area scope.
- [ ] Area admin cannot access another area's data by manipulating API parameters.
- [ ] Area admin can be limited to only payment management.
- [ ] Approval can be separated from payment creation.
- [ ] Payment limits can be configured.
- [ ] Bank details can be restricted.
- [ ] High-risk actions can require approval.
- [ ] Cash handover is reconciled.
- [ ] Daily closing can be performed.
- [ ] Due aging is available.
- [ ] Global and area reports are available.
- [ ] Every dashboard figure drills down.
- [ ] Every payment has a complete audit trail.
- [ ] Every financial action can be traced to an admin.
- [ ] Reversals never delete the original transaction.
- [ ] Automated reconciliation catches mismatches.
- [ ] No frontend-only authorization exists.

---

# 62. Final Architecture

The final financial chain should be:

```text
ORDER
 ↓
PAYMENT / COD
 ↓
RESTAURANT + RIDER + AREA
 ↓
FINANCIAL LEDGER
 ↓
RESTAURANT PAYABLE / RECEIVABLE
 ↓
SETTLEMENT
 ↓
BANK / UPI / CASH ACCOUNT
 ↓
UTR + REFERENCE
 ↓
PROOF / SCREENSHOT
 ↓
VERIFICATION
 ↓
REMAINING DUE
 ↓
RECONCILIATION
 ↓
REPORT / AUDIT
```

And access control:

```text
USER
 ↓
ROLE
 ↓
PERMISSIONS
 ↓
AREA SCOPE
 ↓
RESOURCE
 ↓
ACTION
```

For example:

```text
Imran
 ↓
AREA_PAYMENT_OPERATOR
 ↓
settlement.view
settlement.create
proof.upload
 ↓
Osian only
 ↓
Restaurant X
 ↓
Create ₹3,000 settlement
```

The system must allow this while blocking:

```text
Imran
 ↓
Jodhpur Restaurant
 ↓
Settlement
 ↓
403 Forbidden
```

---

# 63. Main Principle

Anydrop accounting should never depend on:

```text
"Admin ne paid mark kar diya"
```

It should depend on:

```text
Financial transaction created
+
Payment details recorded
+
Proof attached
+
Correct restaurant
+
Correct area
+
Correct account
+
Correct authorization
+
Ledger updated
+
Remaining due recalculated
+
Audit recorded
```

**That is the difference between a simple payment screen and a proper financial management system.**
