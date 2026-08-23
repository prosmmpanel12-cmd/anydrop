<?php
/**
 * ╔══════════════════════════════════════════════════╗
 * ║  EXAMPLE 4 — Wallet / Fund Add                  ║
 * ║  UpiPe SDK by YourApis                          ║
 * ╚══════════════════════════════════════════════════╝
 *
 * USE CASE: User apna wallet balance UPI se recharge kare.
 *
 * Flow:
 *   1. User amount enter karta hai
 *   2. QR + UTR form dikhta hai
 *   3. Payment ke baad UTR submit hota hai
 *   4. Server verify karta hai → balance credit hota hai
 *   5. Merchant dashboard mein manual orders dikhte hain
 */

require_once __DIR__ . '/../UpiPeSDK/UpiPe.php';
session_start();

$API_KEY = 'Pm_XXXXXXXXXXXXXXXXXXXX';   // 👈 Apna API Key
$upi     = new UpiPe($API_KEY);
$userId  = $_SESSION['user_id'] ?? 'wallet_guest';

// ── Quick amounts ───────────────────────────────────────────────
$QUICK_AMOUNTS = [50, 100, 200, 500, 1000, 2000];

// ── AJAX: Create order ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');

    $amount = (float)($_POST['amount'] ?? 0);
    if ($amount < 10) {
        echo json_encode(['status' => 'error', 'message' => 'Minimum ₹10 add karo.']);
        exit;
    }

    $result = $upi->createOrder(
        amount:     $amount,
        customerId: $userId,
        note:       'Wallet Fund Add — ₹' . $amount,
        mode:       'manual'
    );
    echo json_encode($result);
    exit;
}

// ── AJAX: Submit UTR ────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'verify') {
    header('Content-Type: application/json');

    $orderId = $_POST['order_id'] ?? '';
    $utr     = $_POST['utr']      ?? '';

    $result = $upi->verifyPayment($orderId, $utr);

    if (in_array($result['status'], ['paid', 'already_paid'])) {
        /*
         * ✅ BALANCE CREDIT KARO:
         * ─────────────────────────────
         * // Duplicate check
         * $exists = $pdo->prepare("SELECT id FROM pending_funds WHERE order_id=? AND status='PAID'")
         *               ->execute([$orderId])->fetch();
         * if (!$exists) {
         *     $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")
         *         ->execute([$result['amount'], $userId]);
         *     $pdo->prepare("UPDATE pending_funds SET status='PAID' WHERE order_id=?")
         *         ->execute([$orderId]);
         *     $result['new_balance'] = getUserBalance($userId);
         * }
         */
        $result['new_balance'] = 1250; // demo value
    }

    echo json_encode($result);
    exit;
}

// ── AJAX: Status poll (merchant approval ke baad) ───────────────
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    $result = $upi->getOrderStatus($_GET['order_id'] ?? '');
    if (in_array($result['status'], ['paid', 'already_paid'])) {
        $result['approved']    = true;
        // $result['new_balance'] = getUserBalance($userId);
        $result['new_balance'] = 1250; // demo
    }
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Money to Wallet | UpiPe</title>
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    body { background:#08080e; color:#e2e8f0; font-family:'Segoe UI',system-ui,sans-serif; padding:24px 16px; }

    .wrap { max-width:480px; margin:0 auto; }

    /* Header */
    .wallet-header {
      background:linear-gradient(135deg,#0f0f18,#1a1a2e);
      border:1px solid rgba(99,102,241,0.2);
      border-radius:18px; padding:24px; margin-bottom:20px; text-align:center;
    }
    .balance-lbl { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:1px; }
    .balance-amt { font-size:40px; font-weight:800; color:#fff; margin:8px 0; }
    .balance-sub { font-size:12px; color:#475569; }

    /* Cards */
    .card { background:#0f0f18; border:1px solid rgba(255,255,255,0.07); border-radius:16px; padding:24px; margin-bottom:16px; }
    .card-title { font-size:13px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:.5px; margin-bottom:16px; }

    /* Amount input */
    .amount-input-wrap { position:relative; margin-bottom:12px; }
    .amount-input-wrap .rupee { position:absolute; left:16px; top:50%; transform:translateY(-50%); font-size:22px; font-weight:700; color:#6366f1; }
    .amount-input-wrap input {
      width:100%; padding:16px 16px 16px 40px;
      background:rgba(255,255,255,0.04); border:2px solid rgba(99,102,241,0.3);
      border-radius:12px; color:#fff; font-size:26px; font-weight:700; outline:none;
    }
    .amount-input-wrap input:focus { border-color:#6366f1; }
    .amount-input-wrap input::placeholder { color:#334155; }

    /* Quick amounts */
    .quick-amounts { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
    .qa { padding:8px 14px; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.25); border-radius:8px; color:#818cf8; font-size:13px; font-weight:600; cursor:pointer; transition:.15s; }
    .qa:hover { background:rgba(99,102,241,0.2); color:#a5b4fc; }

    /* Action btn */
    .action-btn { width:100%; padding:14px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border:none; border-radius:12px; font-size:15px; font-weight:700; cursor:pointer; transition:opacity .2s; }
    .action-btn:hover { opacity:.9; }
    .action-btn:disabled { opacity:.5; cursor:default; }

    /* QR section */
    #qr-section { display:none; }
    .qr-box { text-align:center; margin-bottom:16px; }
    .qr-box img { width:180px; height:180px; border-radius:12px; border:2px solid rgba(99,102,241,0.3); }
    .order-info { font-size:11px; color:#475569; text-align:center; margin-top:6px; font-family:monospace; }

    .pay-row { display:flex; gap:8px; margin:12px 0; }
    .pay-row a { flex:1; display:flex; align-items:center; justify-content:center; gap:5px; padding:10px; border-radius:9px; font-size:12px; font-weight:600; text-decoration:none; }
    .pg { background:#1a73e8; color:#fff; }
    .pp { background:#5f259f; color:#fff; }
    .pt { background:#00baf2; color:#000; }

    /* UTR form */
    .utr-form { margin-top:16px; }
    .utr-form label { display:block; font-size:12px; color:#64748b; margin-bottom:8px; }
    .utr-form input {
      width:100%; padding:13px; background:rgba(255,255,255,0.04);
      border:2px solid rgba(255,255,255,0.08); border-radius:10px;
      color:#fff; font-size:20px; text-align:center; letter-spacing:4px;
      outline:none; margin-bottom:10px;
    }
    .utr-form input:focus { border-color:#6366f1; }

    /* Status */
    #status { display:none; padding:12px 14px; border-radius:10px; font-size:13px; margin-top:12px; line-height:1.5; }
    .s-load    { background:rgba(99,102,241,.1);  color:#818cf8; border:1px solid rgba(99,102,241,.2);  display:block!important; }
    .s-pending { background:rgba(245,158,11,.1);  color:#fbbf24; border:1px solid rgba(245,158,11,.2);  display:block!important; }
    .s-success { background:rgba(16,185,129,.1);  color:#34d399; border:1px solid rgba(16,185,129,.2);  display:block!important; }
    .s-error   { background:rgba(239,68,68,.1);   color:#f87171; border:1px solid rgba(239,68,68,.2);   display:block!important; }

    /* Steps */
    .steps { display:flex; gap:6px; margin-bottom:16px; }
    .step { flex:1; text-align:center; }
    .step-num { width:28px; height:28px; background:rgba(99,102,241,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:#6366f1; margin:0 auto 4px; }
    .step-num.active { background:#6366f1; color:#fff; }
    .step-txt { font-size:10px; color:#475569; }
    .step-line { width:1px; height:28px; background:rgba(255,255,255,0.07); margin-top:0; }

    /* Success balance update */
    #balance-updated { display:none; text-align:center; }
    .new-bal { font-size:36px; font-weight:800; color:#34d399; margin:12px 0 4px; }
  </style>
</head>
<body>
<div class="wrap">

  <!-- Wallet Balance -->
  <div class="wallet-header">
    <div class="balance-lbl">💰 Wallet Balance</div>
    <div class="balance-amt" id="current-balance">₹750.00</div>
    <div class="balance-sub">Available to use</div>
  </div>

  <!-- Step indicator -->
  <div class="steps">
    <div class="step">
      <div class="step-num active" id="s1">1</div>
      <div class="step-txt">Amount</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
      <div class="step-num" id="s2">2</div>
      <div class="step-txt">Pay</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
      <div class="step-num" id="s3">3</div>
      <div class="step-txt">UTR</div>
    </div>
    <div class="step-line"></div>
    <div class="step">
      <div class="step-num" id="s4">✓</div>
      <div class="step-txt">Done</div>
    </div>
  </div>

  <!-- Step 1: Amount -->
  <div class="card" id="step1-card">
    <div class="card-title">💵 Enter Amount</div>

    <div class="amount-input-wrap">
      <span class="rupee">₹</span>
      <input type="number" id="amount" placeholder="100" min="10" max="50000">
    </div>

    <!-- Quick amounts -->
    <div class="quick-amounts">
      <?php foreach ($QUICK_AMOUNTS as $qa): ?>
      <span class="qa" onclick="setAmount(<?= $qa ?>)">₹<?= $qa ?></span>
      <?php endforeach; ?>
    </div>

    <button class="action-btn" id="get-qr-btn" onclick="getQR()">
      🚀 Generate QR Code
    </button>
  </div>

  <!-- Step 2 & 3: QR + UTR -->
  <div class="card" id="qr-section">
    <div class="card-title">📱 Scan & Pay</div>

    <div class="qr-box">
      <img id="qr-img" src="" alt="QR Code">
    </div>
    <div class="order-info" id="order-info">Loading...</div>

    <!-- Pay buttons -->
    <div class="pay-row">
      <a id="gpay-btn" href="#" class="pg" target="_blank">📱 GPay</a>
      <a id="pp-btn"   href="#" class="pp" target="_blank">📲 PhonePe</a>
      <a id="pt-btn"   href="#" class="pt" target="_blank">💳 Paytm</a>
    </div>

    <hr style="border-color:rgba(255,255,255,0.05);margin:16px 0">

    <div class="utr-form">
      <label>
        📋 Step 3: Pay karne ke baad 12-digit UTR (Bank Reference Number) daalo
        <small style="display:block;color:#334155;margin-top:3px">
          UPI App → Transactions → Transaction Details → Reference Number
        </small>
      </label>
      <input type="text" id="utr-inp" placeholder="• • • • • • • • • • • •" maxlength="12"
        oninput="this.value=this.value.replace(/\D/g,''); checkUTR()">

      <button class="action-btn" id="utr-btn" onclick="submitUTR()" disabled>
        ✅ Submit UTR & Add Funds
      </button>
    </div>

    <div id="status"></div>
  </div>

  <!-- Step 4: Success -->
  <div class="card" id="success-card" style="display:none">
    <div id="balance-updated">
      <div style="font-size:52px;margin-bottom:8px">🎉</div>
      <div style="color:#34d399;font-size:18px;font-weight:700">Fund Add Successful!</div>
      <div class="new-bal" id="new-balance">₹1,250.00</div>
      <div style="color:#64748b;font-size:13px">Current wallet balance</div>
      <div style="margin-top:16px;font-size:12px;color:#475569" id="tx-info"></div>
      <button class="action-btn" onclick="location.reload()" style="margin-top:16px">
        ➕ Add More Funds
      </button>
    </div>
    <div id="manual-pending-msg" style="display:none;text-align:center;padding:10px 0">
      <div style="font-size:48px;margin-bottom:8px">⏳</div>
      <div style="color:#fbbf24;font-size:18px;font-weight:700">Under Review</div>
      <p style="color:#64748b;font-size:13px;margin-top:8px">
        UTR submit ho gaya. Merchant 5-10 minute mein verify karega.<br>
        Approve hone ke baad wallet automatically update hoga.
      </p>
      <div id="poll-status" style="margin-top:12px;font-size:12px;color:#475569">Checking approval status...</div>
    </div>
  </div>

</div>

<script>
let currentOrderId = '';
let pollTimer      = null;

function setAmount(val) {
  document.getElementById('amount').value = val;
}

function setStep(n) {
  [1,2,3,4].forEach(i => {
    const el = document.getElementById('s'+i);
    if (el) el.classList.toggle('active', i === n);
  });
}

function setStatus(msg, cls) {
  const el = document.getElementById('status');
  el.className = 's-' + cls;
  el.innerHTML = msg;
}

async function getQR() {
  const amount = parseFloat(document.getElementById('amount').value);
  if (!amount || amount < 10) { alert('Minimum ₹10 daalo.'); return; }

  const btn = document.getElementById('get-qr-btn');
  btn.disabled = true;
  btn.textContent = '⏳ Creating order...';

  const body = new URLSearchParams({ action: 'create', amount });
  const res  = await fetch('', { method:'POST', body });
  const data = await res.json();

  btn.disabled = false;
  btn.textContent = '🚀 Generate QR Code';

  if (data.status !== 'success') {
    alert('Error: ' + (data.message || 'Order create nahi hua.'));
    return;
  }

  currentOrderId = data.order_id;
  document.getElementById('qr-img').src = data.qr_url;
  document.getElementById('order-info').textContent = 'Order: ' + data.order_id + ' · ₹' + data.amount + ' · ' + (data.expires_at || '30 min valid');
  document.getElementById('gpay-btn').href = data.deep_links.gpay;
  document.getElementById('pp-btn').href   = data.deep_links.phonepe;
  document.getElementById('pt-btn').href   = data.deep_links.paytm;

  document.getElementById('qr-section').style.display = 'block';
  document.getElementById('qr-section').scrollIntoView({ behavior:'smooth' });
  setStep(2);
}

function checkUTR() {
  const utr = document.getElementById('utr-inp').value;
  document.getElementById('utr-btn').disabled = !/^\d{12}$/.test(utr);
  if (/^\d{12}$/.test(utr)) setStep(3);
}

async function submitUTR() {
  const utr = document.getElementById('utr-inp').value.trim();
  if (!/^\d{12}$/.test(utr)) { alert('12 digit UTR daalo!'); return; }

  const btn = document.getElementById('utr-btn');
  btn.disabled = true;
  btn.textContent = '🔄 Verifying...';
  setStatus('🔄 UTR verify ho raha hai...', 'load');

  const body = new URLSearchParams({ action: 'verify', order_id: currentOrderId, utr });
  const res  = await fetch('', { method:'POST', body });
  const data = await res.json();

  btn.disabled = false;
  btn.textContent = '✅ Submit UTR & Add Funds';

  if (data.status === 'paid' || data.status === 'already_paid') {
    showSuccess(data);
  } else if (data.status === 'manual_pending') {
    showManualPending();
  } else {
    setStatus('❌ ' + (data.message || 'UTR verify nahi hua. Sahi UTR daalo.'), 'error');
  }
}

function showSuccess(data) {
  setStep(4);
  document.getElementById('success-card').style.display = 'block';
  document.getElementById('balance-updated').style.display = 'block';
  if (data.new_balance) {
    document.getElementById('new-balance').textContent = '₹' + parseFloat(data.new_balance).toLocaleString('en-IN', {minimumFractionDigits:2});
  }
  document.getElementById('tx-info').innerHTML =
    '💳 UTR: ' + (data.utr || '-') + '<br>🕐 ' + (data.verified_at || new Date().toLocaleString());
  document.getElementById('success-card').scrollIntoView({ behavior:'smooth' });
}

function showManualPending() {
  setStep(4);
  document.getElementById('success-card').style.display = 'block';
  document.getElementById('manual-pending-msg').style.display = 'block';
  document.getElementById('success-card').scrollIntoView({ behavior:'smooth' });

  // Poll for merchant approval
  pollTimer = setInterval(pollApproval, 10000);
}

async function pollApproval() {
  const res  = await fetch('?poll=1&order_id=' + currentOrderId);
  const data = await res.json();
  if (data.approved) {
    clearInterval(pollTimer);
    document.getElementById('poll-status').textContent = '✅ Approved! Balance credit ho gaya!';
    document.getElementById('manual-pending-msg').style.display = 'none';
    showSuccess(data);
  } else {
    document.getElementById('poll-status').textContent = 'Last checked: ' + new Date().toLocaleTimeString();
  }
}
</script>
</body>
</html>
