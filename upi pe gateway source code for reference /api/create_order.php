<?php
/**
 * QRPay API v2 - Create Order
 * GET/POST /api/create_order.php
 *
 * Required params:
 *   apikey      = YourApi API key
 *   amount      = payment amount (₹)
 *   customer_id = tumhara user/order ID
 *
 * Optional params:
 *   note = payment note
 *   mode = auto | manual (default: auto)
 *         auto  → MID se Paytm verify hoga (agar mid set hai)
 *         manual → Customer UTR submit karega
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$apiKey     = trim($_GET['apikey']      ?? $_POST['apikey']      ?? $input['apikey']      ?? '');
$amount     = $_GET['amount']           ?? $_POST['amount']       ?? $input['amount']       ?? 0;
$customerId = trim($_GET['customer_id'] ?? $_POST['customer_id'] ?? $input['customer_id'] ?? '');
$note       = trim($_GET['note']        ?? $_POST['note']        ?? $input['note']        ?? 'Payment');
$mode       = trim($_GET['mode']        ?? $_POST['mode']        ?? $input['mode']        ?? 'auto');

// ─── Validate ─────────────────────────────────────────────────
if (!$apiKey)         fail('Missing required parameter: apikey');
$amount = (float)$amount;
if ($amount < 1)      fail('Amount must be at least ₹1');
if ($amount > 100000) fail('Amount cannot exceed ₹1,00,000');
if (empty($customerId)) fail('Missing required parameter: customer_id');
if (!in_array($mode, ['auto','manual'])) $mode = 'auto';

// ─── Verify API Key ───────────────────────────────────────────
$auth = verifyApiKey($pdo, $apiKey,
    defined('KEY_ID') ? KEY_ID : '',
    defined('SECRET') ? SECRET : '');
if (!$auth['valid']) fail($auth['message'], 401);
$info = $auth['info'];

// ─── User Settings se UPI ID + MID fetch karo ────────────────
$sStmt = $pdo->prepare("SELECT * FROM user_settings WHERE apikey = ?");
$sStmt->execute([$apiKey]);
$userSettings = $sStmt->fetch();

if (!$userSettings || empty($userSettings['upi_id'])) {
    fail('UPI ID not configured. Please log in to your panel → Settings → save your UPI ID.', 400);
}

$upiId = $userSettings['upi_id'];
$mid   = $userSettings['mid'] ?? '';

// Auto mode ke liye MID chahiye, nahi hai toh manual pe degrade karo
if ($mode === 'auto' && empty($mid)) {
    $mode = 'manual'; // silently degrade — mid nahi hai
}

// ─── Generate Order ID ────────────────────────────────────────
$orderId = 'QRP' . strtoupper(bin2hex(random_bytes(5))) . time();

// ─── Save Order (paytm_key column nahi hai ab) ───────────────
$pdo->prepare("
    INSERT INTO payment_orders
      (order_id, apikey, upi_id, mid, customer_id,
       amount, note, mode, status, auto_attempts, created_at, expire_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 0, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))
")->execute([
    $orderId, $apiKey, $upiId,
    $mid ?: null,
    $customerId, $amount, $note, $mode
]);

// ─── Build UPI String + QR ────────────────────────────────────
$displayName = $userSettings['display_name'] ?? 'Merchant';
$upiString = "upi://pay"
    . "?pa=" . urlencode($upiId)
    . "&pn=" . urlencode($displayName)
    . "&am=" . number_format($amount, 2, '.', '')
    . "&cu=INR"
    . "&tn=" . urlencode($note . ' | ' . $orderId)
    . "&tr=" . urlencode($orderId);

$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiString);

// ─── Deep links ───────────────────────────────────────────────
$dlParams = http_build_query(['pa'=>$upiId,'pn'=>$displayName,'am'=>$amount,'cu'=>'INR','tn'=>$note,'tr'=>$orderId]);
$gpay    = "gpay://upi/pay?"    . $dlParams;
$phonepe = "phonepe://pay?"     . $dlParams;
$paytmL  = "paytmmp://pay?"    . $dlParams;

success([
    'order_id'       => $orderId,
    'amount'         => $amount,
    'currency'       => 'INR',
    'upi_id'         => $upiId,
    'upi_link'       => $upiString,
    'qr_url'         => $qrUrl,
    'deep_links'     => [
        'gpay'    => $gpay,
        'phonepe' => $phonepe,
        'paytm'   => $paytmL,
    ],
    'mode'           => $mode,
    'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
    'expires_in_sec' => 1800,
    'AboutApi' => [
        'daily_left'   => $info['daily_left']   ?? null,
        'monthly_left' => $info['monthly_left'] ?? null,
        'credits_left' => $info['credits_left'] ?? null,
    ],
]);
