<?php
/**
 * QrPay — Billing helpers (Phase 5)
 *
 * Shared logic for plans_list.php, coupon_validate.php, subscribe.php,
 * and the subscription_purchase branch of verify_payment.php, so pricing
 * and coupon rules are computed in exactly one place.
 */

/**
 * Active plans, cheapest-first (sort_order), for public pricing display.
 */
function get_active_plans(PDO $pdo): array {
    $stmt = $pdo->query(
        'SELECT id, name, display_name, plan_type, monthly_price, yearly_price,
                yearly_discount_percent, payment_limit, daily_credit_limit
         FROM plans
         WHERE is_active = 1
         ORDER BY sort_order ASC'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id']             = (int) $row['id'];
        $row['monthly_price']  = (float) $row['monthly_price'];
        $row['yearly_price']   = (float) $row['yearly_price'];
        $row['yearly_discount_percent'] = (float) $row['yearly_discount_percent'];
        $row['payment_limit']  = $row['payment_limit'] !== null ? (int) $row['payment_limit'] : null;
        $row['daily_credit_limit'] = $row['daily_credit_limit'] !== null ? (int) $row['daily_credit_limit'] : null;
    }
    return $rows;
}

/**
 * Single active plan by id, or null if it doesn't exist / is deactivated.
 * Deliberately excludes inactive plans — a developer can't subscribe to,
 * or apply a coupon against, a plan that's been taken off sale, even if
 * they already know its id.
 */
function get_active_plan_by_id(PDO $pdo, int $planId): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, name, display_name, plan_type, monthly_price, yearly_price,
                yearly_discount_percent, payment_limit, daily_credit_limit
         FROM plans
         WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$planId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $row['id']            = (int) $row['id'];
    $row['monthly_price'] = (float) $row['monthly_price'];
    $row['yearly_price']  = (float) $row['yearly_price'];
    $row['payment_limit'] = $row['payment_limit'] !== null ? (int) $row['payment_limit'] : null;
    $row['daily_credit_limit'] = $row['daily_credit_limit'] !== null ? (int) $row['daily_credit_limit'] : null;
    return $row;
}

/**
 * Base price for a plan at a given billing cycle, before any coupon.
 */
function base_price_for_plan(array $plan, string $billingCycle): float {
    return $billingCycle === 'yearly' ? (float) $plan['yearly_price'] : (float) $plan['monthly_price'];
}

/**
 * Validates a coupon code against a specific plan + billing cycle and
 * computes the discounted price. Used by BOTH coupon_validate.php (preview,
 * doesn't consume a use) and subscribe.php (re-validated server-side right
 * before creating the order — never trust a client-supplied discount).
 *
 * @return array{
 *   ok:bool, reason:?string, coupon:?array,
 *   base_price:float, discount_amount:float, final_price:float
 * }
 */
function validate_coupon(PDO $pdo, string $code, array $plan, string $billingCycle): array {
    $basePrice = base_price_for_plan($plan, $billingCycle);

    $stmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        return ['ok' => false, 'reason' => 'Coupon code not found.', 'coupon' => null,
                'base_price' => $basePrice, 'discount_amount' => 0, 'final_price' => $basePrice];
    }
    if (!(int) $coupon['is_active']) {
        return ['ok' => false, 'reason' => 'This coupon is no longer active.', 'coupon' => null,
                'base_price' => $basePrice, 'discount_amount' => 0, 'final_price' => $basePrice];
    }

    $now = new DateTime('now');
    if ($now < new DateTime($coupon['valid_from']) || $now > new DateTime($coupon['valid_till'])) {
        return ['ok' => false, 'reason' => 'This coupon has expired or is not yet valid.', 'coupon' => null,
                'base_price' => $basePrice, 'discount_amount' => 0, 'final_price' => $basePrice];
    }

    if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
        return ['ok' => false, 'reason' => 'This coupon has reached its maximum number of uses.', 'coupon' => null,
                'base_price' => $basePrice, 'discount_amount' => 0, 'final_price' => $basePrice];
    }

    if (!empty($coupon['applicable_plans'])) {
        $allowedPlanIds = array_map('intval', explode(',', $coupon['applicable_plans']));
        if (!in_array((int) $plan['id'], $allowedPlanIds, true)) {
            return ['ok' => false, 'reason' => 'This coupon is not applicable to the selected plan.', 'coupon' => null,
                    'base_price' => $basePrice, 'discount_amount' => 0, 'final_price' => $basePrice];
        }
    }

    if ($coupon['discount_type'] === 'percent') {
        $discountAmount = round($basePrice * ((float) $coupon['discount_value'] / 100), 2);
    } else {
        $discountAmount = round((float) $coupon['discount_value'], 2);
    }
    $discountAmount = min($discountAmount, $basePrice); // never go negative
    $finalPrice     = round($basePrice - $discountAmount, 2);
    if ($finalPrice < 1) $finalPrice = 1.00; // keep a minimum payable amount so a QR can still be generated

    return [
        'ok'              => true,
        'reason'          => null,
        'coupon'          => $coupon,
        'base_price'      => $basePrice,
        'discount_amount' => $discountAmount,
        'final_price'     => $finalPrice,
    ];
}

/**
 * Called from verify_payment.php inside its existing transaction, only
 * when a 'subscription_purchase' order transitions to PAID. Upserts the
 * developer's subscription (extends expires_at from "now" or from the
 * current expiry if renewing/switching early) and bumps coupons.used_count.
 *
 * Deliberately does NOT touch free_trial or usage_counters — those only
 * ever move for 'customer_payment' orders.
 */
function upsert_subscription_on_payment(PDO $pdo, int $developerId, int $planId, string $billingCycle, ?string $couponCode): void {
    $interval = $billingCycle === 'yearly' ? 'INTERVAL 1 YEAR' : 'INTERVAL 1 MONTH';

    $stmt = $pdo->prepare(
        'SELECT id, expires_at FROM subscriptions
         WHERE developer_id = ? AND status = "active" AND expires_at > NOW()
         ORDER BY expires_at DESC LIMIT 1'
    );
    $stmt->execute([$developerId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Renewing (same plan) or switching plans early — either way, the
        // remaining time on the current active subscription is preserved
        // and the new cycle is stacked on top of it, not on top of "now".
        $pdo->prepare(
            "UPDATE subscriptions
             SET plan_id = ?, billing_cycle = ?, expires_at = expires_at + $interval
             WHERE id = ?"
        )->execute([$planId, $billingCycle, $existing['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO subscriptions (developer_id, plan_id, billing_cycle, starts_at, expires_at, status)
             VALUES (?, ?, ?, NOW(), NOW() + $interval, 'active')"
        )->execute([$developerId, $planId, $billingCycle]);
    }

    if (!empty($couponCode)) {
        $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?')->execute([$couponCode]);
    }
}
