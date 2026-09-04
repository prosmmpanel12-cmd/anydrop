<?php
/**
 * GET /api/v1/home/promo-banners.php
 * (Mapped from clean URL GET /home/promo-banners per Phase 3.6 §2.2)
 * Auth: Customer token
 *
 * Returns the active, ordered list of promo carousel slides. Two sources
 * are merged into one flat list here — the app's `PromoBannerAdapter`/
 * ViewPager2 carousel doesn't care which table a slide came from, it
 * just renders `PromoBanner` shapes in order:
 *
 * 1. `promo_banners` (see 06_migration_phase36.sql) — the original
 *    Phase 3.6 carousel source, platform-wide only, admin-managed
 *    elsewhere (not through backend/admin/banners.php). Unchanged
 *    behaviour from before this file merged in source 2.
 * 2. `banners` (see 33_migration_banners.sql) — the newer admin Banner
 *    Manager (backend/admin/banners.php, recall.md item 17). This is
 *    the one with **area targeting**, which is the whole reason this
 *    endpoint now accepts optional `lat`/`lng` query params: resolve
 *    them to a service area via lib/geo.php's resolve_service_area()
 *    (same nearest-within-radius rule as areas.php's "Test coordinates"
 *    tool) and only include banners scoped to that area (or platform-
 *    wide ones, area_id IS NULL). No lat/lng, or no area match →
 *    platform-wide banners only, same as an unresolved customer always
 *    got before this merge.
 *
 * `banners.deep_link` is free text (migration 33's own note: "app side
 * interprets it") — no convention existed yet, so this endpoint defines
 * one to map it onto the carousel's existing target_type/target_value
 * contract (see prefixToTarget() below): `restaurant:<id>`,
 * `category:<slug>`, a bare `http(s)://` URL, or blank/anything else
 * (→ "none", visual-only tap). Kept deliberately simple — same three
 * target_types the carousel already handles, nothing new added to the
 * app side.
 *
 * The old single static banner (`app_settings.home_promo_*`) remains
 * the fallback the Customer App itself falls back to when this whole
 * merged list comes back empty — unchanged, handled entirely client-side.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/geo.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=120');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

require_auth('customer');

$db = Database::get();

// ---- Source 1: legacy promo_banners (unchanged query) ----

$stmt = $db->query(
    "SELECT id, title, subtitle, image_url, target_type, target_value, sort_order
     FROM promo_banners
     WHERE is_active = 1
       AND (starts_at IS NULL OR starts_at <= NOW())
       AND (ends_at IS NULL OR ends_at >= NOW())
     ORDER BY sort_order ASC, id ASC
     LIMIT 10"
);
$legacyBanners = array_map(fn($b) => [
    'id' => (int) $b['id'],
    'title' => $b['title'],
    'subtitle' => $b['subtitle'],
    'image_url' => $b['image_url'],
    'target_type' => $b['target_type'],
    'target_value' => $b['target_value'],
], $stmt->fetchAll());

// ---- Source 2: admin Banner Manager (`banners` table), area-filtered ----

/**
 * Maps a free-text deep_link onto the carousel's existing
 * none|restaurant|category|url contract. See this file's header for why
 * this convention (rather than a stored target_type column) was chosen.
 */
function deep_link_to_target(?string $deepLink): array
{
    $deepLink = trim((string) $deepLink);
    if ($deepLink === '') {
        return ['none', null];
    }
    if (str_starts_with($deepLink, 'restaurant:')) {
        return ['restaurant', substr($deepLink, strlen('restaurant:'))];
    }
    if (str_starts_with($deepLink, 'category:')) {
        return ['category', substr($deepLink, strlen('category:'))];
    }
    if (str_starts_with($deepLink, 'http://') || str_starts_with($deepLink, 'https://')) {
        return ['url', $deepLink];
    }
    // Unrecognized format — safer to show it as a visual-only banner
    // than guess wrong and send the customer somewhere unintended.
    return ['none', null];
}

$lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float) $_GET['lng'] : null;

// Eligible area ids for the "area_id IN (...)" match below. Includes the
// nearest resolved node itself, plus its parent when that node is the
// optional 'area' level — so a banner scoped to the parent City/Village
// still reaches a customer who resolved one level deeper into a
// specific Area under it (banners.php's area picker only ever assigns a
// banner to a 'city_village' or 'area' node, never state/district, so
// walking any further up the chain isn't needed).
//
// Bug fix (2026-08-24) — this used to only look at resolve_service_area()'s
// nearest match ($resolved[0]). resolve_service_area() itself returns
// every node whose circle contains the point, not just the closest one
// (see its own kdoc, which explicitly flags this as something "the
// banner-fetch case may want" — this just hadn't been done yet). Only
// checking the nearest node meant a customer sitting inside *two*
// overlapping service-area circles (e.g. a small village node nested
// near/inside a larger City/Village node's radius) would never match a
// banner scoped to the farther-but-still-containing one, even though
// they're genuinely inside it too — exactly what a City/Village-level
// banner (like one scoped to "Osian") is supposed to reach regardless
// of whether some smaller, nearer node also happens to contain the same
// point. Now walks the whole list.
$eligibleAreaIds = [];
if ($lat !== null && $lng !== null) {
    $resolved = resolve_service_area($db, $lat, $lng);
    foreach ($resolved as $match) {
        $eligibleAreaIds[] = $match['id'];
        if ($match['level'] === 'area' && $match['parent_id'] !== null) {
            $eligibleAreaIds[] = $match['parent_id'];
        }
    }
    $eligibleAreaIds = array_values(array_unique($eligibleAreaIds));
}

if (empty($eligibleAreaIds)) {
    $adminStmt = $db->prepare(
        "SELECT id, title, image_url, deep_link, priority FROM banners
         WHERE is_active = 1 AND area_id IS NULL
           AND (start_date IS NULL OR start_date <= CURDATE())
           AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY priority DESC, id DESC
         LIMIT 10"
    );
    $adminStmt->execute();
} else {
    $placeholders = implode(',', array_fill(0, count($eligibleAreaIds), '?'));
    $adminStmt = $db->prepare(
        "SELECT id, title, image_url, deep_link, priority FROM banners
         WHERE is_active = 1 AND (area_id IS NULL OR area_id IN ($placeholders))
           AND (start_date IS NULL OR start_date <= CURDATE())
           AND (end_date IS NULL OR end_date >= CURDATE())
         ORDER BY priority DESC, id DESC
         LIMIT 10"
    );
    $adminStmt->execute($eligibleAreaIds);
}

$adminBanners = array_map(function ($b) {
    [$targetType, $targetValue] = deep_link_to_target($b['deep_link']);
    return [
        // banners.id and promo_banners.id both start from 1 — fine to
        // overlap since the carousel adapter never uses id as a
        // RecyclerView stable id (see PromoBannerAdapter, plain
        // notifyDataSetChanged() on submit()), only for the tap-through
        // payload, and each banner's own target_type/target_value is
        // self-contained regardless of this id colliding with a
        // legacy-source id.
        'id' => (int) $b['id'],
        'title' => $b['title'],
        'subtitle' => null, // banners table has no subtitle field
        'image_url' => $b['image_url'],
        'target_type' => $targetType,
        'target_value' => $targetValue,
    ];
}, $adminStmt->fetchAll());

// Admin-managed (area-aware, curated via banners.php) banners lead the
// carousel; legacy promo_banners fill the rest — both already capped at
// 10 each, combined list stays well within a reasonable carousel length.
$banners = array_merge($adminBanners, $legacyBanners);

respond_ok(['banners' => $banners]);
