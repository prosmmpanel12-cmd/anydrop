<?php
/**
 * ╔══════════════════════════════════════════════════╗
 * ║  EXAMPLE 1 — Basic Payment + Content Unlock     ║
 * ║  UpiPe SDK by YourApis                          ║
 * ╚══════════════════════════════════════════════════╝
 *
 * USE CASE: Pay karo → Password / Secret content unlock ho jaye
 *
 * Flow:
 *   1. Page load → Order create hota hai
 *   2. QR + Pay buttons dikhte hain
 *   3. User "Maine Pay Kar Diya" click karta hai
 *   4. check_status.php poll karta hai
 *   5. Paid → Password / Content reveal!
 *
 * UPLOAD: apne server pe sdk/ folder upload karo
 * OPEN:   https://yoursite.com/sdk/examples/01_basic_order.php
 */

require_once __DIR__ . '/../UpiPeSDK/UpiPe.php';

// ── CONFIG ──────────────────────────────────────────────────────
$API_KEY = 'Pm_XXXXXXXXXXXXXXXXXXXX';   // 👈 Apna API Key yahan
// Base URL default hai (yourapi.42web.io) — change mat karo jab tak self-host na karo

$upi = new UpiPe($API_KEY);

// ── Secret content jo pay ke baad dikhega ───────────────────────
$SECRET_CONTENT = [
    'title'    => 'Premium Course Access',
    'password' => 'ULTRA#2024',
    'link'     => 'https://yoursite.com/course/premium',
    'note'     => '30 din ke liye valid hai',
];

// ── Order create karo ───────────────────────────────────────────
$order = $upi->createOrder(
    amount:     49.00,
    customerId: 'user_' . (session_id() ?: uniqid()),
    note:       'Premium Password Access'
);

if ($order['status'] !== 'success') {
    die('<div style="padding:20px;color:#f87171;background:#1a0a0a;font-family:sans-serif">
        ❌ Order create nahi hua: ' . htmlspecialchars($order['message'] ?? 'Unknown error') . '
        <br><small>API Key aur internet connection check karo.</small>
    </div>');
}

$orderId = $order['order_id'];
$qrUrl   = $order['qr_url'];
?>
<!DOCTYPE html>
<html lang="hi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pay ₹49 — Premium Access | UpiPe</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      background: #0a0a12;
      color: #e2e8f0;
      font-family: 'Segoe UI', system-ui, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .card {
      background: #12121e;
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 20px;
      padding: 32px 28px;
      width: 100%;
      max-width: 380px;
      text-align: center;
      box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    }

    .brand { font-size: 11px; color: #6366f1; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; margin-bottom: 8px; }
    h1 { font-size: 20px; font-weight: 700; color: #fff; margin-bottom: 4px; }
    .sub { font-size: 13px; color: #64748b; margin-bottom: 20px; }

    .amount-pill {
      display: inline-block;
      background: linear-gradient(135deg, #6366f1, #8b5cf6);
      color: #fff;
      font-size: 28px;
      font-weight: 800;
      padding: 8px 28px;
      border-radius: 50px;
      margin-bottom: 20px;
    }

    .qr-wrap {
      position: relative;
      display: inline-block;
      margin-bottom: 6px;
    }
    .qr-wrap img {
      width: 200px;
      height: 200px;
      border-radius: 14px;
      border: 3px solid rgba(99,102,241,0.4);
      display: block;
    }
    .qr-label { font-size: 11px; color: #475569; margin-bottom: 18px; margin-top: 6px; }

    .divider { display: flex; align-items: center; gap: 10px; color: #334155; font-size: 12px; margin: 16px 0; }
    .divider::before, .divider::after { content:''; flex:1; height:1px; background:rgba(255,255,255,0.06); }

    .btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 600;
      text-decoration: none;
      margin-bottom: 8px;
      transition: opacity .2s;
    }
    .btn:hover { opacity: .85; }
    .btn-gpay    { background: #1a73e8; color: #fff; }
    .btn-phonepe { background: #5f259f; color: #fff; }
    .btn-paytm   { background: #00baf2; color: #000; }
    .btn-check   { background: linear-gradient(135deg,#6366f1,#8b5cf6); color: #fff; border: none; cursor: pointer; font-size: 15px; padding: 14px; margin-top: 4px; }

    #status-box {
      display: none;
      margin-top: 16px;
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13px;
      line-height: 1.5;
    }
    .status-checking { background: rgba(99,102,241,0.1); color: #818cf8; border:1px solid rgba(99,102,241,0.2); display:block!important; }
    .status-success  { background: rgba(16,185,129,0.1); color: #34d399; border:1px solid rgba(16,185,129,0.2); display:block!important; }
    .status-error    { background: rgba(239,68,68,0.1);  color: #f87171; border:1px solid rgba(239,68,68,0.2);  display:block!important; }

    #secret-box {
      display: none;
      margin-top: 20px;
      background: linear-gradient(135deg, rgba(16,185,129,0.05), rgba(99,102,241,0.05));
      border: 1px solid rgba(16,185,129,0.3);
      border-radius: 14px;
      padding: 24px;
      animation: fadeIn .4s ease;
    }
    @keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
    #secret-box h3 { color: #34d399; font-size: 18px; margin-bottom: 16px; }
    .secret-item { background: rgba(255,255,255,0.04); border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; text-align: left; }
    .secret-item .label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
    .secret-item .val { font-size: 15px; font-weight: 600; color: #fff; margin-top: 3px; font-family: monospace; }

    .utr-form input {
      width:100%; padding:11px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1);
      border-radius:8px; color:#fff; font-size:16px; text-align:center; letter-spacing:3px; margin:8px 0;
    }
    .utr-form input:focus { border-color:#6366f1; outline:none; }
    .utr-form button { background:#6366f1; color:#fff; border:none; width:100%; padding:12px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; }
  </style>
</head>
<body>

<div class="card">
  <div class="brand">⚡ UpiPe · YourApis</div>
  <h1>Unlock Premium Access</h1>
  <p class="sub">Pay karo, turant access pao</p>

  <div class="amount-pill">₹<?= number_format($order['amount'], 2) ?></div>

  <!-- QR Code -->
  <div class="qr-wrap">
    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="UPI QR Code">
  </div>
  <p class="qr-label">Koi bhi UPI app se scan karo (GPay, PhonePe, Paytm, BHIM)</p>

  <div class="divider">Ya direct pay karo</div>

  <!-- Deep Link Buttons -->
  <a href="<?= htmlspecialchars($order['deep_links']['gpay']) ?>"    class="btn btn-gpay">    📱 Google Pay</a>
  <a href="<?= htmlspecialchars($order['deep_links']['phonepe']) ?>" class="btn btn-phonepe"> 📲 PhonePe</a>
  <a href="<?= htmlspecialchars($order['deep_links']['paytm']) ?>"   class="btn btn-paytm">  💳 Paytm</a>

  <div class="divider">Pay ho gaya?</div>

  <!-- Check Payment -->
  <button class="btn btn-check" onclick="checkPayment()">✅ Maine Pay Kar Diya</button>

  <div id="status-box"></div>
  <div id="utr-form-wrap"></div>

  <!-- Secret Content (shown after payment) -->
  <div id="secret-box">
    <h3>🎉 Payment Successful!</h3>
    <div class="secret-item">
      <div class="label">Access Title</div>
      <div class="val"><?= htmlspecialchars($SECRET_CONTENT['title']) ?></div>
    </div>
    <div class="secret-item">
      <div class="label">Password</div>
      <div class="val"><?= htmlspecialchars($SECRET_CONTENT['password']) ?></div>
    </div>
    <div class="secret-item">
      <div class="label">Access Link</div>
      <div class="val"><a href="<?= htmlspecialchars($SECRET_CONTENT['link']) ?>" style="color:#818cf8"><?= htmlspecialchars($SECRET_CONTENT['link']) ?></a></div>
    </div>
    <div class="secret-item">
      <div class="label">Note</div>
      <div class="val" style="font-family:sans-serif;font-size:13px;color:#94a3b8"><?= htmlspecialchars($SECRET_CONTENT['note']) ?></div>
    </div>
  </div>
</div>

<script>
const ORDER_ID = "<?= $orderId ?>";
let verified = false;

function setStatus(msg, cls) {
  const box = document.getElementById('status-box');
  box.className = 'status-' + cls;
  box.innerHTML = msg;
}

function checkPayment() {
  if (verified) return;
  setStatus('🔄 Payment check ho rahi hai...', 'checking');

  fetch('check_status.php?order_id=' + ORDER_ID)
    .then(r => r.json())
    .then(data => {
      if (data.status === 'paid' || data.status === 'already_paid') {
        verified = true;
        setStatus('✅ Payment verified! ₹' + data.amount + ' receive hua.', 'success');
        document.getElementById('secret-box').style.display = 'block';

      } else if (data.status === 'utr_required') {
        setStatus('📝 UTR number daalo (bank reference / transaction ID):', 'checking');
        document.getElementById('utr-form-wrap').innerHTML = `
          <div class="utr-form" style="margin-top:8px">
            <input type="text" id="utr-inp" placeholder="123456789012" maxlength="12"
              oninput="this.value=this.value.replace(/\\D/g,'')">
            <button onclick="submitUTR()">Submit UTR</button>
          </div>`;

      } else if (data.status === 'not_paid') {
        setStatus('⏳ Payment abhi nahi aayi. 10 second baad dobara try karo.', 'checking');
        setTimeout(() => document.getElementById('status-box').style.display='none', 4000);

      } else if (data.status === 'expired') {
        setStatus('❌ Order expire ho gaya (30 min). Page refresh karo — naya QR milega.', 'error');

      } else {
        setStatus('ℹ️ Status: ' + (data.message || data.status), 'checking');
      }
    })
    .catch(() => setStatus('🌐 Network error. Internet check karo.', 'error'));
}

function submitUTR() {
  const utr = document.getElementById('utr-inp').value.trim();
  if (!/^\d{12}$/.test(utr)) { alert('Exactly 12 digit UTR daalo!'); return; }

  setStatus('🔄 UTR verify ho raha hai...', 'checking');

  fetch('check_status.php?order_id=' + ORDER_ID + '&utr=' + utr)
    .then(r => r.json())
    .then(data => {
      if (data.status === 'manual_pending') {
        setStatus('✅ UTR submit ho gaya! Merchant verify karega. 5-10 min mein access milega.', 'success');
      } else if (data.status === 'paid') {
        verified = true;
        setStatus('✅ Payment verified! ₹' + data.amount, 'success');
        document.getElementById('secret-box').style.display = 'block';
      } else {
        setStatus('❌ ' + (data.message || 'UTR verify nahi hua. Sahi UTR daalo.'), 'error');
      }
    });
}
</script>
</body>
</html>
