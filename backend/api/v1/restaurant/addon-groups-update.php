<?php
/**
 * POST /api/v1/restaurant/addon-groups-update.php?id={group_id}
 * Auth: Restaurant token (must own the group's item)
 * Request: any subset of { name, min_select, max_select, is_required,
 *                           sort_order }
 * Response: { "group": {...} }
 *
 * §1, today.md 2026-08-28 / migration 57. Partial update, same dynamic-
 * SET pattern as profile-update.php. min_select/max_select/is_required
 * are validated together (via validate_addon_group_selection_rules) even
 * when only one of the three is actually being changed — reads the
 * current row first so an update that only touches, say, `name` doesn't
 * accidentally re-validate against stale in-flight values.
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
$groupId = (int) ($_GET['id'] ?? 0);

$db = Database::get();
$group = require_owned_addon_group($db, $restaurantId, $groupId);

$body = get_json_body();
$fields = [];
$params = ['id' => $groupId];

if (array_key_exists('name', $body)) {
    $name = trim((string) $body['name']);
    if ($name === '') {
        respond_error('validation_error', 422, ['fields' => ['name']]);
    }
    $fields[] = 'name = :name';
    $params['name'] = $name;
}

$touchesSelectionRules = array_key_exists('min_select', $body)
    || array_key_exists('max_select', $body)
    || array_key_exists('is_required', $body);
if ($touchesSelectionRules) {
    $minSelect = array_key_exists('min_select', $body) ? (int) $body['min_select'] : (int) $group['min_select'];
    $maxSelect = array_key_exists('max_select', $body) ? (int) $body['max_select'] : (int) $group['max_select'];
    $isRequired = array_key_exists('is_required', $body) ? (bool) $body['is_required'] : (bool) $group['is_required'];
    [$minSelect, $maxSelect, $isRequired] = validate_addon_group_selection_rules($minSelect, $maxSelect, $isRequired);
    $fields[] = 'min_select = :min_select';
    $fields[] = 'max_select = :max_select';
    $fields[] = 'is_required = :is_required';
    $params['min_select'] = $minSelect;
    $params['max_select'] = $maxSelect;
    $params['is_required'] = $isRequired ? 1 : 0;
}

if (array_key_exists('sort_order', $body)) {
    $fields[] = 'sort_order = :sort_order';
    $params['sort_order'] = (int) $body['sort_order'];
}

if (!empty($fields)) {
    $sql = 'UPDATE menu_item_addon_groups SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);
}

$fetch = $db->prepare('SELECT * FROM menu_item_addon_groups WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $groupId]);
$updated = $fetch->fetch();

$addonStmt = $db->prepare('SELECT * FROM menu_item_addons WHERE addon_group_id = :id ORDER BY id ASC');
$addonStmt->execute(['id' => $groupId]);
$addons = array_map('serialize_addon', $addonStmt->fetchAll());

respond_ok([
    'group' => [
        'id' => (int) $updated['id'],
        'name' => $updated['name'],
        'min_select' => (int) $updated['min_select'],
        'max_select' => (int) $updated['max_select'],
        'is_required' => (bool) $updated['is_required'],
        'sort_order' => (int) $updated['sort_order'],
        'is_active' => (bool) $updated['is_active'],
        'addons' => $addons,
    ],
]);
