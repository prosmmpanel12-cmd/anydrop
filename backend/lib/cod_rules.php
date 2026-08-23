<?php
/**
 * Anydrop — Area-wise COD eligibility (recall.md item 4, migration 35).
 *
 * Single source of truth for "can this customer use COD, from this
 * delivery address, for an order of this amount" — used by both
 * orders/create.php (server-side enforcement, never trust the client)
 * and customer/cod-eligibility.php (checkout UI's "can I even offer COD
 * as an option" pre-check). Keeping this in one function means the two
 * can never drift apart the way a duplicated inline check would risk.
 *
 * The Customer App never evaluates this rule itself — it only ever
 * receives eligible/false + a short reason string from whichever
 * endpoint calls this. Per recall.md item 4's explicit requirement,
 * nothing here is an Android constant.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/geo.php';

if (!function_exists('get_effective_cod_rule')) {
    /**
     * Resolves the effective COD rule for a lat/lng: the nearest
     * matching service_areas node's area_cod_rules row if one exists
     * and is active, else the platform-wide app_settings defaults.
     * Same nearest-within-radius resolution and eligible-set walk
     * (nearest node + its parent when the nearest is level='area') as
     * resolve_service_area()'s other callers (promo-banners.php,
     * restaurants/list.php) — a rule set on a City/Village node still
     * applies to a customer who resolved one level deeper into a
     * specific Area under it, unless that Area has its own override
     * row, which takes precedence for being the more specific match.
     *
     * @return array{cod_enabled:bool, min_prepaid_orders:int, max_cod_order_amount:?float, max_cod_orders_per_day:?int, new_customer_cod_blocked:bool, area_id:?int, source:string}
     */
    function get_effective_cod_rule(PDO $db, ?float $lat, ?float $lng): array
    {
        $defaults = [
            'cod_enabled' => (bool) ((int) get_setting('default_cod_enabled', 1)),
            'min_prepaid_orders' => (int) get_setting('default_cod_min_prepaid_orders', 0),
            'max_cod_order_amount' => get_setting('default_cod_max_order_amount', '') !== ''
                ? (float) get_setting('default_cod_max_order_amount', '') : null,
            'max_cod_orders_per_day' => get_setting('default_cod_max_orders_per_day', '') !== ''
                ? (int) get_setting('default_cod_max_orders_per_day', '') : null,
            'new_customer_cod_blocked' => (bool) ((int) get_setting('default_cod_new_customer_blocked', 0)),
            'area_id' => null,
            'source' => 'platform_default',
        ];

        if ($lat === null || $lng === null) {
            return $defaults;
        }

        $resolved = resolve_service_area($db, $lat, $lng);
        if (empty($resolved)) {
            return $defaults;
        }

        // Candidate ids, most-specific-first: the nearest node itself,
        // then its parent (mirrors promo-banners.php's eligible-set
        // logic) — checked in that order so an Area-level override
        // wins over its parent City/Village's, but a City/Village-level
        // rule still reaches a customer resolved into a child Area that
        // has no rule of its own.
        $candidateIds = [$resolved[0]['id']];
        if ($resolved[0]['level'] === 'area' && $resolved[0]['parent_id'] !== null) {
            $candidateIds[] = $resolved[0]['parent_id'];
        }

        foreach ($candidateIds as $areaId) {
            $stmt = $db->prepare(
                'SELECT * FROM area_cod_rules WHERE area_id = :aid AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['aid' => $areaId]);
            $rule = $stmt->fetch();
            if ($rule) {
                return [
                    'cod_enabled' => (bool) $rule['cod_enabled'],
                    'min_prepaid_orders' => (int) $rule['min_prepaid_orders'],
                    'max_cod_order_amount' => $rule['max_cod_order_amount'] !== null ? (float) $rule['max_cod_order_amount'] : null,
                    'max_cod_orders_per_day' => $rule['max_cod_orders_per_day'] !== null ? (int) $rule['max_cod_orders_per_day'] : null,
                    'new_customer_cod_blocked' => (bool) $rule['new_customer_cod_blocked'],
                    'area_id' => $areaId,
                    'source' => 'area_rule',
                ];
            }
        }

        return $defaults;
    }
}

if (!function_exists('evaluate_cod_eligibility')) {
    /**
     * Full eligibility check for a specific customer + amount, given an
     * already-resolved rule (get_effective_cod_rule() above). Order of
     * checks matches the order the rules are listed in recall.md item 4
     * — enabled/disabled first (cheapest, no query needed), then the
     * two count-based checks (each one query), then the amount cap
     * (no query, just compares against $orderAmount).
     *
     * @return array{eligible:bool, reason:?string}
     */
    function evaluate_cod_eligibility(PDO $db, array $rule, int $customerId, ?float $orderAmount): array
    {
        if (!$rule['cod_enabled']) {
            return ['eligible' => false, 'reason' => 'cod_not_available_in_area'];
        }

        // Completed prepaid (UPI) order count — used by both the
        // min-prepaid-orders check and the new-customer check, so fetch
        // once. "Completed" = delivered, same definition orders/list.php
        // and the rest of this project use for a finished order.
        $prepaidStmt = $db->prepare(
            "SELECT COUNT(*) AS c FROM orders
             WHERE customer_id = :cid AND payment_method = 'upi'
               AND payment_status = 'paid' AND status = 'delivered'"
        );
        $prepaidStmt->execute(['cid' => $customerId]);
        $prepaidCount = (int) $prepaidStmt->fetch()['c'];

        if ($rule['new_customer_cod_blocked']) {
            // "New customer" = zero delivered orders of ANY payment
            // method yet — distinct from the prepaid-specific count
            // above, since a customer's very first order (prepaid or
            // not) hasn't delivered yet either way.
            $anyDeliveredStmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM orders WHERE customer_id = :cid AND status = 'delivered'"
            );
            $anyDeliveredStmt->execute(['cid' => $customerId]);
            if ((int) $anyDeliveredStmt->fetch()['c'] === 0) {
                return ['eligible' => false, 'reason' => 'new_customer_cod_blocked'];
            }
        }

        if ($rule['min_prepaid_orders'] > 0 && $prepaidCount < $rule['min_prepaid_orders']) {
            return ['eligible' => false, 'reason' => 'min_prepaid_orders_not_met'];
        }

        if ($rule['max_cod_orders_per_day'] !== null) {
            $todayStmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM orders
                 WHERE customer_id = :cid AND payment_method = 'cod'
                   AND status NOT IN ('cancelled', 'rejected', 'failed', 'expired')
                   AND DATE(created_at) = CURDATE()"
            );
            $todayStmt->execute(['cid' => $customerId]);
            if ((int) $todayStmt->fetch()['c'] >= $rule['max_cod_orders_per_day']) {
                return ['eligible' => false, 'reason' => 'daily_cod_limit_reached'];
            }
        }

        if ($rule['max_cod_order_amount'] !== null && $orderAmount !== null && $orderAmount > $rule['max_cod_order_amount']) {
            return ['eligible' => false, 'reason' => 'order_amount_exceeds_cod_limit'];
        }

        return ['eligible' => true, 'reason' => null];
    }
}
