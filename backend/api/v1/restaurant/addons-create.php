<?php
/**
 * POST /api/v1/restaurant/addons-create.php
 * Auth: Restaurant token (must own the item, and the group if one's given)
 * Request: { "item_id": int, "addon_group_id"?: int|null, "name": "...",
 *            "price"?: number (default 0) }
 * Response: { "addon": {...} }
 *
 * §1, today.md 2026-08-28 / migration 57. addon_group_id is optional —
 * omitted/null creates an ungrouped addon, same flat-checkbox behavior
 * every addon had before this migration (menu_item_addons already
 * existed and was already readable by the Customer app; this is just
 * the first restaurant-side write path for it).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/menu_item_addon_groups.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_menu');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['item_id', 'name']);

$itemId = (int) $body['item_id'];
$name = trim((string) $body['name']);
if ($name === '') {
    respond_error('validation_error', 422, ['fields' => ['name']]);
}
$price = isset($body['price']) && $body['price'] !== null ? (float) $body['price'] : 0.0;
if ($price < 0) {
    respond_error('validation_error', 422, ['fields' => ['price']]);
}
$groupId = isset($body['addon_group_id']) && $body['addon_group_id'] !== null
    ? (int) $body['addon_group_id']
    : null;

$db = Database::get();
require_owned_menu_item($db, $restaurantId, $itemId);

if ($groupId !== null) {
    // Ownership AND "belongs to this same item" both matter here — a
    // group id from a different one of the restaurant's own items would
    // still pass a bare ownership check but would be semantically wrong
    // (an addon can't be grouped under another dish's "Choose Size").
    $group = require_owned_addon_group($db, $restaurantId, $groupId);
    if ((int) $group['menu_item_id'] !== $itemId) {
        respond_error('validation_error', 422, ['fields' => ['addon_group_id']]);
    }
}

$insert = $db->prepare(
    'INSERT INTO menu_item_addons (menu_item_id, addon_group_id, name, price, is_active)
     VALUES (:item_id, :group_id, :name, :price, 1)'
);
$insert->execute([
    'item_id' => $itemId,
    'group_id' => $groupId,
    'name' => $name,
    'price' => $price,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'addon' => [
        'id' => $newId,
        'addon_group_id' => $groupId,
        'name' => $name,
        'price' => $price,
        'is_active' => true,
    ],
], 201);
