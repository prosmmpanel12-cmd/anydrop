<?php
/**
 * QrPay — POST/GET /api/create_order.php
 *
 * Required params: apikey, amount, customer_id
 * Optional params:  note, mode (auto|manual, default auto)
 *
 * Auth: apikey lookup against `developers` — completely separate from
 * the dashboard session (core/session.php). A leaked apikey lets someone
 * create orders against this developer's own UPI ID, same as before,
 * but can no longer log into their dashboard or change their UPI ID/MID.
 *
 * Before creating any order row: can_accept_payment() gate from
 * core/plan_limits.php. Every developer always has an active
 * subscription — either the free plan (daily 10 / monthly 300 credits)
 * or a purchased paid plan. Room in the relevant cycle -> allow.
 * Else -> hard 403, NO order row created at all.
 *
 * Response: QR-only. No deep_links block — just upi_id, upi_link, qr_url.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/plan_limits.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$apiKey     = trim($_GET['apikey']      ?? $_POST['apikey']      ?? $input['apikey']      ?? '');
$amount     = $_GET['amount']           ?? $_POST['amount']       ?? $input['amount']       ?? 0;
$customerId = trim($_GET['customer_id'] ?? $_POST['customer_id'] ?? $input['customer_id'] ?? '');
$note       = trim($_GET['note']        ?? $_POST['note']        ?? $input['note']        ?? 'Payment');
$mode       = trim($_GET['mode']        ?? $_POST['mode']        ?? $input['mode']        ?? 'auto');

// ---- Validate input ----
if (!$apiKey) fail('Missing required parameter: apikey');
if (!preg_match('/^[a-f0-9]{20,80}$/i', $apiKey)) fail('Invalid API key format', 401);

$amount = (float) $amount;
if ($amount < 1)      fail('Amount must be at least ₹1');
if ($amount > 100000) fail('Amount cannot exceed ₹1,00,000');
if (empty($customerId)) fail('Missing required parameter: customer_id');
if (!in_array($mode, ['auto', 'manual'], true)) $mode = 'auto';

// ---- Authenticate via apikey (NOT the dashboard session) ----
$stmt = $pdo->prepare('SELECT id, status FROM developers WHERE apikey = ?');
$stmt->execute([$apiKey]);
$developer = $stmt->fetch();

if (!$developer) fail('Invalid API key.', 401);
if ($developer['status'] === 'suspended') fail('This account is suspended.', 403);

$developerId = (int) $developer['id'];

// ---- Plan-limit gate — BEFORE any order row is created ----
$planStatus = get_plan_status($pdo, $developerId);
if (!$planStatus['can_accept_payment']) {
    fail($planStatus['reason'] ?? 'Payment limit reached. Upgrade your plan.', 403);
}
$isFreePlanOrder = $planStatus['is_free_plan'];

// ---- Fetch this developer's own UPI ID / MID ----
$stmt = $pdo->prepare('SELECT upi_id, mid, display_name FROM user_settings WHERE developer_id = ?');
$stmt->execute([$developerId]);
$userSettings = $stmt->fetch();

if (!$userSettings || empty($userSettings['upi_id'])) {
    fail('UPI ID not configured. Please log in to your panel → Settings → save your UPI ID.', 400);
}

$upiId = $userSettings['upi_id'];
$mid   = $userSettings['mid'] ?? '';

// Auto mode needs an MID; silently degrade to manual if missing.
if ($mode === 'auto' && empty($mid)) {
    $mode = 'manual';
}

// ---- Generate order ID ----
$orderId = 'QRP' . strtoupper(bin2hex(random_bytes(5))) . time();

// ---- Save order ----
$stmt = $pdo->prepare(
    'INSERT INTO payment_orders
      (order_id, developer_id, order_purpose, upi_id, mid, customer_id,
       amount, note, mode, status, auto_attempts, is_free_plan_order, created_at, expire_at)
     VALUES
      (?, ?, "customer_payment", ?, ?, ?, ?, ?, ?, "PENDING", 0, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
);
$stmt->execute([
    $orderId, $developerId, $upiId,
    $mid ?: null,
    $customerId, $amount, $note, $mode,
    $isFreePlanOrder ? 1 : 0,
]);

// ---- Build UPI string + QR ----
$displayName = $userSettings['display_name'] ?? 'Merchant';
$upiString = "upi://pay"
    . "?pa=" . urlencode($upiId)
    . "&pn=" . urlencode($displayName)
    . "&am=" . number_format($amount, 2, '.', '')
    . "&cu=INR"
    . "&tn=" . urlencode($note . ' | ' . $orderId)
    . "&tr=" . urlencode($orderId);

$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiString);

// ---- Response: QR-only, no deep_links ----
success([
    'order_id'       => $orderId,
    'amount'         => $amount,
    'currency'       => 'INR',
    'upi_id'         => $upiId,
    'upi_link'       => $upiString,
    'qr_url'         => $qrUrl,
    'mode'           => $mode,
    'is_free_plan_order' => $isFreePlanOrder,
    'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
    'expires_in_sec' => 1800,
]);
