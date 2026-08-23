<?php
/**
 * QRPay API - My Manual Pending Orders
 * GET /api/manual_orders.php
 *
 * User hardcode karega:
 *   apikey, key_id, secret
 *
 * Returns:
 *   Sirf us apikey ke MANUAL_PENDING orders
 *   User inhe approve ya reject kar sakta hai
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

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$apiKey = trim($_GET['apikey']  ?? $_POST['apikey']  ?? $input['apikey']  ?? '');
$keyId  = trim($_GET['key_id']  ?? $_POST['key_id']  ?? $input['key_id']  ?? '');
$secret = trim($_GET['secret']  ?? $_POST['secret']  ?? $input['secret']  ?? '');
$filter = trim($_GET['status']  ?? $_POST['status']  ?? $input['status']  ?? 'MANUAL_PENDING');

if (!$apiKey || !$keyId || !$secret) fail('Missing: apikey / key_id / secret');

// ─── Verify API Key ───────────────────────────────────────────────────────────
$auth = verifyApiKey($pdo, $apiKey, $keyId, $secret);
if (!$auth['valid']) fail($auth['message'], 401);

// Allowed status filters
$allowed = ['MANUAL_PENDING', 'PAID', 'REJECTED', 'PENDING', 'EXPIRED', 'ALL'];
if (!in_array(strtoupper($filter), $allowed)) $filter = 'MANUAL_PENDING';

// ─── Fetch Orders ─────────────────────────────────────────────────────────────
if (strtoupper($filter) === 'ALL') {
    $stmt = $pdo->prepare("
        SELECT order_id, customer_id, amount, note, mode, status,
               utr, auto_attempts, utr_submitted_at, created_at, expire_at, verified_at
        FROM payment_orders
        WHERE apikey = ?
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$apiKey]);
} else {
    $stmt = $pdo->prepare("
        SELECT order_id, customer_id, amount, note, mode, status,
               utr, auto_attempts, utr_submitted_at, created_at, expire_at, verified_at
        FROM payment_orders
        WHERE apikey = ? AND status = ?
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$apiKey, $filter]);
}

$orders = $stmt->fetchAll();

$statusLabels = [
    'PENDING'        => 'Awaiting payment',
    'MANUAL_PENDING' => 'UTR submitted — awaiting your approval',
    'PAID'           => 'Payment verified',
    'EXPIRED'        => 'Order expired',
    'REJECTED'       => 'Rejected',
];

foreach ($orders as &$o) {
    $o['status_label'] = $statusLabels[$o['status']] ?? $o['status'];
    $o['amount']       = (float)$o['amount'];
}

success([
    'count'  => count($orders),
    'filter' => $filter,
    'orders' => $orders,
]);
