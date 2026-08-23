<?php
/**
 * QRPay API - Approve or Reject Manual Payment
 * POST /api/manual_action.php
 *
 * User hardcode karega:
 *   apikey, key_id, secret
 *
 * Runtime params:
 *   order_id      = Order ID
 *   action        = approve | reject
 *   reject_reason = (optional)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$apiKey       = trim($_POST['apikey']        ?? $input['apikey']        ?? '');
$keyId        = trim($_POST['key_id']        ?? $input['key_id']        ?? '');
$secret       = trim($_POST['secret']        ?? $input['secret']        ?? '');
$orderId      = trim($_POST['order_id']      ?? $input['order_id']      ?? '');
$action       = trim($_POST['action']        ?? $input['action']        ?? '');
$rejectReason = trim($_POST['reject_reason'] ?? $input['reject_reason'] ?? '');

if (!$apiKey || !$keyId || !$secret) fail('Missing required parameters: apikey / key_id / secret');
if (!$orderId)                        fail('Missing: order_id');
if (!in_array($action, ['approve','reject'])) fail('action must be approve or reject');

// ─── Verify API Key ───────────────────────────────────────────────────────────
$auth = verifyApiKey($pdo, $apiKey, $keyId, $secret);
if (!$auth['valid']) fail($auth['message'], 401);

// ─── Fetch Order (must belong to this apikey) ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM payment_orders
    WHERE order_id = ? AND apikey = ? AND status = 'MANUAL_PENDING'
");
$stmt->execute([$orderId, $apiKey]);
$order = $stmt->fetch();

if (!$order) {
    // Check what state it's in
    $chk = $pdo->prepare("SELECT status FROM payment_orders WHERE order_id=? AND apikey=?");
    $chk->execute([$orderId, $apiKey]);
    $row = $chk->fetch();
    if (!$row)             fail('Order not found', 404);
    if ($row['status'] === 'PAID')     fail('Order already approved.');
    if ($row['status'] === 'REJECTED') fail('Order already rejected.');
    fail('Order is not in MANUAL_PENDING state. Current: ' . $row['status']);
}

// ─── REJECT ───────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $pdo->prepare("
        UPDATE payment_orders
        SET status='REJECTED', reject_reason=?, verified_at=NOW()
        WHERE order_id=?
    ")->execute([$rejectReason ?: 'Rejected by merchant', $orderId]);

    success([
        'order_id'      => $orderId,
        'action'        => 'rejected',
        'customer_id'   => $order['customer_id'],
        'amount'        => (float)$order['amount'],
        'reject_reason' => $rejectReason ?: 'Rejected by merchant',
    ], 'Order rejected.');
}

// ─── APPROVE ─────────────────────────────────────────────────────────────────
// Duplicate UTR check before approving
if (!empty($order['utr'])) {
    $chk = $pdo->prepare("
        SELECT id FROM payment_orders
        WHERE utr=? AND order_id!=? AND status='PAID' LIMIT 1
    ");
    $chk->execute([$order['utr'], $orderId]);
    if ($chk->fetch()) fail('This UTR is already used in another approved order.');
}

$pdo->beginTransaction();
try {
    $pdo->prepare("
        UPDATE payment_orders
        SET status='PAID', verified_at=NOW()
        WHERE order_id=?
    ")->execute([$orderId]);
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fail('Approval failed. Try again. Ref: ' . $orderId, 500);
}

success([
    'order_id'    => $orderId,
    'action'      => 'approved',
    'customer_id' => $order['customer_id'],
    'amount'      => (float)$order['amount'],
    'utr'         => $order['utr'],
    'verified_at' => date('Y-m-d H:i:s'),
], 'Payment approved successfully.');
