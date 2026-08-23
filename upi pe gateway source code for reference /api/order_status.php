<?php
/**
 * QRPay API - Order Status
 * GET /api/order_status.php?apikey=X&key_id=X&secret=X&order_id=X
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

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$apiKey  = trim($_GET['apikey']   ?? $_POST['apikey']   ?? $input['apikey']   ?? '');
$keyId   = trim($_GET['key_id']   ?? $_POST['key_id']   ?? $input['key_id']   ?? '');
$secret  = trim($_GET['secret']   ?? $_POST['secret']   ?? $input['secret']   ?? '');
$orderId = trim($_GET['order_id'] ?? $_POST['order_id'] ?? $input['order_id'] ?? '');

if (!$apiKey || !$keyId || !$secret) fail('Missing: apikey / key_id / secret');
if (!$orderId) fail('Missing: order_id');

$auth = verifyApiKey($pdo, $apiKey, $keyId, $secret);
if (!$auth['valid']) fail($auth['message'], 401);

$stmt = $pdo->prepare("
    SELECT order_id, customer_id, amount, note, mode, status,
           utr, auto_attempts, utr_submitted_at,
           created_at, expire_at, verified_at, reject_reason
    FROM payment_orders
    WHERE order_id = ? AND apikey = ?
");
$stmt->execute([$orderId, $apiKey]);
$order = $stmt->fetch();

if (!$order) fail('Order not found', 404);

$labels = [
    'PENDING'        => 'Awaiting payment',
    'MANUAL_PENDING' => 'UTR submitted — awaiting merchant approval',
    'PAID'           => 'Payment verified successfully',
    'EXPIRED'        => 'Order expired',
    'REJECTED'       => 'Payment rejected',
];

$order['amount']       = (float)$order['amount'];
$order['status_label'] = $labels[$order['status']] ?? $order['status'];

success(['order' => $order]);
