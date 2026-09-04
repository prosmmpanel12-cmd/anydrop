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
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/geo.php';

if (!function_exists('get_effective_payment_restrictions')) {
    /**
     * Resolves the effective payment-method restriction for a lat/lng:
     * the nearest matching service_areas node's area_payment_restrictions
     * row if one exists and is active, else the platform-wide
     * app_settings defaults. Same nearest-within-radius resolution and
     * eligible-set walk (nearest node + its parent when the nearest is
     * level='area') as get_effective_cod_rule() / other
     * resolve_service_area() callers — an Area-level override wins over
     * its parent City/Village's, but a City/Village-level rule still
     * reaches a customer resolved into a child Area that has no rule of
     * its own.
     *
     * @return array{upi_allowed:bool, cod_allowed:bool, area_id:?int, source:string}
     */
    function get_effective_payment_restrictions(PDO $db, ?float $lat, ?float $lng): array
    {
        $defaults = [
            'upi_allowed' => (bool) ((int) get_setting('default_upi_allowed', 1)),
            'cod_allowed' => (bool) ((int) get_setting('default_cod_allowed', 1)),
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
