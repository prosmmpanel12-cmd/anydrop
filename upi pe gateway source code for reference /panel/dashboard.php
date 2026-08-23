<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/db.php';
date_default_timezone_set('Asia/Kolkata');

// Settings fetch
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE apikey = ?");
$stmt->execute([$PANEL_APIKEY]);
$settings = $stmt->fetch() ?: [];

// Recent orders (last 20)
$ostmt = $pdo->prepare("SELECT * FROM payment_orders WHERE apikey = ? ORDER BY created_at DESC LIMIT 20");
$ostmt->execute([$PANEL_APIKEY]);
$orders = $ostmt->fetchAll();

// Manual pending orders
$mpstmt = $pdo->prepare("SELECT * FROM payment_orders WHERE apikey = ? AND status = 'MANUAL_PENDING' ORDER BY utr_submitted_at DESC");
$mpstmt->execute([$PANEL_APIKEY]);
$manualOrders = $mpstmt->fetchAll();

// Stats
$sstmt = $pdo->prepare("SELECT status, COUNT(*) as cnt, SUM(amount) as total FROM payment_orders WHERE apikey = ? GROUP BY status");
$sstmt->execute([$PANEL_APIKEY]);
$stats = [];
foreach ($sstmt->fetchAll() as $r) $stats[$r['status']] = $r;

$maskedKey = substr($PANEL_APIKEY, 0, 6) . '••••••••' . substr($PANEL_APIKEY, -4);
$BASE_URL  = 'https://yourapi.42web.io/api/upi';

// ── Settings Save ─────────────────────────────────────────────────────────
$saveMsg = $saveErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $upiId = trim($_POST['upi_id']       ?? '');
    $dname = trim($_POST['display_name'] ?? '');
    $mid   = trim($_POST['mid']          ?? '');
    if (empty($upiId)) {
        $saveErr = 'UPI ID is required.';
    } else {
        $pdo->prepare("
            INSERT INTO user_settings (apikey, upi_id, display_name, mid)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              upi_id=VALUES(upi_id), display_name=VALUES(display_name), mid=VALUES(mid)
        ")->execute([$PANEL_APIKEY, $upiId, $dname ?: null, $mid ?: null]);
        $stmt->execute([$PANEL_APIKEY]);
        $settings = $stmt->fetch() ?: [];
        $saveMsg = 'Settings saved successfully!';
    }
}

// ── Manual Approve/Reject ────────────────────────────────────────────────
$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_action'])) {
    $act    = $_POST['action']       ?? '';
    $oid    = $_POST['action_order'] ?? '';
    $reason = $_POST['reject_reason'] ?? 'Rejected by merchant';
    if ($act === 'approve') {
        $pdo->prepare("UPDATE payment_orders SET status='PAID', verified_at=NOW() WHERE order_id=? AND apikey=? AND status='MANUAL_PENDING'")->execute([$oid, $PANEL_APIKEY]);
        $actionMsg = "✅ Order $oid approved successfully!";
    } elseif ($act === 'reject') {
        $pdo->prepare("UPDATE payment_orders SET status='REJECTED', reject_reason=?, verified_at=NOW() WHERE order_id=? AND apikey=? AND status='MANUAL_PENDING'")->execute([$reason, $oid, $PANEL_APIKEY]);
        $actionMsg = "❌ Order $oid rejected.";
    }
    $mpstmt->execute([$PANEL_APIKEY]);
    $manualOrders = $pdo->prepare("SELECT * FROM payment_orders WHERE apikey = ? AND status = 'MANUAL_PENDING' ORDER BY utr_submitted_at DESC");
    $manualOrders->execute([$PANEL_APIKEY]);
    $manualOrders = $manualOrders->fetchAll();
}

$manualCount = count($manualOrders);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UpiPe · UPI Payment Gateway — Dashboard</title>
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
  --brand:   #6366f1;
  --brand2:  #8b5cf6;
  --green:   #10b981;
  --blue:    #3b82f6;
  --orange:  #f59e0b;
  --red:     #ef4444;
  --bg:      #08080e;
  --surface: #0f0f18;
  --card:    #13131f;
  --border:  rgba(255,255,255,0.07);
  --text:    #e2e8f0;
  --muted:   #64748b;
  --sidebar: 240px;
}
body { background:var(--bg); color:var(--text); font-family:'Segoe UI',system-ui,sans-serif; min-height:100vh; display:flex; }

/* ── Sidebar ── */
.sidebar {
  width:var(--sidebar); min-height:100vh;
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex; flex-direction:column;
  position:fixed; top:0; left:0; z-index:200;
  transition:.3s;
}
.sidebar-logo {
  padding:24px 20px 20px;
  border-bottom:1px solid var(--border);
}
.sidebar-logo .logo-mark {
  width:38px; height:38px;
  background:linear-gradient(135deg,var(--brand),var(--brand2));
  border-radius:10px; display:flex; align-items:center; justify-content:center;
  font-size:18px; font-weight:800; color:#fff; margin-bottom:10px;
  box-shadow:0 0 20px rgba(99,102,241,0.35);
}
.sidebar-logo h1 { font-size:13px; font-weight:700; color:#fff; line-height:1.3; }
.sidebar-logo p  { font-size:11px; color:var(--muted); margin-top:2px; }

.sidebar-nav { padding:16px 12px; flex:1; }
.nav-section { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; padding:0 8px; margin:16px 0 8px; }
.nav-item {
  display:flex; align-items:center; gap:10px;
  padding:10px 12px; border-radius:10px;
  cursor:pointer; font-size:13px; color:var(--muted);
  border:none; background:none; width:100%; text-align:left;
  transition:.15s; position:relative; margin-bottom:2px;
}
.nav-item:hover { background:rgba(255,255,255,0.05); color:#fff; }
.nav-item.active { background:rgba(99,102,241,0.15); color:var(--brand); font-weight:600; }
.nav-item .icon { font-size:16px; width:20px; text-align:center; display:flex; align-items:center; justify-content:center; }
.nav-item .icon svg { stroke:currentColor; }
.nav-item .badge-dot {
  background:var(--red); color:#fff; border-radius:10px;
  padding:1px 7px; font-size:10px; font-weight:700;
  margin-left:auto;
}

.sidebar-footer {
  padding:16px 12px;
  border-top:1px solid var(--border);
}
.key-chip {
  background:rgba(255,255,255,0.04); border:1px solid var(--border);
  border-radius:8px; padding:10px 12px; margin-bottom:10px;
}
.key-chip .label { font-size:10px; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; }
.key-chip .val { font-size:12px; font-family:monospace; color:#fff; margin-top:3px; }
.logout-btn {
  display:flex; align-items:center; gap:8px;
  padding:10px 12px; border-radius:10px;
  cursor:pointer; font-size:13px; color:var(--red);
  border:none; background:none; width:100%; text-align:left;
  transition:.15s;
}
.logout-btn:hover { background:rgba(239,68,68,0.1); }

/* ── Main ── */
.main {
  margin-left:var(--sidebar);
  flex:1; min-height:100vh;
  display:flex; flex-direction:column;
}
.topbar {
  background:rgba(13,13,24,0.9); backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);
  padding:0 28px; height:58px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:100;
}
.topbar-left h2 { font-size:16px; font-weight:600; color:#fff; }
.topbar-left p  { font-size:12px; color:var(--muted); }
.topbar-right { display:flex; align-items:center; gap:12px; }
.status-pill {
  font-size:11px; font-weight:600; padding:5px 12px; border-radius:20px;
  background:rgba(16,185,129,0.12); color:var(--green);
  border:1px solid rgba(16,185,129,0.2);
}

.content { padding:28px; flex:1; }
.page { display:none; }
.page.active { display:block; }

/* ── Cards ── */
.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card {
  background:var(--card); border:1px solid var(--border); border-radius:16px;
  padding:20px; position:relative; overflow:hidden;
}
.stat-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:2px;
  background:linear-gradient(90deg,var(--accent,var(--brand)),transparent);
}
.stat-card.green { --accent:var(--green); }
.stat-card.blue  { --accent:var(--blue); }
.stat-card.orange{ --accent:var(--orange); }
.stat-card.red   { --accent:var(--red); }
.stat-card.purple{ --accent:var(--brand); }

.stat-icon { font-size:20px; margin-bottom:12px; }
.stat-val  { font-size:28px; font-weight:700; color:#fff; line-height:1; }
.stat-label{ font-size:12px; color:var(--muted); margin-top:6px; }
.stat-sub  { font-size:11px; margin-top:4px; }

.card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-bottom:24px; }
.card {
  background:var(--card); border:1px solid var(--border); border-radius:16px; padding:22px;
}
.card-title { font-size:14px; font-weight:600; color:#fff; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

/* ── Table ── */
.table-wrap { overflow-x:auto; margin-top:4px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { text-align:left; padding:10px 12px; color:var(--muted); font-weight:500; font-size:12px; border-bottom:1px solid var(--border); }
td { padding:10px 12px; border-bottom:1px solid rgba(255,255,255,0.03); vertical-align:middle; }
tr:last-child td { border:none; }
tr:hover td { background:rgba(255,255,255,0.02); }

.badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-green  { background:rgba(16,185,129,0.12);  color:var(--green); }
.badge-blue   { background:rgba(59,130,246,0.12);  color:var(--blue); }
.badge-orange { background:rgba(245,158,11,0.12);  color:var(--orange); }
.badge-red    { background:rgba(239,68,68,0.12);   color:var(--red); }
.badge-purple { background:rgba(99,102,241,0.12);  color:var(--brand); }

/* ── Info rows ── */
.info-row { display:flex; justify-content:space-between; align-items:center; padding:11px 0; border-bottom:1px solid var(--border); }
.info-row:last-child { border:none; }
.info-label { font-size:13px; color:var(--muted); }
.info-val   { font-size:13px; color:#fff; font-weight:500; }

/* ── Forms ── */
.field       { margin-bottom:18px; }
.field label { display:block; font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
.field input,
.field select,
.field textarea {
  width:100%; background:rgba(255,255,255,0.05);
  border:1px solid var(--border); border-radius:10px;
  padding:12px 14px; color:#fff; font-size:14px; outline:none; transition:.2s;
}
.field input:focus,
.field select:focus { border-color:var(--brand); background:rgba(99,102,241,0.06); }
.field small { display:block; font-size:11px; color:var(--muted); margin-top:5px; }
.field input::placeholder { color:#333345; }

/* ── Buttons ── */
.btn { background:linear-gradient(135deg,var(--brand),var(--brand2)); border:none; border-radius:10px; padding:12px 22px; color:#fff; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; }
.btn:hover { opacity:.9; transform:translateY(-1px); }
.btn-sm   { padding:7px 14px; font-size:12px; border-radius:8px; }
.btn-green{ background:linear-gradient(135deg,var(--green),#059669); }
.btn-red  { background:linear-gradient(135deg,var(--red),#b91c1c); }
.btn-outline { background:transparent; border:1px solid rgba(99,102,241,0.4); color:var(--brand); }
.btn-outline:hover { background:rgba(99,102,241,0.1); transform:none; }

/* ── Alert ── */
.alert { border-radius:12px; padding:13px 16px; font-size:13px; margin-bottom:18px; }
.alert-success { background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); color:#6ee7b7; }
.alert-error   { background:rgba(239,68,68,0.08);  border:1px solid rgba(239,68,68,0.25);  color:#fca5a5; }

/* ── Code box ── */
.code-box {
  background:#09090f; border:1px solid var(--border); border-radius:10px;
  padding:14px 16px; font-family:monospace; font-size:12px; color:#818cf8;
  word-break:break-all; position:relative; margin:8px 0 16px;
}
.code-box pre { color:#a5b4fc; font-size:12px; white-space:pre-wrap; }
.copy-btn {
  position:absolute; top:10px; right:10px;
  background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.25);
  color:var(--brand); border-radius:6px; padding:4px 10px; font-size:11px; cursor:pointer;
  transition:.15s;
}
.copy-btn:hover { background:rgba(99,102,241,0.2); }

/* ── Manual card ── */
.manual-card {
  background:rgba(59,130,246,0.05); border:1px solid rgba(59,130,246,0.15);
  border-radius:14px; padding:18px; margin-bottom:14px;
}
.utr-badge { font-family:monospace; background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.25); color:var(--brand); padding:4px 12px; border-radius:8px; font-size:13px; }
.manual-actions { display:flex; gap:8px; margin-top:14px; flex-wrap:wrap; align-items:center; }
.reject-input {
  background:rgba(255,255,255,0.05); border:1px solid var(--border);
  border-radius:8px; padding:8px 12px; color:#fff; font-size:12px; flex:1; min-width:150px; outline:none;
}

/* ── Test panel ── */
.test-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(310px,1fr)); gap:20px; margin-bottom:20px; }
.test-result {
  background:#09090f; border:1px solid var(--border); border-radius:12px;
  padding:18px; font-family:monospace; font-size:12px; color:#a5b4fc;
  min-height:100px; white-space:pre-wrap; word-break:break-all; display:none;
  margin-top:8px;
}

/* ── Endpoint tabs ── */
.ep-tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
.ep-tab {
  padding:8px 16px; border-radius:8px; cursor:pointer; font-size:12px; color:var(--muted);
  border:1px solid var(--border); background:transparent; transition:.15s;
}
.ep-tab.active  { background:rgba(99,102,241,0.1); color:var(--brand); border-color:rgba(99,102,241,0.3); }
.ep-tab:hover:not(.active) { color:#fff; }
.ep-doc { display:none; }
.ep-doc.active { display:block; }

.param-table th { color:var(--brand); font-size:11px; }
.param-table td { font-size:12px; vertical-align:top; }
.param-name { font-family:monospace; color:var(--orange); }
.param-req  { color:var(--red); font-size:11px; }
.param-opt  { color:var(--muted); font-size:11px; }
.resp-block { background:#09090f; border:1px solid var(--border); border-radius:10px; padding:16px; font-family:monospace; font-size:12px; color:#a5b4fc; margin-top:12px; overflow-x:auto; }

code { background:rgba(255,255,255,0.07); padding:2px 7px; border-radius:5px; font-family:monospace; font-size:12px; color:var(--orange); }

.section-title { font-size:14px; font-weight:600; color:#fff; margin-bottom:14px; }
.page-header { margin-bottom:24px; }
.page-header h2 { font-size:20px; font-weight:700; color:#fff; }
.page-header p  { font-size:13px; color:var(--muted); margin-top:4px; }

/* ── Sidebar toggle (mobile) ── */
.sidebar-toggle {
  display:none; background:none; border:none; color:#fff; font-size:22px; cursor:pointer; padding:4px;
}
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:150; }

@media(max-width:768px){
  :root { --sidebar:0px; }
  .sidebar { transform:translateX(-240px); width:240px; }
  .sidebar.open { transform:translateX(0); }
  .sidebar-overlay.show { display:block; }
  .main { margin-left:0; }
  .sidebar-toggle { display:block; }
  .content { padding:20px 16px; }
  .topbar { padding:0 16px; }
  .stat-grid { grid-template-columns:repeat(2,1fr); }
}
</style>
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">
      <!-- UPI Rupee logo mark -->
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 3h12M6 8h12M6 13l6 8 6-8M6 8a6 6 0 0 0 6 6"/>
      </svg>
    </div>
    <h1>UpiPe Gateway</h1>
    <p>by YourApis</p>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>

    <button class="nav-item active" onclick="showPage('overview',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
          <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
      </span>
      Overview
    </button>

    <button class="nav-item" onclick="showPage('orders',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
          <rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>
        </svg>
      </span>
      Orders
    </button>

    <button class="nav-item" onclick="showPage('manual',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/>
          <path d="M12 8v4l3 3"/>
        </svg>
      </span>
      Manual Review
      <?php if($manualCount>0): ?><span class="badge-dot"><?= $manualCount ?></span><?php endif; ?>
    </button>

    <button class="nav-item" onclick="showPage('test',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/><path d="m13 8 3 3-7 7-4 1 1-4 7-7z"/>
        </svg>
      </span>
      Test Payment
    </button>

    <div class="nav-section">Developer</div>

    <button class="nav-item" onclick="showPage('docs',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>
        </svg>
      </span>
      API Docs
    </button>

    <button class="nav-item" onclick="showPage('sdk',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="16,18 22,12 16,6"/><polyline points="8,6 2,12 8,18"/><line x1="12" y1="4" x2="12" y2="20"/>
        </svg>
      </span>
      SDK & Examples
    </button>

    <div class="nav-section">Account</div>

    <button class="nav-item" onclick="showPage('settings',this)">
      <span class="icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </span>
      Settings
    </button>
  </nav>

  <div class="sidebar-footer">
    <div class="key-chip">
      <div class="label">API Key</div>
      <div class="val"><?= $maskedKey ?></div>
    </div>
    <a href="logout.php">
      <button class="logout-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:middle">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        Logout
      </button>
    </a>
  </div>
</aside>

<!-- ── MAIN ── -->
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="sidebar-toggle" onclick="toggleSidebar()">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="topbar-left">
        <h2 id="page-title">Overview</h2>
        <p id="page-sub">UpiPe · UPI Payment Gateway by YourApis</p>
      </div>
    </div>
    <div class="topbar-right">
      <span class="status-pill">● Live</span>
    </div>
  </div>

  <div class="content">

    <!-- ══════════ OVERVIEW ══════════ -->
    <div class="page active" id="page-overview">
      <?php if($actionMsg): ?><div class="alert alert-success"><?= htmlspecialchars($actionMsg) ?></div><?php endif; ?>

      <!-- API Key stats -->
      <div class="stat-grid" style="margin-bottom:20px">
        <div class="stat-card green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
          <div class="stat-val"><?= htmlspecialchars($PANEL_DAILY) ?></div>
          <div class="stat-label">Daily Calls Left</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg></div>
          <div class="stat-val"><?= $PANEL_MONTHLY == 999999 ? '∞' : htmlspecialchars($PANEL_MONTHLY) ?></div>
          <div class="stat-label">Monthly Calls Left</div>
        </div>
        <div class="stat-card orange">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div>
          <div class="stat-val" style="font-size:16px;margin-top:4px"><?= htmlspecialchars($PANEL_EXPIRY) ?></div>
          <div class="stat-label">Key Expires</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg></div>
          <div class="stat-val" style="font-size:14px;margin-top:4px"><?= htmlspecialchars($PANEL_PROVIDER) ?></div>
          <div class="stat-label">Provider</div>
        </div>
      </div>

      <!-- Order stats -->
      <div class="stat-grid" style="margin-bottom:24px">
        <div class="stat-card green">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg></div>
          <div class="stat-val"><?= $stats['PAID']['cnt'] ?? 0 ?></div>
          <div class="stat-label">Paid Orders</div>
          <div class="stat-sub" style="color:var(--green)">₹<?= number_format($stats['PAID']['total'] ?? 0, 2) ?></div>
        </div>
        <div class="stat-card orange">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg></div>
          <div class="stat-val"><?= $stats['PENDING']['cnt'] ?? 0 ?></div>
          <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
          <div class="stat-val"><?= $stats['MANUAL_PENDING']['cnt'] ?? 0 ?></div>
          <div class="stat-label">Manual Review</div>
        </div>
        <div class="stat-card red">
          <div class="stat-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
          <div class="stat-val"><?= ($stats['EXPIRED']['cnt']??0)+($stats['REJECTED']['cnt']??0) ?></div>
          <div class="stat-label">Expired / Rejected</div>
        </div>
      </div>

      <div class="card-grid">
        <div class="card">
          <div class="card-title">🔑 Account Info</div>
          <div class="info-row">
            <span class="info-label">Developer</span>
            <span class="info-val"><?= htmlspecialchars($PANEL_DEV) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">API Key</span>
            <span class="info-val" style="font-family:monospace;font-size:12px"><?= $maskedKey ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Key Expires</span>
            <span class="info-val"><?= htmlspecialchars($PANEL_EXPIRY) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Provider</span>
            <span class="info-val"><?= htmlspecialchars($PANEL_PROVIDER) ?></span>
          </div>
        </div>

        <div class="card">
          <div class="card-title">💳 UPI Settings</div>
          <?php if(!empty($settings['upi_id'])): ?>
            <div class="info-row">
              <span class="info-label">UPI ID</span>
              <span class="info-val"><span class="badge badge-green"><?= htmlspecialchars($settings['upi_id']) ?></span></span>
            </div>
            <div class="info-row">
              <span class="info-label">Display Name</span>
              <span class="info-val"><?= !empty($settings['display_name']) ? htmlspecialchars($settings['display_name']) : '<span style="color:var(--muted)">Not set</span>' ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Payment Mode</span>
              <span class="info-val">
                <?php if (!empty($settings['mid'])): ?>
                  <span class="badge badge-green">Auto (Paytm)</span>
                <?php else: ?>
                  <span class="badge badge-orange">Manual (UTR)</span>
                <?php endif; ?>
              </span>
            </div>
            <?php if (!empty($settings['mid'])): ?>
            <div class="info-row">
              <span class="info-label">MID</span>
              <span class="info-val" style="font-family:monospace;font-size:12px"><?= htmlspecialchars(substr($settings['mid'],0,4).'••••••'.substr($settings['mid'],-4)) ?></span>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div style="text-align:center;padding:24px;color:var(--muted)">
              ⚠️ UPI ID not configured.<br>
              <button onclick="showPage('settings',null)" style="background:none;border:none;color:var(--brand);cursor:pointer;font-size:13px;margin-top:10px;">Go to Settings →</button>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Recent Orders -->
      <div class="card">
        <div class="card-title">🕐 Recent Orders</div>
        <?php if(empty($orders)): ?>
          <div style="text-align:center;padding:40px;color:var(--muted)">No orders yet. Create your first payment to get started.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Order ID</th><th>Customer</th><th>Amount</th><th>Mode</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach($orders as $o):
              $bc = match($o['status']){'PAID'=>'badge-green','PENDING'=>'badge-orange','MANUAL_PENDING'=>'badge-blue','EXPIRED'=>'badge-red','REJECTED'=>'badge-red',default=>''};
            ?>
              <tr>
                <td style="font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars($o['order_id']) ?></td>
                <td><?= htmlspecialchars($o['customer_id']) ?></td>
                <td style="font-weight:600;color:#fff">₹<?= number_format($o['amount'],2) ?></td>
                <td><span class="badge badge-purple"><?= strtoupper($o['mode']) ?></span></td>
                <td><span class="badge <?= $bc ?>"><?= $o['status'] ?></span></td>
                <td style="color:var(--muted)"><?= date('d M, H:i', strtotime($o['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══════════ MANUAL REVIEW ══════════ -->
    <div class="page" id="page-manual">
      <div class="page-header">
        <h2>Manual Payment Review</h2>
        <p>Customers have submitted their UTR number. Approve or reject each payment.</p>
      </div>

      <?php if(empty($manualOrders)): ?>
        <div class="card" style="text-align:center;padding:48px;color:var(--muted)">
          ✅ No pending manual orders at the moment.
        </div>
      <?php else: ?>
        <?php foreach($manualOrders as $mo): ?>
        <div class="manual-card">
          <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:10px">
            <div>
              <span style="font-family:monospace;font-size:11px;color:var(--muted)"><?= htmlspecialchars($mo['order_id']) ?></span>
              <span class="badge badge-blue" style="margin-left:8px">MANUAL_PENDING</span>
            </div>
            <div style="font-size:20px;font-weight:700;color:#fff">₹<?= number_format($mo['amount'],2) ?></div>
          </div>
          <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-bottom:12px">
            <span>👤 Customer: <strong style="color:#fff"><?= htmlspecialchars($mo['customer_id']) ?></strong></span>
            <span>📅 Submitted: <strong style="color:#fff"><?= date('d M H:i', strtotime($mo['utr_submitted_at']??$mo['created_at'])) ?></strong></span>
            <?php if($mo['note']): ?><span>📝 Note: <strong style="color:#fff"><?= htmlspecialchars($mo['note']) ?></strong></span><?php endif; ?>
          </div>
          <div style="margin-bottom:4px">UTR Reference: <span class="utr-badge"><?= htmlspecialchars($mo['utr']) ?></span></div>
          <form method="POST" style="display:inline">
            <input type="hidden" name="manual_action" value="1">
            <input type="hidden" name="action_order" value="<?= htmlspecialchars($mo['order_id']) ?>">
            <div class="manual-actions">
              <button type="submit" name="action" value="approve" class="btn btn-sm btn-green"
                onclick="return confirm('Approve UTR <?= htmlspecialchars($mo['utr']) ?>?')">
                ✅ Approve
              </button>
              <input type="text" name="reject_reason" class="reject-input" placeholder="Reject reason (optional)…">
              <button type="submit" name="action" value="reject" class="btn btn-sm btn-red"
                onclick="return confirm('Reject this order?')">
                ❌ Reject
              </button>
            </div>
          </form>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ══════════ TEST PAYMENT ══════════ -->
    <div class="page" id="page-test">
      <div class="page-header">
        <h2>Test Payment</h2>
        <p>Create a live order with your API key, scan the QR, pay, then verify.</p>
      </div>

      <div class="test-grid">
        <div class="card">
          <div class="card-title">Step 1 — Create Order</div>
          <div class="field">
            <label>Amount (₹)</label>
            <input type="number" id="t_amount" value="1" min="1" max="100000" placeholder="Enter amount">
          </div>
          <div class="field">
            <label>Customer / Order ID</label>
            <input type="text" id="t_cid" value="test_user_<?= rand(100,999) ?>">
          </div>
          <div class="field">
            <label>Note</label>
            <input type="text" id="t_note" value="Test Payment">
          </div>
          <div class="field">
            <label>Mode</label>
            <select id="t_mode" onchange="onModeChange()">
              <?php if (!empty($settings['mid'])): ?>
              <option value="auto" selected>Auto (Paytm verify)</option>
              <option value="manual">Manual (UTR required)</option>
              <?php else: ?>
              <option value="manual" selected>Manual (UTR required)</option>
              <option value="auto">Auto (Paytm verify — MID required)</option>
              <?php endif; ?>
            </select>
          </div>
          <button class="btn" onclick="createTestOrder()">🚀 Create Order</button>
        </div>

        <div class="card" id="qr-panel" style="display:none">
          <div class="card-title">Step 2 — Scan & Pay</div>
          <div style="text-align:center;margin-bottom:18px">
            <img id="qr-img" src="" alt="QR Code" style="width:190px;height:190px;border-radius:14px;border:2px solid rgba(99,102,241,0.3)">
            <div id="order-id-disp" style="font-family:monospace;font-size:11px;color:var(--muted);margin-top:8px"></div>
            <div id="expires-disp" style="font-size:11px;color:var(--orange);margin-top:4px"></div>
          </div>
          <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:20px">
            <a id="gpay-link"    href="#" class="btn btn-sm btn-outline" target="_blank">GPay</a>
            <a id="phonepe-link" href="#" class="btn btn-sm btn-outline" target="_blank">PhonePe</a>
            <a id="paytm-link"   href="#" class="btn btn-sm btn-outline" target="_blank">Paytm</a>
          </div>

          <div class="card-title" style="margin-bottom:12px">Step 3 — Verify Payment</div>

          <!-- UTR box: shown always for manual, shown after failure for auto -->
          <div id="utr-box" style="display:none;margin-bottom:14px">
            <div class="field">
              <label>12-Digit UTR / Reference Number</label>
              <input type="text" id="t_utr" placeholder="123456789012" maxlength="12" inputmode="numeric">
              <small id="utr-hint">Enter the UTR number from your bank payment confirmation.</small>
            </div>
          </div>

          <button class="btn" onclick="verifyTestPayment()">🔍 Verify Payment</button>
        </div>
      </div>

      <div id="test-result" class="test-result"></div>
    </div>

    <!-- ══════════ API DOCS ══════════ -->
    <div class="page" id="page-docs">
      <div class="page-header">
        <h2>📡 API Documentation</h2>
        <p>All endpoints for UPI Payment Gateway by YourApis. Base URL: <code style="color:var(--brand)"><?= $BASE_URL ?></code></p>
      </div>

      <div class="ep-tabs">
        <button class="ep-tab active" onclick="showEp('create',this)">🛒 Create Order</button>
        <button class="ep-tab" onclick="showEp('verify',this)">✅ Verify Payment</button>
        <button class="ep-tab" onclick="showEp('status',this)">🔍 Order Status</button>
        <button class="ep-tab" onclick="showEp('manual-orders',this)">📋 Manual Orders</button>
        <button class="ep-tab" onclick="showEp('manual-action',this)">⚖️ Approve / Reject</button>
      </div>

      <!-- CREATE ORDER -->
      <div class="ep-doc active" id="ep-create">
        <div class="card" style="margin-bottom:16px">
          <div class="section-title">🛒 Create Payment Order</div>
          <div class="code-box">
            GET <?= $BASE_URL ?>/api/create_order.php?apikey=YOUR_KEY&amp;amount=499&amp;customer_id=user_42
            <button class="copy-btn" onclick="copyText(this,'GET <?= $BASE_URL ?>/api/create_order.php?apikey=YOUR_KEY&amount=499&customer_id=user_42')">Copy</button>
          </div>
          <table class="param-table">
            <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
            <tr><td class="param-name">apikey</td><td>string</td><td class="param-req">Required</td><td>Your YourApis API key</td></tr>
            <tr><td class="param-name">amount</td><td>float</td><td class="param-req">Required</td><td>Payment amount in ₹ (min ₹1, max ₹1,00,000)</td></tr>
            <tr><td class="param-name">customer_id</td><td>string</td><td class="param-req">Required</td><td>Your system's user or order ID</td></tr>
            <tr><td class="param-name">note</td><td>string</td><td class="param-opt">Optional</td><td>Payment note (default: "Payment")</td></tr>
            <tr><td class="param-name">mode</td><td>enum</td><td class="param-opt">Optional</td><td><code>auto</code> (Paytm auto-verify) or <code>manual</code> (UTR)</td></tr>
          </table>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">📄 JSON Response</div>
          <div class="resp-block">{
  "status": "success",
  "order_id": "QRP5A8B2F1719123456",
  "amount": 499,
  "currency": "INR",
  "upi_id": "merchant@upi",
  "upi_link": "upi://pay?pa=merchant@upi&pn=My+Store&am=499.00&cu=INR",
  "qr_url": "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=upi%3A%2F%2F...",
  "deep_links": {
    "gpay": "gpay://upi/pay?pa=merchant@upi&...",
    "phonepe": "phonepe://pay?pa=merchant@upi&...",
    "paytm": "paytmmp://pay?pa=merchant@upi&..."
  },
  "mode": "manual",
  "expires_at": "2026-06-13 15:30:00",
  "expires_in_sec": 1800
}</div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">🐘 PHP Sample</div>
          <div class="code-box" style="position:relative"><pre id="create-php-code">$url = '<?= $BASE_URL ?>/api/create_order.php?' . http_build_query([
    'apikey'      => '<?= htmlspecialchars($PANEL_APIKEY) ?>',
    'amount'      => 499,
    'customer_id' => 'user_42',
    'note'        => 'Order #1001',
    'mode'        => 'manual',
]);

$response = json_decode(file_get_contents($url), true);

if ($response['status'] === 'success') {
    $orderId = $response['order_id'];
    $qrUrl   = $response['qr_url'];
    echo '&lt;img src="' . $qrUrl . '" /&gt;';
} else {
    echo 'Error: ' . $response['message'];
}</pre><button class="copy-btn" onclick="copyEl(this,'create-php-code')">Copy</button></div>
        </div>
        <div class="card">
          <div class="section-title" style="font-size:13px">🌐 JavaScript (fetch) Sample</div>
          <div class="code-box" style="position:relative"><pre id="create-js-code">const apiKey = '<?= htmlspecialchars($PANEL_APIKEY) ?>';
const url = `<?= $BASE_URL ?>/api/create_order.php?apikey=${apiKey}&amount=499&customer_id=user_42&mode=manual`;

const res  = await fetch(url);
const data = await res.json();

if (data.status === 'success') {
  document.getElementById('qr').src = data.qr_url;
  console.log('Order ID:', data.order_id);
  console.log('Expires:', data.expires_at);
} else {
  console.error('Error:', data.message);
}</pre><button class="copy-btn" onclick="copyEl(this,'create-js-code')">Copy</button></div>
        </div>
      </div>

      <!-- VERIFY -->
      <div class="ep-doc" id="ep-verify">
        <div class="card" style="margin-bottom:16px">
          <div class="section-title">✅ Verify Payment</div>
          <div class="code-box">POST <?= $BASE_URL ?>/api/verify_payment.php<button class="copy-btn" onclick="copyText(this,'POST <?= $BASE_URL ?>/api/verify_payment.php')">Copy</button></div>
          <table class="param-table" style="margin-top:14px">
            <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
            <tr><td class="param-name">apikey</td><td>string</td><td class="param-req">Required</td><td>Your API key</td></tr>
            <tr><td class="param-name">order_id</td><td>string</td><td class="param-req">Required</td><td>Order ID from create_order response</td></tr>
            <tr><td class="param-name">utr</td><td>string</td><td class="param-opt">Optional</td><td>Manual mode: 12-digit bank UTR / transaction reference</td></tr>
          </table>
          <div class="section-title" style="font-size:13px;margin-top:18px">📊 Response Status Values</div>
          <div class="table-wrap"><table>
            <tr><th>Status</th><th>Meaning</th><th>Next Action</th></tr>
            <tr><td class="param-name">paid</td><td>✅ Payment verified</td><td>Process the order</td></tr>
            <tr><td class="param-name">already_paid</td><td>✅ Already verified before</td><td>Ignore duplicate</td></tr>
            <tr><td class="param-name">not_paid</td><td>⏳ Payment not received yet</td><td>Retry in 15–30 seconds</td></tr>
            <tr><td class="param-name">utr_required</td><td>📝 Auto verify failed</td><td>Show UTR input to customer</td></tr>
            <tr><td class="param-name">manual_pending</td><td>🕐 UTR submitted, awaiting review</td><td>Show "Under review" to customer</td></tr>
            <tr><td class="param-name">expired</td><td>❌ 30-minute window passed</td><td>Create a new order</td></tr>
            <tr><td class="param-name">rejected</td><td>❌ Rejected by merchant</td><td>Inform customer</td></tr>
          </table></div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">📄 JSON Response (paid)</div>
          <div class="resp-block">{
  "status": "paid",
  "message": "Payment verified successfully.",
  "order_id": "QRP5A8B2F1719123456",
  "amount": 499,
  "utr": "123456789012",
  "customer_id": "user_42",
  "verified_at": "2026-06-13 14:05:22"
}</div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">🐘 PHP Sample</div>
          <div class="code-box" style="position:relative"><pre id="verify-php-code">$ch = curl_init('<?= $BASE_URL ?>/api/verify_payment.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'apikey'   => '<?= htmlspecialchars($PANEL_APIKEY) ?>',
        'order_id' => 'QRP5A8B2F1719123456',
        // 'utr'   => '123456789012', // manual mode
    ]),
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['status'] === 'paid') {
    // Grant access, update DB, send confirmation
    echo 'Payment confirmed! UTR: ' . $response['utr'];
} elseif ($response['status'] === 'utr_required') {
    // Show UTR input form to customer
} elseif ($response['status'] === 'not_paid') {
    // Retry after 15-30 seconds
}</pre><button class="copy-btn" onclick="copyEl(this,'verify-php-code')">Copy</button></div>
        </div>
        <div class="card">
          <div class="section-title" style="font-size:13px">🌐 JavaScript (fetch) Sample</div>
          <div class="code-box" style="position:relative"><pre id="verify-js-code">const body = new URLSearchParams({
  apikey:   '<?= htmlspecialchars($PANEL_APIKEY) ?>',
  order_id: 'QRP5A8B2F1719123456',
  // utr: '123456789012',  // manual mode
});

const res  = await fetch('<?= $BASE_URL ?>/api/verify_payment.php', { method: 'POST', body });
const data = await res.json();

if (data.status === 'paid') {
  alert('Payment confirmed! UTR: ' + data.utr);
} else if (data.status === 'utr_required') {
  showUtrForm(); // Show UTR input
} else if (data.status === 'not_paid') {
  setTimeout(verifyAgain, 15000); // Retry in 15 seconds
}</pre><button class="copy-btn" onclick="copyEl(this,'verify-js-code')">Copy</button></div>
        </div>
      </div>

      <!-- STATUS -->
      <div class="ep-doc" id="ep-status">
        <div class="card" style="margin-bottom:16px">
          <div class="section-title">🔍 Order Status</div>
          <div class="code-box">GET <?= $BASE_URL ?>/api/order_status.php?apikey=YOUR_KEY&amp;order_id=QRP...<button class="copy-btn" onclick="copyText(this,'GET <?= $BASE_URL ?>/api/order_status.php?apikey=YOUR_KEY&order_id=QRP...')">Copy</button></div>
          <p style="font-size:13px;color:var(--muted);margin-top:10px">Fetch full details for any order — status, UTR, timestamps, and payment info. Use for read-only checks (does not trigger verification).</p>
          <table class="param-table" style="margin-top:14px">
            <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
            <tr><td class="param-name">apikey</td><td>string</td><td class="param-req">Required</td><td>Your API key</td></tr>
            <tr><td class="param-name">order_id</td><td>string</td><td class="param-req">Required</td><td>The order ID to look up</td></tr>
          </table>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">📄 JSON Response</div>
          <div class="resp-block">{
  "status": "success",
  "order": {
    "order_id": "QRP5A8B2F1719123456",
    "amount": "499.00",
    "customer_id": "user_42",
    "status": "PAID",
    "mode": "manual",
    "utr": "123456789012",
    "note": "Order #1001",
    "created_at": "2026-06-13 14:00:00",
    "expire_at": "2026-06-13 14:30:00",
    "verified_at": "2026-06-13 14:05:22"
  }
}</div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">🐘 PHP Sample</div>
          <div class="code-box" style="position:relative"><pre id="status-php-code">$url = '<?= $BASE_URL ?>/api/order_status.php?' . http_build_query([
    'apikey'   => '<?= htmlspecialchars($PANEL_APIKEY) ?>',
    'order_id' => 'QRP5A8B2F1719123456',
]);
$response = json_decode(file_get_contents($url), true);

if ($response['status'] === 'success') {
    $order = $response['order'];
    echo 'Status: ' . $order['status'];
    echo ' | Amount: ₹' . $order['amount'];
    echo ' | UTR: ' . ($order['utr'] ?? 'N/A');
}</pre><button class="copy-btn" onclick="copyEl(this,'status-php-code')">Copy</button></div>
        </div>
        <div class="card">
          <div class="section-title" style="font-size:13px">🌐 JavaScript (fetch) Sample</div>
          <div class="code-box" style="position:relative"><pre id="status-js-code">const apiKey = '<?= htmlspecialchars($PANEL_APIKEY) ?>';
const orderId = 'QRP5A8B2F1719123456';
const res  = await fetch(`<?= $BASE_URL ?>/api/order_status.php?apikey=${apiKey}&order_id=${orderId}`);
const data = await res.json();

if (data.status === 'success') {
  const order = data.order;
  console.log('Status:', order.status, '| UTR:', order.utr ?? 'N/A');
}</pre><button class="copy-btn" onclick="copyEl(this,'status-js-code')">Copy</button></div>
        </div>
      </div>

      <!-- MANUAL ORDERS -->
      <div class="ep-doc" id="ep-manual-orders">
        <div class="card" style="margin-bottom:16px">
          <div class="section-title">📋 List Manual Orders</div>
          <div class="code-box">GET <?= $BASE_URL ?>/api/manual_orders.php?apikey=YOUR_KEY&amp;status=MANUAL_PENDING<button class="copy-btn" onclick="copyText(this,'GET <?= $BASE_URL ?>/api/manual_orders.php?apikey=YOUR_KEY&status=MANUAL_PENDING')">Copy</button></div>
          <p style="font-size:13px;color:var(--muted);margin-top:10px">List orders filtered by status. Use this to build your own review dashboard or webhook polling.</p>
          <table class="param-table" style="margin-top:14px">
            <tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr>
            <tr><td class="param-name">apikey</td><td>string</td><td class="param-req">Required</td><td>Your API key</td></tr>
            <tr><td class="param-name">status</td><td>enum</td><td class="param-opt">Optional</td><td><code>MANUAL_PENDING</code> (default) · <code>PAID</code> · <code>REJECTED</code> · <code>PENDING</code> · <code>EXPIRED</code> · <code>ALL</code></td></tr>
          </table>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">📄 JSON Response</div>
          <div class="resp-block">{
  "status": "success",
  "count": 2,
  "orders": [
    {
      "order_id": "QRP5A8B2F1719123456",
      "amount": "499.00",
      "customer_id": "user_42",
      "status": "MANUAL_PENDING",
      "utr": "123456789012",
      "utr_submitted_at": "2026-06-13 14:03:10",
      "created_at": "2026-06-13 14:00:00"
    }
  ]
}</div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">🐘 PHP Sample</div>
          <div class="code-box" style="position:relative"><pre id="mo-php-code">$url = '<?= $BASE_URL ?>/api/manual_orders.php?' . http_build_query([
    'apikey' => '<?= htmlspecialchars($PANEL_APIKEY) ?>',
    'status' => 'MANUAL_PENDING',
]);
$response = json_decode(file_get_contents($url), true);

foreach ($response['orders'] ?? [] as $order) {
    echo $order['order_id'] . ' — ₹' . $order['amount'];
    echo ' — UTR: ' . $order['utr'] . PHP_EOL;
}</pre><button class="copy-btn" onclick="copyEl(this,'mo-php-code')">Copy</button></div>
        </div>
        <div class="card">
          <div class="section-title" style="font-size:13px">🌐 JavaScript (fetch) Sample</div>
          <div class="code-box" style="position:relative"><pre id="mo-js-code">const apiKey = '<?= htmlspecialchars($PANEL_APIKEY) ?>';
const res  = await fetch(`<?= $BASE_URL ?>/api/manual_orders.php?apikey=${apiKey}&status=MANUAL_PENDING`);
const data = await res.json();

data.orders.forEach(order => {
  console.log(order.order_id, '₹' + order.amount, 'UTR:', order.utr);
});</pre><button class="copy-btn" onclick="copyEl(this,'mo-js-code')">Copy</button></div>
        </div>
      </div>

      <!-- MANUAL ACTION -->
      <div class="ep-doc" id="ep-manual-action">
        <div class="card" style="margin-bottom:16px">
          <div class="section-title">⚖️ Approve / Reject Manual Payment</div>
          <div class="code-box">POST <?= $BASE_URL ?>/api/manual_action.php<button class="copy-btn" onclick="copyText(this,'POST <?= $BASE_URL ?>/api/manual_action.php')">Copy</button></div>
          <table class="param-table" style="margin-top:14px">
            <tr><th>Parameter</th><th>Required</th><th>Description</th></tr>
            <tr><td class="param-name">apikey</td><td class="param-req">Required</td><td>Your API key</td></tr>
            <tr><td class="param-name">key_id</td><td class="param-req">Required</td><td>YourApis Key ID</td></tr>
            <tr><td class="param-name">secret</td><td class="param-req">Required</td><td>YourApis Secret</td></tr>
            <tr><td class="param-name">order_id</td><td class="param-req">Required</td><td>Order to act on</td></tr>
            <tr><td class="param-name">action</td><td class="param-req">Required</td><td><code>approve</code> or <code>reject</code></td></tr>
            <tr><td class="param-name">reject_reason</td><td class="param-opt">Optional</td><td>Reason shown for rejection</td></tr>
          </table>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">📄 JSON Response (approved)</div>
          <div class="resp-block">{
  "status": "success",
  "message": "Payment approved successfully.",
  "order_id": "QRP5A8B2F1719123456",
  "action": "approved",
  "customer_id": "user_42",
  "amount": 499,
  "utr": "123456789012",
  "verified_at": "2026-06-13 14:08:00"
}</div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="section-title" style="font-size:13px">🐘 PHP Sample</div>
          <div class="code-box" style="position:relative"><pre id="ma-php-code">$ch = curl_init('<?= $BASE_URL ?>/api/manual_action.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'apikey'   => '<?= htmlspecialchars($PANEL_APIKEY) ?>',
        'key_id'   => 'YOUR_KEY_ID',
        'secret'   => 'YOUR_SECRET',
        'order_id' => 'QRP5A8B2F1719123456',
        'action'   => 'approve', // or 'reject'
        // 'reject_reason' => 'UTR does not match',
    ]),
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['status'] === 'success') {
    echo 'Order ' . $response['action'] . ': ' . $response['order_id'];
}</pre><button class="copy-btn" onclick="copyEl(this,'ma-php-code')">Copy</button></div>
        </div>
        <div class="card">
          <div class="section-title" style="font-size:13px">🌐 JavaScript (fetch) Sample</div>
          <div class="code-box" style="position:relative"><pre id="ma-js-code">const body = new URLSearchParams({
  apikey:   '<?= htmlspecialchars($PANEL_APIKEY) ?>',
  key_id:   'YOUR_KEY_ID',
  secret:   'YOUR_SECRET',
  order_id: 'QRP5A8B2F1719123456',
  action:   'approve', // or 'reject'
  // reject_reason: 'UTR does not match',
});

const res  = await fetch('<?= $BASE_URL ?>/api/manual_action.php', { method: 'POST', body });
const data = await res.json();

console.log(data.status, data.action, data.order_id);</pre><button class="copy-btn" onclick="copyEl(this,'ma-js-code')">Copy</button></div>
        </div>
      </div>
    </div>

    <!-- ══════════ SDK ══════════ -->
    <div class="page" id="page-sdk">
      <div class="page-header">
        <h2>PHP SDK & Examples</h2>
        <p>Drop-in SDK — your API key is pre-filled.</p>
      </div>

      <div style="margin-bottom:20px">
        <a href="../sdk/download.php?apikey=<?= urlencode($PANEL_APIKEY) ?>" class="btn">⬇️ Download Full SDK Kit (ZIP)</a>
      </div>

      <div class="card" style="margin-bottom:20px">
        <div class="card-title">🚀 Quick Start</div>
        <div class="code-box" style="position:relative">
          <pre>require 'UpiPeSDK/UpiPe.php';
$qr    = new UpiPe('<span style="color:var(--orange)"><?= htmlspecialchars($PANEL_APIKEY) ?></span>', '<?= $BASE_URL ?>');
$order = $upi->createOrder(499, 'user_123', 'My Store');
echo $order['qr_url']; // Show QR to customer
$paid  = $upi->isPaid($order['order_id']); // true / false</pre>
          <button class="copy-btn" onclick="copyText(this,`require 'UpiPeSDK/UpiPe.php';\n\$upi = new UpiPe('<?= htmlspecialchars($PANEL_APIKEY) ?>', '<?= $BASE_URL ?>');\n\$order = \$upi->createOrder(499, 'user_123', 'My Store');\n\$paid = \$upi->isPaid(\$order['order_id']);`)">Copy</button>
        </div>
      </div>

      <div class="card" style="margin-bottom:20px">
        <div class="card-title">📖 SDK Methods</div>
        <div class="table-wrap"><table class="param-table">
          <tr><th>Method</th><th>Parameters</th><th>Returns</th></tr>
          <tr><td class="param-name">createOrder()</td><td>amount, customer_id, note?, mode?</td><td>order array (order_id, qr_url, upi_link, deep_links)</td></tr>
          <tr><td class="param-name">verifyPayment()</td><td>order_id, utr?</td><td>status array</td></tr>
          <tr><td class="param-name">isPaid()</td><td>order_id</td><td>true / false</td></tr>
          <tr><td class="param-name">getOrderStatus()</td><td>order_id</td><td>full order detail</td></tr>
          <tr><td class="param-name">getManualOrders()</td><td>status?</td><td>list of orders</td></tr>
          <tr><td class="param-name">manualAction()</td><td>order_id, action, reject_reason?</td><td>result</td></tr>
        </table></div>
      </div>

      <div class="card">
        <div class="card-title">💡 AI Integration Prompt</div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:14px">Paste this into ChatGPT or Claude to get a complete custom integration for your site.</p>
        <div class="code-box" style="position:relative">
<pre id="ai-prompt" style="color:var(--text);font-size:12px;white-space:pre-wrap">I want to integrate UPI Payment Gateway by YourApis into my website.

API Base URL: <?= $BASE_URL ?>

My API Key: <?= htmlspecialchars($PANEL_APIKEY) ?>

Available Endpoints:
- Create Order: GET /api/create_order.php?apikey=KEY&amount=AMT&customer_id=CID&mode=auto|manual
- Verify Payment: POST /api/verify_payment.php (params: apikey, order_id, utr?)
- Order Status: GET /api/order_status.php?apikey=KEY&order_id=OID
- Manual Orders: GET /api/manual_orders.php?apikey=KEY&status=MANUAL_PENDING

SDK: UpiPe.php class with methods: createOrder(), verifyPayment(), isPaid(), getOrderStatus(), getManualOrders(), manualAction()

Payment modes:
- AUTO: Paytm auto-verifies the payment (MID required)
- MANUAL: Customer submits 12-digit UTR, merchant approves/rejects

Please ask me:
1. Which programming language are you using? (PHP/JS/Python/etc)
2. What is your use case? (fund top-up, product unlock, subscription, donation, etc)
3. What should happen after payment? (credit funds, complete order, unlock feature)
4. Do you want manual or auto mode?
5. Do you need a webhook/callback for payment confirmation?
6. Which frontend framework? (plain HTML, React, Laravel, CodeIgniter, etc)

After I answer, give me complete working code with:
- Payment page (QR + deep links)
- Polling/verify logic
- Success/failure handling
- Manual UTR form (if manual mode)
- Database status saving</pre>
          <button class="copy-btn" onclick="copyText(this, document.getElementById('ai-prompt').innerText)">Copy Prompt</button>
        </div>
      </div>
    </div>

    <!-- ══════════ SETTINGS ══════════ -->
    <div class="page" id="page-settings">
      <div class="page-header">
        <h2>Settings</h2>
        <p>Configure your UPI ID for payment collection.</p>
      </div>

      <?php if($saveMsg): ?><div class="alert alert-success">✅ <?= $saveMsg ?></div><?php endif; ?>
      <?php if($saveErr): ?><div class="alert alert-error">⚠️ <?= $saveErr ?></div><?php endif; ?>

      <div class="card-grid">
        <div class="card">
          <div class="card-title">💳 Payment Settings</div>
          <form method="POST">
            <div class="field">
              <label>UPI ID <span style="color:var(--red)">*</span></label>
              <input type="text" name="upi_id"
                value="<?= htmlspecialchars($settings['upi_id']??'') ?>"
                placeholder="e.g. yourname@upi or 9876543210@paytm">
              <small>This UPI ID will appear on all QR codes. Make sure it&apos;s correct.</small>
            </div>
            <div class="field">
              <label>Display Name <span style="color:var(--muted)">(optional)</span></label>
              <input type="text" name="display_name"
                value="<?= htmlspecialchars($settings['display_name']??'') ?>"
                placeholder="Your Store Name">
              <small>Shown to customers on the UPI payment screen.</small>
            </div>
            <div class="field">
              <label>Paytm MID <span style="color:var(--muted)">(optional — for auto-verify)</span></label>
              <input type="text" name="mid"
                value="<?= htmlspecialchars($settings['mid']??'') ?>"
                placeholder="e.g. MYSTORE12345678901234">
              <small>Your Paytm Merchant ID. Required only for <strong>auto</strong> payment mode. Leave blank to use manual UTR mode.</small>
            </div>
            <button class="btn" type="submit" name="save_settings">💾 Save Settings</button>
          </form>
        </div>

        <div class="card">
          <div class="card-title">ℹ️ Settings Guide</div>
          <div style="font-size:13px;color:var(--muted);line-height:1.9">
            <p style="margin-bottom:14px">🔑 <strong style="color:#fff">UPI ID</strong> — Required. No payments will be created without this. Use the VPA linked to your bank account.</p>
            <p style="margin-bottom:14px">🏷️ <strong style="color:#fff">Display Name</strong> — Optional. Your brand name shown to customers on the UPI payment screen.</p>
            <p style="margin-bottom:14px">🏦 <strong style="color:#fff">Paytm MID</strong> — Optional. Your Paytm Merchant ID. When provided, orders default to <strong style="color:var(--green)">Auto mode</strong> (Paytm auto-verifies payments). Without MID, orders use <strong style="color:var(--orange)">Manual mode</strong> (customers submit UTR).</p>
            <p>⚠️ <strong style="color:var(--orange)">Note:</strong> Changes apply to new orders only. Existing pending orders are not affected.</p>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<script>
const MY_API_KEY  = '<?= htmlspecialchars($PANEL_APIKEY) ?>';
const BASE_URL    = '<?= $BASE_URL ?>';
let currentOrderId = '';
let currentMode    = '<?= !empty($settings['mid']) ? 'auto' : 'manual' ?>';

// ── Sidebar ──────────────────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

// ── Page navigation ───────────────────────────────────────────────────────
const pageTitles = {
  overview: ['Overview','Dashboard summary and recent orders'],
  manual:   ['Manual Review','Approve or reject UTR payments'],
  test:     ['Test Payment','Create and verify a live test payment'],
  docs:     ['API Documentation','All available endpoints and parameters'],
  sdk:      ['SDK & Examples','PHP SDK and integration examples'],
  settings: ['Settings','Configure your UPI ID and display name'],
};
function showPage(name, el) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-'+name).classList.add('active');
  if (el) el.classList.add('active');
  const [title, sub] = pageTitles[name] || [name, ''];
  document.getElementById('page-title').textContent = title;
  document.getElementById('page-sub').textContent = sub;
  closeSidebar();
}

// ── Endpoint docs ─────────────────────────────────────────────────────────
function showEp(name, el) {
  document.querySelectorAll('.ep-doc').forEach(d => d.classList.remove('active'));
  document.querySelectorAll('.ep-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('ep-'+name).classList.add('active');
  if (el) el.classList.add('active');
}

// ── Copy ──────────────────────────────────────────────────────────────────
function copyText(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '✓ Copied';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}

function copyEl(btn, id) {
  const el = document.getElementById(id);
  if (!el) return;
  navigator.clipboard.writeText(el.innerText).then(() => {
    btn.textContent = '✓ Copied';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}

// ── Mode change in test ───────────────────────────────────────────────────
function onModeChange() {
  currentMode = document.getElementById('t_mode').value;
  const utrBox = document.getElementById('utr-box');
  if (currentMode === 'manual') {
    utrBox.style.display = 'block';
    document.getElementById('utr-hint').textContent = 'Enter the 12-digit UTR from your bank app after paying.';
  } else {
    utrBox.style.display = 'none';
  }
}

// ── Create Test Order ─────────────────────────────────────────────────────
async function createTestOrder() {
  const amount = document.getElementById('t_amount').value;
  const cid    = document.getElementById('t_cid').value;
  const note   = document.getElementById('t_note').value;
  const mode   = document.getElementById('t_mode').value;

  const res  = await fetch(`${BASE_URL}/api/create_order.php?apikey=${encodeURIComponent(MY_API_KEY)}&amount=${amount}&customer_id=${encodeURIComponent(cid)}&note=${encodeURIComponent(note)}&mode=${mode}`);
  const data = await res.json();

  const box = document.getElementById('test-result');
  box.style.display = 'block';
  box.style.borderColor = '';
  box.textContent = JSON.stringify(data, null, 2);

  if (data.status === 'success') {
    currentOrderId = data.order_id;
    currentMode    = mode;
    document.getElementById('qr-img').src          = data.qr_url;
    document.getElementById('order-id-disp').textContent = 'Order: ' + data.order_id;
    document.getElementById('expires-disp').textContent  = '⏰ Expires: ' + data.expires_at;
    document.getElementById('gpay-link').href    = data.deep_links.gpay;
    document.getElementById('phonepe-link').href = data.deep_links.phonepe;
    document.getElementById('paytm-link').href   = data.deep_links.paytm;
    document.getElementById('qr-panel').style.display = 'block';

    // Show UTR box immediately for manual mode
    const utrBox = document.getElementById('utr-box');
    utrBox.style.display = (mode === 'manual') ? 'block' : 'none';
    if (mode === 'manual') {
      document.getElementById('utr-hint').textContent = 'Enter the 12-digit UTR from your bank app after paying.';
    }
  }
}

// ── Verify Test Payment ───────────────────────────────────────────────────
async function verifyTestPayment() {
  if (!currentOrderId) { alert('Create an order first!'); return; }
  const utr  = document.getElementById('t_utr')?.value?.trim() || '';
  const body = new URLSearchParams({ apikey: MY_API_KEY, order_id: currentOrderId });
  if (utr) body.append('utr', utr);

  const res  = await fetch(`${BASE_URL}/api/verify_payment.php`, { method:'POST', body });
  const data = await res.json();

  const box = document.getElementById('test-result');
  box.style.display = 'block';
  box.textContent   = JSON.stringify(data, null, 2);

  if (data.status === 'paid' || data.status === 'already_paid') {
    box.style.borderColor = 'rgba(16,185,129,0.5)';
  } else if (data.status === 'manual_pending') {
    box.style.borderColor = 'rgba(59,130,246,0.5)';
  } else if (data.status === 'not_paid' || data.status === 'utr_required') {
    // Auto verify failed → show UTR input
    box.style.borderColor = 'rgba(245,158,11,0.5)';
    const utrBox = document.getElementById('utr-box');
    utrBox.style.display = 'block';
    document.getElementById('utr-hint').textContent =
      'Auto verification failed. Enter the 12-digit UTR from your bank app and verify again.';
  } else {
    box.style.borderColor = 'rgba(239,68,68,0.4)';
  }
}
</script>
</body>
</html>
