<?php
/**
 * ╔══════════════════════════════════════════════════╗
 * ║  EXAMPLE 2 — Auto Polling + Subscription        ║
 * ║  UpiPe SDK by YourApis                          ║
 * ╚══════════════════════════════════════════════════╝
 *
 * USE CASE: Monthly membership / subscription activate karo.
 * Page automatically payment check karta rahega — user ko
 * kuch click nahi karna, bas pay karo aur page update ho jata hai.
 *
 * Flow:
 *   1. Page load → Order banao → QR dikhao
 *   2. JavaScript har 5 sec mein auto poll karta hai
 *   3. Payment detect → DB update → User ko redirect karo
 */

require_once __DIR__ . '/../UpiPeSDK/UpiPe.php';
session_start();

// ── CONFIG ──────────────────────────────────────────────────────
$API_KEY = 'Pm_XXXXXXXXXXXXXXXXXXXX';    // 👈 Apna API Key
$upi     = new UpiPe($API_KEY);

$PLANS = [
    'basic'   => ['name' => 'Basic',   'amount' => 99,  'days' => 30,  'color' => '#3b82f6'],
    'pro'     => ['name' => 'Pro',     'amount' => 299, 'days' => 30,  'color' => '#6366f1'],
    'premium' => ['name' => 'Premium', 'amount' => 599, 'days' => 30,  'color' => '#8b5cf6'],
];

// ── AJAX: status poll ───────────────────────────────────────────
if (isset($_GET['poll']) && isset($_GET['order_id'])) {
    header('Content-Type: application/json');
    $result = $upi->verifyPayment($_GET['order_id']);

    if (in_array($result['status'], ['paid', 'already_paid'])) {
        /*
         * ✅ YAHAN APNA SUBSCRIPTION LOGIC DAALO:
         * ─────────────────────────────────────────
         * $pdo->prepare(
         *   "UPDATE users SET
         *      plan = ?, is_premium = 1,
         *      plan_expires = DATE_ADD(NOW(), INTERVAL ? DAY)
         *    WHERE id = ?"
         * )->execute([$plan, $days, $_SESSION['user_id']]);
         */
        $result['activated'] = true;
        $result['redirect']  = '/dashboard?welcome=1';
    }

    echo json_encode($result);
    exit;
}

// ── Select plan ─────────────────────────────────────────────────
$planKey = $_GET['plan'] ?? 'pro';
$plan    = $PLANS[$planKey] ?? $PLANS['pro'];
$userId  = $_SESSION['user_id'] ?? 'guest_' . session_id();

// ── Create order ────────────────────────────────────────────────
$order = $upi->createOrder(
    amount:     $plan['amount'],
    customerId: $userId,
    note:       $plan['name'] . ' Membership — 30 Days'
);

if ($order['status'] !== 'success') {
    die('Order error: ' . htmlspecialchars($order['message']));
}
$orderId = $order['order_id'];
?>
<!DOCTYPE html>
<html lang="hi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $plan['name'] ?> Membership — UpiPe</title>
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root { --brand: <?= $plan['color'] ?>; }
    body { background:#08080e; color:#e2e8f0; font-family:'Segoe UI',system-ui,sans-serif; padding:24px 16px; }

    .page { max-width:900px; margin:0 auto; }
    .page-header { text-align:center; margin-bottom:32px; }
    .page-header h1 { font-size:28px; color:#fff; }
    .page-header p  { color:#64748b; margin-top:6px; }

    .layout { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    @media(max-width:640px) { .layout { grid-template-columns:1fr; } }

    .card {
      background:#0f0f18;
      border:1px solid rgba(255,255,255,0.07);
      border-radius:18px;
      padding:28px;
    }
    .card h2 { font-size:15px; color:var(--brand); margin-bottom:18px; font-weight:600; }

    /* Plan badge */
    .plan-badge {
      display:flex; align-items:center; gap:12px;
      background:rgba(255,255,255,0.03); border-radius:12px; padding:16px; margin-bottom:16px;
    }
    .plan-icon { width:44px; height:44px; background:var(--brand); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; }
    .plan-name { font-weight:700; font-size:16px; color:#fff; }
    .plan-desc { font-size:12px; color:#64748b; margin-top:2px; }
    .plan-price { margin-left:auto; font-size:22px; font-weight:800; color:#fff; }

    /* Features list */
    .features { list-style:none; margin-bottom:20px; }
    .features li { display:flex; align-items:center; gap:8px; padding:7px 0; font-size:13px; color:#cbd5e1; border-bottom:1px solid rgba(255,255,255,0.04); }
    .features li:last-child { border-bottom:none; }
    .features li .check { color:var(--brand); font-size:14px; }

    /* QR */
    .qr-center { text-align:center; }
    .qr-center img { width:190px; height:190px; border-radius:14px; border:3px solid rgba(255,255,255,0.1); margin-bottom:12px; }

    /* Status */
    .pulse-ring { display:flex; align-items:center; gap:8px; justify-content:center; font-size:13px; color:#64748b; margin-bottom:16px; }
    .dot { width:8px; height:8px; background:var(--brand); border-radius:50%; animation:pulse 1.5s infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.3;transform:scale(1.6)} }

    /* Timer */
    .timer-bar-wrap { background:rgba(255,255,255,0.05); border-radius:50px; height:4px; margin:12px 0; overflow:hidden; }
    .timer-bar { height:100%; background:var(--brand); border-radius:50px; transition:width 1s linear; }
    .timer-txt { font-size:11px; color:#475569; text-align:center; }

    /* Pay buttons */
    .pay-btn { display:flex; align-items:center; gap:8px; width:100%; padding:11px 16px; border-radius:10px; font-size:13px; font-weight:600; text-decoration:none; margin-bottom:8px; transition:opacity .2s; }
    .pay-btn:hover { opacity:.85; }
    .pay-gpay    { background:#1a73e8; color:#fff; }
    .pay-phonepe { background:#5f259f; color:#fff; }
    .pay-paytm   { background:#00baf2; color:#000; }

    /* Success overlay */
    #success { display:none; text-align:center; padding:32px; }
    #success .big-check { font-size:56px; margin-bottom:12px; animation:bounce .4s ease; }
    @keyframes bounce { 0%{transform:scale(0)} 70%{transform:scale(1.2)} 100%{transform:scale(1)} }
    #success h2 { color:#34d399; font-size:22px; }
    #success p  { color:#94a3b8; margin-top:8px; font-size:14px; }
    .redirect-bar { background:rgba(52,211,153,0.1); border-radius:8px; padding:12px; margin-top:16px; font-size:13px; color:#34d399; }

    /* Plan selector */
    .plan-tabs { display:flex; gap:8px; margin-bottom:24px; justify-content:center; flex-wrap:wrap; }
    .plan-tab { padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid rgba(255,255,255,0.1); color:#94a3b8; transition:.15s; }
    .plan-tab.active, .plan-tab:hover { background:var(--brand); color:#fff; border-color:var(--brand); }
  </style>
</head>
<body>
<div class="page">

  <div class="page-header">
    <h1>💎 Choose Your Plan</h1>
    <p>UPI se pay karo — turant activate hoga</p>
  </div>

  <!-- Plan Tabs -->
  <div class="plan-tabs">
    <?php foreach ($PLANS as $key => $p): ?>
    <a href="?plan=<?= $key ?>" class="plan-tab <?= ($key === $planKey ? 'active' : '') ?>">
      <?= $p['name'] ?> — ₹<?= $p['amount'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="layout">

    <!-- Left: Plan Details -->
    <div class="card">
      <h2>📋 Plan Details</h2>

      <div class="plan-badge">
        <div class="plan-icon">⭐</div>
        <div>
          <div class="plan-name"><?= $plan['name'] ?> Plan</div>
          <div class="plan-desc"><?= $plan['days'] ?> days validity</div>
        </div>
        <div class="plan-price">₹<?= $plan['amount'] ?></div>
      </div>

      <ul class="features">
        <li><span class="check">✓</span> Unlimited access to all features</li>
        <li><span class="check">✓</span> Priority customer support</li>
        <li><span class="check">✓</span> Download all content</li>
        <li><span class="check">✓</span> Advanced analytics dashboard</li>
        <li><span class="check">✓</span> API access (Pro & Premium)</li>
        <li><span class="check">✓</span> No ads experience</li>
      </ul>

      <div style="background:rgba(255,255,255,0.03);border-radius:10px;padding:14px;font-size:12px;color:#64748b;line-height:1.7">
        🔒 <strong style="color:#94a3b8">Secure Payment</strong> via UPI<br>
        ✅ Instant activation after payment<br>
        📧 Confirmation email milega<br>
        💬 Support: support@yoursite.com
      </div>
    </div>

    <!-- Right: Payment -->
    <div class="card">
      <div id="payment-section">
        <h2>📱 Scan & Pay</h2>

        <div class="qr-center">
          <img src="<?= htmlspecialchars($order['qr_url']) ?>" alt="UPI QR">
        </div>

        <div class="pulse-ring">
          <span class="dot"></span>
          Auto checking payment...
        </div>

        <!-- Countdown timer -->
        <div class="timer-bar-wrap">
          <div class="timer-bar" id="timer-bar" style="width:100%"></div>
        </div>
        <div class="timer-txt" id="timer-txt">⏱ 30:00 remaining</div>

        <!-- Deep link buttons -->
        <div style="margin-top:16px">
          <a href="<?= htmlspecialchars($order['deep_links']['gpay']) ?>"    class="pay-btn pay-gpay">    📱 Google Pay</a>
          <a href="<?= htmlspecialchars($order['deep_links']['phonepe']) ?>" class="pay-btn pay-phonepe"> 📲 PhonePe</a>
          <a href="<?= htmlspecialchars($order['deep_links']['paytm']) ?>"   class="pay-btn pay-paytm">  💳 Paytm</a>
        </div>

        <div style="text-align:center;font-size:11px;color:#334155;margin-top:10px">
          Order ID: <code style="color:#475569"><?= $orderId ?></code>
        </div>
      </div>

      <!-- Success State -->
      <div id="success">
        <div class="big-check">🎉</div>
        <h2>Payment Successful!</h2>
        <p><?= $plan['name'] ?> Membership activate ho gayi!</p>
        <div class="redirect-bar">
          ✅ ₹<?= $plan['amount'] ?> received — <?= $plan['days'] ?> days activated<br>
          <span id="redirect-count">3</span> seconds mein dashboard pe le ja rahe hain...
        </div>
      </div>
    </div>

  </div><!-- end .layout -->
</div>

<script>
const ORDER_ID = "<?= $orderId ?>";
let seconds    = 1800;
let polls      = 0;
const MAX_POLLS = 360; // 30 min

// Countdown
const timerBar = document.getElementById('timer-bar');
const timerTxt = document.getElementById('timer-txt');
const clock = setInterval(() => {
  seconds--;
  const pct = (seconds / 1800) * 100;
  const m   = String(Math.floor(seconds/60)).padStart(2,'0');
  const s   = String(seconds % 60).padStart(2,'0');
  timerBar.style.width = pct + '%';
  timerTxt.textContent = '⏱ ' + m + ':' + s + ' remaining';
  if (seconds <= 0) { clearInterval(clock); timerTxt.textContent = '❌ Expired'; stopPoll(); }
}, 1000);

// Auto polling
const poller = setInterval(checkPayment, 5000);

function stopPoll() { clearInterval(poller); clearInterval(clock); }

function checkPayment() {
  polls++;
  if (polls > MAX_POLLS) { stopPoll(); return; }

  fetch('?poll=1&order_id=' + ORDER_ID)
    .then(r => r.json())
    .then(data => {
      if (data.activated) {
        stopPoll();
        document.getElementById('payment-section').style.display = 'none';
        document.getElementById('success').style.display = 'block';

        // Countdown redirect
        let cnt = 3;
        const rd = setInterval(() => {
          document.getElementById('redirect-count').textContent = --cnt;
          if (cnt <= 0) {
            clearInterval(rd);
            window.location.href = data.redirect || '/dashboard';
          }
        }, 1000);

      } else if (data.status === 'expired') {
        stopPoll();
        timerTxt.textContent = '❌ Order expired. Page refresh karo.';
      }
    })
    .catch(() => {});
}
</script>
</body>
</html>
