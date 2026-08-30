<?php
/**
 * POST /api/v1/restaurant/addons-update.php?id={addon_id}
 * Auth: Restaurant token (must own the addon's item)
 * Request: any subset of { name, price, is_active, addon_group_id }
 * Response: { "addon": {...} }
 *
 * §1, today.md 2026-08-28 / migration 57. is_active here doubles as this
 * addon's own delete/restore toggle — mirrors how out-of-stock already
 * works on menu_items (a plain field flip, not a separate delete
 * endpoint) rather than adding a dedicated addons-delete.php for a
 * single boolean; AddonGroupsActivity's per-addon "Remove" action just
 * calls this with { is_active: false }.
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
$addonId = (int) ($_GET['id'] ?? 0);

$db = Database::get();
$addon = require_owned_addon($db, $restaurantId, $addonId);

$body = get_json_body();
$fields = [];
$params = ['id' => $addonId];

if (array_key_exists('name', $body)) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $fields[] = 'name = :name';
    $params['name'] = $name;
}

if (array_key_exists('price', $body) && $body['price'] !== null) {
    $price = (float) $body['price'];
    if ($price < 0) {
        respond_error('validation_error', 422, ['fields' => ['price']]);
    }
    $fields[] = 'price = :price';
    $params['price'] = $price;
}

if (array_key_exists('is_active', $body)) {
    $fields[] = 'is_active = :is_active';
    $params['is_active'] = ((bool) $body['is_active']) ? 1 : 0;
}

if (array_key_exists('addon_group_id', $body)) {
    $newGroupId = $body['addon_group_id'] !== null ? (int) $body['addon_group_id'] : null;
    if ($newGroupId !== null) {
        $group = require_owned_addon_group($db, $restaurantId, $newGroupId);
        if ((int) $group['menu_item_id'] !== (int) $addon['menu_item_id']) {
            respond_error('validation_error', 422, ['fields' => ['addon_group_id']]);
        }
    }
    $fields[] = 'addon_group_id = :addon_group_id';
    $params['addon_group_id'] = $newGroupId;
}

if (!empty($fields)) {
    $sql = 'UPDATE menu_item_addons SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);
}

$fetch = $db->prepare('SELECT * FROM menu_item_addons WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $addonId]);
$updated = $fetch->fetch();

respond_ok(['addon' => serialize_addon($updated)]);
