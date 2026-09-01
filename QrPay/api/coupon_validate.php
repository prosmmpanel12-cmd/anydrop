<?php
/**
 * QrPay — POST /api/coupon_validate.php
 *
 * Dashboard-session authenticated (Billing screen, Phase 6) — NOT
 * apikey-authenticated, since only a logged-in developer previewing
 * their own subscription purchase should be able to probe coupon codes.
 *
 * Required params: plan_id, billing_cycle (monthly|yearly), code
 *
 * Pure preview — does NOT consume a coupon use (used_count is only
 * ever bumped by verify_payment.php on an actual PAID subscription
 * order, see core/billing.php::upsert_subscription_on_payment()).
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
$code         = trim($_POST['code'] ?? $input['code'] ?? '');

if (!$planId) fail('Missing required parameter: plan_id');
if (!in_array($billingCycle, ['monthly', 'yearly'], true)) fail('billing_cycle must be "monthly" or "yearly"');
if (!$code) fail('Missing required parameter: code');

$plan = get_active_plan_by_id($pdo, $planId);
if (!$plan) fail('Selected plan is not available.', 404);

$result = validate_coupon($pdo, $code, $plan, $billingCycle);

if (!$result['ok']) {
    fail($result['reason'], 400, [
        'base_price' => $result['base_price'],
    ]);
}

success([
    'code'             => $code,
    'plan_id'          => $plan['id'],
    'plan_name'        => $plan['display_name'],
    'billing_cycle'    => $billingCycle,
    'base_price'       => $result['base_price'],
    'discount_amount'  => $result['discount_amount'],
    'final_price'      => $result['final_price'],
]);
