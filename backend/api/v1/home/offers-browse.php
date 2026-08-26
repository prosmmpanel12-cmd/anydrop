<?php
/**
 * GET /api/v1/home/offers-browse.php?lat=&lng=
 * (Mapped from clean URL GET /home/offers-browse — docs/33's own spec,
 * built docs/34)
 * Auth: Customer token
 *
 * Backs the "Offers" category chip (docs/33 items 2/3 — a synthetic
 * first category chip that opens a dedicated Offers screen instead of
 * filtering the normal restaurant list). Returns every restaurant
 * currently running at least one browsable offer, each with the specific
 * items on its menu that offer actually badges — grouped by restaurant
 * per docs/33's own response shape, so the screen can render "item +
 * restaurant, both" the way the app owner's original ask (docs/33 §2/§3)
 * described.
 *
 * Reuses get_browsable_offers_for_restaurant() + pick_item_badge_offer()
 * + offer_badge_label() (lib/offers.php) — the exact same trio
 * restaurants/menu.php, home/popular-items.php, and search/search.php
 * already use for their own item-card offer_tag, so a badge shown here
 * always matches what the customer would see landing on that
 * restaurant's actual menu. Nothing here is itself a new eligibility
 * check — price_cart() remains the one authoritative check at
 * cart/validate.php and order time, same "browse-time badge is
 * approximate, checkout is authoritative" split get_browsable_offers_for_restaurant()'s
 * own kdoc already documents.
 *
 * Simplification, flagged (same one home/popular-items.php and
 * search/search.php already carry, docs/33's own precedent): category-
 * scoped offer matching is skipped here (categoryId passed as null to
 * pick_item_badge_offer()) — this endpoint spans every restaurant with a
 * live offer, and resolving each item's food_category_id in bulk would
 * need an extra query this endpoint doesn't already run. Item-scoped and
 * restaurant-wide offers (the common cases) still badge correctly; a
 * category-scoped-only offer simply won't surface an item here yet.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/offers.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;

$db = Database::get();

// Step 1 (docs/33's own plan): bounded set of restaurants actually
// running something right now — same date-range condition
// get_date_eligible_offers_for_restaurant() itself uses, just DISTINCTed
// by restaurant here so a restaurant with 5 live offers only costs one
// follow-up get_browsable_offers_for_restaurant() call below, not five.
// free_delivery excluded up front, same reasoning
// get_browsable_offers_for_restaurant() itself documents — it's a
// checkout perk, not a per-item/category badge, and has no item to show
// on this screen.
$stmt = $db->query(
    "SELECT DISTINCT po.restaurant_id
     FROM promo_offers po
     INNER JOIN restaurants r ON r.id = po.restaurant_id
     WHERE po.status = 'active'
       AND po.deleted_at IS NULL
       AND po.offer_type != 'free_delivery'
       AND (po.start_date IS NULL OR po.start_date <= CURDATE())
       AND (po.end_date IS NULL OR po.end_date >= CURDATE())
       AND r.status = 'approved' AND r.deleted_at IS NULL"
);
$restaurantIds = array_map('intval', array_column($stmt->fetchAll(), 'restaurant_id'));

if (empty($restaurantIds)) {
    respond_ok(['restaurants' => []]);
}

$placeholders = implode(',', array_fill(0, count($restaurantIds), '?'));

$rStmt = $db->prepare(
    "SELECT id, name, logo_url, rating_avg, latitude, longitude
     FROM restaurants WHERE id IN ($placeholders)"
);
$rStmt->execute($restaurantIds);
$restaurantById = [];
foreach ($rStmt->fetchAll() as $r) {
    $restaurantById[(int) $r['id']] = $r;
}

$miStmt = $db->prepare(
    "SELECT id, name, image_url, price, is_veg, restaurant_id
     FROM menu_items
     WHERE restaurant_id IN ($placeholders) AND deleted_at IS NULL AND is_available = 1"
);
$miStmt->execute($restaurantIds);
$menuItemsByRestaurant = [];
foreach ($miStmt->fetchAll() as $mi) {
    $menuItemsByRestaurant[(int) $mi['restaurant_id']][] = $mi;
}

// Step 2 (docs/33's own plan): per restaurant, reuse the standard
// browsable-offers + item-badge trio to build the on-offer item list —
// only items that actually got a non-null offer_tag are included.
$restaurants = [];
foreach ($restaurantIds as $rid) {
    if (!isset($restaurantById[$rid])) {
        continue; // defensive only — the JOIN above already guarantees this
    }

    $browsableOffers = get_browsable_offers_for_restaurant($db, $rid, $customerId);
    if (empty($browsableOffers)) {
        // Date-eligible (matched the query above) but not actually
        // browsable right now — e.g. a happy-hour window that's closed,
        // or new_customer/existing_customer eligibility excludes this
        // customer. Not an error, just nothing to show for this
        // restaurant on this screen right now.
        continue;
    }

    // docs/40 Step 6 — see index_combo_offers()'s own kdoc. Without
    // this, a live combo here would (before this fix) badge and list
    // EVERY item on the restaurant's menu via the old restaurant-wide
    // scope fallback, instead of just the combo's own item set.
    $comboIndex = index_combo_offers($db, $browsableOffers);

    $items = [];
    foreach ($menuItemsByRestaurant[$rid] ?? [] as $mi) {
        $badgeOffer = pick_item_badge_offer($browsableOffers, (int) $mi['id'], null, $comboIndex['index']);
        if ($badgeOffer === null) {
            continue;
        }
        $items[] = [
            'id' => (int) $mi['id'],
            'name' => $mi['name'],
            'image_url' => $mi['image_url'],
            'price' => (float) $mi['price'],
            'is_veg' => (bool) $mi['is_veg'],
            'offer_tag' => offer_badge_label($badgeOffer, (int) $mi['id'], $comboIndex['names']),
        ];
    }

    if (empty($items)) {
        // A live, browsable offer that happens to match nothing
        // currently orderable (e.g. an item-scoped offer whose target
        // item was 86'd) — nothing to show for this restaurant.
        continue;
    }

    $r = $restaurantById[$rid];
    $distanceKm = null;
    if ($lat !== null && $lng !== null && $r['latitude'] !== null && $r['longitude'] !== null) {
        $distanceKm = round(haversine_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']), 2);
    }

    $restaurants[] = [
        'id' => $rid,
        'name' => $r['name'],
        'logo_url' => $r['logo_url'],
        'rating_avg' => (float) $r['rating_avg'],
        'distance_km' => $distanceKm,
        // Titles, not full offer objects — this screen shows what's
        // running, not a per-offer management view (that's the
        // Restaurant App's job). array_unique since two offers could
        // share a title (e.g. two different item-scoped "Weekend
        // Special" offers).
        'offer_titles' => array_values(array_unique(array_map(fn($o) => $o['title'], $browsableOffers))),
        'items' => $items,
    ];
}

if ($lat !== null && $lng !== null) {
    usort($restaurants, fn($a, $b) => ($a['distance_km'] ?? PHP_FLOAT_MAX) <=> ($b['distance_km'] ?? PHP_FLOAT_MAX));
}

respond_ok(['restaurants' => $restaurants]);

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
