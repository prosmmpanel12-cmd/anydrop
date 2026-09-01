<?php
/**
 * QrPay — Plan Limits
 *
 * Every developer has exactly ONE active `subscriptions` row at all times:
 *   - a brand new developer is auto-subscribed to the 'free' plan at
 *     signup (100-year expiry — see auth/signup.php), OR
 *   - a developer who has purchased a paid plan has that subscription
 *     instead (see core/billing.php::upsert_subscription_on_payment()).
 *
 * This means there is only ONE code path for "find the active
 * subscription + its monthly usage" — no separate free-trial table.
 * The free plan just has TWO caps instead of one:
 *   - payment_limit       -> monthly cap (300), same mechanism as paid
 *                            plans, enforced via usage_counters.
 *   - daily_credit_limit  -> daily cap (10), free-plan-only, enforced
 *                            via daily_usage_counters (resets at
 *                            midnight automatically — new date, new row).
 *
 * Only PAID orders ever increment usage — this file only READS counters;
 * incrementing happens in verify_payment.php on the PENDING -> PAID
 * transition (Phase 4), never here.
 */

/**
 * @return array{
 *   developer_id:int,
 *   plan: ?array{id:int,name:string,display_name:string,plan_type:string,payment_limit:?int,daily_credit_limit:?int},
 *   subscription: ?array{id:int,billing_cycle:string,expires_at:string},
 *   usage: array{cycle_start:?string,cycle_end:?string,verified_count:int},
 *   daily_usage: ?array{date:string,used:int,limit:int,remaining:int},
 *   is_free_plan:bool,
 *   can_accept_payment:bool,
 *   reason:?string
 * }
 */
function get_plan_status(PDO $pdo, int $developerId): array {
    // ---- Active subscription + its plan ----
    $stmt = $pdo->prepare(
        'SELECT s.id AS subscription_id, s.billing_cycle, s.expires_at,
                p.id AS plan_id, p.name AS plan_name, p.display_name AS plan_display_name,
                p.plan_type, p.payment_limit, p.daily_credit_limit
         FROM subscriptions s
         JOIN plans p ON p.id = s.plan_id
         WHERE s.developer_id = ? AND s.status = "active" AND s.expires_at > NOW()
         ORDER BY s.expires_at DESC
         LIMIT 1'
    );
    $stmt->execute([$developerId]);
    $row = $stmt->fetch();

    $usage = ['cycle_start' => null, 'cycle_end' => null, 'verified_count' => 0];
    $dailyUsage = null;

    if (!$row) {
        // Defensive only — every developer should get a free-plan
        // subscription row at signup (Phase 3). If somehow missing,
        // hard-block rather than silently allowing unlimited orders.
        return [
            'developer_id'       => $developerId,
            'plan'               => null,
            'subscription'       => null,
            'usage'              => $usage,
            'daily_usage'        => null,
            'is_free_plan'       => false,
            'can_accept_payment' => false,
            'reason'             => 'No active plan found for this account. Please contact support.',
        ];
    }

    $plan = [
        'id'                 => (int) $row['plan_id'],
        'name'               => $row['plan_name'],
        'display_name'       => $row['plan_display_name'],
        'plan_type'          => $row['plan_type'],
        'payment_limit'      => $row['payment_limit'] !== null ? (int) $row['payment_limit'] : null,
        'daily_credit_limit' => $row['daily_credit_limit'] !== null ? (int) $row['daily_credit_limit'] : null,
    ];
    $isFreePlan = $plan['plan_type'] === 'free';

    $subscription = [
        'id'            => (int) $row['subscription_id'],
        'billing_cycle' => $row['billing_cycle'],
        'expires_at'    => $row['expires_at'],
    ];

    // ---- Monthly usage (same mechanism for free and paid plans) ----
    $stmt = $pdo->prepare(
        'SELECT cycle_start, cycle_end, verified_count
         FROM usage_counters
         WHERE developer_id = ? AND subscription_id = ? AND NOW() BETWEEN cycle_start AND cycle_end
         ORDER BY cycle_start DESC
         LIMIT 1'
    );
    $stmt->execute([$developerId, $subscription['id']]);
    $usageRow = $stmt->fetch();

    if ($usageRow) {
        $usage = [
            'cycle_start'    => $usageRow['cycle_start'],
            'cycle_end'      => $usageRow['cycle_end'],
            'verified_count' => (int) $usageRow['verified_count'],
        ];
    }
    // If no usage_counters row exists yet for the current cycle,
    // verify_payment.php is responsible for creating one lazily on
    // first PAID order of the cycle.

    // ---- Daily usage (free plan only) ----
    if ($isFreePlan) {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare(
            'SELECT used_count FROM daily_usage_counters WHERE developer_id = ? AND usage_date = ?'
        );
        $stmt->execute([$developerId, $today]);
        $dailyRow = $stmt->fetch();
        $usedToday = $dailyRow ? (int) $dailyRow['used_count'] : 0;
        $dailyLimit = $plan['daily_credit_limit'] ?? 10;

        $dailyUsage = [
            'date'      => $today,
            'used'      => $usedToday,
            'limit'     => $dailyLimit,
            'remaining' => max(0, $dailyLimit - $usedToday),
        ];
    }

    // ---- Decide can_accept_payment ----
    $canAccept = true;
    $reason = null;

    if ($isFreePlan && $dailyUsage !== null && $dailyUsage['remaining'] <= 0) {
        $canAccept = false;
        $reason = 'Daily free credit limit reached (10/day). Try again tomorrow or upgrade your plan.';
    } elseif ($plan['payment_limit'] !== null && $usage['verified_count'] >= $plan['payment_limit']) {
        $canAccept = false;
        $reason = $isFreePlan
            ? 'Monthly free credit limit reached (300/month). Upgrade your plan.'
            : 'Payment limit reached for your current plan cycle. Upgrade your plan.';
    }

    return [
        'developer_id'       => $developerId,
        'plan'               => $plan,
        'subscription'       => $subscription,
        'usage'              => $usage,
        'daily_usage'        => $dailyUsage,
        'is_free_plan'       => $isFreePlan,
        'can_accept_payment' => $canAccept,
        'reason'             => $reason,
    ];
}

/**
 * Convenience wrapper for the common case — just the boolean.
 * api/create_order.php should call this FIRST, before creating any
 * payment_orders row, and hard-block with fail(..., 403) if false.
 */
function can_accept_payment(PDO $pdo, int $developerId): bool {
    return get_plan_status($pdo, $developerId)['can_accept_payment'];
}

/**
 * Whether the NEXT order for this developer should be tagged
 * is_free_plan_order = 1. Used by create_order.php to set
 * payment_orders.is_free_plan_order correctly, and by
 * verify_payment.php to know whether to also bump
 * daily_usage_counters (in addition to the normal monthly
 * usage_counters bump, which happens for every plan type) on PAID.
 */
function is_next_order_free_plan(PDO $pdo, int $developerId): bool {
    return get_plan_status($pdo, $developerId)['is_free_plan'];
}
