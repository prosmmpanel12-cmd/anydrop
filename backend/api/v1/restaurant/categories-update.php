<?php
/**
 * POST /api/v1/restaurant/categories-update.php?id={category_id}
 * Auth: Restaurant token (must own the category)
 * Request: { "name"?: "...", "sort_order"?: int, "is_active"?: bool }
 * Response: { "category": {...} }
 * Partial update — only fields present in the body are changed, same
 * pattern as orders-status.php / status-update.php elsewhere in this API.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
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

$body = get_json_body();

$fields = [];
$params = ['id' => $categoryId];

if (array_key_exists('name', $body) && $body['name'] !== null) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $fields[] = 'name = :name';
    $params['name'] = $name;
}
if (array_key_exists('sort_order', $body) && $body['sort_order'] !== null) {
    $fields[] = 'sort_order = :sort_order';
    $params['sort_order'] = (int) $body['sort_order'];
}
if (array_key_exists('is_active', $body) && $body['is_active'] !== null) {
    $fields[] = 'is_active = :is_active';
    $params['is_active'] = $body['is_active'] ? 1 : 0;
}

if (!empty($fields)) {
    $sql = 'UPDATE menu_categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $upd = $db->prepare($sql);
    $upd->execute($params);
}

$fetch = $db->prepare(
    'SELECT c.id, c.name, c.sort_order, c.is_active,
            (SELECT COUNT(*) FROM menu_items i
             WHERE i.category_id = c.id AND i.deleted_at IS NULL) AS item_count
     FROM menu_categories c WHERE c.id = :id LIMIT 1'
);
$fetch->execute(['id' => $categoryId]);
$row = $fetch->fetch();

respond_ok([
    'category' => [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'sort_order' => (int) $row['sort_order'],
        'is_active' => (bool) $row['is_active'],
        'item_count' => (int) $row['item_count'],
    ],
]);
