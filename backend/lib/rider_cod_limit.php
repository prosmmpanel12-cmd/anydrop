<?php
/**
 * Anydrop — Area-wise Rider COD Cash-Hold Limit (Deep Plan Phase 4,
 * migration 81).
 *
 * Single source of truth for "how much COD cash may this rider hold
 * before being excluded from new COD-order dispatch" — used by
 * `lib/dispatch.php`'s find_eligible_riders() (server-side enforcement)
 * and `rider/me.php` (so the Rider App can show a "cash hold limit
 * reached" banner without a separate poll). Keeping this in one
 * function means the two can never drift apart, same reasoning
 * `lib/cod_rules.php`'s file-header comment gives for that table's
 * shared resolver.
 *
 * Falls back to the existing platform-wide `rider_cod_settlement_limit`
 * setting (migration 53, `lib/rider_ledger.php`'s
 * rider_cod_settlement_limit() helper) when a rider's service area has
 * no override row — nothing about that existing flat limit changes for
 * a rider whose area was never given a specific override.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/rider_ledger.php';

if (!function_exists('resolve_area_rider_cod_limit_by_area_id')) {
    /**
     * Given a KNOWN area_id (riders.service_area_id, assigned at
     * signup/approval — not resolved from lat/lng), walks up its full
     * parent chain (City/Village -> District -> State) via
     * service_areas and returns the first active area_rider_cod_limits
     * row's cod_hold_limit found, or null if nothing anywhere up the
     * chain has one. Same "walk to root, more specific wins" shape as
     * `cod_rules.php`'s resolve_area_cod_rule_by_area_id() — that one
     * resolves a KNOWN area_id too (a restaurant's own), for the exact
     * same reason: there's no "nearest" concept for an already-assigned
     * id the way there is for a customer's lat/lng.
     */
    function resolve_area_rider_cod_limit_by_area_id(PDO $db, int $areaId): ?float
    {
        $areaNodes = [];
        foreach ($db->query('SELECT id, parent_id FROM service_areas')->fetchAll() as $row) {
            $areaNodes[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        }

        $cursor = $areaId;
        $seen = [];
        while ($cursor !== null && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $stmt = $db->prepare(
                'SELECT cod_hold_limit FROM area_rider_cod_limits WHERE area_id = :a AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['a' => $cursor]);
            $row = $stmt->fetch();
            if ($row) {
                return (float) $row['cod_hold_limit'];
            }
            $cursor = $areaNodes[$cursor] ?? null;
        }
        return null;
    }
}

if (!function_exists('get_effective_rider_cod_limit')) {
    /**
     * Resolves the ₹ cash-hold limit that applies to a rider whose
     * `service_area_id` is $riderAreaId (pass null for a rider with no
     * area assigned yet — falls straight to the platform default, same
     * as every other "no area" case in this codebase).
     *
     * @return array{limit: float, area_id: ?int, source: string}
     */
    function get_effective_rider_cod_limit(PDO $db, ?int $riderAreaId): array
    {
        $platformDefault = rider_cod_settlement_limit();

        if ($riderAreaId === null) {
            return ['limit' => $platformDefault, 'area_id' => null, 'source' => 'platform_default'];
        }

        $override = resolve_area_rider_cod_limit_by_area_id($db, $riderAreaId);
        if ($override === null) {
            return ['limit' => $platformDefault, 'area_id' => null, 'source' => 'platform_default'];
        }

        return ['limit' => $override, 'area_id' => $riderAreaId, 'source' => 'area_rule'];
    }
}
