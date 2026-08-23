<?php
/**
 * UPI Payment Gateway by YourApis — Login
 * API Key based login — no password needed
 */
session_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim($_POST['apikey'] ?? '');

    if (empty($apiKey)) {
        $error = 'API Key is required to login.';
    } else {
        $ch = curl_init(VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'key_id'  => KEY_ID,
                'api_key' => $apiKey,
                'secret'  => SECRET,
            ]),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch); curl_close($ch);
        $info = $res ? json_decode($res, true) : null;

        if (!$info || !($info['valid'] ?? false)) {
            $error = $info['message'] ?? 'Invalid API Key. Copy the correct key from your YourApis dashboard.';
        } else {
            $_SESSION['qrpay_apikey']       = $apiKey;
            $_SESSION['qrpay_daily_left']   = $info['daily_left']   ?? '—';
            $_SESSION['qrpay_monthly_left'] = $info['monthly_left'] ?? '—';
            $_SESSION['qrpay_expiry']       = $info['expire_at']    ?? '—';
            $_SESSION['qrpay_provider']     = $info['provider']     ?? '—';
            $_SESSION['qrpay_developer']    = $info['developer']    ?? '—';
            $_SESSION['qrpay_login_time']   = time();

            $pdo->prepare("INSERT IGNORE INTO user_settings (apikey) VALUES (?)")
                ->execute([$apiKey]);

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UPI Payment Gateway by YourApis — Login</title>
<style>
  *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
  :root {
    --brand:    #6366f1;
    --brand2:   #8b5cf6;
    --success:  #10b981;
    --danger:   #ef4444;
    --warn:     #f59e0b;
    --bg:       #0a0a0f;
    --surface:  #111118;
    --border:   rgba(255,255,255,0.08);
    --text:     #e2e8f0;
    --muted:    #64748b;
  }
  body {
    min-height:100vh;
    background: var(--bg);
    background-image:
      radial-gradient(ellipse 60% 50% at 50% -10%, rgba(99,102,241,0.18) 0%, transparent 70%);
    display:flex; align-items:center; justify-content:center;
    font-family: 'Segoe UI', system-ui, sans-serif;
    color: var(--text);
  }
  .login-wrap {
    width:100%; max-width:440px; padding:24px;
  }
  .brand-badge {
    text-align:center; margin-bottom:36px;
  }
  .brand-icon {
    width:56px; height:56px; margin:0 auto 14px;
    background: linear-gradient(135deg, var(--brand), var(--brand2));
    border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    box-shadow: 0 0 40px rgba(99,102,241,0.4);
  }
  .brand-badge h1 { font-size:20px; font-weight:700; color:#fff; letter-spacing:-.3px; }
  .brand-badge p  { font-size:13px; color:var(--muted); margin-top:5px; }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius:20px;
    padding:36px 32px;
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
    animation: up .4s ease;
  }
  @keyframes up { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:none} }
  .card h2 { font-size:16px; font-weight:600; color:#fff; margin-bottom:6px; }
  .card p  { font-size:13px; color:var(--muted); margin-bottom:28px; }
  .field { margin-bottom:20px; }
  .field label { display:block; font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px; }
  .field input {
    width:100%; background:rgba(255,255,255,0.05);
    border:1px solid var(--border); border-radius:12px;
    padding:14px 16px; color:#fff; font-size:14px;
    outline:none; transition:.2s; font-family:monospace;
    letter-spacing:.5px;
  }
  .field input:focus { border-color:var(--brand); background:rgba(99,102,241,0.06); box-shadow:0 0 0 3px rgba(99,102,241,0.12); }
  .field input::placeholder { color:#3a3a4a; font-family:'Segoe UI',sans-serif; letter-spacing:0; }
  .btn {
    width:100%; padding:15px;
    background:linear-gradient(135deg,var(--brand),var(--brand2));
    border:none; border-radius:12px;
    color:#fff; font-size:15px; font-weight:600; cursor:pointer;
    transition:.2s; letter-spacing:.2px;
  }
  .btn:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 8px 24px rgba(99,102,241,0.35); }
  .btn:active { transform:none; }
  .error {
    background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25);
    border-radius:12px; padding:13px 16px;
    color:#fca5a5; font-size:13px; margin-bottom:22px;
    display:flex; align-items:center; gap:10px;
  }
  .hint { text-align:center; font-size:12px; color:var(--muted); margin-top:22px; line-height:1.7; }
  .hint a { color:var(--brand); text-decoration:none; }
  .hint a:hover { text-decoration:underline; }
  .divider { border:none; border-top:1px solid var(--border); margin:22px 0; }
  .feature-list { display:flex; gap:12px; margin-bottom:28px; flex-wrap:wrap; }
  .feat { background:rgba(255,255,255,0.03); border:1px solid var(--border); border-radius:10px; padding:10px 14px; font-size:12px; color:var(--muted); display:flex; align-items:center; gap:6px; }
  .feat span { color:#fff; font-weight:500; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="brand-badge">
    <div class="brand-icon">₹</div>
    <h1>UPI Payment Gateway</h1>
    <p>by YourApis</p>
  </div>

  <div class="card">
    <h2>Merchant Login</h2>
    <p>Enter your API key to access the merchant dashboard.</p>

    <div class="feature-list">
      <div class="feat">⚡ <span>Instant QR Payments</span></div>
      <div class="feat">🔒 <span>Auto Verification</span></div>
      <div class="feat">📋 <span>Manual UTR Flow</span></div>
    </div>

    <?php if ($error): ?>
      <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="field">
        <label>API Key</label>
        <input
          type="password"
          name="apikey"
          placeholder="Paste your YourApis API key…"
          autocomplete="off"
          required
        >
      </div>
      <button class="btn" type="submit">Login to Dashboard →</button>
    </form>

    <hr class="divider">
    <p class="hint">
      Don't have an API key?
      <a href="https://yourapi.42web.io" target="_blank">Register on YourApis</a>
      and copy your key from the dashboard.
    </p>
  </div>
</div>
</body>
</html>
