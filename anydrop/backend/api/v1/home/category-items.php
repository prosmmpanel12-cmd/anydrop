<?php
/**
 * GET /api/v1/home/category-items.php?slug=pizza&lat=&lng=
 * (Mapped from clean URL GET /home/category-items per 02_API_Contract.md)
 * Auth: Customer token
 *
 * Tapping a category chip (Pizza / Rolls / Burger...) on Home calls this —
 * returns every available menu item mapped to that food_category, from
 * every approved restaurant, each tagged with its restaurant name (same
 * "from <Restaurant>" pattern as /search).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';
require_once __DIR__ . '/../../../lib/menu_item_availability.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$savedItems = get_saved_item_ids((int) $owner['owner_id']);

$slug = trim((string) ($_GET['slug'] ?? ''));
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$vegOnly = isset($_GET['veg_only']) && $_GET['veg_only'] === '1';
// Same filter values as restaurants/list.php's `filter` param (near_fast /
// pure_veg / under_200 / open_now / rating_4 / has_offer) — previously
// silently ignored here, so selecting a category AND a filter chip together
// (e.g. "Thali" + "Under ₹200") looked applied in the UI but the chip's
// constraint was never actually sent to this endpoint.
$filter = $_GET['filter'] ?? null;

if ($slug === '') {
    respond_error('validation_error', 422, ['fields' => ['slug']]);
}

$db = Database::get();

$catStmt = $db->prepare('SELECT id, name FROM food_categories WHERE slug = :slug AND is_active = 1 LIMIT 1');
$catStmt->execute(['slug' => $slug]);
$category = $catStmt->fetch();
if (!$category) {
    respond_error('not_found', 404);
}

$where = "r.status = 'approved' AND r.deleted_at IS NULL AND mi.deleted_at IS NULL AND mi.is_available = 1";
$params = ['cid' => $category['id']];
if ($vegOnly) {
    $where .= ' AND mi.is_veg = 1';
}
if ($filter === 'veg') {
    $where .= ' AND r.is_veg_only = 1';
} elseif ($filter === 'under_200') {
    // This endpoint lists individual dishes, not restaurants — "Under
    // ₹200" here has to mean "the dish costs ≤ ₹200", not "this
    // restaurant's minimum order amount is ≤ ₹200" (that check made
    // sense on restaurants/list.php, but on a dish grid it let items
    // like a ₹210 Dal Makhani through as long as their restaurant's
    // min order was low).
    $where .= ' AND mi.price <= 200';
} elseif ($filter === 'has_offer') {
    $where .= ' AND r.offer_badge_text IS NOT NULL';
} elseif ($filter === 'near_fast' || $filter === 'pure_veg' || $filter === 'gold_extra_10') {
    // Tag-based filters — same restaurant_tags join restaurants/list.php uses.
    $where .= " AND r.id IN (
        SELECT rtm.restaurant_id FROM restaurant_tag_map rtm
        INNER JOIN restaurant_tags rt ON rt.id = rtm.restaurant_tag_id
        WHERE rt.slug = :tag_slug
    )";
    $params['tag_slug'] = $filter;
}
// open_now / rating_4 depend on server-computed time / already-selected
// columns respectively — applied in the PHP loop below, same pattern
// restaurants/list.php already uses for open_now.

$stmt = $db->prepare(
    "SELECT mi.*, r.id AS r_id, r.name AS r_name, r.logo_url AS r_logo_url,
            r.rating_avg AS r_rating_avg, r.latitude AS r_lat, r.longitude AS r_lng,
            r.operational_status AS r_operational_status, r.opening_time AS r_opening_time,
            r.closing_time AS r_closing_time, r.working_days AS r_working_days
     FROM menu_item_categories mic
     INNER JOIN menu_items mi ON mi.id = mic.menu_item_id
     INNER JOIN restaurants r ON r.id = mi.restaurant_id
     WHERE mic.food_category_id = :cid AND {$where}
     ORDER BY mi.is_bestseller DESC, mi.name ASC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$now = new DateTime();
$currentTime = $now->format('H:i:s');
$currentDow = (int) $now->format('N');

$items = [];
foreach ($rows as $it) {
    // doc 68's "Explicitly known, NOT fixed this session" follow-up —
    // the SQL `$where` above only filters `is_available = 1`, missing
    // the available_from/available_until time window (migration 62).
    // This grid never shows unavailable items at all, so an item
    // currently outside its window is excluded here too, same as an
    // is_available = 0 item already was.
    if (!is_menu_item_available_now($it)) {
        continue;
    }

    $isOpenNow = false;
    if ($it['r_operational_status'] === 'open' && $it['r_opening_time'] && $it['r_closing_time']) {
        $days = explode(',', (string) $it['r_working_days']);
        $dayMatches = in_array((string) $currentDow, $days, true);
        $timeMatches = ($currentTime >= $it['r_opening_time'] && $currentTime <= $it['r_closing_time']);
        $isOpenNow = $dayMatches && $timeMatches;
    }

    if ($filter === 'open_now' && !$isOpenNow) {
        continue;
    }
    if ($filter === 'rating_4' && (float) $it['r_rating_avg'] < 4.0) {
        continue;
    }

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
        'restaurant_is_open_now' => $isOpenNow,
        'distance_km' => $distanceKm,
        'is_saved' => isset($savedItems[(int) $it['id']]),
    ];
}

if ($lat !== null && $lng !== null) {
    usort($items, fn($a, $b) => ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX));
}

respond_ok([
    'category' => ['id' => (int) $category['id'], 'name' => $category['name'], 'slug' => $slug],
    'items' => $items,
    'meta' => ['total' => count($items)],
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
