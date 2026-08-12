<?php
/**
 * GET /api/v1/home/popular-items.php?lat=&lng=&limit=
 * (Mapped from clean URL GET /home/popular-items per Phase 3.6 §2.4)
 * Auth: Customer token
 *
 * Returns a curated set of dishes (bestsellers / recommended first) across
 * nearby open restaurants, for the "Popular dishes near you" horizontal
 * row on Home — same "from <Restaurant Name>" tagging pattern already
 * used by search.php and category-items.php, plus `is_saved` so the
 * bookmark icon on each dish card renders correctly.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$limit = min(30, max(1, (int) ($_GET['limit'] ?? 15)));

$db = Database::get();

$stmt = $db->query(
    "SELECT mi.*, r.id AS r_id, r.name AS r_name, r.logo_url AS r_logo_url,
            r.rating_avg AS r_rating_avg, r.latitude AS r_lat, r.longitude AS r_lng,
            r.operational_status AS r_operational_status
     FROM menu_items mi
     INNER JOIN restaurants r ON r.id = mi.restaurant_id
     WHERE r.status = 'approved' AND r.deleted_at IS NULL
       AND mi.deleted_at IS NULL AND mi.is_available = 1
       AND (mi.is_bestseller = 1 OR mi.is_recommended = 1)
     ORDER BY mi.is_bestseller DESC, mi.is_recommended DESC, r.rating_avg DESC"
);
$rows = $stmt->fetchAll();

$savedItems = get_saved_item_ids((int) $owner['owner_id']);

$items = [];
foreach ($rows as $it) {
    $distanceKm = null;
    if ($lat !== null && $lng !== null && $it['r_lat'] !== null && $it['r_lng'] !== null) {
        $distanceKm = round(haversine_km($lat, $lng, (float) $it['r_lat'], (float) $it['r_lng']), 2);
    }

    $items[] = [
        'id' => (int) $it['id'],
        'name' => $it['name'],
        'description' => $it['description'],
        'price' => (float) $it['price'],
        'discount_percent' => (float) $it['discount_percent'],
        'is_veg' => (bool) $it['is_veg'],
        'image_url' => $it['image_url'],
        'is_recommended' => (bool) $it['is_recommended'],
        'is_bestseller' => (bool) $it['is_bestseller'],
        'restaurant_id' => (int) $it['r_id'],
        'restaurant_name' => $it['r_name'],
        'restaurant_logo_url' => $it['r_logo_url'],
        'restaurant_rating' => (float) $it['r_rating_avg'],
        'distance_km' => $distanceKm,
        'is_saved' => isset($savedItems[(int) $it['id']]),
    ];
}

usort($items, function ($a, $b) {
    if ($a['distance_km'] !== null && $b['distance_km'] !== null) {
        return $a['distance_km'] <=> $b['distance_km'];
    }
    return $b['restaurant_rating'] <=> $a['restaurant_rating'];
});

respond_ok(['items' => array_slice($items, 0, $limit)]);

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
