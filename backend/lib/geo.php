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
