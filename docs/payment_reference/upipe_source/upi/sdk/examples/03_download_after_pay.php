<?php
/**
 * ╔══════════════════════════════════════════════════╗
 * ║  EXAMPLE 3 — Pay & Download (Digital Product)   ║
 * ║  UpiPe SDK by YourApis                          ║
 * ╚══════════════════════════════════════════════════╝
 *
 * USE CASE: E-book, software, template, course etc.
 * Payment ke baad secure download link generate ho.
 *
 * Security features:
 *   - Download link server-side verify ke baad milta hai
 *   - Token-based time-limited download URL
 *   - Direct file path kabhi expose nahi hoti
 */

require_once __DIR__ . '/../UpiPeSDK/UpiPe.php';
session_start();

$API_KEY = 'Pm_XXXXXXXXXXXXXXXXXXXX';   // 👈 Apna API Key
$upi     = new UpiPe($API_KEY);

// ── Digital Products Catalog ────────────────────────────────────
$PRODUCTS = [
    'ebook'    => [
        'name'     => 'PHP Pro Guide 2024',
        'type'     => 'E-Book (PDF)',
        'amount'   => 149,
        'file'     => '/var/www/files/php-pro-guide.pdf',  // ← apna actual path
        'filename' => 'php-pro-guide-2024.pdf',
        'icon'     => '📘',
        'preview'  => 'https://yoursite.com/preview/ebook.jpg',
    ],
    'template' => [
        'name'     => 'Admin Dashboard Template',
        'type'     => 'HTML Template (ZIP)',
        'amount'   => 299,
        'file'     => '/var/www/files/admin-template.zip',
        'filename' => 'admin-dashboard-template.zip',
        'icon'     => '🎨',
        'preview'  => 'https://yoursite.com/preview/template.jpg',
    ],
    'software' => [
        'name'     => 'Inventory Manager Pro',
        'type'     => 'PHP Script (ZIP)',
        'amount'   => 999,
        'file'     => '/var/www/files/inventory-pro.zip',
        'filename' => 'inventory-manager-pro.zip',
        'icon'     => '⚙️',
        'preview'  => 'https://yoursite.com/preview/software.jpg',
    ],
];

$productKey = $_GET['product'] ?? 'ebook';
$product    = $PRODUCTS[$productKey] ?? $PRODUCTS['ebook'];

// ── SECURE DOWNLOAD ENDPOINT ─────────────────────────────────────
if (isset($_GET['download']) && isset($_GET['token'])) {
    $token = $_GET['token'];

    // Token verify karo (session mein save tha)
    if (
        isset($_SESSION['download_tokens'][$token]) &&
        $_SESSION['download_tokens'][$token]['expires'] > time()
    ) {
        $info = $_SESSION['download_tokens'][$token];
        $file = $PRODUCTS[$info['product']]['file']    ?? '';
        $name = $PRODUCTS[$info['product']]['filename'] ?? 'download';

        if (file_exists($file)) {
            // Secure file serve karo
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-cache, no-store');
            readfile($file);
            exit;
        }
    }
    die('<div style="padding:20px;color:#f87171;font-family:sans-serif">❌ Invalid or expired download link.</div>');
}

// ── AJAX: Verify payment + Generate download token ────────────────
if (isset($_POST['action']) && $_POST['action'] === 'verify') {
    header('Content-Type: application/json');

    $orderId = $_POST['order_id'] ?? '';
    $utr     = $_POST['utr']      ?? '';

    $result = $upi->verifyPayment($orderId, $utr);

    if (in_array($result['status'], ['paid', 'already_paid'])) {
        // Generate secure download token
        $token = bin2hex(random_bytes(24));
        $_SESSION['download_tokens'][$token] = [
            'product' => $productKey,
            'expires' => time() + 3600, // 1 hour
            'order'   => $orderId,
        ];
        $result['download_token'] = $token;
        $result['download_url']   = '?download=1&token=' . $token . '&product=' . $productKey;

        /*
         * DB mein log karo:
         * $pdo->prepare("INSERT INTO purchases (order_id,user_id,product,amount,purchased_at)
         *                VALUES (?,?,?,?,NOW())")
         *      ->execute([$orderId, $_SESSION['user_id'], $productKey, $result['amount']]);
         */
    }

    echo json_encode($result);
    exit;
}

// ── Create order ─────────────────────────────────────────────────
$order = $upi->createOrder(
    amount:     $product['amount'],
    customerId: 'buyer_' . session_id(),
    note:       'Buy: ' . $product['name']
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
  <title>Buy <?= htmlspecialchars($product['name']) ?> | UpiPe</title>
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    body { background:#08080e; color:#e2e8f0; font-family:'Segoe UI',system-ui,sans-serif; padding:20px; }

    .container { max-width:860px; margin:0 auto; }
    h1 { font-size:22px; color:#fff; margin-bottom:4px; text-align:center; }
    .subtitle { text-align:center; color:#64748b; font-size:13px; margin-bottom:28px; }

    .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    @media(max-width:600px){ .grid { grid-template-columns:1fr; } }

    .box { background:#0f0f18; border:1px solid rgba(255,255,255,0.07); border-radius:18px; padding:24px; }

    /* Product card */
    .product-header { display:flex; align-items:center; gap:14px; margin-bottom:16px; }
    .product-icon { width:52px; height:52px; background:linear-gradient(135deg,#6366f1,#8b5cf6); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0; }
    .product-name { font-size:17px; font-weight:700; color:#fff; }
    .product-type { font-size:12px; color:#64748b; margin-top:3px; }

    .price-tag { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; display:inline-block; font-size:26px; font-weight:800; padding:8px 20px; border-radius:50px; margin:12px 0 20px; }

    .feature-list { list-style:none; }
    .feature-list li { display:flex; align-items:center; gap:8px; padding:8px 0; font-size:13px; color:#cbd5e1; border-bottom:1px solid rgba(255,255,255,0.04); }
    .feature-list li:last-child { border:none; }
    .feature-list .ck { color:#6366f1; }

    /* Payment side */
    .qr-area { text-align:center; margin-bottom:16px; }
    .qr-area img { width:180px; height:180px; border-radius:12px; border:2px solid rgba(255,255,255,0.1); }

    .pay-btns { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin:12px 0; }
    .pbtn { display:flex; align-items:center; justify-content:center; gap:6px; padding:11px; border-radius:9px; font-size:12px; font-weight:600; text-decoration:none; transition:opacity .2s; }
    .pbtn:hover { opacity:.85; }
    .p-gpay    { background:#1a73e8; color:#fff; grid-column:span 2; }
    .p-phonepe { background:#5f259f; color:#fff; }
    .p-paytm   { background:#00baf2; color:#000; }

    .or-manual { text-align:center; color:#334155; font-size:12px; margin:12px 0 6px; }

    .check-btn { width:100%; padding:13px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:8px; }

    #status { display:none; margin-top:12px; padding:12px; border-radius:9px; font-size:13px; }
    .s-check   { background:rgba(99,102,241,.1); color:#818cf8; border:1px solid rgba(99,102,241,.2); display:block!important; }
    .s-success { background:rgba(16,185,129,.1); color:#34d399; border:1px solid rgba(16,185,129,.2); display:block!important; }
    .s-error   { background:rgba(239,68,68,.1);  color:#f87171; border:1px solid rgba(239,68,68,.2);  display:block!important; }

    .utr-box { margin-top:10px; }
    .utr-box input { width:100%; padding:11px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; color:#fff; font-size:16px; text-align:center; letter-spacing:3px; margin-bottom:8px; }
    .utr-box input:focus { border-color:#6366f1; outline:none; }
    .utr-box button { width:100%; padding:11px; background:#6366f1; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }

    /* Download success */
    #download-section { display:none; text-align:center; padding:20px 0; }
    .dl-icon { font-size:52px; margin-bottom:10px; }
    #download-section h3 { color:#34d399; font-size:20px; margin-bottom:8px; }
    #download-section p { color:#94a3b8; font-size:13px; margin-bottom:16px; }
    .dl-btn { display:inline-flex; align-items:center; gap:8px; padding:14px 28px; background:linear-gradient(135deg,#10b981,#059669); color:#fff; text-decoration:none; border-radius:12px; font-weight:700; font-size:15px; animation:pulse2 2s infinite; }
    @keyframes pulse2 { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.4)} 70%{box-shadow:0 0 0 10px rgba(16,185,129,0)} }
    .dl-note { font-size:11px; color:#475569; margin-top:12px; }
  </style>
</head>
<body>
<div class="container">
  <h1>🛒 Purchase Digital Product</h1>
  <p class="subtitle">UPI se pay karo — turant download link milega</p>

  <div class="grid">

    <!-- Product Info -->
    <div class="box">
      <div class="product-header">
        <div class="product-icon"><?= $product['icon'] ?></div>
        <div>
          <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
          <div class="product-type"><?= htmlspecialchars($product['type']) ?></div>
        </div>
      </div>

      <div class="price-tag">₹<?= $product['amount'] ?></div>

      <ul class="feature-list">
        <li><span class="ck">✓</span> Instant download after payment</li>
        <li><span class="ck">✓</span> Secure encrypted download link</li>
        <li><span class="ck">✓</span> 1 hour valid download link</li>
        <li><span class="ck">✓</span> Lifetime access to file</li>
        <li><span class="ck">✓</span> Free updates (when available)</li>
        <li><span class="ck">✓</span> Support via email</li>
      </ul>
    </div>

    <!-- Payment -->
    <div class="box">
      <div id="pay-panel">
        <div class="qr-area">
          <img src="<?= htmlspecialchars($order['qr_url']) ?>" alt="QR Code">
          <div style="font-size:11px;color:#475569;margin-top:6px">Scan with any UPI app</div>
        </div>

        <div class="pay-btns">
          <a href="<?= htmlspecialchars($order['deep_links']['gpay'])    ?>" class="pbtn p-gpay">    📱 Google Pay</a>
          <a href="<?= htmlspecialchars($order['deep_links']['phonepe']) ?>" class="pbtn p-phonepe"> 📲 PhonePe</a>
          <a href="<?= htmlspecialchars($order['deep_links']['paytm'])   ?>" class="pbtn p-paytm">  💳 Paytm</a>
        </div>

        <div class="or-manual">── Pay karne ke baad ──</div>
        <button class="check-btn" onclick="checkPay()">✅ Maine Pay Kar Diya — Get Download Link</button>

        <div id="status"></div>

        <div id="utr-box" class="utr-box" style="display:none">
          <input type="text" id="utr-inp" placeholder="12-digit UTR Number" maxlength="12"
            oninput="this.value=this.value.replace(/\D/g,'')">
          <button onclick="submitUTR()">Submit UTR → Get Link</button>
        </div>
      </div>

      <!-- Download success panel -->
      <div id="download-section">
        <div class="dl-icon">⬇️</div>
        <h3>Payment Verified!</h3>
        <p>Tumhara download ready hai. Link 1 ghante ke liye valid hai.</p>
        <a id="dl-link" href="#" class="dl-btn">📥 Download Now</a>
        <div class="dl-note">⚠️ Link share mat karo — yeh sirf tumhare liye hai</div>
      </div>
    </div>

  </div>
</div>

<script>
const ORDER_ID   = "<?= $orderId ?>";
const PRODUCT_ID = "<?= $productKey ?>";

function setStatus(msg, cls) {
  const el = document.getElementById('status');
  el.className = 's-' + cls;
  el.innerHTML = msg;
}

async function callVerify(utr = '') {
  const body = new URLSearchParams({ action: 'verify', order_id: ORDER_ID, utr });
  const res  = await fetch('', { method: 'POST', body });
  return res.json();
}

async function checkPay() {
  setStatus('🔄 Payment check ho rahi hai...', 'check');

  const data = await callVerify();

  if (data.status === 'paid' || data.status === 'already_paid') {
    showDownload(data.download_url);
  } else if (data.status === 'utr_required') {
    setStatus('📝 UTR number daalo (Bank Reference / Transaction ID):', 'check');
    document.getElementById('utr-box').style.display = 'block';
  } else if (data.status === 'not_paid') {
    setStatus('⏳ Payment nahi aayi abhi. 10 sec baad retry karo.', 'check');
    setTimeout(() => document.getElementById('status').style.display='none', 5000);
  } else if (data.status === 'expired') {
    setStatus('❌ Order expire ho gaya. Page refresh karo — naya QR milega.', 'error');
  } else {
    setStatus('ℹ️ ' + (data.message || data.status), 'check');
  }
}

async function submitUTR() {
  const utr = document.getElementById('utr-inp').value.trim();
  if (!/^\d{12}$/.test(utr)) { alert('Exactly 12 digit UTR daalo!'); return; }

  setStatus('🔄 UTR verify ho raha hai...', 'check');
  const data = await callVerify(utr);

  if (data.status === 'paid' || data.status === 'already_paid') {
    showDownload(data.download_url);
  } else if (data.status === 'manual_pending') {
    setStatus('⏳ UTR submit ho gaya! Merchant approve karega, phir download link milega.', 'check');
  } else {
    setStatus('❌ ' + (data.message || 'UTR verify nahi hua.'), 'error');
  }
}

function showDownload(url) {
  document.getElementById('pay-panel').style.display = 'none';
  const ds = document.getElementById('download-section');
  ds.style.display = 'block';
  document.getElementById('dl-link').href = url;
}
</script>
</body>
</html>
