<?php
/**
 * POST /api/v1/restaurant/categories-delete.php?id={category_id}
 * Auth: Restaurant token (must own the category)
 * Response: { "deleted": true }
 *
 * menu_categories has no deleted_at column (01_Database_Schema.md §2 —
 * only id/restaurant_id/name/sort_order/is_active), so "delete" here is a
 * soft-disable (is_active = 0), same column restaurants/menu.php already
 * filters customer-facing category listing on. Deliberately does NOT
 * touch the category's menu_items rows or their category_id — those items
 * just stop appearing grouped under a visible category rather than being
 * deleted or orphaned; re-activating the category (categories-update.php,
 * is_active: true) brings them back exactly as they were.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_menu');
$restaurantId = $owner['owner_id'];
$categoryId = (int) ($_GET['id'] ?? 0);

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM menu_categories WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $categoryId]);
$category = $stmt->fetch();

if (!$category) {
    respond_error('not_found', 404);
}
if ((int) $category['restaurant_id'] !== (int) $restaurantId) {
    respond_error('forbidden', 403);
}

$upd = $db->prepare('UPDATE menu_categories SET is_active = 0 WHERE id = :id');
$upd->execute(['id' => $categoryId]);

respond_ok(['deleted' => true]);
