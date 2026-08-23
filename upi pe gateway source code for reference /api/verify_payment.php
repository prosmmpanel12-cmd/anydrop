<?php
/**
 * QRPay API v2 - Verify Payment
 * POST /api/verify_payment.php
 *
 * FLOW:
 *   - Auto verify (Paytm) HAMESHA chalta rahega — koi limit nahi
 *   - 5 min baad user UTR submit karke manual verify pe bhi shift ho sakta hai
 *   - Dono parallel mein — jo pehle succeed kare
 *   - Jab tak UTR actually submit na ho, status MANUAL_PENDING nahi hoga
 *
 * Required params: apikey, order_id
 * Optional param:  utr (12-digit, sirf 5 min ke baad accepted)
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
require_once __DIR__ . '/../lib/encdec_paytm.php';

if (!defined('PAYTM_MERCHANT_KEY')) {
    define('PAYTM_MERCHANT_KEY', 'YourPaytmMerchantKeyHere');
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$apiKey  = trim($_POST['apikey']   ?? $_GET['apikey']   ?? $input['apikey']   ?? '');
$orderId = trim($_POST['order_id'] ?? $_GET['order_id'] ?? $input['order_id'] ?? '');
$utr     = trim($_POST['utr']      ?? $_GET['utr']      ?? $input['utr']      ?? '');

if (!$apiKey)  fail('Missing required parameter: apikey');
if (!$orderId) fail('Missing required parameter: order_id');

// ─── Verify API Key ───────────────────────────────────────────
$auth = verifyApiKey($pdo, $apiKey,
    defined('KEY_ID') ? KEY_ID : '',
    defined('SECRET') ? SECRET : '');
if (!$auth['valid']) fail($auth['message'], 401);
$info = $auth['info'];

// ─── Fetch Order ──────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM payment_orders WHERE order_id = ? AND apikey = ?");
$stmt->execute([$orderId, $apiKey]);
$order = $stmt->fetch();
if (!$order) fail('Order not found', 404);

// ─── Terminal states ──────────────────────────────────────────
if ($order['status'] === 'PAID') {
    echo json_encode([
        'status'      => 'already_paid',
        'message'     => 'Payment already verified.',
        'order_id'    => $orderId,
        'amount'      => (float)$order['amount'],
        'utr'         => $order['utr'],
        'verified_at' => $order['verified_at'],
    ]); exit;
}
if ($order['status'] === 'EXPIRED')  { echo json_encode(['status'=>'expired',  'message'=>'Order has expired. Please create a new order.']); exit; }
if ($order['status'] === 'REJECTED') { echo json_encode(['status'=>'rejected', 'message'=>'Payment was rejected by the merchant.']); exit; }

// MANUAL_PENDING: UTR submit ho chuka hai, merchant review karega
if ($order['status'] === 'MANUAL_PENDING') {
    echo json_encode([
        'status'   => 'manual_pending',
        'message'  => 'UTR submitted and awaiting merchant review.',
        'order_id' => $orderId,
        'utr'      => $order['utr'],
    ]); exit;
}

// ─── Expiry check ─────────────────────────────────────────────
$expStmt = $pdo->prepare("SELECT expire_at < NOW() AS is_expired FROM payment_orders WHERE order_id = ?");
$expStmt->execute([$orderId]);
if ($expStmt->fetch()['is_expired']) {
    $pdo->prepare("UPDATE payment_orders SET status='EXPIRED' WHERE order_id=?")->execute([$orderId]);
    fail('Order has expired. Please create a new order.');
}

// ─── Order age calculate (5 min = 300 sec threshold) ─────────
$ageStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds FROM payment_orders WHERE order_id = ?");
$ageStmt->execute([$orderId]);
$orderAge      = (int)($ageStmt->fetch()['age_seconds'] ?? 0);
$manualAllowed = ($orderAge >= 300);
$remaining     = max(0, 300 - $orderAge);

// ─── UTR submit attempt (sirf 5 min baad) ────────────────────
if (!empty($utr)) {
    if (!$manualAllowed) {
        echo json_encode([
            'status'            => 'manual_not_allowed',
            'message'           => 'Manual UTR submission is not available yet. Please wait ' . $remaining . ' more second(s). Auto-verification is still running.',
            'order_id'          => $orderId,
            'manual_allowed_in' => $remaining,
        ]); exit;
    }

    if (!preg_match('/^\d{12}$/', $utr)) fail('Invalid UTR — must be exactly 12 digits');

    $chk = $pdo->prepare("SELECT id FROM payment_orders WHERE utr = ? AND order_id != ? LIMIT 1");
    $chk->execute([$utr, $orderId]);
    if ($chk->fetch()) fail('This UTR has already been used for another order.');

    $pdo->prepare("
        UPDATE payment_orders
        SET utr = ?, status = 'MANUAL_PENDING', utr_submitted_at = NOW()
        WHERE order_id = ?
    ")->execute([$utr, $orderId]);

    echo json_encode([
        'status'   => 'manual_pending',
        'message'  => 'UTR submitted successfully. Awaiting merchant verification.',
        'order_id' => $orderId,
        'utr'      => $utr,
        'AboutApi' => ['daily_left' => $info['daily_left'] ?? null],
    ]); exit;
}

// ─── AUTO VERIFY (hamesha chalta hai — koi limit nahi) ───────
$mid      = $order['mid'] ?? '';
$attempts = (int)($order['auto_attempts'] ?? 0);

// Manual-only order (no MID set)
if ($order['mode'] === 'manual' || empty($mid)) {
    if ($manualAllowed) {
        echo json_encode([
            'status'   => 'utr_required',
            'message'  => 'Please submit your 12-digit UTR to verify payment.',
            'order_id' => $orderId,
        ]); exit;
    } else {
        echo json_encode([
            'status'            => 'pending',
            'message'           => 'Payment pending. UTR submission will be available in ' . $remaining . ' second(s).',
            'order_id'          => $orderId,
            'manual_allowed_in' => $remaining,
        ]); exit;
    }
}

// Increment attempt counter (no cap)
$pdo->prepare("UPDATE payment_orders SET auto_attempts = auto_attempts + 1 WHERE order_id = ?")->execute([$orderId]);

// Paytm status check
$resp      = getTxnStatusNew([
    'MID'     => $mid,
    'ORDERID' => $orderId,
    'KEY'     => PAYTM_MERCHANT_KEY,
]);
$txnStatus = $resp['STATUS'] ?? 'UNKNOWN';

if ($txnStatus !== 'TXN_SUCCESS') {
    // Auto verify nahi hua — response build karo based on order age
    $response = [
        'status'         => 'not_paid',
        'message'        => 'Payment not received yet. Keep trying or wait for manual option.',
        'gateway_status' => $txnStatus,
        'auto_attempts'  => $attempts + 1,
        'order_id'       => $orderId,
    ];

    if ($manualAllowed) {
        // 5 min ho gaye — UTR submit ka option batao
        $response['manual_utr_available'] = true;
        $response['message'] = 'Payment not detected automatically. You can now submit your 12-digit UTR as an alternative.';
    } else {
        // Abhi 5 min nahi hue — countdown batao
        $response['manual_utr_available'] = false;
        $response['manual_allowed_in']    = $remaining;
        $response['message'] = 'Payment not received yet. Auto-verify will keep retrying. Manual UTR option available in ' . $remaining . ' second(s).';
    }

    echo json_encode($response); exit;
}

// ─── TXN_SUCCESS — verify and mark PAID ──────────────────────
if (abs((float)($resp['TXNAMOUNT'] ?? 0) - (float)$order['amount']) > 0.01) {
    fail('Amount mismatch detected. Please contact support. Ref: ' . $orderId);
}

$bankUtr = trim($resp['BANKTXNID'] ?? '');
if (empty($bankUtr)) fail('Transaction ID not found. Please try again.');

$chk = $pdo->prepare("SELECT id FROM payment_orders WHERE utr = ? AND order_id != ? LIMIT 1");
$chk->execute([$bankUtr, $orderId]);
if ($chk->fetch()) fail('Transaction already used for another order.');

$pdo->beginTransaction();
try {
    $pdo->prepare("
        UPDATE payment_orders
        SET status='PAID', utr=?, gateway_response=?, verified_at=NOW()
        WHERE order_id=?
    ")->execute([$bankUtr, json_encode($resp, JSON_UNESCAPED_SLASHES), $orderId]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fail('Verification failed. Please contact support. Ref: ' . $orderId, 500);
}

echo json_encode([
    'status'      => 'paid',
    'message'     => 'Payment verified successfully.',
    'order_id'    => $orderId,
    'amount'      => (float)$order['amount'],
    'utr'         => $bankUtr,
    'customer_id' => $order['customer_id'],
    'verified_at' => date('Y-m-d H:i:s'),
    'AboutApi'    => ['daily_left' => $info['daily_left'] ?? null],
]); exit;
