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
require_once __DIR__ . '/../../../lib/restaurant_status.php';
require_once __DIR__ . '/../../../lib/offers.php';

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

// Offers Engine (migration 47) — browse-time item tags + category
// discount icon (app owner feedback, 2026-08-24). See
// get_browsable_offers_for_restaurant()'s own kdoc for why this is a
// deliberately looser check than price_cart()'s cart-time one — this
// only decides what badge to *show*, never what a customer is charged.
$browsableOffers = get_browsable_offers_for_restaurant($db, $restaurantId, (int) $owner['owner_id']);

// docs/40 Step 6 — combo item/name index, built once for this
// restaurant's offer set (empty arrays, no query, when it has no live
// combo). See index_combo_offers()'s own kdoc for the shape.
$comboIndex = index_combo_offers($db, $browsableOffers);

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
    'SELECT id, name, sort_order, image_url FROM menu_categories WHERE restaurant_id = :rid AND is_active = 1 ORDER BY sort_order ASC'
);
$catStmt->execute(['rid' => $restaurantId]);
$categories = $catStmt->fetchAll();

// bugs.md §6.3 follow-up (out-of-stock) — previously filtered to
// is_available = 1 only, so an out-of-stock item just silently vanished
// from the menu with no way for the customer to see it existed. Now
// fetched regardless of is_available and returned with the flag so the
// app can render it greyed-out ("Out of stock") instead of hiding it.
// Ordering keeps in-stock items first within each category (menu_items'
// existing ORDER BY name is applied within that split, not overridden).
$itemStmt = $db->prepare(
    'SELECT * FROM menu_items WHERE restaurant_id = :rid AND deleted_at IS NULL ORDER BY is_available DESC, name ASC'
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
        // bugs.md §6.3 follow-up — out-of-stock flag, see the item query
        // comment above for why unavailable items are included at all now.
        'is_available' => (bool) $item['is_available'],
        // features.md §1 dietary-preference chips — see
        // 13_migration_menu_item_dietary_flags.sql. Default 0/false on
        // rows created before that migration ran.
        'is_spicy' => (bool) ($item['is_spicy'] ?? false),
        'is_kids_choice' => (bool) ($item['is_kids_choice'] ?? false),
        'prep_time_minutes' => (int) $item['prep_time_minutes'],
        'is_saved' => isset($savedItems[(int) $item['id']]),
        // Offers Engine badge (app owner feedback, 2026-08-24) — null
        // when no item/category/restaurant offer currently applies to
        // this item, see pick_item_badge_offer()'s own kdoc for the
        // item > category > restaurant precedence. Short display text
        // only ("3 @ ₹50", "20% OFF") — the full offer record isn't
        // needed on a menu card, unlike the checkout-time
        // cart/validate.php response which already carries
        // offer_title/offer_discount_amount for the applied offer.
        'offer_tag' => ($badgeOffer = pick_item_badge_offer($browsableOffers, (int) $item['id'], $item['category_id'] !== null ? (int) $item['category_id'] : null, $comboIndex['index']))
            ? offer_badge_label($badgeOffer, (int) $item['id'], $comboIndex['names']) : null,
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
    $catItems = $itemsByCategory[$cat['id']] ?? [];
    $result[] = [
        'id' => (int) $cat['id'],
        'name' => $cat['name'],
        // Category icon (customer-side surfacing of the Restaurant app's
        // category-photo-upload — 22_migration_category_image.sql). Raw
        // relative path, same as every other image_url field here; the
        // client prefixes it with its static-files base URL.
        'image_url' => $cat['image_url'],
        // Offers Engine discount icon (app owner feedback, 2026-08-24) —
        // true when any item actually shown in this category carries an
        // offer_tag above (covers item-scoped, category-scoped, and
        // restaurant-wide offers alike, since a restaurant-wide offer
        // would tag every item in every category the same way).
        'has_active_offer' => (bool) array_filter($catItems, fn($item) => $item['offer_tag'] !== null),
        'items' => $catItems,
    ];
}

// Items with no category (category_id NULL or unmatched)
if (!empty($itemsByCategory[0])) {
    $result[] = [
        'id' => null,
        'name' => 'Other',
        'has_active_offer' => (bool) array_filter($itemsByCategory[0], fn($item) => $item['offer_tag'] !== null),
        'items' => $itemsByCategory[0],
    ];
}

// Restaurant header block — bug 1.1 fix: RestaurantDetailActivity previously
// only received id/name/cover from Home and never populated rating/cuisine/
// badge, because that data was never fetched for the detail screen. Now
// included directly in the menu response (single round trip) alongside the
// items, so the detail screen has everything it needs without a second call.
// bugs.md §6.3 follow-up — restaurant detail page never showed an
// open/closed/paused badge at all, unlike Home cards and search results
// (which already had it). Same compute_restaurant_status() helper those
// two use, so all three surfaces can never show contradictory status for
// the same restaurant.
$statusFlags = compute_restaurant_status($restaurant);

// Restaurant banners (app-owner feedback item #3, 2026-08-17) — owner-
// curated promotional images shown as a carousel at the top of the
// Customer app's restaurant-detail screen once it's open (i.e. once the
// detail screen itself is open — see RestaurantBannerCarouselView.kt for
// the "2+ banners = auto-transition, exactly 1 = static, 0 = falls back
// to cover_url" display logic; that decision lives entirely client-side
// so this endpoint just returns whatever's there, ordered).
$bannerStmt = $db->prepare(
    'SELECT image_url FROM restaurant_banners WHERE restaurant_id = ? ORDER BY sort_order, id'
);
$bannerStmt->execute([$restaurant['id']]);
$banners = array_map(fn($b) => $b['image_url'], $bannerStmt->fetchAll());

respond_ok([
    'restaurant' => [
        'id' => (int) $restaurant['id'],
        'name' => $restaurant['name'],
        'address' => $restaurant['address'],
        'logo_url' => $restaurant['logo_url'],
        'cover_url' => $restaurant['cover_url'],
        'banners' => $banners,
        'cuisine_tags' => $restaurant['cuisine_tags'],
        'is_veg_only' => (bool) $restaurant['is_veg_only'],
        'is_open_now' => $statusFlags['is_open_now'],
        'is_paused' => $statusFlags['is_paused'],
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
