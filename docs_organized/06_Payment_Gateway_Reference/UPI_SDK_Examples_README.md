# UpiPe SDK — Examples

**SDK v2.2** | `yourapi.42web.io`

---

## Examples List

| File | Use Case |
|------|----------|
| `01_basic_order.php` | Pay → Unlock password / secret content |
| `02_subscription_auto.php` | Pay → Activate monthly membership |
| `03_download_after_pay.php` | Pay → Download file / e-book |
| `04_wallet_fund_add.php` | Pay → Add balance to wallet |

---

## Verification Flow (v2.2)

```
Order Created
     │
     ▼
  0–5 min ──► Auto verify (Paytm) polls every 5 sec
              Response: { status: 'not_paid', manual_utr_available: false, manual_allowed_in: X }
              Frontend: show countdown timer

  5 min+  ──► Auto verify STILL runs (no limit)
              PLUS: UTR input unlocks for customer
              Response: { status: 'not_paid', manual_utr_available: true }
              Frontend: show UTR input field

  Customer submits UTR
              Response: { status: 'manual_pending' }
              Merchant reviews in panel → Approve / Reject

  Either path leads to:
              ✅ PAID  or  ❌ REJECTED
```

**Key points:**
- Auto verify has NO attempt limit — it keeps retrying forever
- UTR submission only accepted after 5 min from order creation
- Status stays `PENDING` until UTR is actually submitted
- Auto + Manual run in parallel — whichever succeeds first wins

---

## check_status.php (Shared AJAX endpoint)

Place this in your project, all examples use it:

```php
<?php
// check_status.php
require 'UpiPeSDK/UpiPe.php';

header('Content-Type: application/json');

$apiKey  = 'YOUR_API_KEY';   // 👈 replace
$orderId = trim($_GET['order_id'] ?? '');

if (empty($orderId)) {
    echo json_encode(['status' => 'error', 'message' => 'order_id required']);
    exit;
}

$upi    = new UpiPe($apiKey);
$result = $upi->verifyPayment($orderId);
echo json_encode($result);
```

---

## submit_utr.php (UTR submission endpoint)

```php
<?php
// submit_utr.php
require 'UpiPeSDK/UpiPe.php';

header('Content-Type: application/json');

$apiKey  = 'YOUR_API_KEY';   // 👈 replace
$orderId = trim($_POST['order_id'] ?? '');
$utr     = trim($_POST['utr'] ?? '');

if (empty($orderId) || empty($utr)) {
    echo json_encode(['status' => 'error', 'message' => 'order_id and utr required']);
    exit;
}

$upi    = new UpiPe($apiKey);
$result = $upi->verifyPayment($orderId, $utr);
echo json_encode($result);
```

---

## JavaScript Polling (Copy-Paste Ready)

```javascript
let utrShown = false;

const poll = setInterval(async () => {
    const res  = await fetch('check_status.php?order_id=' + orderId);
    const data = await res.json();

    switch (data.status) {
        case 'paid':
        case 'already_paid':
            clearInterval(poll);
            handleSuccess(data);
            break;

        case 'not_paid':
            if (data.manual_utr_available && !utrShown) {
                utrShown = true;
                showUtrInput(); // show 12-digit UTR field
            } else if (!data.manual_utr_available) {
                updateCountdown(data.manual_allowed_in); // show seconds remaining
            }
            break;

        case 'manual_not_allowed':
            updateCountdown(data.manual_allowed_in);
            break;

        case 'manual_pending':
            showStatus('UTR submitted — awaiting merchant verification...');
            break;

        case 'expired':
            clearInterval(poll);
            showExpired();
            break;

        case 'rejected':
            clearInterval(poll);
            showRejected();
            break;
    }
}, 5000);

// Submit UTR button handler:
async function submitUtr() {
    const utr = document.getElementById('utrInput').value.trim();
    if (!/^\d{12}$/.test(utr)) {
        alert('UTR must be exactly 12 digits');
        return;
    }

    const body = new FormData();
    body.append('order_id', orderId);
    body.append('utr', utr);

    const res  = await fetch('submit_utr.php', { method: 'POST', body });
    const data = await res.json();

    if (data.status === 'manual_pending') {
        showStatus('UTR submitted! Awaiting merchant verification...');
        hideUtrInput();
    } else if (data.status === 'manual_not_allowed') {
        showStatus('Please wait ' + data.manual_allowed_in + ' more seconds before submitting UTR.');
    } else {
        showStatus(data.message || 'Something went wrong. Try again.');
    }
}
```

---

## Status Reference

| Status | meaning | Frontend Action |
|--------|---------|-----------------|
| `paid` | ✅ Verified | Unlock / deliver |
| `already_paid` | ✅ Was already verified | Skip (no duplicate action) |
| `not_paid` | ⏳ Not detected yet | Check `manual_utr_available` |
| `manual_not_allowed` | ⏳ UTR submitted before 5 min | Show `manual_allowed_in` countdown |
| `manual_pending` | 🕐 UTR submitted, under review | Show "processing" message |
| `utr_required` | 📝 Manual-only order | Show UTR input immediately |
| `pending` | ⏳ Manual order, 5 min not passed | Show countdown |
| `expired` | ❌ 30 min window passed | New order button |
| `rejected` | ❌ Merchant rejected UTR | Contact support message |
