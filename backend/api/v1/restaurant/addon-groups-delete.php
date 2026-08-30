<?php
/**
 * POST /api/v1/restaurant/addon-groups-delete.php?id={group_id}
 * Auth: Restaurant token (must own the group's item)
 * Response: { "deleted": true }
 *
 * §1, today.md 2026-08-28 / migration 57. Soft-disable (is_active = 0),
 * same convention as categories-delete.php — menu_item_addon_groups has
 * no separate "was this ever used in a past order" tracking, and past
 * orders store addons_json as a point-in-time snapshot anyway (see
 * lib/orders.php), so a hard delete would be safe in principle, but
 * soft-disable keeps this consistent with every other "delete" in this
 * app's restaurant-facing CRUD and lets a restaurant undo an accidental
 * delete without re-creating the group and losing its addons.
 *
 * Cascades the same soft-disable to every addon still inside the group
 * — a customer should never see "Choose Size" as a header with Large/
 * Small addons still individually toggleable once the group itself is
 * gone. Addons keep their addon_group_id pointing at the now-inactive
 * group (not nulled out) so re-enabling the group (addon-groups-update.php,
 * is_active isn't actually settable there today — see note below) would
 * restore the same grouping. is_active on the group itself has no
 * dedicated re-enable endpoint yet since nothing asked for one this
 * session; flagged in the handover doc as the one asymmetry versus
 * categories (categories-update.php does expose is_active).
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
require_owned_addon_group($db, $restaurantId, $groupId);

$db->prepare('UPDATE menu_item_addon_groups SET is_active = 0 WHERE id = :id')
    ->execute(['id' => $groupId]);
$db->prepare('UPDATE menu_item_addons SET is_active = 0 WHERE addon_group_id = :id')
    ->execute(['id' => $groupId]);

respond_ok(['deleted' => true]);
