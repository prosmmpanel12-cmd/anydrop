<?php
/**
 * POST /api/v1/restaurant/categories-create.php
 * Auth: Restaurant token
 * Request: { "name": "...", "sort_order"?: int, "image_url"?: string, "icon_key"?: string }
 * Response: { "category": {...} }
 *
 * sort_order defaults to "append to the end" (current max + 1) when the
 * client doesn't send one — CategoryCreateBody.sortOrder is nullable and
 * MenuFragment.saveCategory() never actually passes it today, so this
 * default path is what every category creation currently hits.
 *
 * image_url (optional): app-owner real-device-feedback item 4. Requires
 * backend/sql/22_migration_category_image.sql to have been run — column
 * didn't exist in the original schema. Same upload-then-save split as
 * menu-items-create.php's image_url: category-photo-upload.php uploads
 * the file and returns this path first.
 *
 * icon_key (optional): doc 22 item 1, bundled category-icon picker.
 * Requires backend/sql/28_migration_category_icon_key.sql. Mutually
 * exclusive with image_url at the UI level (a category shows either an
 * uploaded photo or a bundled icon) — enforced here, not in the schema:
 * if both are present in the request body, image_url wins and icon_key is
 * dropped, since an explicit photo upload is the stronger signal.
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

$body = get_json_body();
require_fields($body, ['name']);
$name = trim((string) $body['name']);
if ($name === '') {
    respond_error('validation_error', 422, ['fields' => ['name']]);
}

$db = Database::get();

if (isset($body['sort_order']) && $body['sort_order'] !== null) {
    $sortOrder = (int) $body['sort_order'];
} else {
    $maxStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) AS m FROM menu_categories WHERE restaurant_id = :rid');
    $maxStmt->execute(['rid' => $restaurantId]);
    $sortOrder = ((int) $maxStmt->fetch()['m']) + 1;
}
$imageUrl = isset($body['image_url']) && $body['image_url'] !== '' ? (string) $body['image_url'] : null;
$iconKey = isset($body['icon_key']) && $body['icon_key'] !== '' ? (string) $body['icon_key'] : null;
// Mutually exclusive — see kdoc above.
if ($imageUrl !== null) {
    $iconKey = null;
}

$insert = $db->prepare(
    'INSERT INTO menu_categories (restaurant_id, name, image_url, icon_key, sort_order, is_active)
     VALUES (:rid, :name, :image_url, :icon_key, :sort_order, 1)'
);
$insert->execute([
    'rid' => $restaurantId,
    'name' => $name,
    'image_url' => $imageUrl,
    'icon_key' => $iconKey,
    'sort_order' => $sortOrder,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'category' => [
        'id' => $newId,
        'name' => $name,
        'image_url' => $imageUrl,
        'icon_key' => $iconKey,
        'sort_order' => $sortOrder,
        'is_active' => true,
        'item_count' => 0,
    ],
], 201);
