<?php
/**
 * POST /api/v1/restaurant/menu-items-create.php
 * Auth: Restaurant token
 * Request: { "category_id": int, "name": "...", "price": number,
 *            "description"?: string, "is_veg"?: bool,
 *            "prep_time_minutes"?: int }
 * Response: { "item": {...} }
 *
 * category_id must belong to the calling restaurant — checked explicitly
 * rather than trusted, same defense-in-depth as orders-status.php's
 * restaurant_id ownership check on orders.
 * discount_percent/is_recommended/is_bestseller aren't settable here —
 * MenuItemCreateBody doesn't expose them (no UI for them yet per
 * MenuFragment's dialog); they default to their schema defaults (0/0/0)
 * and stay restaurant-app-invisible until that UI exists.
 *
 * image_url (optional): the app-owner real-device-feedback item 4 photo
 * upload. menu-item-photo-upload.php uploads the file and returns this
 * path first; the client then sends it here as an ordinary string field,
 * same upload-then-save split as logo_url on profile-update.php. Not
 * validated as a real path (no ownership check needed — unlike
 * category_id, this can't be pointed at another restaurant's data, it's
 * just a relative file path).
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
require_fields($body, ['category_id', 'name', 'price']);

$categoryId = (int) $body['category_id'];
$name = trim((string) $body['name']);
$price = (float) $body['price'];
$description = isset($body['description']) && $body['description'] !== '' ? (string) $body['description'] : null;
$isVeg = array_key_exists('is_veg', $body) ? (bool) $body['is_veg'] : true;
$prepTimeMinutes = isset($body['prep_time_minutes']) && $body['prep_time_minutes'] !== null
    ? (int) $body['prep_time_minutes']
    : 15;
$imageUrl = isset($body['image_url']) && $body['image_url'] !== '' ? (string) $body['image_url'] : null;

if ($name === '' || $price <= 0) {
    respond_error('validation_error', 422, ['fields' => ['name', 'price']]);
}

$db = Database::get();

$catStmt = $db->prepare('SELECT id FROM menu_categories WHERE id = :id AND restaurant_id = :rid LIMIT 1');
$catStmt->execute(['id' => $categoryId, 'rid' => $restaurantId]);
if (!$catStmt->fetch()) {
    respond_error('invalid_category', 422, ['fields' => ['category_id']]);
}

$insert = $db->prepare(
    'INSERT INTO menu_items
        (restaurant_id, category_id, name, description, price, discount_percent,
         is_veg, image_url, is_available, is_recommended, is_bestseller, prep_time_minutes)
     VALUES
        (:rid, :cid, :name, :description, :price, 0,
         :is_veg, :image_url, 1, 0, 0, :prep_time_minutes)'
);
$insert->execute([
    'rid' => $restaurantId,
    'cid' => $categoryId,
    'name' => $name,
    'description' => $description,
    'price' => $price,
    'is_veg' => $isVeg ? 1 : 0,
    'image_url' => $imageUrl,
    'prep_time_minutes' => $prepTimeMinutes,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'item' => [
        'id' => $newId,
        'category_id' => $categoryId,
        'name' => $name,
        'description' => $description,
        'price' => $price,
        'discount_percent' => 0.0,
        'is_veg' => $isVeg,
        'image_url' => $imageUrl,
        'is_available' => true,
        'is_recommended' => false,
        'is_bestseller' => false,
        'prep_time_minutes' => $prepTimeMinutes,
    ],
], 201);
