<?php
/**
 * GET /api/v1/restaurants/menu.php?id={restaurant_id}
 * (Path-style /restaurants/{id}/menu is mapped to this by the router)
 * Auth: Customer token
 * Response: categories with nested menu items, variants, addons.
 * Cache-Control: max-age=300 (menus change rarely)
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';
require_once __DIR__ . '/../../../lib/geo.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=300');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');

$restaurantId = (int) ($_GET['id'] ?? 0);
if ($restaurantId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

// features.md §6 — restaurant detail header's "2.7 km · Sardarpura" line.
// Same optional lat/lng-in, distance_km-out contract as restaurants/list.php
// (null when either side is missing, e.g. no GPS fix yet or an older client
// build that doesn't send these) — never required, menu still loads fine
// without them.
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;

$db = Database::get();

$stmt = $db->prepare("SELECT * FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    respond_error('not_found', 404);
}

$savedRestaurants = get_saved_restaurant_ids((int) $owner['owner_id']);
$savedItems = get_saved_item_ids((int) $owner['owner_id']);

$tagStmt = $db->prepare(
    "SELECT rt.name, rt.slug FROM restaurant_tag_map rtm
     INNER JOIN restaurant_tags rt ON rt.id = rtm.restaurant_tag_id
     WHERE rtm.restaurant_id = :rid"
);
$tagStmt->execute(['rid' => $restaurantId]);
$restaurantTags = $tagStmt->fetchAll();

// features.md §6 — offer strip ("3 offers ⌄"). See
// 14_migration_restaurant_offers_and_tags.sql; empty array (not an error)
// for a restaurant with none seeded yet, same as tags above.
$offerStmt = $db->prepare(
    'SELECT id, title, description FROM restaurant_offers
     WHERE restaurant_id = :rid AND is_active = 1 ORDER BY sort_order ASC, id ASC'
);
$offerStmt->execute(['rid' => $restaurantId]);
$restaurantOffers = $offerStmt->fetchAll();

// Same distance_km / estimated_delivery_minutes calc as restaurants/list.php
// (haversine + the same 15-plus-4-per-km placeholder ETA formula — no real
// routing engine yet, that's OSRM's job in Phase 4 per 00_README.md).
$distanceKm = null;
if ($lat !== null && $lng !== null && $restaurant['latitude'] !== null && $restaurant['longitude'] !== null) {
    $distanceKm = round(haversine_km($lat, $lng, (float) $restaurant['latitude'], (float) $restaurant['longitude']), 2);
}
$etaMinutes = $distanceKm !== null ? (int) round(15 + $distanceKm * 4) : null;

$catStmt = $db->prepare(
    'SELECT id, name, sort_order FROM menu_categories WHERE restaurant_id = :rid AND is_active = 1 ORDER BY sort_order ASC'
);
$catStmt->execute(['rid' => $restaurantId]);
$categories = $catStmt->fetchAll();

$itemStmt = $db->prepare(
    'SELECT * FROM menu_items WHERE restaurant_id = :rid AND is_available = 1 AND deleted_at IS NULL ORDER BY name ASC'
);
$itemStmt->execute(['rid' => $restaurantId]);
$items = $itemStmt->fetchAll();

$variantStmt = $db->prepare('SELECT * FROM menu_item_variants WHERE menu_item_id = :iid');
$addonStmt = $db->prepare('SELECT * FROM menu_item_addons WHERE menu_item_id = :iid AND is_active = 1');

$itemsByCategory = [];
foreach ($items as $item) {
    $variantStmt->execute(['iid' => $item['id']]);
    $variants = $variantStmt->fetchAll();

    $addonStmt->execute(['iid' => $item['id']]);
    $addons = $addonStmt->fetchAll();

    $formatted = [
        'id' => (int) $item['id'],
        'name' => $item['name'],
        'description' => $item['description'],
        'price' => (float) $item['price'],
        'discount_percent' => (float) $item['discount_percent'],
        'is_veg' => (bool) $item['is_veg'],
        'image_url' => $item['image_url'],
        'is_recommended' => (bool) $item['is_recommended'],
        'is_bestseller' => (bool) $item['is_bestseller'],
        // features.md §1 dietary-preference chips — see
        // 13_migration_menu_item_dietary_flags.sql. Default 0/false on
        // rows created before that migration ran.
        'is_spicy' => (bool) ($item['is_spicy'] ?? false),
        'is_kids_choice' => (bool) ($item['is_kids_choice'] ?? false),
        'prep_time_minutes' => (int) $item['prep_time_minutes'],
        'is_saved' => isset($savedItems[(int) $item['id']]),
        'variants' => array_map(fn($v) => [
            'id' => (int) $v['id'],
            'name' => $v['name'],
            'price_delta' => (float) $v['price_delta'],
        ], $variants),
        'addons' => array_map(fn($a) => [
            'id' => (int) $a['id'],
            'name' => $a['name'],
            'price' => (float) $a['price'],
        ], $addons),
    ];

    $catId = $item['category_id'] ?? 0;
    $itemsByCategory[$catId][] = $formatted;
}

$result = [];
foreach ($categories as $cat) {
    $result[] = [
        'id' => (int) $cat['id'],
        'name' => $cat['name'],
        'items' => $itemsByCategory[$cat['id']] ?? [],
    ];
}

// Items with no category (category_id NULL or unmatched)
if (!empty($itemsByCategory[0])) {
    $result[] = [
        'id' => null,
        'name' => 'Other',
        'items' => $itemsByCategory[0],
    ];
}

// Restaurant header block — bug 1.1 fix: RestaurantDetailActivity previously
// only received id/name/cover from Home and never populated rating/cuisine/
// badge, because that data was never fetched for the detail screen. Now
// included directly in the menu response (single round trip) alongside the
// items, so the detail screen has everything it needs without a second call.
respond_ok([
    'restaurant' => [
        'id' => (int) $restaurant['id'],
        'name' => $restaurant['name'],
        'address' => $restaurant['address'],
        'logo_url' => $restaurant['logo_url'],
        'cover_url' => $restaurant['cover_url'],
        'cuisine_tags' => $restaurant['cuisine_tags'],
        'is_veg_only' => (bool) $restaurant['is_veg_only'],
        'min_order_amount' => (float) $restaurant['min_order_amount'],
        'rating_avg' => (float) $restaurant['rating_avg'],
        'rating_count' => (int) ($restaurant['rating_count'] ?? 0),
        'offer_badge_text' => $restaurant['offer_badge_text'] ?? null,
        'tags' => array_map(fn($t) => ['name' => $t['name'], 'slug' => $t['slug']], $restaurantTags),
        'is_saved' => isset($savedRestaurants[(int) $restaurant['id']]),
        // features.md §6 additions below — all null/empty-safe, see comments above.
        'distance_km' => $distanceKm,
        'estimated_delivery_minutes' => $etaMinutes,
        // features.md §I4 — "Schedule for later" time-slot picker needs the
        // restaurant's remaining open hours *today* to bound its slot list
        // (same-day only, per app owner's scope call). Raw TIME strings
        // ("HH:MM:SS") straight off the restaurants row, same as the
        // is_open_now computation in restaurants/list.php — null if the
        // restaurant never had hours configured, in which case the app
        // should just hide "Schedule for later" entirely.
        'opening_time' => $restaurant['opening_time'],
        'closing_time' => $restaurant['closing_time'],
        'offers' => array_map(fn($o) => [
            'id' => (int) $o['id'],
            'title' => $o['title'],
            'description' => $o['description'],
        ], $restaurantOffers),
    ],
    'categories' => $result,
]);
