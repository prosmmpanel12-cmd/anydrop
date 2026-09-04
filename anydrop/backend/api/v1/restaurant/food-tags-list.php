<?php
/**
 * GET /api/v1/restaurant/food-tags-list.php
 * Auth: Restaurant token
 * Response: { "tags": [{ "id", "name", "slug", "icon_url" }, ...] }
 *
 * Restaurant-app ask (2026-08-20): "tags ka option do item ke niche" —
 * a checkable-chip list under the add/edit menu item dialog so the
 * restaurant can mark a dish as Pizza / Onion / Capsicum / etc. Backed
 * by the same `food_categories` table the Customer app's Home chip row
 * already reads from (home/categories.php) — restaurant-auth copy of
 * that endpoint rather than reusing it directly, same reasoning every
 * other restaurant/* endpoint has its own require_auth('restaurant')
 * instead of sharing a customer-auth one.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=600');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

require_auth('restaurant');

$db = Database::get();
$stmt = $db->query(
    "SELECT id, name, slug, icon_url
     FROM food_categories
     WHERE is_active = 1
     ORDER BY sort_order ASC"
);
$rows = $stmt->fetchAll();

$tags = array_map(fn($c) => [
    'id' => (int) $c['id'],
    'name' => $c['name'],
    'slug' => $c['slug'],
    'icon_url' => $c['icon_url'],
], $rows);

respond_ok(['tags' => $tags]);
