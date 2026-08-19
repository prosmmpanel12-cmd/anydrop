<?php
/**
 * GET /api/v1/restaurant/categories-list.php
 * Auth: Restaurant token
 * Response: all of the calling restaurant's menu categories (active AND
 * inactive — restaurant/CategoryAdapter.kt already filters to is_active
 * client-side, same pattern as orders-list.php leaving status filtering
 * choices to the caller), ordered by sort_order so drag-to-reorder (still
 * unbuilt client-side, see docs/restorent/00_Status.md §10 item 4) has a
 * stable base order to start from once it lands.
 *
 * item_count is a live COUNT of that category's non-deleted menu_items,
 * not a stored counter — cheap at this scale and can never drift out of
 * sync with menu-items-create/delete below.
 *
 * image_url added per app-owner real-device-feedback item 4 (photo
 * upload) — requires backend/sql/22_migration_category_image.sql.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();

$stmt = $db->prepare(
    'SELECT c.id, c.name, c.image_url, c.icon_key, c.sort_order, c.is_active,
            (SELECT COUNT(*) FROM menu_items i
             WHERE i.category_id = c.id AND i.deleted_at IS NULL) AS item_count
     FROM menu_categories c
     WHERE c.restaurant_id = :rid
     ORDER BY c.sort_order ASC, c.id ASC'
);
$stmt->execute(['rid' => $restaurantId]);
$rows = $stmt->fetchAll();

// icon_key added by 28_migration_category_icon_key.sql (doc 22 item 1,
// bundled category icon picker) — see that migration's kdoc for why it's
// mutually exclusive with image_url.
$categories = array_map(fn($c) => [
    'id' => (int) $c['id'],
    'name' => $c['name'],
    'image_url' => $c['image_url'],
    'icon_key' => $c['icon_key'],
    'sort_order' => (int) $c['sort_order'],
    'is_active' => (bool) $c['is_active'],
    'item_count' => (int) $c['item_count'],
], $rows);

respond_ok(['categories' => $categories]);
