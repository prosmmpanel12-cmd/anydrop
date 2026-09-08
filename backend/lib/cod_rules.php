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
 *
 * 2026-09-07 (app owner decision — combine both sides, strictest wins):
 * this used to only ever look at the DELIVERY ADDRESS's resolved area.
 * get_effective_cod_rule() now optionally also takes the RESTAURANT's
 * own admin-assigned area_id (restaurants.area_id — the same field
 * restaurants/list.php's area filter already uses, not a fresh
 * geometric resolution of the restaurant's own lat/lng) and, if given,
 * resolves that area's own area_cod_rules row the same way, then
 * combines the two field-by-field: whichever value is more restrictive
 * wins. A restaurant with no area_id assigned yet (or one whose area
 * chain has no override) simply contributes the platform defaults on
 * its side, same as before this change — nothing regresses for an
 * unassigned restaurant.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/geo.php';

if (!function_exists('resolve_area_cod_rule_by_area_id')) {
    /**
     * Given a KNOWN area_id (not resolved from lat/lng — e.g. a
     * restaurant's own admin-assigned restaurants.area_id), walks up
     * its full parent chain (City/Village -> District -> State) via
     * service_areas and returns the first active area_cod_rules row
     * found, or null if nothing anywhere up the chain has one. Same
     * "walk to root, more specific wins" shape as
     * delivery_pricing.php's resolve_area_pricing_rule_row() — that
     * one resolves a KNOWN area_id too (a restaurant's own), unlike
     * get_effective_cod_rule()'s lat/lng-based customer-side
     * resolution, which only checks the nearest node + its immediate
     * parent (see that function's own comment for why those two
     * resolution shapes differ).
     */
    function resolve_area_cod_rule_by_area_id(PDO $db, int $areaId): ?array
    {
        $areaNodes = [];
        foreach ($db->query('SELECT id, parent_id FROM service_areas')->fetchAll() as $row) {
            $areaNodes[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        $cursor = $areaId;
        $seen = [];
        while ($cursor !== null && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $stmt = $db->prepare('SELECT * FROM area_cod_rules WHERE area_id = :a AND is_active = 1 LIMIT 1');
            $stmt->execute(['a' => $cursor]);
            $rule = $stmt->fetch();
            if ($rule) {
                return $rule;
            }
            $cursor = $areaNodes[$cursor] ?? null;
        }
        return null;
    }
}

if (!function_exists('cod_rule_platform_defaults')) {
    /** Pulled out so both the customer-side and restaurant-side
     *  resolution below (and the no-override fallback for either) all
     *  read the exact same platform defaults, never two copies that
     *  could drift. */
    function cod_rule_platform_defaults(): array
    {
        return [
            'cod_enabled' => (bool) ((int) get_setting('default_cod_enabled', 1)),
            'min_prepaid_orders' => (int) get_setting('default_cod_min_prepaid_orders', 0),
            'max_cod_order_amount' => get_setting('default_cod_max_order_amount', '') !== ''
                ? (float) get_setting('default_cod_max_order_amount', '') : null,
            'max_cod_orders_per_day' => get_setting('default_cod_max_orders_per_day', '') !== ''
                ? (int) get_setting('default_cod_max_orders_per_day', '') : null,
            'new_customer_cod_blocked' => (bool) ((int) get_setting('default_cod_new_customer_blocked', 0)),
        ];
    }
}

if (!function_exists('resolve_cod_rule_for_address')) {
    /** The customer-side half of get_effective_cod_rule() — unchanged
     *  logic, pulled into its own function so it can be combined with
     *  the restaurant-side half below without one call site doing both
     *  resolutions inline.
     *
     * @return array{cod_enabled:bool, min_prepaid_orders:int, max_cod_order_amount:?float, max_cod_orders_per_day:?int, new_customer_cod_blocked:bool, area_id:?int, source:string}
     */
    function resolve_cod_rule_for_address(PDO $db, ?float $lat, ?float $lng): array
    {
        $defaults = cod_rule_platform_defaults() + ['area_id' => null, 'source' => 'platform_default'];

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

if (!function_exists('resolve_cod_rule_for_restaurant_area')) {
    /** The restaurant-side half — resolves from a KNOWN area_id
     *  (restaurants.area_id, already assigned by an admin), walking the
     *  full parent chain via resolve_area_cod_rule_by_area_id() rather
     *  than the nearest-node-plus-immediate-parent shape the lat/lng
     *  side uses, since there's no "nearest" concept for an already-known
     *  id — same reasoning delivery_pricing.php's
     *  get_min_order_floor_for_area_id() already established for this
     *  exact kind of lookup. */
    function resolve_cod_rule_for_restaurant_area(PDO $db, ?int $restaurantAreaId): array
    {
        $defaults = cod_rule_platform_defaults() + ['area_id' => null, 'source' => 'platform_default'];

        if ($restaurantAreaId === null) {
            return $defaults;
        }

        $rule = resolve_area_cod_rule_by_area_id($db, $restaurantAreaId);
        if (!$rule) {
            return $defaults;
        }

        return [
            'cod_enabled' => (bool) $rule['cod_enabled'],
            'min_prepaid_orders' => (int) $rule['min_prepaid_orders'],
            'max_cod_order_amount' => $rule['max_cod_order_amount'] !== null ? (float) $rule['max_cod_order_amount'] : null,
            'max_cod_orders_per_day' => $rule['max_cod_orders_per_day'] !== null ? (int) $rule['max_cod_orders_per_day'] : null,
            'new_customer_cod_blocked' => (bool) $rule['new_customer_cod_blocked'],
            'area_id' => (int) $rule['area_id'],
            'source' => 'area_rule',
        ];
    }
}

if (!function_exists('get_effective_cod_rule')) {
    /**
     * Resolves the effective COD rule, combining BOTH sides —
     * strictest field wins — when a restaurant area is given:
     *
     *   - customer side: the delivery address's lat/lng, resolved the
     *     same nearest-within-radius way as before this change
     *     (resolve_cod_rule_for_address() above, logic unchanged).
     *   - restaurant side: the restaurant's own admin-assigned area_id,
     *     if the caller has one to pass (resolve_cod_rule_for_restaurant_area()
     *     above). Omit $restaurantAreaId (or pass null) to get the old,
     *     address-only behaviour untouched — every existing call site
     *     that hasn't been updated to pass it yet still works exactly
     *     as before.
     *
     * "Strictest wins" per field:
     *   - cod_enabled: false if EITHER side disables it (AND)
     *   - min_prepaid_orders: the HIGHER requirement (MAX)
     *   - max_cod_order_amount: the LOWER cap (MIN; null = no cap, so a
     *     null on one side never weakens a real cap on the other)
     *   - max_cod_orders_per_day: the LOWER cap (MIN; same null handling)
     *   - new_customer_cod_blocked: true if EITHER side blocks it (OR)
     *
     * @return array{cod_enabled:bool, min_prepaid_orders:int, max_cod_order_amount:?float, max_cod_orders_per_day:?int, new_customer_cod_blocked:bool, area_id:?int, restaurant_area_id:?int, source:string}
     */
    function get_effective_cod_rule(PDO $db, ?float $lat, ?float $lng, ?int $restaurantAreaId = null): array
    {
        $customerRule = resolve_cod_rule_for_address($db, $lat, $lng);

        if ($restaurantAreaId === null) {
            // Old call sites that haven't been updated yet — unchanged
            // shape/behaviour, just with the now-always-present
            // restaurant_area_id key set to null for a consistent shape.
            return $customerRule + ['restaurant_area_id' => null];
        }

        $restaurantRule = resolve_cod_rule_for_restaurant_area($db, $restaurantAreaId);

        $minCap = function (?float $a, ?float $b): ?float {
            if ($a === null) return $b;
            if ($b === null) return $a;
            return min($a, $b);
        };
        $minCapInt = function (?int $a, ?int $b): ?int {
            if ($a === null) return $b;
            if ($b === null) return $a;
            return min($a, $b);
        };

        $bothDefault = $customerRule['source'] === 'platform_default' && $restaurantRule['source'] === 'platform_default';

        return [
            'cod_enabled' => $customerRule['cod_enabled'] && $restaurantRule['cod_enabled'],
            'min_prepaid_orders' => max($customerRule['min_prepaid_orders'], $restaurantRule['min_prepaid_orders']),
            'max_cod_order_amount' => $minCap($customerRule['max_cod_order_amount'], $restaurantRule['max_cod_order_amount']),
            'max_cod_orders_per_day' => $minCapInt($customerRule['max_cod_orders_per_day'], $restaurantRule['max_cod_orders_per_day']),
            'new_customer_cod_blocked' => $customerRule['new_customer_cod_blocked'] || $restaurantRule['new_customer_cod_blocked'],
            'area_id' => $customerRule['area_id'],
            'restaurant_area_id' => $restaurantRule['area_id'],
            'source' => $bothDefault ? 'platform_default' : 'combined_strictest',
        ];
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
