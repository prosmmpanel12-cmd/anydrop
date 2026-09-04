<?php
/**
 * Shared geo helpers. haversine_km() previously lived only inside
 * restaurants/list.php; features.md §6 needs the exact same distance calc
 * in restaurants/menu.php too (restaurant detail header's "2.7 km" line),
 * so it's pulled out here rather than copy-pasted a second time.
 */

if (!function_exists('haversine_km')) {
    function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}

if (!function_exists('resolve_service_area')) {
    /**
     * Nearest-within-radius service-area resolution — the same rule
     * backend/admin/areas.php's "Test coordinates" tool has used since
     * migration 30/32 (recall.md item 2), pulled out here so any other
     * caller (starting with home/promo-banners.php's area-targeted
     * banners, recall.md item 17) can reuse it instead of re-deriving
     * the same query.
     *
     * A node is only a candidate if it actually has center_lat/
     * center_lng/radius_km set (City/Village or Area level, whichever
     * is the deepest one actually created in a branch — see migration
     * 30's header). Returns every active match within its own radius,
     * nearest first — NOT just the top one — since a caller may care
     * about "also within" overlaps the same way areas.php's UI shows
     * them, or (banner-fetch's case) may want to walk the list rather
     * than trust only the single nearest node.
     *
     * @return array<int, array{id:int, parent_id:?int, name:string, level:string, distance:float}>
     */
    function resolve_service_area(PDO $db, float $lat, float $lng): array
    {
        $areaRows = $db->query(
            "SELECT id, parent_id, name, level, center_lat, center_lng, radius_km FROM service_areas
             WHERE is_active = 1
               AND center_lat IS NOT NULL AND center_lng IS NOT NULL AND radius_km IS NOT NULL"
        )->fetchAll();

        $matches = [];
        foreach ($areaRows as $ar) {
            $dist = haversine_km($lat, $lng, (float) $ar['center_lat'], (float) $ar['center_lng']);
            if ($dist <= (float) $ar['radius_km']) {
                $matches[] = [
                    'id' => (int) $ar['id'],
                    'parent_id' => $ar['parent_id'] !== null ? (int) $ar['parent_id'] : null,
                    'name' => $ar['name'],
                    'level' => $ar['level'],
                    'distance' => $dist,
                ];
            }
        }
        usort($matches, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return $matches;
    }
}
