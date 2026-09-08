<?php
/**
 * Anydrop — Area-wise general payment method restrictions
 * (recall.md Phase B item 15, migration 37).
 *
 * This is the coarse "is this payment method allowed in this area at
 * all" gate — distinct from lib/cod_rules.php's finer COD-specific
 * eligibility (min prepaid orders, max amount, daily cap, new-customer
 * block), which only ever runs for a customer whose payment method has
 * already passed this gate. An order's payment_method must clear BOTH
 * layers when it's 'cod'; 'upi' only needs this one.
 *
 * Single source of truth reused by orders/create.php (server-side
 * enforcement) and customer/payment-methods.php (checkout UI's
 * "which methods can I even offer" pre-check), same
 * never-let-the-two-drift-apart reasoning as cod_rules.php.
 *
 * The Customer App never evaluates this rule itself — it only ever
 * receives the resolved allowed/blocked list + reasons from whichever
 * endpoint calls this. Nothing here is an Android constant.
 *
 * 2026-09-07 (app owner decision — same "combine both sides, strictest
 * wins" call as cod_rules.php's identical change today): this now
 * optionally also takes the restaurant's own admin-assigned area_id.
 * "Stricter" for a plain allowed/blocked flag is just AND — a method
 * is only allowed if BOTH the delivery address's area and the
 * restaurant's own area allow it. Omit $restaurantAreaId to keep the
 * old address-only behaviour, same escape hatch cod_rules.php's
 * version offers.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/geo.php';

if (!function_exists('resolve_area_payment_restriction_by_area_id')) {
    /** Same "walk a KNOWN area_id up to root" shape as
     *  cod_rules.php's resolve_area_cod_rule_by_area_id() /
     *  delivery_pricing.php's resolve_area_pricing_rule_row() — for a
     *  restaurant's own admin-assigned area_id, not a lat/lng
     *  resolution. */
    function resolve_area_payment_restriction_by_area_id(PDO $db, int $areaId): ?array
    {
        $areaNodes = [];
        foreach ($db->query('SELECT id, parent_id FROM service_areas')->fetchAll() as $row) {
            $areaNodes[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        $cursor = $areaId;
        $seen = [];
        while ($cursor !== null && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $stmt = $db->prepare('SELECT * FROM area_payment_restrictions WHERE area_id = :a AND is_active = 1 LIMIT 1');
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

if (!function_exists('payment_restriction_platform_defaults')) {
    function payment_restriction_platform_defaults(): array
    {
        return [
            'upi_allowed' => (bool) ((int) get_setting('default_upi_allowed', 1)),
            'cod_allowed' => (bool) ((int) get_setting('default_cod_allowed', 1)),
        ];
    }
}

if (!function_exists('resolve_payment_restriction_for_address')) {
    /** The customer-side half — unchanged logic from before this
     *  session's change, pulled into its own function.
     *
     * @return array{upi_allowed:bool, cod_allowed:bool, area_id:?int, source:string}
     */
    function resolve_payment_restriction_for_address(PDO $db, ?float $lat, ?float $lng): array
    {
        $defaults = payment_restriction_platform_defaults() + ['area_id' => null, 'source' => 'platform_default'];

        if ($lat === null || $lng === null) {
            return $defaults;
        }

        $resolved = resolve_service_area($db, $lat, $lng);
        if (empty($resolved)) {
            return $defaults;
        }

        $candidateIds = [$resolved[0]['id']];
        if ($resolved[0]['level'] === 'area' && $resolved[0]['parent_id'] !== null) {
            $candidateIds[] = $resolved[0]['parent_id'];
        }

        foreach ($candidateIds as $areaId) {
            $stmt = $db->prepare(
                'SELECT * FROM area_payment_restrictions WHERE area_id = :aid AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['aid' => $areaId]);
            $rule = $stmt->fetch();
            if ($rule) {
                return [
                    'upi_allowed' => (bool) $rule['upi_allowed'],
                    'cod_allowed' => (bool) $rule['cod_allowed'],
                    'area_id' => $areaId,
                    'source' => 'area_rule',
                ];
            }
        }

        return $defaults;
    }
}

if (!function_exists('resolve_payment_restriction_for_restaurant_area')) {
    /** The restaurant-side half — resolves from restaurants.area_id
     *  (already assigned by an admin), walking the full parent chain. */
    function resolve_payment_restriction_for_restaurant_area(PDO $db, ?int $restaurantAreaId): array
    {
        $defaults = payment_restriction_platform_defaults() + ['area_id' => null, 'source' => 'platform_default'];

        if ($restaurantAreaId === null) {
            return $defaults;
        }

        $rule = resolve_area_payment_restriction_by_area_id($db, $restaurantAreaId);
        if (!$rule) {
            return $defaults;
        }

        return [
            'upi_allowed' => (bool) $rule['upi_allowed'],
            'cod_allowed' => (bool) $rule['cod_allowed'],
            'area_id' => (int) $rule['area_id'],
            'source' => 'area_rule',
        ];
    }
}

if (!function_exists('get_effective_payment_restrictions')) {
    /**
     * Resolves the effective payment-method restriction, combining
     * BOTH the delivery address's area and (if given) the restaurant's
     * own admin-assigned area — a method is only allowed if BOTH sides
     * allow it (plain AND, since these are just booleans; there's no
     * "how strict" gradient like cod_rules.php's numeric fields have).
     * Omit $restaurantAreaId to get the old address-only behaviour.
     *
     * @return array{upi_allowed:bool, cod_allowed:bool, area_id:?int, restaurant_area_id:?int, source:string}
     */
    function get_effective_payment_restrictions(PDO $db, ?float $lat, ?float $lng, ?int $restaurantAreaId = null): array
    {
        $customerRestriction = resolve_payment_restriction_for_address($db, $lat, $lng);

        if ($restaurantAreaId === null) {
            return $customerRestriction + ['restaurant_area_id' => null];
        }

        $restaurantRestriction = resolve_payment_restriction_for_restaurant_area($db, $restaurantAreaId);
        $bothDefault = $customerRestriction['source'] === 'platform_default' && $restaurantRestriction['source'] === 'platform_default';

        return [
            'upi_allowed' => $customerRestriction['upi_allowed'] && $restaurantRestriction['upi_allowed'],
            'cod_allowed' => $customerRestriction['cod_allowed'] && $restaurantRestriction['cod_allowed'],
            'area_id' => $customerRestriction['area_id'],
            'restaurant_area_id' => $restaurantRestriction['area_id'],
            'source' => $bothDefault ? 'platform_default' : 'combined_strictest',
        ];
    }
}

if (!function_exists('is_payment_method_allowed_in_area')) {
    /**
     * The actual yes/no + reason for one specific payment_method value,
     * given an already-resolved restriction (get_effective_payment_restrictions()
     * above). This only covers the general area-wide gate — a 'cod'
     * result of true here still has to separately clear
     * evaluate_cod_eligibility() (lib/cod_rules.php) before an order can
     * actually use COD; the two are intentionally not merged into one
     * function so each stays scoped to what its own migration owns.
     *
     * @return array{allowed:bool, reason:?string}
     */
    function is_payment_method_allowed_in_area(array $restriction, string $paymentMethod): array
    {
        if ($paymentMethod === 'upi' && !$restriction['upi_allowed']) {
            return ['allowed' => false, 'reason' => 'upi_not_available_in_area'];
        }
        if ($paymentMethod === 'cod' && !$restriction['cod_allowed']) {
            return ['allowed' => false, 'reason' => 'cod_not_available_in_area'];
        }
        return ['allowed' => true, 'reason' => null];
    }
}
