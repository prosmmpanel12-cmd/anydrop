<?php
/**
 * QrPay — POST /api/subscribe.php
 *
 * Dashboard-session authenticated (Billing screen, Phase 6) — NOT
 * apikey-authenticated. This is a developer paying QrPay itself, not a
 * developer's own customer paying them, so it uses the same session
 * identity as the rest of the panel.
 *
 * Required params: plan_id, billing_cycle (monthly|yearly)
 * Optional params: coupon_code
 *
 * Creates a `payment_orders` row with order_purpose = 'subscription_purchase',
 * raised against admin_settings' own UPI ID/MID (qrpay_admin_settings()) —
 * never the developer's own user_settings. The coupon (if any) is
 * RE-validated here server-side; a client-supplied discount is never
 * trusted. Response shape matches create_order.php (QR-only) plus plan info.
 *
 * Verification of the resulting order reuses api/verify_payment.php, same
 * as any other order — polled with this developer's own apikey.
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/billing.php';
require_once __DIR__ . '/../core/session.php';

$sessionInfo = qrpay_require_login_json();
$developerId = $sessionInfo['developer_id'];

$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$planId       = (int) ($_POST['plan_id'] ?? $input['plan_id'] ?? 0);
$billingCycle = trim($_POST['billing_cycle'] ?? $input['billing_cycle'] ?? '');
$couponCode   = trim($_POST['coupon_code'] ?? $input['coupon_code'] ?? '');

if (!$planId) fail('Missing required parameter: plan_id');
if (!in_array($billingCycle, ['monthly', 'yearly'], true)) fail('billing_cycle must be "monthly" or "yearly"');

// ---- Confirm developer account is not suspended ----
$stmt = $pdo->prepare('SELECT status, apikey FROM developers WHERE id = ?');
$stmt->execute([$developerId]);
$developer = $stmt->fetch();
if (!$developer) fail('Developer account not found.', 404);
if ($developer['status'] === 'suspended') fail('This account is suspended.', 403);

// ---- Plan must exist and be active ----
$plan = get_active_plan_by_id($pdo, $planId);
if (!$plan) fail('Selected plan is not available.', 404);
if ($plan['plan_type'] === 'free') {
    fail('The Free plan is assigned automatically at signup and cannot be purchased.', 400);
}

// ---- Price (re-validate coupon server-side, never trust the client) ----
$finalPrice = base_price_for_plan($plan, $billingCycle);
$appliedCouponCode = null;

if (!empty($couponCode)) {
    $couponResult = validate_coupon($pdo, $couponCode, $plan, $billingCycle);
    if (!$couponResult['ok']) {
        fail($couponResult['reason'], 400);
    }
    $finalPrice = $couponResult['final_price'];
    $appliedCouponCode = $couponCode;
}

// ---- QrPay's own payout identity (never the developer's own UPI ID) ----
$adminSettings = qrpay_admin_settings($pdo);
if (!$adminSettings || empty($adminSettings['owner_upi_id']) || $adminSettings['owner_upi_id'] === 'CHANGE-ME@upi') {
    fail('Subscription purchases are not available yet. Please contact support.', 503);
}

$upiId = $adminSettings['owner_upi_id'];
$mid   = $adminSettings['owner_mid'] ?? '';
$mode  = !empty($mid) ? 'auto' : 'manual'; // same auto->manual degrade rule as create_order.php

// ---- Generate order ID ----
$orderId = 'QRPSUB' . strtoupper(bin2hex(random_bytes(5))) . time();

// ---- Save order ----
$stmt = $pdo->prepare(
    'INSERT INTO payment_orders
      (order_id, developer_id, order_purpose, upi_id, mid, customer_id,
       amount, note, mode, status, auto_attempts, sub_plan_id, sub_billing_cycle,
       sub_coupon_code, is_free_plan_order, created_at, expire_at)
     VALUES
      (?, ?, "subscription_purchase", ?, ?, ?, ?, ?, ?, "PENDING", 0, ?, ?, ?, 0, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
);
$stmt->execute([
    $orderId, $developerId, $upiId,
    $mid ?: null,
    (string) $developerId, // customer_id: for subscription_purchase this is the developer's own id
    $finalPrice,
    'QrPay ' . $plan['display_name'] . ' (' . $billingCycle . ') subscription',
    $mode,
    $plan['id'], $billingCycle, $appliedCouponCode,
]);

// ---- Build UPI string + QR ----
$displayName = $adminSettings['display_name'] ?? 'QrPay';
$upiString = "upi://pay"
    . "?pa=" . urlencode($upiId)
    . "&pn=" . urlencode($displayName)
    . "&am=" . number_format($finalPrice, 2, '.', '')
    . "&cu=INR"
    . "&tn=" . urlencode('QrPay Subscription | ' . $orderId)
    . "&tr=" . urlencode($orderId);

$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upiString);

success([
    'order_id'      => $orderId,
    'apikey'        => $developer['apikey'], // needed by dashboard JS to poll verify_payment.php
    'plan_id'       => $plan['id'],
    'plan_name'     => $plan['display_name'],
    'billing_cycle' => $billingCycle,
    'coupon_code'   => $appliedCouponCode,
    'amount'        => $finalPrice,
    'currency'      => 'INR',
    'upi_id'        => $upiId,
    'upi_link'      => $upiString,
    'qr_url'        => $qrUrl,
    'mode'          => $mode,
    'expires_at'     => date('Y-m-d H:i:s', strtotime('+30 minutes')),
    'expires_in_sec' => 1800,
]);
