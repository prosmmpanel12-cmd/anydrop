# UpiPe SDK — PHP UPI Payment Gateway

**by YourApis** · `yourapi.42web.io` · SDK v2.2

---

## What is this?

UpiPe is a PHP SDK that adds UPI payments to your website in 5 minutes.  
Customer scans QR → Pays → Server auto-verifies. If auto-verify doesn't detect the payment,  
the customer can submit their UTR manually after 5 minutes.

```
API Base URL: https://yourapi.42web.io/api/upi/api/
```

---

## Folder Structure

```
sdk/
├── UpiPeSDK/
│   └── UpiPe.php                     ← Include this in your project
│
└── examples/
    ├── 01_basic_order.php            ← Password / content unlock after pay
    ├── 02_subscription_auto.php      ← Monthly membership with auto polling
    ├── 03_download_after_pay.php     ← E-book / file download after pay
    └── 04_wallet_fund_add.php        ← Wallet balance top-up
```

---

## Quick Start (5 Minutes)

### Step 1 — Include SDK

```php
require 'UpiPeSDK/UpiPe.php';
$upi = new UpiPe('YOUR_API_KEY');
```

### Step 2 — Create Order

```php
$order = $upi->createOrder(
    amount:     499,
    customerId: 'user_123',
    note:       'Premium Access'
);

// $order['order_id']     → 'QRP3A9F1C...'  — save this!
// $order['qr_url']       → QR code image URL
// $order['upi_link']     → 'upi://pay...'  — mobile button
// $order['deep_links']   → { gpay, phonepe, paytm }
// $order['expires_in_sec'] → 1800 (30 min)
```

### Step 3 — Show QR to Customer

```html
<img src="<?= $order['qr_url'] ?>">
<a href="<?= $order['deep_links']['gpay'] ?>">Pay with GPay</a>
<a href="<?= $order['deep_links']['phonepe'] ?>">Pay with PhonePe</a>
<a href="<?= $order['deep_links']['paytm'] ?>">Pay with Paytm</a>
```

### Step 4 — Verify Payment (Poll every 5 sec via AJAX)

```php
$verify = $upi->verifyPayment($order['order_id']);

switch ($verify['status']) {
    case 'paid':
    case 'already_paid':
        // ✅ Payment done — unlock content
        activatePremium($userId);
        break;

    case 'not_paid':
        // ⏳ Not yet detected by auto-verify
        // Check $verify['manual_utr_available']:
        //   false → keep polling, show countdown timer
        //   true  → show UTR input field to customer
        break;

    case 'manual_not_allowed':
        // ⏳ Customer tried UTR before 5 min
        // Show: "Please wait {manual_allowed_in} more seconds"
        break;

    case 'manual_pending':
        // 🕐 UTR submitted, merchant reviewing
        break;

    case 'expired':
        // ❌ 30 min passed — ask customer to create new order
        break;
}
```

---

## Verification Flow

```
Order Created
      │
      ▼
   0 min ──────────────────────── Auto Verify (Paytm) ──── loops forever
      │                                    │
      │                                    ▼
      │                             TXN_SUCCESS?
      │                            YES ──► PAID ✅
      │                            NO  ──► not_paid
      │                                    │
   5 min ──────────────────────────────────┤
      │                                    │
      │                        manual_utr_available = true
      │                                    │
      │                    Customer can now submit UTR
      │                                    │
      ▼                                    ▼
  (parallel)              UTR submitted ──► MANUAL_PENDING
                                           │
                               Merchant approves/rejects
                                           │
                                    PAID ✅ / REJECTED ❌
```

**Key rules:**
- Auto-verify runs indefinitely — no attempt limit
- UTR submission only allowed after 5 minutes from order creation
- Status stays `PENDING` (not `MANUAL_PENDING`) until UTR is actually submitted
- Both paths (auto + manual) run in parallel — whichever succeeds first wins

---

## SDK Methods

### `createOrder(amount, customerId, note, mode)`

| Parameter    | Type   | Default     | Description                          |
|--------------|--------|-------------|--------------------------------------|
| `amount`     | float  | —           | Amount in ₹ (min ₹1, max ₹1,00,000) |
| `customerId` | string | —           | Your user/order ID                   |
| `note`       | string | `'Payment'` | Note shown to customer               |
| `mode`       | string | `'auto'`    | `'auto'` or `'manual'`               |

**Response:**

| Field           | Description                          |
|-----------------|--------------------------------------|
| `status`        | `'success'` or `'error'`             |
| `order_id`      | Unique order ID — use in all calls   |
| `qr_url`        | QR code image URL                    |
| `upi_link`      | UPI deep link string                 |
| `deep_links`    | `{ gpay, phonepe, paytm }`           |
| `mode`          | `'auto'` or `'manual'`               |
| `expires_in_sec`| `1800` (30 minutes)                  |
| `expires_at`    | Expiry datetime                      |

---

### `verifyPayment(orderId, utr?)`

```php
// Auto verify (call every 5 sec):
$result = $upi->verifyPayment('QRP...');

// Manual verify (after 5 min, with customer's UTR):
$result = $upi->verifyPayment('QRP...', '123456789012');
```

**Status values:**

| Status                | What it means                                        | Action                               |
|-----------------------|------------------------------------------------------|--------------------------------------|
| `paid`                | ✅ Payment confirmed                                 | Unlock / deliver product             |
| `already_paid`        | ✅ Already verified earlier                          | Skip duplicate action                |
| `not_paid`            | ⏳ Not detected yet                                  | Check `manual_utr_available` field   |
| `manual_pending`      | 🕐 UTR submitted, awaiting merchant review           | Poll until paid/rejected             |
| `manual_not_allowed`  | ⏳ UTR submitted too early (< 5 min)                 | Show countdown: `manual_allowed_in`  |
| `utr_required`        | 📝 Manual-only order — ask for UTR                   | Show UTR input field                 |
| `pending`             | ⏳ Manual-only order, 5 min not yet passed           | Show countdown: `manual_allowed_in`  |
| `expired`             | ❌ 30 min passed without payment                     | Ask customer to create new order     |
| `rejected`            | ❌ Merchant rejected the UTR                         | Ask customer to contact support      |

**Extra fields in `not_paid` response:**

| Field                  | Type    | Description                                      |
|------------------------|---------|--------------------------------------------------|
| `manual_utr_available` | bool    | `true` = 5 min over, show UTR input to customer  |
| `manual_allowed_in`    | int     | Seconds until UTR option unlocks (when false)    |
| `auto_attempts`        | int     | How many times auto-verify has been tried        |
| `gateway_status`       | string  | Raw Paytm gateway status                         |

---

### `getOrderStatus(orderId)`

Fetch current DB status without triggering a verify call.

```php
$status = $upi->getOrderStatus('QRP...');
// Returns: { order: { order_id, status, amount, utr, created_at, ... } }
```

---

### `getManualOrders(status?)`

List orders for merchant dashboard.

```php
$orders = $upi->getManualOrders('MANUAL_PENDING');
// status options: MANUAL_PENDING | PAID | REJECTED | PENDING | EXPIRED | ALL
```

---

### `manualAction(orderId, action, reason?)`

Approve or reject a manual (UTR-submitted) order.

```php
$upi->manualAction('QRP...', 'approve');
$upi->manualAction('QRP...', 'reject', 'UTR does not match our records');
```

---

### `isPaid(orderId)` — Boolean Helper

```php
// Simple polling loop (blocking):
while (!$upi->isPaid($orderId)) {
    sleep(5);
}
// Payment confirmed — proceed
```

---

### `waitForPayment(orderId, intervalSec?, maxWaitSec?, utrCallback?)` — Blocking Wait

For background scripts or CLI — automatically handles the full flow.

```php
// Basic (auto-verify only):
$result = $upi->waitForPayment($orderId, 5, 1800);

// With UTR fallback after 5 min:
$result = $upi->waitForPayment(
    orderId:     $orderId,
    intervalSec: 5,
    maxWaitSec:  1800,
    utrCallback: function(array $lastResponse): ?string {
        // Called when manual_utr_available becomes true
        // Fetch UTR from your DB, queue, or any source
        return getUtrFromQueue($orderId); // return null if not available yet
    }
);

if ($result['status'] === 'paid') {
    // Done!
}
```

---

## Auto vs Manual Mode

| | Auto Mode | Manual Mode |
|--|-----------|-------------|
| **When to use** | Paytm MID configured | Any UPI app, no MID needed |
| **How it works** | Paytm API auto-detects payment | Customer submits UTR → merchant approves |
| **Speed** | 10–30 seconds | 2–10 minutes |
| **Fallback** | After 5 min, UTR option also unlocks | UTR required from the start |
| **mode param** | `'auto'` (default) | `'manual'` |

> **Best practice:** Use `'auto'` mode always. The UTR fallback activates automatically at 5 min.  
> Use `'manual'` only when you have no Paytm MID and want to skip auto-verify entirely.

---

## Frontend Polling Pattern (JavaScript)

```javascript
// Poll every 5 seconds
const interval = setInterval(async () => {
    const res = await fetch('/check_status.php?order_id=' + orderId);
    const data = await res.json();

    if (data.status === 'paid' || data.status === 'already_paid') {
        clearInterval(interval);
        showSuccess();

    } else if (data.status === 'not_paid') {
        if (data.manual_utr_available) {
            // 5 min ho gaye — show UTR input
            showUtrInput();
        } else {
            // Show countdown
            showCountdown(data.manual_allowed_in);
        }

    } else if (data.status === 'manual_pending') {
        showMessage('UTR submitted — awaiting verification...');

    } else if (data.status === 'expired') {
        clearInterval(interval);
        showExpired();
    }
}, 5000);
```

---

## Security Rules

```php
// ✅ Correct — always verify server-side
$result = $upi->verifyPayment($orderId);
if ($result['status'] === 'paid') { ... }

// ❌ Wrong — never trust frontend
if ($_POST['paid'] === 'true') { ... }  // easily faked!
```

1. Never put API key in JavaScript or frontend HTML
2. Always call `verifyPayment()` from server-side PHP only
3. Handle `already_paid` — prevent duplicate credits
4. Verify amount: `$result['amount'] >= $expectedAmount`
5. Use HTTPS in production

---

## First-Time Setup

```bash
# 1. Run the SQL schema
mysql -u root -p yourdb < config/schema.sql

# 2. Configure database
nano config/db.php

# 3. Login to panel
# https://yoursite.com/panel/login.php
# (Only API Key needed — no password)

# 4. Save your UPI ID in Settings (REQUIRED)
# Panel → Settings → UPI ID

# 5. Optional: Set Paytm MID for auto-verify
# Panel → Settings → Paytm MID
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| `"UPI ID not configured"` | Panel → Settings → Save UPI ID |
| Auto verify not working | Set Paytm MID in settings, or use `mode='manual'` |
| `"Order not found"` | Wrong order_id, or belongs to different API key |
| `"Invalid UTR"` | Must be exactly 12 digits (bank reference number) |
| `"manual_not_allowed"` | Wait 5 minutes from order creation before submitting UTR |
| Order expired | 30-min window passed — create a new order |
| cURL error | Check server internet connection and SSL config |
| `"UTR already used"` | That UTR is linked to a different order |

---

## Support

- **Dashboard:** `yourapi.42web.io`
- **API Base URL:** `https://yourapi.42web.io/api/upi/api/`
- **SDK Version:** 2.2
- **PHP Required:** 7.4+
