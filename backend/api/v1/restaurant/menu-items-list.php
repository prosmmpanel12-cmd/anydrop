<?php
/**
 * GET /api/v1/restaurant/menu-items-list.php?category_id={id}&search={q}
 * Auth: Restaurant token
 * Response: { "items": [...] } — the calling restaurant's menu items,
 * non-deleted, both category_id and search optional and combinable.
 *
 * search matches item name (LIKE, case-insensitive via utf8mb4's default
 * ci collation) — this is the ?search= param
 * docs/restorent/00_Status.md's 2026-08-16 entry wired MenuFragment's
 * debounced search bar to; MenuFragment filters its category cards down
 * to whichever ones still have a matching item client-side, so this
 * endpoint doesn't need to know about categories at all when searching.
 *
 * Includes is_available = 0 items (out-of-stock), same reasoning as
 * restaurants/menu.php's customer-facing query: the restaurant managing
 * its own menu needs to see and toggle those rows, not have them vanish.
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

$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== ''
    ? (int) $_GET['category_id']
    : null;
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

$sql = 'SELECT * FROM menu_items WHERE restaurant_id = :rid AND deleted_at IS NULL';
$params = ['rid' => $restaurantId];

if ($categoryId !== null) {
    $sql .= ' AND category_id = :cid';
    $params['cid'] = $categoryId;
}
if ($search !== '') {
    $sql .= ' AND name LIKE :search';
    $params['search'] = '%' . $search . '%';
}
$sql .= ' ORDER BY name ASC';

$db = Database::get();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$items = array_map(fn($item) => [
    'id' => (int) $item['id'],
    'category_id' => (int) $item['category_id'],
    'name' => $item['name'],
    'description' => $item['description'],
    'price' => (float) $item['price'],
    'discount_percent' => (float) $item['discount_percent'],
    'is_veg' => (bool) $item['is_veg'],
    'image_url' => $item['image_url'],
    'is_available' => (bool) $item['is_available'],
    'is_recommended' => (bool) $item['is_recommended'],
    'is_bestseller' => (bool) $item['is_bestseller'],
    'prep_time_minutes' => (int) $item['prep_time_minutes'],
], $rows);

respond_ok(['items' => $items]);
