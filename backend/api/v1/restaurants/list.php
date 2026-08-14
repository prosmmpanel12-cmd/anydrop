<?php
/**
 * GET /api/v1/restaurants?lat=&lng=&filter=&sort=&page=&per_page=
 * Auth: Customer token
 * Response: paginated list with distance_km, is_open_now computed server-side.
 * Also includes is_paused — true when the restaurant is on-demand paused
 * (operational_status busy/temp_closed) rather than simply outside its
 * fixed hours; lets the app show "Temporarily unavailable" instead of a
 * plain "Closed" for that case (Part B follow-up).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';
require_once __DIR__ . '/../../../lib/geo.php';
require_once __DIR__ . '/../../../lib/restaurant_status.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$savedRestaurants = get_saved_restaurant_ids((int) $owner['owner_id']);

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$filter = $_GET['filter'] ?? null;
$vegOnly = isset($_GET['veg_only']) && $_GET['veg_only'] === '1';
$sort = $_GET['sort'] ?? 'rating';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$db = Database::get();

$where = ["status = 'approved'", "deleted_at IS NULL"];
$params = [];

if ($filter === 'veg' || $vegOnly) {
    $where[] = 'is_veg_only = 1';
} elseif ($filter === 'open_now') {
    // filtered in PHP below since "open now" depends on server-computed time logic
} elseif ($filter === 'near_fast' || $filter === 'pure_veg' || $filter === 'gold_extra_10') {
    // Tag-based filters (screenshot reference: "Near & Fast", "Pure Veg
    // restaurant", "Extra 10% OFF"). Restrict to restaurants carrying
    // that restaurant_tags.slug via a correlated subquery.
    $where[] = "id IN (
        SELECT rtm.restaurant_id FROM restaurant_tag_map rtm
        INNER JOIN restaurant_tags rt ON rt.id = rtm.restaurant_tag_id
        WHERE rt.slug = :tag_slug
    )";
    $params['tag_slug'] = $filter;
} elseif ($filter === 'under_200') {
    $where[] = 'min_order_amount <= 200';
} elseif ($filter === 'has_offer') {
    // "Offers" Explore More tile (§2.3) — any restaurant with a live badge.
    $where[] = 'offer_badge_text IS NOT NULL';
}

$sql = 'SELECT * FROM restaurants WHERE ' . implode(' AND ', $where);
$stmt = $db->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

// Fetch tags (Near & Fast / Pure Veg / Under ₹200 / Gold extra 10%) for all
// fetched restaurants in one query, then group in PHP — avoids N+1 queries.
$tagsByRestaurant = [];
if (!empty($all)) {
    $ids = array_map(fn($r) => (int) $r['id'], $all);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $db->prepare(
        "SELECT rtm.restaurant_id, rt.name, rt.slug
         FROM restaurant_tag_map rtm
         INNER JOIN restaurant_tags rt ON rt.id = rtm.restaurant_tag_id
         WHERE rtm.restaurant_id IN ($placeholders)"
    );
    $tagStmt->execute($ids);
    foreach ($tagStmt->fetchAll() as $t) {
        $tagsByRestaurant[$t['restaurant_id']][] = ['name' => $t['name'], 'slug' => $t['slug']];
    }
}

$now = new DateTime();
$currentTime = $now->format('H:i:s');
$currentDow = (int) $now->format('N'); // 1 (Mon) - 7 (Sun)

// Gallery photos for the auto-advancing card carousel (§2.7), same
// batched-then-grouped-in-PHP pattern as the tags query above — avoids
// N+1 queries. A restaurant with no rows here just gets an empty array;
// the app falls back to a plain static cover_url image for it.
$galleryByRestaurant = [];
if (!empty($all)) {
    $galleryStmt = $db->prepare(
        "SELECT restaurant_id, image_url, dish_name, price
         FROM restaurant_gallery_photos
         WHERE restaurant_id IN ($placeholders)
         ORDER BY restaurant_id, sort_order"
    );
    $galleryStmt->execute($ids);
    foreach ($galleryStmt->fetchAll() as $g) {
        $galleryByRestaurant[$g['restaurant_id']][] = [
            'image_url' => $g['image_url'],
            'dish_name' => $g['dish_name'],
            'price' => $g['price'] !== null ? (float) $g['price'] : null,
        ];
    }
}

$results = [];
foreach ($all as $r) {
    // Consolidated into compute_restaurant_status() (bugs.md §6.3
    // follow-up) — was inline here and duplicated separately in
    // search.php; restaurants/menu.php now uses the same function too.
    $statusFlags = compute_restaurant_status($r, $currentTime, $currentDow);
    $isOpenNow = $statusFlags['is_open_now'];
    $isPaused = $statusFlags['is_paused'];

    if ($filter === 'open_now' && !$isOpenNow) {
        continue;
    }
    if ($filter === 'free_delivery' && (float) $r['min_order_amount'] <= 0) {
        // placeholder rule; refine once delivery_charge logic is implemented in Phase 2
    }
    if ($filter === 'rating_4' && (float) $r['rating_avg'] < 4.0) {
        continue;
    }

    $distanceKm = null;
    if ($lat !== null && $lng !== null && $r['latitude'] !== null && $r['longitude'] !== null) {
        $distanceKm = round(haversine_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']), 2);
    }

    // Delivery-radius filter — a restaurant only serves customers within its
    // own delivery_radius_km (defaults to 5.0 per 01_schema.sql if the
    // restaurant owner never set one). Only enforced once we actually know
    // both sides' coordinates; if the user has no GPS fix yet or the
    // restaurant has no lat/lng, $distanceKm is null and this restaurant is
    // still shown (same "don't hide things behind an unresolved fix" stance
    // as the rest of this file) rather than being incorrectly excluded.
    if ($distanceKm !== null) {
        $radiusKm = $r['delivery_radius_km'] !== null ? (float) $r['delivery_radius_km'] : 5.0;
        if ($distanceKm > $radiusKm) {
            continue;
        }
    }

    $results[] = [
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
        'is_saved' => isset($savedRestaurants[(int) $r['id']]),
    ];
}

// Sort
usort($results, function ($a, $b) use ($sort) {
    if ($sort === 'distance') {
        return ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX);
    }
    if ($sort === 'delivery_time') {
        return ($a['estimated_delivery_minutes'] ?? PHP_INT_MAX) <=> ($b['estimated_delivery_minutes'] ?? PHP_INT_MAX);
    }
    // default: rating
    return $b['rating_avg'] <=> $a['rating_avg'];
});

$total = count($results);
$totalPages = (int) ceil($total / $perPage);
$pageResults = array_slice($results, $offset, $perPage);

respond_ok([
    'data' => $pageResults,
    'meta' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
    ],
]);
