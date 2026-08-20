<?php
/**
 * POST /api/v1/restaurant/menu-items-update.php?id={item_id}
 * Auth: Restaurant token (must own the item)
 * Request: any subset of { category_id, name, description, price, is_veg,
 *                           is_available, prep_time_minutes, image_url }
 * Response: { "item": {...} }
 *
 * Partial update, same pattern as categories-update.php. Doubles as the
 * out-of-stock quick-toggle switch's write path (MenuFragment.
 * toggleItemAvailable() sends just { "is_available": bool }) and the
 * edit-item dialog's full-field save — same endpoint, different subsets
 * of the body populated.
 *
 * image_url: same upload-then-save split as menu-items-create.php — the
 * client uploads via menu-item-photo-upload.php first, then sends the
 * returned path here alongside whatever else is being edited.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/menu_item_tags.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];
$itemId = (int) ($_GET['id'] ?? 0);

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM menu_items WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute(['id' => $itemId]);
$item = $stmt->fetch();

if (!$item) {
    respond_error('not_found', 404);
}
if ((int) $item['restaurant_id'] !== (int) $restaurantId) {
    respond_error('forbidden', 403);
}

$body = get_json_body();
$fields = [];
$params = ['id' => $itemId];

if (array_key_exists('category_id', $body) && $body['category_id'] !== null) {
    $categoryId = (int) $body['category_id'];
    $catStmt = $db->prepare('SELECT id FROM menu_categories WHERE id = :id AND restaurant_id = :rid LIMIT 1');
    $catStmt->execute(['id' => $categoryId, 'rid' => $restaurantId]);
    if (!$catStmt->fetch()) {
        respond_error('invalid_category', 422, ['fields' => ['category_id']]);
    }
    $fields[] = 'category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if (array_key_exists('name', $body) && $body['name'] !== null) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $fields[] = 'name = :name';
    $params['name'] = $name;
}
if (array_key_exists('description', $body)) {
    $fields[] = 'description = :description';
    $params['description'] = $body['description'] !== '' ? $body['description'] : null;
}
if (array_key_exists('price', $body) && $body['price'] !== null) {
    $price = (float) $body['price'];
    if ($price <= 0) {
        respond_error('validation_error', 422, ['fields' => ['price']]);
    }
    $fields[] = 'price = :price';
    $params['price'] = $price;
}
if (array_key_exists('is_veg', $body) && $body['is_veg'] !== null) {
    $fields[] = 'is_veg = :is_veg';
    $params['is_veg'] = $body['is_veg'] ? 1 : 0;
}
if (array_key_exists('is_available', $body) && $body['is_available'] !== null) {
    $fields[] = 'is_available = :is_available';
    $params['is_available'] = $body['is_available'] ? 1 : 0;
}
if (array_key_exists('prep_time_minutes', $body) && $body['prep_time_minutes'] !== null) {
    $fields[] = 'prep_time_minutes = :prep_time_minutes';
    $params['prep_time_minutes'] = (int) $body['prep_time_minutes'];
}
if (array_key_exists('image_url', $body) && $body['image_url'] !== null) {
    // Same null-skip convention as every other field on this endpoint.
    // A true explicit-clear isn't reachable from this app today anyway —
    // ApiClient.kt's default (non-serializeNulls()) Gson instance omits
    // null fields from the JSON body entirely, same behavior
    // profile-update.php's kdoc already notes for logo_url/lat/lng.
    $fields[] = 'image_url = :image_url';
    $params['image_url'] = $body['image_url'] !== '' ? $body['image_url'] : null;
}

if (!empty($fields)) {
    $sql = 'UPDATE menu_items SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $upd = $db->prepare($sql);
    $upd->execute($params);
}

// tags: only touched when the request body actually includes a `tags`
// key — the out-of-stock toggle call (just { "is_available": bool })
// and any other partial update must never wipe existing tags just
// because they didn't mention them. An explicit [] clears all tags.
if (array_key_exists('tags', $body) && is_array($body['tags'])) {
    set_menu_item_tags($itemId, array_map('strval', $body['tags']));
}

$fetch = $db->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $itemId]);
$row = $fetch->fetch();

respond_ok([
    'item' => [
        'id' => (int) $row['id'],
        'category_id' => (int) $row['category_id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'price' => (float) $row['price'],
        'discount_percent' => (float) $row['discount_percent'],
        'is_veg' => (bool) $row['is_veg'],
        'image_url' => $row['image_url'],
        'is_available' => (bool) $row['is_available'],
        'is_recommended' => (bool) $row['is_recommended'],
        'is_bestseller' => (bool) $row['is_bestseller'],
        'prep_time_minutes' => (int) $row['prep_time_minutes'],
        'tags' => get_menu_item_tags($itemId),
    ],
]);
