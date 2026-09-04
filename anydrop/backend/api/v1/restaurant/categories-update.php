<?php
/**
 * POST /api/v1/restaurant/categories-update.php?id={category_id}
 * Auth: Restaurant token (must own the category)
 * Request: { "name"?: "...", "sort_order"?: int, "is_active"?: bool, "image_url"?: string, "icon_key"?: string }
 * Response: { "category": {...} }
 * Partial update — only fields present in the body are changed, same
 * pattern as orders-status.php / status-update.php elsewhere in this API.
 *
 * image_url: same null-skip convention as menu-items-update.php's field of
 * the same name — requires backend/sql/22_migration_category_image.sql.
 *
 * icon_key: doc 22 item 1, requires backend/sql/28_migration_category_
 * icon_key.sql. Mutually exclusive with image_url (see categories-create.
 * php's kdoc) — whichever one is present in *this* request body clears the
 * other in the DB, so switching from a photo to a bundled icon (or back)
 * in one edit never leaves the old value dangling.
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
if (array_key_exists('image_url', $body) && $body['image_url'] !== null) {
    $fields[] = 'image_url = :image_url';
    $params['image_url'] = $body['image_url'] !== '' ? $body['image_url'] : null;
    // A photo was just set — clear any bundled icon so the two never
    // both linger stale server-side (see kdoc above).
    $fields[] = 'icon_key = NULL';
} elseif (array_key_exists('icon_key', $body) && $body['icon_key'] !== null) {
    $fields[] = 'icon_key = :icon_key';
    $params['icon_key'] = $body['icon_key'] !== '' ? $body['icon_key'] : null;
    // An icon was just set — clear any uploaded photo, same reasoning.
    $fields[] = 'image_url = NULL';
}

if (!empty($fields)) {
    $sql = 'UPDATE menu_categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $upd = $db->prepare($sql);
    $upd->execute($params);
}

$fetch = $db->prepare(
    'SELECT c.id, c.name, c.image_url, c.icon_key, c.sort_order, c.is_active,
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
        'image_url' => $row['image_url'],
        'icon_key' => $row['icon_key'],
        'sort_order' => (int) $row['sort_order'],
        'is_active' => (bool) $row['is_active'],
        'item_count' => (int) $row['item_count'],
    ],
]);
