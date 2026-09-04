<?php
/**
 * GET /api/v1/restaurant/addon-groups-list.php?item_id={menu_item_id}
 * Auth: Restaurant token (must own the item)
 * Response: { "groups": [{id, name, min_select, max_select, is_required,
 *                          sort_order, is_active, addons: [...]}],
 *             "ungrouped_addons": [...] }
 *
 * §1, today.md 2026-08-28 / migration 57. Opened from
 * MenuFragment.showItemDialog()'s new "Manage Add-ons" row (edit-existing-
 * item only — a brand-new item has no id yet to attach groups to).
 *
 * "ungrouped_addons" surfaces any addon created before this migration
 * (or any addon a restaurant later creates without picking a group) so
 * they're still visible/editable here rather than silently invisible
 * just because they predate the group concept — AddonGroupsActivity
 * shows these under an "Other add-ons" section separate from the
 * group cards.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/menu_item_addon_groups.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];
$itemId = (int) ($_GET['item_id'] ?? 0);

$db = Database::get();
require_owned_menu_item($db, $restaurantId, $itemId);

respond_ok(get_addon_groups_for_item($db, $itemId));
