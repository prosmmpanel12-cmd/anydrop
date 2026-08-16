<?php
/**
 * POST /api/v1/restaurant/menu-items-delete.php?id={item_id}
 * Auth: Restaurant token (must own the item)
 * Response: { "deleted": true }
 *
 * Soft delete (deleted_at = NOW()) — menu_items has a real deleted_at
 * column (01_Database_Schema.md §2), unlike menu_categories, so unlike
 * categories-delete.php this is a genuine delete rather than an
 * is_active=0 disable. Every existing menu_items query in this codebase
 * (menu-items-list.php above, restaurants/menu.php) already filters on
 * deleted_at IS NULL, so a soft-deleted item disappears everywhere
 * immediately without needing any other code to change. Past orders that
 * already reference this item by id are untouched — order line items
 * store their own name/price snapshot (see orders.php's format_order()),
 * not a live join to menu_items, so old receipts stay intact.
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

$upd = $db->prepare('UPDATE menu_items SET deleted_at = NOW() WHERE id = :id');
$upd->execute(['id' => $itemId]);

respond_ok(['deleted' => true]);
