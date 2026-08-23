<?php
/**
 * Anydrop — Area-wise Minimum Order Floor + Distance-Based Delivery Fee
 * (recall.md Phase B items 13/14, migration 36).
 *
 * Two independent things live here, both keyed off the same
 * area_pricing_rules table:
 *
 *   1. get_min_order_floor_for_area_id() — used by
 *      restaurant/profile-update.php to reject a restaurant trying to
 *      save its own min_order_amount below its area's admin-set floor.
 *      Walks a KNOWN area_id's own parent chain (the restaurant's own
 *      restaurants.area_id, already assigned by admin — recall.md item
 *      2) rather than resolving from lat/lng, since there's no
 *      "delivery location" in play at profile-save time.
 *
 *   2. calculate_delivery_fee() — used by lib/orders.php's price_cart()
 *      to price an actual order/preview. Resolves the rate/base-fee
 *      rule from the DELIVERY ADDRESS's lat/lng (same resolution
 *      pattern as get_effective_cod_rule() — nearest service_areas
 *      node, then its parent), because delivery fee is fundamentally
 *      about "what does it cost to deliver INTO this area", not about
 *      the restaurant's own area assignment. Falls back to the old
 *      flat delivery_charge_flat setting whenever a distance can't be
 *      computed (restaurant or delivery address missing lat/lng) —
 *      same "don't hide behind unresolved data" stance as every other
 *      geo feature in this project.
 *
 * Both never let the app itself decide these numbers — server is the
 * only source of truth, per recall.md rule 34.8 ("never hardcode
 * area-specific rules... inside the Customer/Restaurant App").
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/geo.php';

if (!function_exists('ceil_to_nearest_5')) {
    /**
     * Ceiling to the nearest ₹5 — app owner's explicit rounding rule
     * for delivery fee (2026-08-22): "mera paisa minus nahi hona
     * chahiye" (my money should never go down from rounding).
     * 16→20, 17→20, 18→20, 19→20, 20→20, 21→25. Never rounds down;
     * an already-exact multiple of 5 is left unchanged.
     */
    function ceil_to_nearest_5(float $amount): float
    {
        return ceil($amount / 5) * 5;
    }
}

if (!function_exists('resolve_area_pricing_rule_row')) {
    /**
     * Shared lookup: given a starting area_id, checks that node's own
     * area_pricing_rules row, then walks up to its parent if the node
     * itself has none — same "more specific override wins, but a
     * parent-level rule still reaches an unconfigured child" pattern
     * as get_effective_cod_rule(). Returns null if nothing is found
     * anywhere up the chain (caller applies platform defaults).
     */
    function resolve_area_pricing_rule_row(PDO $db, int $areaId): ?array
    {
        $areaNodes = [];
        foreach ($db->query('SELECT id, parent_id FROM service_areas')->fetchAll() as $row) {
            $areaNodes[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        $cursor = $areaId;
        $seen = [];
        while ($cursor !== null && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $stmt = $db->prepare('SELECT * FROM area_pricing_rules WHERE area_id = :a AND is_active = 1 LIMIT 1');
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

if (!function_exists('get_min_order_floor_for_area_id')) {
    /**
     * The minimum a restaurant in this area is allowed to set its own
     * min_order_amount to. NULL area_id (restaurant not yet assigned
     * to a service area by admin — recall.md item 2) simply uses the
     * platform default, same as an unresolved customer location
     * elsewhere in this project.
     */
    function get_min_order_floor_for_area_id(PDO $db, ?int $areaId): float
    {
        $platformDefault = (float) get_setting('default_min_order_amount', 0);

        if ($areaId === null) {
            return $platformDefault;
        }

        $rule = resolve_area_pricing_rule_row($db, $areaId);
        if ($rule && $rule['min_order_amount'] !== null) {
            return (float) $rule['min_order_amount'];
        }
        return $platformDefault;
    }
}

if (!function_exists('calculate_delivery_fee')) {
    /**
     * @return array{fee: float, source: string, distance_km: ?float, rate_per_km: ?float, base_fee: ?float, area_id: ?int}
     *
     * source is one of:
     *   'distance_area_rule'   — real distance, area-specific rate/base
     *   'distance_platform_default' — real distance, platform default rate/base
     *   'flat_fallback'        — couldn't compute a distance at all
     *     (restaurant or delivery address missing lat/lng); uses the
     *     original flat delivery_charge_flat setting untouched, so
     *     nothing regresses for any caller that still can't supply
     *     coordinates.
     */
    function calculate_delivery_fee(
        PDO $db,
        ?float $restaurantLat,
        ?float $restaurantLng,
        ?float $deliveryLat,
        ?float $deliveryLng
    ): array {
        if ($restaurantLat === null || $restaurantLng === null || $deliveryLat === null || $deliveryLng === null) {
            return [
                'fee' => (float) get_setting('delivery_charge_flat', 25),
                'source' => 'flat_fallback',
                'distance_km' => null,
                'rate_per_km' => null,
                'base_fee' => null,
                'area_id' => null,
            ];
        }

        $distanceKm = haversine_km($restaurantLat, $restaurantLng, $deliveryLat, $deliveryLng);

        $platformRate = (float) get_setting('default_delivery_rate_per_km', 8);
        $platformBase = (float) get_setting('default_delivery_base_fee', 0);

        $ratePerKm = $platformRate;
        $baseFee = $platformBase;
        $source = 'distance_platform_default';
        $matchedAreaId = null;

        $resolved = resolve_service_area($db, $deliveryLat, $deliveryLng);
        if (!empty($resolved)) {
            // Nearest node first, then its parent — same eligible-set
            // walk as get_effective_cod_rule()/resolve_pricing_rule_for_area_id.
            $candidateIds = [$resolved[0]['id']];
            if ($resolved[0]['level'] === 'area' && $resolved[0]['parent_id'] !== null) {
                $candidateIds[] = $resolved[0]['parent_id'];
            }
            foreach ($candidateIds as $candidateId) {
                $stmt = $db->prepare('SELECT * FROM area_pricing_rules WHERE area_id = :a AND is_active = 1 LIMIT 1');
                $stmt->execute(['a' => $candidateId]);
                $rule = $stmt->fetch();
                if ($rule) {
                    if ($rule['delivery_rate_per_km'] !== null) {
                        $ratePerKm = (float) $rule['delivery_rate_per_km'];
                    }
                    if ($rule['delivery_base_fee'] !== null) {
                        $baseFee = (float) $rule['delivery_base_fee'];
                    }
                    $source = 'distance_area_rule';
                    $matchedAreaId = $candidateId;
                    break;
                }
            }
        }

        $rawFee = $baseFee + ($distanceKm * $ratePerKm);
        $fee = ceil_to_nearest_5($rawFee);

        return [
            'fee' => $fee,
            'source' => $source,
            'distance_km' => round($distanceKm, 2),
            'rate_per_km' => $ratePerKm,
            'base_fee' => $baseFee,
            'area_id' => $matchedAreaId,
        ];
    }
}
