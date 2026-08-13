<?php
/**
 * GET /api/v1/search/search.php?q=&lat=&lng=
 * (Mapped from clean URL GET /search?q=&lat=&lng= per 02_API_Contract.md §2)
 * Auth: Customer token
 *
 * Searches restaurant names, cuisine tags, and menu item names.
 *
 * Response shape:
 *   data.restaurants  — restaurants ranked by relevance (name match > cuisine
 *                        match > dish match), each carrying `matched_dish`
 *                        when the match came from a menu item.
 *   data.items        — every menu item whose name matched the query, from
 *                        ANY restaurant (not just the top-ranked one), each
 *                        tagged with `restaurant_id` / `restaurant_name` so
 *                        the UI can show "from <Restaurant>" on the item card
 *                        and group "also available at" alternatives for the
 *                        same dish.
 *
 * This lets a search like "pizza" or a specific restaurant name surface
 * both that restaurant's own menu AND the same/similar dish from other
 * restaurants, each clearly tagged with its source restaurant.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$savedRestaurants = get_saved_restaurant_ids((int) $owner['owner_id']);
$savedItems = get_saved_item_ids((int) $owner['owner_id']);

$q = trim((string) ($_GET['q'] ?? ''));
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;

if ($q === '' || mb_strlen($q) < 2) {
    respond_ok(['restaurants' => [], 'items' => [], 'meta' => ['query' => $q]]);
}

$db = Database::get();
$like = '%' . $q . '%';

// 1) Restaurants matching by name or cuisine tags
$stmt = $db->prepare(
    "SELECT *, 'name' AS match_type, NULL AS matched_dish FROM restaurants
     WHERE status = 'approved' AND deleted_at IS NULL
       AND (name LIKE :q1 OR cuisine_tags LIKE :q2)"
);
$stmt->execute(['q1' => $like, 'q2' => $like]);
$byName = $stmt->fetchAll();

// 2) Restaurants matching via a menu item name (dish search, e.g. "biryani")
$stmt = $db->prepare(
    "SELECT DISTINCT r.*, 'dish' AS match_type,
        (SELECT mi2.name FROM menu_items mi2
         WHERE mi2.restaurant_id = r.id AND mi2.deleted_at IS NULL
           AND mi2.is_available = 1 AND mi2.name LIKE :q3
         LIMIT 1) AS matched_dish
     FROM restaurants r
     INNER JOIN menu_items mi ON mi.restaurant_id = r.id
     WHERE r.status = 'approved' AND r.deleted_at IS NULL
       AND mi.deleted_at IS NULL AND mi.is_available = 1
       AND mi.name LIKE :q"
);
$stmt->execute(['q' => $like, 'q3' => $like]);
$byDish = $stmt->fetchAll();

// Merge restaurants, name-matches take priority, no duplicate restaurants
$mergedRestaurants = [];
foreach ($byName as $r) {
    $mergedRestaurants[$r['id']] = $r;
}
foreach ($byDish as $r) {
    if (!isset($mergedRestaurants[$r['id']])) {
        $mergedRestaurants[$r['id']] = $r;
    }
}

$restaurantIds = array_map(fn($r) => (int) $r['id'], $mergedRestaurants);

// Fetch tags (Near & Fast / Pure Veg / Under ₹200 / Gold extra 10%) for all
// matched restaurants in one query, then group in PHP — avoids N+1 queries.
// Same pattern as restaurants/list.php. Missing this is what causes the
// Android client's Restaurant.tags to deserialize as null (Gson does not
// apply Kotlin default parameter values), crashing SearchResultsAdapter.
$tagsByRestaurant = [];
if (!empty($restaurantIds)) {
    $placeholders = implode(',', array_fill(0, count($restaurantIds), '?'));
    $tagStmt = $db->prepare(
        "SELECT rtm.restaurant_id, rt.name, rt.slug
         FROM restaurant_tag_map rtm
         INNER JOIN restaurant_tags rt ON rt.id = rtm.restaurant_tag_id
         WHERE rtm.restaurant_id IN ($placeholders)"
    );
    $tagStmt->execute(array_values($restaurantIds));
    foreach ($tagStmt->fetchAll() as $t) {
        $tagsByRestaurant[$t['restaurant_id']][] = ['name' => $t['name'], 'slug' => $t['slug']];
    }
}

// Gallery photos for the auto-advancing card carousel (§2.7) — same
// pattern as tags above, and same "missing this = null on the Android
// side" caveat noted in the comment above (RestaurantAdapter/
// SearchResultsAdapter both call `.orEmpty()` on it, so a missing key just
// silently falls back to the plain single-image card, no crash).
$galleryByRestaurant = [];
if (!empty($restaurantIds)) {
    $galleryStmt = $db->prepare(
        "SELECT restaurant_id, image_url, dish_name, price
         FROM restaurant_gallery_photos
         WHERE restaurant_id IN ($placeholders)
         ORDER BY restaurant_id, sort_order"
    );
    $galleryStmt->execute(array_values($restaurantIds));
    foreach ($galleryStmt->fetchAll() as $g) {
        $galleryByRestaurant[$g['restaurant_id']][] = [
            'image_url' => $g['image_url'],
            'dish_name' => $g['dish_name'],
            'price' => $g['price'] !== null ? (float) $g['price'] : null,
        ];
    }
}

$now = new DateTime();
$currentTime = $now->format('H:i:s');
$currentDow = (int) $now->format('N');

function compute_open_now(array $r, string $currentTime, int $currentDow): bool
{
    if ($r['operational_status'] !== 'open' || !$r['opening_time'] || !$r['closing_time']) {
        return false;
    }
    $days = explode(',', (string) $r['working_days']);
    $dayMatches = in_array((string) $currentDow, $days, true);
    $timeMatches = ($currentTime >= $r['opening_time'] && $currentTime <= $r['closing_time']);
    return $dayMatches && $timeMatches;
}

$restaurantResults = [];
foreach ($mergedRestaurants as $r) {
    $isOpenNow = compute_open_now($r, $currentTime, $currentDow);
    // Part B follow-up — same "paused vs outside hours" distinction as
    // restaurants/list.php; kept in sync since both feed the same
    // Restaurant model/card UI on the Android side.
    $isPaused = in_array($r['operational_status'], ['busy', 'temp_closed'], true);

    $distanceKm = null;
    if ($lat !== null && $lng !== null && $r['latitude'] !== null && $r['longitude'] !== null) {
        $distanceKm = round(haversine_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']), 2);
    }

    $restaurantResults[] = [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'address' => $r['address'],
        'latitude' => $r['latitude'] !== null ? (float) $r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float) $r['longitude'] : null,
        'logo_url' => $r['logo_url'],
        'cover_url' => $r['cover_url'],
        'cuisine_tags' => $r['cuisine_tags'],
        'is_veg_only' => (bool) $r['is_veg_only'],
        'min_order_amount' => (float) $r['min_order_amount'],
        'rating_avg' => (float) $r['rating_avg'],
        'rating_count' => (int) ($r['rating_count'] ?? 0),
        'distance_km' => $distanceKm,
        'estimated_delivery_minutes' => $distanceKm !== null ? (int) round(15 + $distanceKm * 4) : null,
        'is_open_now' => $isOpenNow,
        'is_paused' => $isPaused,
        'offer_badge_text' => $r['offer_badge_text'] ?? null,
        'tags' => $tagsByRestaurant[$r['id']] ?? [],
        'gallery' => $galleryByRestaurant[$r['id']] ?? [],
        'match_type' => $r['match_type'],
        'matched_dish' => $r['matched_dish'],
        'is_saved' => isset($savedRestaurants[(int) $r['id']]),
    ];
}

usort($restaurantResults, function ($a, $b) {
    if ($a['match_type'] !== $b['match_type']) {
        return $a['match_type'] === 'name' ? -1 : 1;
    }
    if ($a['distance_km'] !== null && $b['distance_km'] !== null) {
        return $a['distance_km'] <=> $b['distance_km'];
    }
    return $b['rating_avg'] <=> $a['rating_avg'];
});

// ------------------------------------------------------------------
// 3) Items block — every available menu item matching the query BY
//    NAME, from any approved restaurant (not limited to the restaurants
//    matched above), each tagged with its restaurant so the app can show
//    "from <Restaurant Name>" and group same-named dishes as
//    "also available at" alternatives.
// ------------------------------------------------------------------
$itemStmt = $db->prepare(
    "SELECT mi.*, r.id AS r_id, r.name AS r_name, r.logo_url AS r_logo_url,
            r.rating_avg AS r_rating_avg, r.latitude AS r_lat, r.longitude AS r_lng,
            r.operational_status AS r_operational_status, r.opening_time AS r_opening_time,
            r.closing_time AS r_closing_time, r.working_days AS r_working_days
     FROM menu_items mi
     INNER JOIN restaurants r ON r.id = mi.restaurant_id
     WHERE r.status = 'approved' AND r.deleted_at IS NULL
       AND mi.deleted_at IS NULL AND mi.is_available = 1
       AND mi.name LIKE :q
     ORDER BY mi.name ASC"
);
$itemStmt->execute(['q' => $like]);
$rawItems = $itemStmt->fetchAll();

$items = [];
foreach ($rawItems as $it) {
    $rArr = [
        'operational_status' => $it['r_operational_status'],
        'opening_time' => $it['r_opening_time'],
        'closing_time' => $it['r_closing_time'],
        'working_days' => $it['r_working_days'],
    ];
    $isOpenNow = compute_open_now($rArr, $currentTime, $currentDow);

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
        // The tag the UI shows on every item card, e.g. "from Burger King"
        'restaurant_id' => (int) $it['r_id'],
        'restaurant_name' => $it['r_name'],
        'restaurant_logo_url' => $it['r_logo_url'],
        'restaurant_rating' => (float) $it['r_rating_avg'],
        'restaurant_is_open_now' => $isOpenNow,
        'distance_km' => $distanceKm,
        // True when this item's restaurant is NOT one of the restaurants
        // that matched the query directly (i.e. it showed up only because
        // the dish name matched) — the app can label this group
        // "Also available at" to distinguish from the searched restaurant's
        // own menu.
        'is_cross_restaurant_match' => !in_array((int) $it['r_id'], $restaurantIds, true),
        'is_saved' => isset($savedItems[(int) $it['id']]),
    ];
}

// Rank items: same restaurant as the top restaurant match first, then by
// distance, then rating — so a restaurant-name search shows that
// restaurant's own items first, followed by the same dish elsewhere.
usort($items, function ($a, $b) {
    if ($a['is_cross_restaurant_match'] !== $b['is_cross_restaurant_match']) {
        return $a['is_cross_restaurant_match'] ? 1 : -1;
    }
    if ($a['distance_km'] !== null && $b['distance_km'] !== null) {
        return $a['distance_km'] <=> $b['distance_km'];
    }
    return $b['restaurant_rating'] <=> $a['restaurant_rating'];
});

respond_ok([
    'restaurants' => $restaurantResults,
    'items' => $items,
    'meta' => ['query' => $q, 'total_restaurants' => count($restaurantResults), 'total_items' => count($items)],
]);

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
