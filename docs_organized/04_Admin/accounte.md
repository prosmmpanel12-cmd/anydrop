# Anydrop — Complete Accounts & Cash Flow System

**File:** `accounte.md`  
**Purpose:** Anydrop ke platform par har ₹ ka exact source, destination, owner, order, restaurant, rider, payment method, settlement, refund aur current balance traceable banana.

> **Core rule:** Kisi bhi financial amount ko sirf ek mutable `balance`/`due` number se represent nahi karna hai. Har money movement ka immutable ledger entry hona chahiye. Current balances sirf derived/cache values hon.

---

# 1. Goal

Accounts module ko ye answer dena chahiye:

1. Paisa **kahan se aaya?**
2. Kis **order** se aaya?
3. Kis **restaurant** se related hai?
4. COD tha ya prepaid/UPI?
5. Agar COD tha to kis **rider** ne collect kiya?
6. Rider ne cash kab handover kiya?
7. Platform ke paas actual cash/online money kitna hai?
8. Restaurant ko kitna dena hai?
9. Restaurant ko kitna already diya?
10. Restaurant ka **current due** kitna hai?
11. Platform ne kitna commission kamaya?
12. Delivery/platform fees kitni hain?
13. Refund kitna hua aur kis payment se reverse hua?
14. Payment kis bank/UPI account se hua?
15. UTR/reference kya hai?
16. Payment ka screenshot/receipt kahan hai?
17. Kis admin ne entry create/verify ki?
18. Kisi balance mein mismatch hai ya nahi?
19. Kisi ₹ ko source se final destination tak trace kiya ja sakta hai ya nahi?

---

# 2. Existing v27 Finance Foundation

Current project mein already ye foundation hai:

- `restaurants.current_due`
- `restaurant_due_ledger`
- `restaurant_payments`
- `restaurant_bank_details`
- `platform_ledger`
- category + area based commission rules
- settlement/Pay Now flow
- UTR/reference fields
- settlement remarks/date
- audit logging
- platform ledger reconciliation check
- COD order ledger writer
- paid-order ledger writer

Existing implementation mein `current_due` ko direct update nahi karna chahiye; ledger writer ke through update karna hai.

## Important current gaps

Current source inspection ke according:

- COD automatic ledger writer ready hai, but actual delivered/cash-collected order transition se wired nahi hai.
- Paid/UPI order automatic ledger writer ready hai, but payment confirmation/webhook flow se wired nahi hai.
- Settlement screenshot column hai, but actual file upload/storage flow complete nahi hai.
- Restaurant-wise daily/weekly/monthly financial analytics incomplete hain.
- Platform reconciliation ka automated periodic checker abhi nahi hai.
- Full rider cash collection/handover accounting required hai.
- Refund/reversal accounting ko complete ledger flow chahiye.
- Payment source/account tracking ko detailed transaction level par expand karna chahiye.
- Settlement ko individual orders/batches ke against allocate karna chahiye.
- Cash-in-hand vs bank/UPI balance separate ledgers/accounts mein track karna chahiye.

---

# 3. Accounting Architecture

Recommended structure:

```text
ORDER
  |
  +--> Payment Source
  |      +--> COD
  |      +--> UPI
  |      +--> Other online payment
  |
  +--> Restaurant
  |
  +--> Rider (if delivered)
  |
  +--> Commission
  |
  +--> Platform Fees
  |
  +--> Restaurant Payable
  |
  +--> Cash/Bank/UPI Movement
  |
  +--> Settlement
  |
  +--> Refund/Reversal
  |
  +--> Ledger Entries
```

Every financial event should create ledger records.

---

# 4. Golden Accounting Rule

Never do this:

```text
restaurant.current_due = 5000
```

Never do this:

```text
platform_balance = platform_balance + 1000
```

Instead:

```text
financial transaction
        ↓
append ledger entry
        ↓
calculate running balance
        ↓
update cache if required
```

The ledger is the source of truth.

---

# 5. Separate Money Accounts

Platform ke andar virtual accounting accounts maintain karo:

```text
PLATFORM_BANK
PLATFORM_UPI
PLATFORM_CASH
RIDER_CASH_PENDING
RIDER_CASH_RECEIVED
RESTAURANT_PAYABLE
CUSTOMER_REFUND_PENDING
CUSTOMER_REFUND_PAID
PLATFORM_REVENUE
```

Future mein multiple bank accounts/UPI IDs ho sakte hain:

```text
HDFC Current Account
SBI Current Account
Anydrop UPI 1
Anydrop UPI 2
Cash Counter
```

Har payment ko exact source/destination account se link karo.

---

# 6. Transaction / Money Movement Model

Every transaction should have:

```text
transaction_id
transaction_type
direction
amount
currency
order_id
restaurant_id
rider_id
customer_id
source_account
destination_account
payment_method
payment_provider
payment_reference
utr_number
gateway_transaction_id
status
transaction_date
created_at
created_by
verified_by
remarks
```

Optional fields:

```text
screenshot_url
receipt_url
invoice_url
settlement_batch_id
refund_id
parent_transaction_id
reversal_of_transaction_id
```

---

# 7. Order-Level Financial Snapshot

Order create/complete hone ke time financial snapshot preserve hona chahiye.

Example:

```text
Order ID: AD-10025
Restaurant: ABC Restaurant

Item Total             ₹500
Discount                -₹50
Delivery Fee            ₹30
Platform Fee            ₹10
Customer Total         ₹490

Payment Method: UPI
Payment Status: Paid

Commission              ₹50
Restaurant Share       ₹440
Platform Revenue        ₹60
```

Important:

Commission rules future mein change ho sakte hain.

Isliye order ke time actual applied values snapshot karo:

```text
commission_percent
commission_amount
platform_fee
restaurant_share
delivery_fee
discount
tax
```

Historical orders ko current commission setting se recalculate nahi karna hai.

---

# 8. COD Accounting

COD order create hone par actual cash income assume nahi karni.

Correct flow:

```text
COD Order Created
        ↓
No cash ledger entry yet
        ↓
Order Delivered
        ↓
Rider collected COD cash
        ↓
COD collection ledger entry
        ↓
Rider cash pending
        ↓
Rider hands over cash
        ↓
Platform cash received
        ↓
Restaurant commission/due updated
```

## COD Order Fields

```text
order_id
restaurant_id
rider_id
cod_amount
commission_amount
restaurant_share
delivery_status
cash_collection_status
cash_collected_at
cash_handover_id
```

## COD statuses

```text
not_due
cash_expected
cash_collected
cash_handover_pending
cash_handed_over
cash_verified
short_cash
over_cash
disputed
cancelled
```

---

# 9. Rider Cash Ledger

This is a major required module.

Each rider should have a cash account:

```text
Rider: Rahul

COD Collected              ₹12,500
Cash Handed Over           ₹10,000
Pending Cash                ₹2,500
```

Every COD collection automatically links:

```text
Order → Restaurant → Rider → COD Cash
```

No manual restaurant selection required.

## Rider cash transaction

```text
cash_transaction_id
rider_id
order_id
restaurant_id
amount
type
status
collected_at
handover_id
verified_at
```

Types:

```text
cod_collection
cash_handover
cash_adjustment
short_cash
over_cash
refund_cash
```

---

# 10. Rider Cash Handover

Rider ko multiple orders ka cash ek batch mein handover karne dena.

Example:

```text
Handover #RH-20260823-001

Rider: Rahul
Orders: 12

Expected Cash:       ₹8,450
Declared Cash:       ₹8,450
Verified Cash:       ₹8,450

Difference:               ₹0

Handover Time: 8:45 PM
Verified By: Admin
```

If mismatch:

```text
Expected: ₹8,450
Received: ₹8,200
Short:      ₹250
```

Then create a separate shortage transaction.

Never silently modify the original COD order amount.

---

# 11. Restaurant Due Accounting

Restaurant balance should be derived from ledger.

Recommended meaning:

```text
positive current_due = restaurant owes Anydrop
negative current_due = Anydrop owes restaurant
zero = settled
```

Example COD:

```text
COD commission ₹100
Restaurant owes Anydrop ₹100

current_due = +100
```

Example prepaid:

```text
Customer paid ₹500
Commission ₹50
Platform fee ₹10

Restaurant share = ₹440

Anydrop owes restaurant ₹440

current_due = -440
```

Then admin pays ₹440:

```text
settlement_to_restaurant = +440

current_due = 0
```

---

# 12. Restaurant Ledger Entry Types

Use explicit entry types:

```text
commission_cod
payout_payable
platform_fee
delivery_fee_adjustment
restaurant_settlement_in
restaurant_settlement_out
refund_adjustment
cancel_adjustment
manual_adjustment
chargeback
tax_adjustment
dispute_adjustment
```

Do not overload one generic `payment_received` type for every scenario.

---

# 13. Restaurant Settlement

Admin should be able to open:

```text
Admin
 → Restaurants
 → Restaurant
 → Accounts
```

Show:

```text
Opening Due
Order Earnings
COD Commission
Platform Fees
Refunds
Adjustments
Payments Made
Payments Received
Current Due
```

---

# 14. Settlement Payment

When paying restaurant:

```text
Restaurant
Amount
Payment Method
Bank Account / UPI Account
UTR
Transaction Reference
Payment Date
Payment Time
Settlement Batch
Order IDs
Remarks
Screenshot / Receipt
Admin
```

Payment method:

```text
UPI
Bank Transfer
NEFT
IMPS
RTGS
Cash
Other
```

For UPI:

```text
UPI ID
UPI Reference
UTR
Gateway Reference
```

For bank:

```text
Bank Name
Account Number
IFSC
Beneficiary Name
UTR
```

Sensitive bank data should be masked in UI.

---

# 15. Payment Screenshot / Receipt

Actual upload must be implemented.

Do not only store:

```text
screenshot_url
```

Implement:

```text
upload
validate
store
associate
audit
view
```

Recommended metadata:

```text
file_id
payment_id
file_type
original_name
storage_path
mime_type
file_size
uploaded_by
uploaded_at
sha256
```

Allow:

- payment screenshot
- bank receipt
- UPI receipt
- settlement proof

Never overwrite old proof.

---

# 16. Settlement Batch

Instead of manually paying one order at a time, support batches.

Example:

```text
Settlement Batch: ST-20260823-001

Restaurant: ABC Restaurant

Orders:
AD1001
AD1002
AD1005
AD1011
AD1017

Gross Restaurant Payable: ₹8,750
Adjustments:               -₹250
Net Payable:               ₹8,500

Paid:                      ₹8,500
UTR:                       XXXXX
```

Every batch must remain linked to its component orders.

---

# 17. Partial Settlement

Restaurant ko full due pay karna compulsory nahi.

Example:

```text
Current Due: ₹15,000
Payment:     ₹10,000
Remaining:   ₹5,000
```

Ledger should show exactly why ₹5,000 remains.

---

# 18. Overpayment Protection

If restaurant payable is ₹5,000 and admin tries to pay ₹8,000:

Show warning:

```text
Current payable: ₹5,000
Entered payment: ₹8,000

Excess payment: ₹3,000
```

Require explicit confirmation.

Possible handling:

```text
advance_credit = ₹3,000
```

Do not silently create negative/incorrect due.

---

# 19. Platform Cash Flow

Platform ledger should answer:

```text
TOTAL MONEY IN
TOTAL MONEY OUT
NET MONEY HELD
```

But also split by source.

## Money In

```text
customer_payment_in
cod_cash_received
restaurant_settlement_in
refund_recovery
other_income
manual_cash_in
```

## Money Out

```text
restaurant_payout_out
customer_refund_out
rider_payout_out
expense_out
bank_transfer_out
cash_withdrawal
other_expense
```

## Non-cash informational entries

```text
platform_revenue
commission_accrual
restaurant_payable
```

Non-cash accounting entries must not inflate actual cash balance.

---

# 20. Cash vs Bank vs UPI

Never combine all money into one balance.

Dashboard:

```text
Platform Bank          ₹42,500
Platform UPI            ₹8,250
Physical Cash           ₹3,700
Rider Cash Pending      ₹6,400
--------------------------------
Actual Platform Funds  ₹54,450
Expected Rider Cash     ₹6,400
```

Restaurant payable should be shown separately:

```text
Restaurant Payable     ₹31,250
```

Do not call payable money "profit".

---

# 21. Daily Cash Closing

At end of day:

```text
Opening Balance
+ Cash In
- Cash Out
= Expected Closing Balance
```

For physical cash:

```text
Expected Cash: ₹12,500
Actual Cash:   ₹12,450
Difference:       -₹50
```

Require admin to record reason for mismatch.

---

# 22. Bank Reconciliation

Admin should be able to upload/import bank statement later.

Match:

```text
UTR
Amount
Date
Reference
```

System should identify:

```text
Matched
Unmatched
Amount mismatch
Duplicate
Possible match
```

Example:

```text
Bank transaction ₹8,500
UTR ABC123

Matched settlement ST-1005
Restaurant: ABC Restaurant
```

---

# 23. UPI Reconciliation

Same concept for UPI.

Track:

```text
UPI ID
UPI transaction ID
UTR
Amount
Timestamp
Sender
Receiver
Status
```

If gateway/API provides webhook data, automatically reconcile it.

---

# 24. Customer Payment Accounting

For prepaid order:

```text
Customer pays ₹500
        ↓
Payment verified
        ↓
Platform cash/bank +₹500
        ↓
Restaurant payable created
        ↓
Commission recognized
        ↓
Platform revenue recognized
```

Do not create restaurant payable before payment is genuinely confirmed.

---

# 25. Payment Lifecycle

Use:

```text
initiated
pending
authorized
paid
failed
expired
refunded
partially_refunded
chargeback
```

Only the appropriate confirmed states should affect cash ledger.

---

# 26. Payment Idempotency

Webhook/payment confirmation can arrive multiple times.

Never create duplicate money entries.

Use:

```text
provider_transaction_id UNIQUE
gateway_reference UNIQUE
idempotency_key UNIQUE
```

Before writing ledger:

```text
Is this financial event already posted?
```

If yes:

```text
return existing transaction
```

Do not create another ₹500 entry.

---

# 27. Refund Accounting

Refund must reference original payment.

Example:

```text
Original Payment:
₹500
Transaction: TX100

Refund:
₹500
Transaction: RF100
Parent: TX100
```

Partial refund:

```text
Original: ₹500
Refund:   ₹200
Remaining refundable: ₹300
```

Restaurant/platform ledger must also reverse the correct financial allocation.

---

# 28. Cancellation Accounting

Cancellation timing matters.

Examples:

### Before payment

```text
No financial movement
```

### Paid but not accepted

```text
Payment reversal/refund
Restaurant payable should not remain active
```

### Restaurant accepted then cancelled

```text
Apply cancellation/refund policy
Create reversal entries
```

Never delete old ledger entries.

Use reversal entries.

---

# 29. Reversal Rule

Never delete a wrong financial transaction.

Wrong entry:

```text
+₹500
```

Correct it with:

```text
-₹500 reversal
+₹450 corrected entry
```

Both remain visible.

---

# 30. Manual Adjustment

Admin may need manual adjustment.

Required fields:

```text
Amount
Direction
Reason
Restaurant
Order (optional)
Account
Remarks
Evidence
Admin
Approval
```

High-value manual adjustments should require second-admin approval.

---

# 31. Dual Approval

Recommended for:

- large restaurant payouts
- manual balance adjustments
- refund above configurable threshold
- cash shortage write-off
- bank account changes
- suspicious settlement changes

Workflow:

```text
Created
 → Pending Approval
 → Approved
 → Executed
```

Admin who creates should not necessarily be allowed to approve their own high-risk adjustment.

---

# 32. Restaurant Bank Account Security

Store:

```text
beneficiary_name
account_number
ifsc
bank_name
upi_id
```

UI:

```text
XXXX XXXX 1234
```

Account changes should be audited:

```text
old account
new account
changed by
changed at
reason
```

Optional cooldown before payout after bank details change.

---

# 33. Restaurant Statement

Restaurant account page should show:

```text
Date
Type
Order
Description
Credit
Debit
Running Balance
```

Example:

```text
23 Aug
AD1001
Online payout          ₹450
Balance               -₹450

23 Aug
AD1002
COD commission          ₹50
Balance               -₹400

23 Aug
Settlement              ₹400
Balance                  ₹0
```

Export:

```text
PDF
CSV
Excel
```

---

# 34. Platform Statement

Admin should see all money movements.

Filters:

```text
Date range
Restaurant
Rider
Order
Payment method
Transaction type
Account
Status
Admin
UTR
```

Search:

```text
Order ID
UTR
Transaction ID
Restaurant name
Rider name
```

---

# 35. Order Financial Timeline

Every order should have a financial timeline.

Example:

```text
10:01 Order created
10:02 UPI payment initiated
10:02 Payment confirmed
10:02 ₹650 added to platform account
10:02 ₹570 restaurant payable created
10:02 ₹80 platform revenue recognized
10:35 Order delivered
11:00 Restaurant payout batch created
18:00 ₹570 paid to restaurant
18:00 UTR recorded
```

This is extremely useful for disputes.

---

# 36. Restaurant Cash Flow Report

For every restaurant:

```text
Gross Order Value
- Customer Discounts
+ Delivery Fees
+ Platform Fees
- Commission
- Refunds
+/- Adjustments
= Restaurant Payable

Payments Made
- Advances
+ Amounts Received From Restaurant
= Current Due
```

The exact formula must be based on the actual business pricing model and stored order-level snapshots.

---

# 37. Revenue Report

Platform revenue should be separated:

```text
Commission Revenue
Delivery Revenue
Platform Fees
Cancellation Fees
Other Revenue
```

Do not count restaurant payable or customer money held as revenue.

---

# 38. GST / Tax Reporting Foundation

If applicable, store tax components separately:

```text
taxable_amount
cgst
sgst
igst
tax_amount
tax_type
```

Do not bury tax inside a generic amount.

Tax reporting should be generated from immutable transaction/order snapshots.

---

# 39. Expense Accounting

Platform expenses should have their own ledger.

Examples:

```text
Rider payout
Marketing
SMS
Hosting
Payment gateway fee
Refund processing
Office expense
Cash withdrawal
Bank charges
Other
```

Fields:

```text
expense_id
category
amount
account
date
vendor
reference
receipt
created_by
approved_by
remarks
```

---

# 40. Payment Gateway Fees

If gateway deducts ₹10 from ₹500:

```text
Customer payment          ₹500
Gateway fee                ₹10
Net bank settlement       ₹490
```

Accounting must show both.

Do not record only ₹490 without explaining the ₹10 difference.

---

# 41. Rider Earnings Accounting

Rider accounting should be separate from rider cash collection.

Example:

```text
Rider delivery earnings       ₹2,000
COD cash collected            ₹8,500
Cash handed over              ₹8,500
Rider payout due              ₹2,000
```

COD cash is not rider income.

This distinction is critical.

---

# 42. Rider Payout

Rider payout should support:

```text
delivery earnings
incentives
bonuses
penalties
adjustments
previous advances
net payable
payment method
UTR
receipt
```

Separate rider payable ledger:

```text
rider_due_ledger
rider_payments
```

---

# 43. Customer Wallet

If Anydrop later adds wallet/cashback:

Do not use only:

```text
wallet.balance
```

Use:

```text
wallet_transactions
```

Types:

```text
cashback
refund
wallet_topup
order_debit
admin_adjustment
expiry
```

Every transaction gets running balance.

---

# 44. Accounting Period Lock

Once a month/day is closed:

```text
2026-08-23 CLOSED
```

Do not allow silent edits to old financial records.

Corrections must create adjustment/reversal entries.

---

# 45. Audit Log

Every sensitive action:

```text
who
what
when
before
after
IP/device if available
reason
```

Audit:

- settlement creation
- settlement rejection
- UTR change
- screenshot change
- bank account change
- manual adjustment
- refund
- cash shortage
- cash handover verification
- commission rule change
- expense
- account transfer

---

# 46. Financial Event ID

Every money event should have a globally unique human-readable ID:

```text
TXN-20260823-000001
```

Other IDs:

```text
SET-...
REF-...
COD-...
HAND-...
EXP-...
ADJ-...
```

Internal numeric DB IDs can remain unchanged.

---

# 47. Parent / Child Transaction Linking

Financial records must be traceable.

Example:

```text
Payment TX100
 └── Order AD1001
      ├── Commission TX101
      ├── Restaurant Payable TX102
      └── Settlement SET100
            └── UTR XYZ123
```

Refund:

```text
Refund RF100
 └── reverses Payment TX100
```

This allows complete tracing.

---

# 48. Reconciliation Engine

Create automated reconciliation jobs.

Checks:

### Restaurant

```text
current_due
==
SUM(restaurant_due_ledger.amount)
```

### Platform

```text
platform running balance
==
opening balance + all cash movements
```

### Rider

```text
expected COD cash
==
cash collected
```

### Settlement

```text
payment amount
==
ledger settlement amount
```

### Order

```text
customer payment
==
order financial snapshot
```

Any mismatch should create an alert.

---

# 49. Daily Automated Reconciliation

Every night:

```text
1. Recalculate restaurant balances
2. Compare current_due
3. Recalculate platform cash
4. Compare rider pending cash
5. Find unmatched UTRs
6. Find duplicate transactions
7. Find orphan ledger entries
8. Find missing order ledger entries
9. Find negative/invalid balances
10. Generate reconciliation report
```

---

# 50. Exception Dashboard

Admin should see:

```text
🔴 3 Cash Mismatches
🟠 5 Unmatched Payments
🟠 2 Missing COD Ledger Entries
🔴 1 Duplicate UTR
🟡 4 Pending Restaurant Settlements
🟡 2 Rider Handovers Pending
```

Clicking an exception should open the exact related order/transaction.

---

# 51. Accounting Dashboard

Main dashboard:

```text
TODAY

Orders                 125
GMV                  ₹42,500

Customer Payments    ₹25,000
COD Collected        ₹17,500
Total Cash In        ₹42,500

Restaurant Payable   ₹35,200
Restaurant Paid      ₹20,000

Rider Cash Pending    ₹6,500

Platform Revenue      ₹7,300

Refunds               ₹1,250

Net Cash Held        ₹21,250
```

Do not confuse:

```text
GMV
Cash In
Revenue
Profit
Payables
Cash Held
```

These are different metrics.

---

# 52. Cash Flow Filters

Allow:

```text
Today
Yesterday
7 Days
30 Days
This Month
Last Month
Custom Range
```

And:

```text
All
COD
UPI
Bank
Cash
Restaurant
Rider
Customer
Expense
Refund
```

---

# 53. Reports

Required reports:

1. Daily Cash Flow
2. Restaurant Settlement Report
3. Restaurant Due Report
4. COD Collection Report
5. Rider Cash Report
6. Rider Settlement Report
7. Prepaid/Online Payment Report
8. UPI Reconciliation
9. Bank Reconciliation
10. Refund Report
11. Commission Report
12. Platform Revenue Report
13. Expense Report
14. GST/Tax Report
15. Outstanding Payables
16. Outstanding Receivables
17. Manual Adjustments
18. Audit Report
19. Exception/Mismatch Report

---

# 54. Restaurant-wise Cash Flow

Example:

```text
ABC Restaurant

Order Sales                  ₹25,000

Online Customer Payments    ₹15,000
COD Sales                   ₹10,000

Commission                  ₹2,500
Platform Fees                 ₹500

Restaurant Gross Payable    ₹22,000

Already Settled             ₹15,000

Current Payable              ₹7,000
```

Clicking every number should open the underlying orders.

---

# 55. Drill-Down Requirement

Every dashboard number should be clickable.

Example:

```text
COD Collected ₹17,500
```

Click:

```text
Order AD1001    ABC Restaurant    ₹500    Rider Rahul
Order AD1002    XYZ Restaurant    ₹700    Rider Imran
...
```

Then order → financial timeline.

---

# 56. No Hidden Money Movement

Every financial change must have:

```text
source
destination
amount
reason
reference
timestamp
actor
```

If any money cannot be explained through these fields, accounting is incomplete.

---

# 57. Data Integrity Constraints

Use DB constraints where possible:

```text
amount > 0
UTR uniqueness where applicable
foreign keys
transaction references
status enums
unique provider transaction IDs
unique idempotency keys
```

Use database transactions for multi-table financial writes.

---

# 58. Atomic Financial Operations

These must be atomic:

```text
Payment confirmation
COD completion
Cash handover
Restaurant settlement
Rider settlement
Refund
Chargeback
Manual adjustment
```

If one part fails, the complete financial operation rolls back.

Never allow:

```text
payment row inserted
ledger row missing
```

or:

```text
ledger row inserted
balance not updated
```

---

# 59. Concurrency Protection

Two admins should not be able to simultaneously pay the same due.

Use:

```text
SELECT ... FOR UPDATE
```

or equivalent transaction locking.

Before settlement:

```text
lock restaurant account
recalculate current due
validate amount
create settlement
update ledger
commit
```

---

# 60. Duplicate Settlement Protection

Before creating a settlement:

```text
Check:
UTR
payment reference
amount
restaurant
date
```

If suspicious duplicate:

```text
⚠ Possible duplicate payment
```

Require confirmation or reject.

---

# 61. Negative Balance Rules

Negative restaurant balance can be legitimate when:

```text
Anydrop owes restaurant
```

But unexpected values should be explainable.

Do not use:

```text
ABS(current_due)
```

to hide direction.

Always show:

```text
Receivable from Restaurant
OR
Payable to Restaurant
```

---

# 62. Opening Balance

When migrating existing business data, support:

```text
opening_balance
opening_balance_date
opening_balance_reason
created_by
evidence
```

Opening balance must itself be a ledger entry.

Never manually set a starting balance without a record.

---

# 63. Import / Migration

If old settlements exist:

```text
Import old payments
Import opening balances
Map restaurant
Map date
Map UTR
Mark source = migration
```

Do not fake historical order transactions if the original order-level data does not exist.

---

# 64. Accounting Export

CSV/Excel should include:

```text
Transaction ID
Date
Type
Order ID
Restaurant
Rider
Customer
Payment Method
Source Account
Destination Account
Amount
Commission
Platform Fee
Restaurant Share
UTR
Status
Created By
Verified By
Remarks
```

---

# 65. PDF Statement

Restaurant PDF:

```text
Restaurant Details
Statement Period
Opening Balance
Order Transactions
Commission
Fees
Refunds
Adjustments
Settlements
Closing Balance
```

Include:

```text
Generated At
Generated By
Statement ID
```

---

# 66. Permissions

Recommended permissions:

```text
accounts_view
accounts_export

restaurant_ledger_view
restaurant_settlement_create
restaurant_settlement_approve

rider_cash_view
rider_cash_verify
rider_settlement_create

refund_create
refund_approve

manual_adjustment_create
manual_adjustment_approve

expense_create
expense_approve

bank_details_view
bank_details_edit

reconciliation_view
reconciliation_run
```

Super Admin can control permissions.

---

# 67. Admin UI Structure

Recommended navigation:

```text
Admin
├── Accounts Dashboard
├── Cash Flow
├── Transactions
├── Restaurant Accounts
│   ├── Due
│   ├── Statements
│   ├── Settlements
│   └── Bank Details
├── Rider Accounts
│   ├── COD Cash
│   ├── Handovers
│   └── Payouts
├── Customer Payments
├── Refunds
├── Expenses
├── Bank / UPI
├── Reconciliation
├── Exceptions
├── Reports
└── Audit Log
```

---

# 68. Restaurant Account Page

Top cards:

```text
Current Due
Total Orders
Gross Sales
Commission
Online Collected
COD Collected
Total Paid
```

Tabs:

```text
Overview
Orders
Ledger
Settlements
Bank Details
Refunds
Adjustments
```

---

# 69. Rider Account Page

Show:

```text
COD Collected
Cash Pending
Cash Handed Over
Short Cash
Over Cash
Delivery Earnings
Bonuses
Penalties
Net Rider Payable
Paid
```

Tabs:

```text
Orders
Cash Collection
Handovers
Ledger
Payouts
```

---

# 70. Transaction Details Page

Every transaction should show:

```text
Transaction ID
Status
Amount
Date/Time

Source
Destination

Order
Restaurant
Rider
Customer

Payment Method
UTR
Gateway Reference

Parent Transaction
Child Transactions

Attachments

Created By
Verified By

Audit Timeline
```

---

# 71. Accounting Status Rules

Example:

```text
Order Created
→ no revenue/cash unless payment confirmed

UPI Paid
→ customer payment + platform ledger

COD Delivered
→ rider cash expected/collected

COD Handover
→ platform cash received

Restaurant Settlement
→ platform cash out + restaurant due reduction

Refund
→ reversal of original financial allocation
```

---

# 72. Critical Missing Features for Current v27

These should be treated as implementation tasks:

- [ ] Wire COD ledger writer to actual delivered/cash-collected transition.
- [ ] Wire prepaid ledger writer to verified payment/webhook.
- [ ] Build real payment provider/webhook verification.
- [ ] Add rider COD cash ledger.
- [ ] Add rider cash handover batches.
- [ ] Add cash shortage/overage handling.
- [ ] Add platform bank/UPI/cash account separation.
- [ ] Complete settlement screenshot upload/storage.
- [ ] Add settlement batch/order allocation.
- [ ] Add partial settlement support.
- [ ] Add overpayment/advance handling.
- [ ] Add refund/reversal ledger flow.
- [ ] Add payment idempotency.
- [ ] Add duplicate UTR/reference protection.
- [ ] Add automated reconciliation.
- [ ] Add financial exception dashboard.
- [ ] Add restaurant financial analytics.
- [ ] Add rider financial analytics.
- [ ] Add expense ledger.
- [ ] Add gateway fee accounting.
- [ ] Add bank/UPI reconciliation.
- [ ] Add account-level cash balances.
- [ ] Add complete transaction detail/drill-down.
- [ ] Add CSV/Excel/PDF statements.
- [ ] Add accounting permissions.
- [ ] Add high-risk dual approval.
- [ ] Add accounting period close/lock.
- [ ] Add opening balance/migration support.
- [ ] Add complete audit trail for sensitive changes.
- [ ] Add financial event IDs and parent/child transaction linking.

---

# 73. Recommended Database Layer

Minimum recommended financial tables:

```text
restaurant_due_ledger
restaurant_payments
restaurant_bank_details

platform_ledger

rider_cash_ledger
rider_cash_handovers
rider_due_ledger
rider_payments

payment_transactions
payment_accounts

refund_transactions
refund_allocations

settlement_batches
settlement_batch_orders

expenses
expense_attachments

account_reconciliation
reconciliation_exceptions

financial_attachments
financial_audit_log
```

Existing tables should be reused where possible instead of duplicating them.

---

# 74. Important: Do Not Duplicate Order Data

Restaurant/order relationship already comes from the order.

Accounting should reference:

```text
order_id
restaurant_id
rider_id
```

Do not ask admin:

```text
Which restaurant was this COD from?
```

for normal automatic entries.

The system should derive it from the order.

Manual selection should exist only for genuine manual adjustments.

---

# 75. Example — Complete COD Flow

```text
Customer places COD order
AD1001
Restaurant ABC
₹700

No cash received yet.

Order delivered by Rider Rahul.

COD collected:
₹700

Ledger:
Rider Cash Pending +₹700
Restaurant COD Commission +₹70
Restaurant due +₹70

Rider hands over ₹700.

Ledger:
Rider Cash Pending -₹700
Platform Cash +₹700

Restaurant current due:
+₹70
```

Later admin pays restaurant ₹70:

```text
Platform Bank -₹70
Restaurant Settlement +₹70
Restaurant current_due → ₹0
UTR recorded
Screenshot stored
```

Complete trail:

```text
AD1001
 → ABC Restaurant
 → Rahul
 → COD ₹700
 → Platform Cash ₹700
 → Commission ₹70
 → Restaurant Payable ₹630 if business model requires it
 → Settlement
 → UTR
```

The exact restaurant-share formula must follow the finalized commercial model.

---

# 76. Example — Complete Prepaid Flow

```text
Customer pays ₹700 online.

Payment verified.

Platform bank/UPI:
+₹700

Restaurant payable:
restaurant share calculated from order snapshot

Platform revenue:
commission + applicable platform fees

Later settlement:
Platform bank
-₹restaurant_share

Restaurant due:
reduced toward zero
```

Payment gateway fee, taxes, refunds and discounts must be represented separately where applicable.

---

# 77. Example — Refund

```text
Order: AD1001
Customer paid ₹700

Refund ₹700

Original payment:
+₹700

Refund:
-₹700

Restaurant payable reversal:
appropriate negative/reversal entry

Platform revenue:
reverse only the amount actually recognized
```

Never simply delete the original payment.

---

# 78. Example — Full Trace

Admin searches:

```text
UTR: ABC123
```

System returns:

```text
Settlement ST100
₹5,000
ABC Restaurant
23 Aug 2026

Related:
Orders: 12
Restaurant Due Before: ₹8,200
Settlement: ₹5,000
Restaurant Due After: ₹3,200

Bank Account:
XXXX1234

Screenshot:
payment_receipt.jpg

Created By:
Admin A
```

Admin can click:

```text
Settlement
→ Restaurant
→ Orders
→ Individual Order
→ Payment
→ Ledger
```

---

# 79. Financial Truth Hierarchy

Use this priority:

```text
1. Immutable transaction/ledger records
2. Order/payment snapshots
3. Reconciliation records
4. Cached balances
5. Dashboard aggregates
```

If a dashboard and ledger disagree:

```text
ledger wins
```

Then reconciliation fixes the cache/report.

---

# 80. Implementation Order

Recommended sequence:

## Phase A — Core Financial Events

- [ ] Payment transaction table
- [ ] Financial transaction IDs
- [ ] Idempotency
- [ ] Order payment confirmation
- [ ] COD delivered trigger
- [ ] Restaurant ledger integration

## Phase B — Rider Cash

- [ ] Rider cash ledger
- [ ] COD collection
- [ ] Cash handover
- [ ] Short/over cash
- [ ] Rider reconciliation

## Phase C — Restaurant Settlement

- [ ] Settlement batches
- [ ] Order allocation
- [ ] Partial settlement
- [ ] Overpayment protection
- [ ] Screenshot upload
- [ ] UTR/reference validation

## Phase D — Refunds

- [ ] Full refund
- [ ] Partial refund
- [ ] Reversal entries
- [ ] Refund reconciliation

## Phase E — Platform Accounts

- [ ] Bank account ledger
- [ ] UPI account ledger
- [ ] Physical cash ledger
- [ ] Gateway fee accounting
- [ ] Expense ledger

## Phase F — Reconciliation

- [ ] Bank reconciliation
- [ ] UPI reconciliation
- [ ] Rider reconciliation
- [ ] Restaurant reconciliation
- [ ] Automated mismatch detection

## Phase G — Reports

- [ ] Dashboard
- [ ] Statements
- [ ] Cash flow
- [ ] Revenue
- [ ] Commission
- [ ] Refund
- [ ] Expense
- [ ] Tax
- [ ] CSV/Excel/PDF

## Phase H — Security

- [ ] Permissions
- [ ] Audit trail
- [ ] Dual approval
- [ ] Period locking
- [ ] Bank-change protection

---

# 81. Definition of Done

Accounts module tabhi `DONE` maana jayega jab:

- [ ] Every prepaid payment automatically creates the correct financial entries.
- [ ] Every completed COD order automatically creates the correct COD/rider entries.
- [ ] Every COD cash handover reconciles rider cash.
- [ ] Every restaurant due is ledger-derived.
- [ ] Every restaurant payment creates linked settlement + ledger + platform cash entries.
- [ ] Every payment has UTR/reference where applicable.
- [ ] Payment screenshot/receipt is actually stored.
- [ ] Every refund creates reversal entries.
- [ ] Duplicate webhooks cannot duplicate money.
- [ ] Duplicate UTRs are detected.
- [ ] Platform bank/UPI/cash balances are separately visible.
- [ ] Restaurant, rider and platform statements are available.
- [ ] Every dashboard number can be drilled down to transactions/orders.
- [ ] Automated reconciliation finds mismatches.
- [ ] No financial record is silently deleted/overwritten.
- [ ] Manual adjustments are audited.
- [ ] High-risk adjustments can require approval.
- [ ] Old periods can be locked.
- [ ] CSV/Excel/PDF exports work.
- [ ] Full cash-flow trail can be followed from source to destination.

---

# 82. Final Business Principle

Anydrop Accounts ka objective sirf:

```text
Restaurant Due = ₹X
```

dikhana nahi hai.

Objective hona chahiye:

```text
₹X exactly kis order se aaya
        ↓
kis customer ne diya
        ↓
COD tha ya prepaid
        ↓
kis restaurant ka tha
        ↓
kis rider ne deliver/collect kiya
        ↓
cash kisne hold kiya
        ↓
cash kab platform ko mila
        ↓
commission kitna tha
        ↓
restaurant ko kitna payable hua
        ↓
restaurant ko kab kitna diya
        ↓
kis bank/UPI account se diya
        ↓
UTR kya tha
        ↓
receipt/screenshot kya hai
        ↓
ab remaining due kitna hai
```

**Agar ek ₹ bhi is chain mein unexplained reh jata hai, Accounts module complete nahi maana jayega.**

---

# 83. Developer Rule

Claude Code / developer ko Accounts module mein:

1. Existing order, restaurant, rider aur payment data reuse karna hai.
2. Duplicate restaurant/order selection avoid karna hai.
3. Financial writes transaction-safe hone chahiye.
4. Ledger append-only hona chahiye.
5. Wrong entry ko delete nahi, reverse karna hai.
6. Current balances cache hain, source of truth ledger hai.
7. Every automatic money movement must have an exact trigger.
8. Every trigger must be idempotent.
9. Every settlement must be traceable to restaurant + payment + UTR.
10. Every COD collection must be traceable to order + restaurant + rider.
11. Every dashboard total must be drill-downable.
12. Any financial mismatch must be visible to Admin.
13. No hardcoded business rule for commission/payment/accounting.
14. Existing working code ko unnecessarily replace nahi karna.
15. Before marking an accounting item DONE, test the complete flow end-to-end.

---

# 84. Current v27 Priority

**Highest priority first:**

```text
1. Payment confirmation/webhook
2. COD delivered/cash-collected trigger
3. Rider cash ledger + handover
4. Restaurant automatic due
5. Settlement screenshot upload
6. Settlement batch/order allocation
7. Refund/reversal
8. Platform bank/UPI/cash accounts
9. Reconciliation engine
10. Reports + drill-down
11. Expenses
12. Advanced approval/security
```

This document should be treated as the master Accounts/Accounting specification for future Anydrop development.
