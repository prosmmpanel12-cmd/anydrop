<?php
/**
 * GET /api/v1/restaurants?lat=&lng=&filter=&sort=&page=&per_page=
 * Auth: Customer token
 * Response: paginated list with distance_km, is_open_now computed server-side.
 * Also includes is_paused — true when the restaurant is on-demand paused
 * (operational_status busy/temp_closed) rather than simply outside its
 * fixed hours; lets the app show "Temporarily unavailable" instead of a
 * plain "Closed" for that case (Part B follow-up).
 *
 * Service-area filter (recall.md item 3): resolves lat/lng to a
 * service_areas node via lib/geo.php's resolve_service_area() — same
 * nearest-within-radius rule and same eligible-set (nearest node + its
 * parent when the nearest is level='area') already used by
 * home/promo-banners.php for area-targeted banners. This is layered ON
 * TOP of the existing per-restaurant delivery_radius_km haversine check
 * below, never a replacement for it — a restaurant with a mismatched
 * area_id is excluded even if it's geometrically within its own
 * delivery_radius_km, and a restaurant within the matched area still
 * has to separately pass its own radius check. Per the app owner's
 * explicit 2026-08-21 clarification, these two checks measure different
 * things (area-center-to-customer vs. restaurant-to-customer) and must
 * never be conflated or have one substitute for the other.
 * A restaurant with area_id still NULL (not yet assigned by an admin)
 * is not excluded by this filter — same "don't hide behind unresolved
 * data" stance the radius check below already takes for missing
 * lat/lng. Same for an unresolved customer (no lat/lng, or no area
 * match at all): the area filter is simply skipped, radius-only
 * behaviour unchanged from before this filter existed.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/favorites.php';
require_once __DIR__ . '/../../../lib/geo.php';
require_once __DIR__ . '/../../../lib/restaurant_status.php';
require_once __DIR__ . '/../../../lib/settings.php';

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

// Eligible service-area ids for this customer's resolved location —
// see docblock above. Empty array means either no lat/lng or no area
// match, in which case the per-restaurant area check below is skipped
// entirely (radius-only, unchanged behaviour).
$eligibleAreaIds = [];
if ($lat !== null && $lng !== null) {
    $resolvedAreas = resolve_service_area($db, $lat, $lng);
    if (!empty($resolvedAreas)) {
        $nearestArea = $resolvedAreas[0];
        $eligibleAreaIds[] = $nearestArea['id'];
        if ($nearestArea['level'] === 'area' && $nearestArea['parent_id'] !== null) {
            $eligibleAreaIds[] = $nearestArea['parent_id'];
        }
    }
}

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
//
// Bug fix (app-owner report, 2026-08-17 — "WhatsApp-status wali dish
// image purani/galat dikhti hai"): this used to read from the
// `restaurant_gallery_photos` table, which was only ever populated by a
// one-time SQL seed (backend/sql/12_seed_gallery_from_menu_items.sql) and
// is never written to by menu-items-create.php/menu-items-update.php or
// menu-item-photo-upload.php. So a restaurant owner uploading/changing a
// dish photo after that seed ran had no effect on the carousel — it kept
// showing whatever was seeded (which, for freshly-created test
// restaurants with no seed row at all, could even fall through to a
// stray/placeholder image). Reading straight from `menu_items` here
// instead means the carousel always reflects whatever the owner has
// *currently* uploaded — no separate table to keep in sync, nothing can
// go stale. `restaurant_gallery_photos` and its seed scripts are no
// longer used by this endpoint; safe to drop in a future cleanup pass.
//
// No SQL window function (per this project's established InfinityFree/
// older-MySQL constraint — see 12b_seed_gallery_from_menu_items_no_window.sql)
// — fetch every eligible item per restaurant in one batched query, ordered
// so the "best" photos sort first, then cap to MAX_GALLERY_PHOTOS per
// restaurant in PHP below.
const MAX_GALLERY_PHOTOS = 6;
$galleryByRestaurant = [];
if (!empty($all)) {
    $galleryStmt = $db->prepare(
        "SELECT restaurant_id, image_url, name AS dish_name, price
         FROM menu_items
         WHERE restaurant_id IN ($placeholders)
           AND deleted_at IS NULL
           AND is_available = 1
           AND image_url IS NOT NULL
           AND image_url <> ''
         ORDER BY restaurant_id, is_bestseller DESC, is_recommended DESC, updated_at DESC"
    );
    $galleryStmt->execute($ids);
    foreach ($galleryStmt->fetchAll() as $g) {
        $rid = $g['restaurant_id'];
        if (!isset($galleryByRestaurant[$rid])) {
            $galleryByRestaurant[$rid] = [];
        }
        if (count($galleryByRestaurant[$rid]) >= MAX_GALLERY_PHOTOS) {
            continue;
        }
        $galleryByRestaurant[$rid][] = [
            'image_url' => $g['image_url'],
            'dish_name' => $g['dish_name'],
            'price' => $g['price'] !== null ? (float) $g['price'] : null,
        ];
    }
}

$results = [];
// Tracks restaurants excluded purely by the radius check below (as
// opposed to open_now/rating filters) — lets the response tell the
// customer app "there simply aren't any restaurants near you" (radius)
// apart from "your filters excluded everything" (filter), so
// HomeActivity can show the right empty state (see out_of_range_count
// in the response below and RestaurantAdapter/HomeActivity's handling
// of it).
$outOfRangeCount = 0;
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

    // Service-area filter — see docblock above. Only enforced when the
    // customer resolved to at least one area AND this restaurant has an
    // area_id assigned; either side missing means "not enough
    // information to say they don't match" so the restaurant is kept
    // (radius check below still applies regardless).
    if (!empty($eligibleAreaIds) && $r['area_id'] !== null && !in_array((int) $r['area_id'], $eligibleAreaIds, true)) {
        continue;
    }

    $distanceKm = null;
    if ($lat !== null && $lng !== null && $r['latitude'] !== null && $r['longitude'] !== null) {
        $distanceKm = round(haversine_km($lat, $lng, (float) $r['latitude'], (float) $r['longitude']), 2);
    }

    // Delivery-radius filter — a restaurant only serves customers within its
    // own delivery_radius_km (falls back to the admin-configurable
    // 'default_delivery_radius_km' app_setting, itself defaulting to 5.0,
    // if the restaurant owner never set one — see
    // 24_migration_default_radius_setting.sql). Only enforced once we
    // actually know both sides' coordinates; if the user has no GPS fix
    // yet or the restaurant has no lat/lng, $distanceKm is null and this
    // restaurant is still shown (same "don't hide things behind an
    // unresolved fix" stance as the rest of this file) rather than being
    // incorrectly excluded.
    if ($distanceKm !== null) {
        $radiusKm = $r['delivery_radius_km'] !== null
            ? (float) $r['delivery_radius_km']
            : (float) get_setting('default_delivery_radius_km', 5);
        if ($distanceKm > $radiusKm) {
            $outOfRangeCount++;
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
        // 0 restaurants in `data` + this > 0 means the customer app
        // should show "No restaurants deliver to your area yet" rather
        // than a generic "no results" — see HomeActivity's handling.
        'out_of_range_count' => $outOfRangeCount,
    ],
]);
