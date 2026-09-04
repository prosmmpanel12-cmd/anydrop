<?php
/**
 * Anydrop — Item Customization / Add-on Group helpers (§1, today.md
 * 2026-08-28, migration 57)
 *
 * Shared ownership checks + serialization for the addon-groups-*.php /
 * addons-*.php restaurant endpoints, same "one shared lib, several thin
 * endpoints" split as menu_item_tags.php.
 *
 * Ownership is always resolved through menu_items.restaurant_id — a
 * restaurant token only ever proves who owns the *item*, so every
 * operation on a group or an individual addon has to walk back up to
 * the item to confirm it belongs to the calling restaurant, same
 * defense-in-depth reasoning as menu-items-update.php's category_id
 * check and orders-status.php's restaurant_id check on orders.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

/** Menu item row (only if it belongs to $restaurantId, not soft-deleted),
 * or calls respond_error and exits. Used as the first check on every
 * create-under-an-item endpoint (addon-groups-create.php, and
 * addons-create.php for an ungrouped addon). */
function require_owned_menu_item(PDO $db, int $restaurantId, int $menuItemId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM menu_items WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $menuItemId, 'rid' => $restaurantId]);
    $item = $stmt->fetch();
    if (!$item) {
        respond_error('not_found', 404);
    }
    return $item;
}

/** Addon group row (only if its parent item belongs to $restaurantId),
 * or calls respond_error and exits. Joins to menu_items rather than
 * trusting a bare menu_item_id column on the group row, same reasoning
 * as require_owned_menu_item above. */
function require_owned_addon_group(PDO $db, int $restaurantId, int $groupId): array
{
    $stmt = $db->prepare(
        'SELECT g.* FROM menu_item_addon_groups g
         INNER JOIN menu_items i ON i.id = g.menu_item_id
         WHERE g.id = :id AND i.restaurant_id = :rid AND i.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $groupId, 'rid' => $restaurantId]);
    $group = $stmt->fetch();
    if (!$group) {
        respond_error('not_found', 404);
    }
    return $group;
}

/** Addon row (only if its parent item belongs to $restaurantId), or
 * calls respond_error and exits. Same join-through-item pattern as
 * require_owned_addon_group. */
function require_owned_addon(PDO $db, int $restaurantId, int $addonId): array
{
    $stmt = $db->prepare(
        'SELECT a.* FROM menu_item_addons a
         INNER JOIN menu_items i ON i.id = a.menu_item_id
         WHERE a.id = :id AND i.restaurant_id = :rid AND i.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $addonId, 'rid' => $restaurantId]);
    $addon = $stmt->fetch();
    if (!$addon) {
        respond_error('not_found', 404);
    }
    return $addon;
}

/** min_select/max_select/is_required validated together, same shape
 * used by both addon-groups-create.php and addon-groups-update.php.
 * Returns the normalized [min_select, max_select, is_required] triple,
 * or calls respond_error and exits on an invalid combination.
 *
 * is_required=true implicitly floors min_select at 1 — "required" with
 * a 0 minimum is a contradiction (nothing would actually be required),
 * so this bumps it up automatically rather than rejecting the request
 * outright; matches how EditProfileActivity-side callers naturally think
 * about the toggle ("required" switch + a separate max-select stepper,
 * not two independently-typed numbers a restaurant owner has to keep
 * consistent by hand).
 */
function validate_addon_group_selection_rules(int $minSelect, int $maxSelect, bool $isRequired): array
{
    if ($minSelect < 0 || $maxSelect < 1) {
        respond_error('validation_error', 422, ['fields' => ['min_select', 'max_select']]);
    }
    if ($isRequired && $minSelect < 1) {
        $minSelect = 1;
    }
    if ($minSelect > $maxSelect) {
        respond_error('validation_error', 422, ['fields' => ['min_select', 'max_select']]);
    }
    return [$minSelect, $maxSelect, $isRequired];
}

/** All addon groups (active + inactive — the restaurant app itself
 * needs to see disabled ones to re-enable them, same reasoning as
 * menu-items-list.php including out-of-stock items) for one menu item,
 * each with its own nested addons array, plus a separate flat list of
 * ungrouped addons (addon_group_id IS NULL — the pre-existing flat
 * addons every item already had before this migration). Ordered by
 * sort_order so a restaurant's chosen group order round-trips through
 * reload/re-edit. */
function get_addon_groups_for_item(PDO $db, int $menuItemId): array
{
    $groupStmt = $db->prepare(
        'SELECT * FROM menu_item_addon_groups WHERE menu_item_id = :id ORDER BY sort_order ASC, id ASC'
    );
    $groupStmt->execute(['id' => $menuItemId]);
    $groupRows = $groupStmt->fetchAll();

    $addonStmt = $db->prepare(
        'SELECT * FROM menu_item_addons WHERE menu_item_id = :id ORDER BY id ASC'
    );
    $addonStmt->execute(['id' => $menuItemId]);
    $addonRows = $addonStmt->fetchAll();

    $addonsByGroupId = [];
    $ungrouped = [];
    foreach ($addonRows as $row) {
        $serialized = serialize_addon($row);
        if ($row['addon_group_id'] !== null) {
            $addonsByGroupId[(int) $row['addon_group_id']][] = $serialized;
        } else {
            $ungrouped[] = $serialized;
        }
    }

    $groups = array_map(function ($g) use ($addonsByGroupId) {
        return [
            'id' => (int) $g['id'],
            'name' => $g['name'],
            'min_select' => (int) $g['min_select'],
            'max_select' => (int) $g['max_select'],
            'is_required' => (bool) $g['is_required'],
            'sort_order' => (int) $g['sort_order'],
            'is_active' => (bool) $g['is_active'],
            'addons' => $addonsByGroupId[(int) $g['id']] ?? [],
        ];
    }, $groupRows);

    return ['groups' => $groups, 'ungrouped_addons' => $ungrouped];
}

function serialize_addon(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'addon_group_id' => $row['addon_group_id'] !== null ? (int) $row['addon_group_id'] : null,
        'name' => $row['name'],
        'price' => (float) $row['price'],
        'is_active' => (bool) $row['is_active'],
    ];
}
