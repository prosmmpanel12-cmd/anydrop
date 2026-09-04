<?php
/**
 * POST /api/v1/restaurant/addon-groups-create.php
 * Auth: Restaurant token (must own the item)
 * Request: { "item_id": int, "name": "...", "min_select"?: int (default 0),
 *            "max_select"?: int (default 1), "is_required"?: bool,
 *            "sort_order"?: int }
 * Response: { "group": {...} } (empty addons array — a group always
 *            starts empty, addons are added afterward via
 *            addons-create.php)
 *
 * §1, today.md 2026-08-28 / migration 57.
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

$minSelect = isset($body['min_select']) ? (int) $body['min_select'] : 0;
$maxSelect = isset($body['max_select']) ? (int) $body['max_select'] : 1;
$isRequired = array_key_exists('is_required', $body) ? (bool) $body['is_required'] : false;
[$minSelect, $maxSelect, $isRequired] = validate_addon_group_selection_rules($minSelect, $maxSelect, $isRequired);
$sortOrder = isset($body['sort_order']) ? (int) $body['sort_order'] : 0;

$db = Database::get();
require_owned_menu_item($db, $restaurantId, $itemId);

$insert = $db->prepare(
    'INSERT INTO menu_item_addon_groups
        (menu_item_id, name, min_select, max_select, is_required, sort_order, is_active)
     VALUES
        (:item_id, :name, :min_select, :max_select, :is_required, :sort_order, 1)'
);
$insert->execute([
    'item_id' => $itemId,
    'name' => $name,
    'min_select' => $minSelect,
    'max_select' => $maxSelect,
    'is_required' => $isRequired ? 1 : 0,
    'sort_order' => $sortOrder,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'group' => [
        'id' => $newId,
        'name' => $name,
        'min_select' => $minSelect,
        'max_select' => $maxSelect,
        'is_required' => $isRequired,
        'sort_order' => $sortOrder,
        'is_active' => true,
        'addons' => [],
    ],
], 201);
