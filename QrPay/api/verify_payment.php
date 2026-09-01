<?php
/**
 * QrPay — POST /api/verify_payment.php
 *
 * Logic kept as-is from the old system (confirmed working):
 *   - Auto verify (Paytm) runs indefinitely, no attempt limit.
 *   - Manual UTR submission only allowed after 5 minutes from creation.
 *   - Both paths run in parallel — whichever succeeds first wins.
 *   - Status stays PENDING (not MANUAL_PENDING) until UTR is submitted.
 *
 * Changes from the old file:
 *   - Auth via apikey -> developers (not the old auth.php verifyApiKey()).
 *   - Orders are looked up by developer_id, not apikey column.
 *   - PAYTM_MERCHANT_KEY comes from user_settings per developer, not a
 *     hardcoded constant — passed into getTxnStatusNew() per call.
 *   - On transition to PAID: increments usage_counters for the developer's
 *     current cycle (every developer always has one, free plan or paid).
 *     If the order was flagged is_free_plan_order at creation, ALSO
 *     bumps daily_usage_counters (the free plan's extra daily cap).
 *     Only PAID orders ever increment anything.
 *
 * Phase 5 addition:
 *   - order_purpose = 'subscription_purchase' orders (created by
 *     api/subscribe.php) take a different branch on PAID: they upsert
 *     `subscriptions` and bump `coupons.used_count` instead of touching
 *     usage_counters — see core/billing.php.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/billing.php';
require_once __DIR__ . '/../lib/paytm_status.php';

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$apiKey  = trim($_POST['apikey']   ?? $_GET['apikey']   ?? $input['apikey']   ?? '');
$orderId = trim($_POST['order_id'] ?? $_GET['order_id'] ?? $input['order_id'] ?? '');
$utr     = trim($_POST['utr']      ?? $_GET['utr']      ?? $input['utr']      ?? '');

if (!$apiKey)  fail('Missing required parameter: apikey');
if (!$orderId) fail('Missing required parameter: order_id');

// ---- Authenticate via apikey ----
$stmt = $pdo->prepare('SELECT id, status FROM developers WHERE apikey = ?');
$stmt->execute([$apiKey]);
$developer = $stmt->fetch();
if (!$developer) fail('Invalid API key.', 401);
if ($developer['status'] === 'suspended') fail('This account is suspended.', 403);
$developerId = (int) $developer['id'];

// ---- Fetch order (scoped to this developer) ----
$stmt = $pdo->prepare('SELECT * FROM payment_orders WHERE order_id = ? AND developer_id = ?');
$stmt->execute([$orderId, $developerId]);
$order = $stmt->fetch();
if (!$order) fail('Order not found', 404);

// ---- Terminal states ----
if ($order['status'] === 'PAID') {
    echo json_encode([
        'status'      => 'already_paid',
        'message'     => 'Payment already verified.',
        'order_id'    => $orderId,
        'amount'      => (float) $order['amount'],
        'utr'         => $order['utr'],
        'verified_at' => $order['verified_at'],
    ]); exit;
}
if ($order['status'] === 'EXPIRED') {
    echo json_encode(['status' => 'expired', 'message' => 'Order has expired. Please create a new order.']); exit;
}
if ($order['status'] === 'REJECTED') {
    echo json_encode(['status' => 'rejected', 'message' => 'Payment was rejected by the merchant.']); exit;
}
if ($order['status'] === 'MANUAL_PENDING') {
    echo json_encode([
        'status'   => 'manual_pending',
        'message'  => 'UTR submitted and awaiting merchant review.',
        'order_id' => $orderId,
        'utr'      => $order['utr'],
    ]); exit;
}

// ---- Expiry check ----
$stmt = $pdo->prepare('SELECT expire_at < NOW() AS is_expired FROM payment_orders WHERE order_id = ?');
$stmt->execute([$orderId]);
if ($stmt->fetch()['is_expired']) {
    $pdo->prepare('UPDATE payment_orders SET status = "EXPIRED" WHERE order_id = ?')->execute([$orderId]);
    fail('Order has expired. Please create a new order.');
}

// ---- Order age (5 min = 300 sec threshold) ----
$stmt = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_seconds FROM payment_orders WHERE order_id = ?');
$stmt->execute([$orderId]);
$orderAge      = (int) ($stmt->fetch()['age_seconds'] ?? 0);
$manualAllowed = ($orderAge >= 300);
$remaining     = max(0, 300 - $orderAge);

// ---- UTR submit attempt (only after 5 min) ----
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

    $chk = $pdo->prepare('SELECT id FROM payment_orders WHERE utr = ? AND order_id != ? LIMIT 1');
    $chk->execute([$utr, $orderId]);
    if ($chk->fetch()) fail('This UTR has already been used for another order.');

    $pdo->prepare(
        'UPDATE payment_orders SET utr = ?, status = "MANUAL_PENDING", utr_submitted_at = NOW() WHERE order_id = ?'
    )->execute([$utr, $orderId]);

    echo json_encode([
        'status'   => 'manual_pending',
        'message'  => 'UTR submitted successfully. Awaiting merchant verification.',
        'order_id' => $orderId,
        'utr'      => $utr,
    ]); exit;
}

// ---- AUTO VERIFY (runs indefinitely, no limit) ----
$mid      = $order['mid'] ?? '';
$attempts = (int) ($order['auto_attempts'] ?? 0);

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

// Fetch this developer's Paytm merchant key — per-developer, not a
// hardcoded global constant.
$stmt = $pdo->prepare('SELECT paytm_merchant_key FROM user_settings WHERE developer_id = ?');
$stmt->execute([$developerId]);
$merchantKey = $stmt->fetch()['paytm_merchant_key'] ?? null;

if (empty($merchantKey)) {
    // No Paytm key configured — can't auto-verify even though mid is set.
    // Degrade the same way as a manual-only order rather than erroring.
    if ($manualAllowed) {
        echo json_encode([
            'status'   => 'utr_required',
            'message'  => 'Auto-verification is not configured. Please submit your 12-digit UTR to verify payment.',
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
$pdo->prepare('UPDATE payment_orders SET auto_attempts = auto_attempts + 1 WHERE order_id = ?')->execute([$orderId]);

$resp = getTxnStatusNew([
    'MID'     => $mid,
    'ORDERID' => $orderId,
], $merchantKey);

$txnStatus = $resp['STATUS'] ?? 'UNKNOWN';

if ($txnStatus !== 'TXN_SUCCESS') {
    $response = [
        'status'         => 'not_paid',
        'message'        => 'Payment not received yet. Keep trying or wait for manual option.',
        'gateway_status' => $txnStatus,
        'auto_attempts'  => $attempts + 1,
        'order_id'       => $orderId,
    ];

    if ($manualAllowed) {
        $response['manual_utr_available'] = true;
        $response['message'] = 'Payment not detected automatically. You can now submit your 12-digit UTR as an alternative.';
    } else {
        $response['manual_utr_available'] = false;
        $response['manual_allowed_in']    = $remaining;
        $response['message'] = 'Payment not received yet. Auto-verify will keep retrying. Manual UTR option available in ' . $remaining . ' second(s).';
    }

    echo json_encode($response); exit;
}

// ---- TXN_SUCCESS — verify amount, mark PAID, increment counters ----
if (abs((float) ($resp['TXNAMOUNT'] ?? 0) - (float) $order['amount']) > 0.01) {
    fail('Amount mismatch detected. Please contact support. Ref: ' . $orderId);
}

$bankUtr = trim($resp['BANKTXNID'] ?? '');
if (empty($bankUtr)) fail('Transaction ID not found. Please try again.');

$chk = $pdo->prepare('SELECT id FROM payment_orders WHERE utr = ? AND order_id != ? LIMIT 1');
$chk->execute([$bankUtr, $orderId]);
if ($chk->fetch()) fail('Transaction already used for another order.');

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE payment_orders
         SET status = "PAID", utr = ?, gateway_response = ?, txn_id = ?, verified_at = NOW()
         WHERE order_id = ?'
    )->execute([$bankUtr, json_encode($resp, JSON_UNESCAPED_SLASHES), $resp['TXNID'] ?? null, $orderId]);

    // Phase 5: subscription_purchase orders take a completely separate
    // path — they never touch usage_counters, those are only for
    // customer_payment orders (a developer's own customers paying them,
    // gated by their plan limits).
    if ($order['order_purpose'] === 'subscription_purchase') {
        upsert_subscription_on_payment(
            $pdo,
            $developerId,
            (int) $order['sub_plan_id'],
            $order['sub_billing_cycle'],
            $order['sub_coupon_code'] ?? null
        );
    } else {
        // Every developer always has exactly one active subscription
        // (free plan or paid — see core/plan_limits.php), so this is
        // the SAME code path regardless of plan type: bump monthly
        // usage_counters for the current cycle.
        $stmt = $pdo->prepare(
            'SELECT s.id AS subscription_id, s.starts_at, s.expires_at
             FROM subscriptions s
             WHERE s.developer_id = ? AND s.status = "active" AND s.expires_at > NOW()
             ORDER BY s.expires_at DESC LIMIT 1'
        );
        $stmt->execute([$developerId]);
        $sub = $stmt->fetch();

        if ($sub) {
            $stmt = $pdo->prepare(
                'SELECT id FROM usage_counters
                 WHERE developer_id = ? AND subscription_id = ? AND NOW() BETWEEN cycle_start AND cycle_end
                 LIMIT 1'
            );
            $stmt->execute([$developerId, $sub['subscription_id']]);
            $counterRow = $stmt->fetch();

            if ($counterRow) {
                $pdo->prepare('UPDATE usage_counters SET verified_count = verified_count + 1 WHERE id = ?')
                    ->execute([$counterRow['id']]);
            } else {
                // Lazily create this cycle's counter row, bounded to the
                // subscription's own start/expiry so it can't run past it.
                $pdo->prepare(
                    'INSERT INTO usage_counters (developer_id, subscription_id, cycle_start, cycle_end, verified_count)
                     VALUES (?, ?, ?, ?, 1)'
                )->execute([$developerId, $sub['subscription_id'], $sub['starts_at'], $sub['expires_at']]);
            }
        }

        // Free-plan orders ALSO bump the daily cap (10/day), on top of
        // the monthly usage_counters bump above. Paid plans skip this
        // entirely — is_free_plan_order was set at create_order.php time.
        if ((int) $order['is_free_plan_order'] === 1) {
            $pdo->prepare(
                'INSERT INTO daily_usage_counters (developer_id, usage_date, used_count)
                 VALUES (?, CURDATE(), 1)
                 ON DUPLICATE KEY UPDATE used_count = used_count + 1'
            )->execute([$developerId]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('QrPay verify_payment commit failed for ' . $orderId . ': ' . $e->getMessage());
    fail('Verification failed. Please contact support. Ref: ' . $orderId, 500);
}

echo json_encode([
    'status'      => 'paid',
    'message'     => 'Payment verified successfully.',
    'order_id'    => $orderId,
    'amount'      => (float) $order['amount'],
    'utr'         => $bankUtr,
    'customer_id' => $order['customer_id'],
    'verified_at' => date('Y-m-d H:i:s'),
]); exit;
