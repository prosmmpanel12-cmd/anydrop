<?php
/**
 * Anydrop — Menu item food-category tag helpers
 *
 * Restaurant-app ask (2026-08-20): let a restaurant tag each menu item
 * with one or more platform-wide food categories (Pizza / Onion /
 * Capsicum / ...) so the Customer app's Home category chips
 * (food_categories + menu_item_categories, see
 * 05_migration_categories_and_tags.sql and
 * backend/api/v1/home/category-items.php) actually have data to filter
 * on. No new tables needed — food_categories/menu_item_categories
 * already existed for exactly this, just nothing on the restaurant side
 * ever wrote to menu_item_categories. These two functions are the only
 * shared read/write path, used by menu-items-create.php,
 * menu-items-update.php and menu-items-list.php so all three stay in
 * sync instead of each hand-rolling the join/replace SQL.
 */

require_once __DIR__ . '/../config/database.php';

/** Slugs -> food_category ids, silently dropping any slug that doesn't
 * match an active category (same "ignore unknown, don't error" leniency
 * restaurants/list.php's `filter` param already uses for unknown values). */
function resolve_food_category_ids(array $slugs): array
{
    $slugs = array_values(array_unique(array_filter(array_map('trim', $slugs), fn($s) => $s !== '')));
    if (empty($slugs)) {
        return [];
    }
    $db = Database::get();
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = $db->prepare(
        "SELECT id FROM food_categories WHERE is_active = 1 AND slug IN ($placeholders)"
    );
    $stmt->execute($slugs);
    return array_map(fn($r) => (int) $r['id'], $stmt->fetchAll());
}

/** Replaces a menu item's tag set entirely (delete-then-insert) — called
 * on create (starting from empty) and on update (only when the request
 * body actually included a `tags` key, so a PATCH that doesn't mention
 * tags never wipes existing ones — see menu-items-update.php). */
function set_menu_item_tags(int $menuItemId, array $slugs): void
{
    $db = Database::get();
    $db->prepare('DELETE FROM menu_item_categories WHERE menu_item_id = :id')
        ->execute(['id' => $menuItemId]);

    $categoryIds = resolve_food_category_ids($slugs);
    if (empty($categoryIds)) {
        return;
    }
    $insert = $db->prepare(
        'INSERT IGNORE INTO menu_item_categories (menu_item_id, food_category_id) VALUES (:mid, :cid)'
    );
    foreach ($categoryIds as $cid) {
        $insert->execute(['mid' => $menuItemId, 'cid' => $cid]);
    }
}

/** Tag slugs currently set on one menu item, alphabetical for stable UI order. */
function get_menu_item_tags(int $menuItemId): array
{
    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT fc.slug FROM menu_item_categories mic
         INNER JOIN food_categories fc ON fc.id = mic.food_category_id
         WHERE mic.menu_item_id = :id
         ORDER BY fc.name ASC'
    );
    $stmt->execute(['id' => $menuItemId]);
    return array_map(fn($r) => $r['slug'], $stmt->fetchAll());
}

/** Tag slugs for many menu items at once (avoids N+1 queries on
 * menu-items-list.php) — returns [menu_item_id => [slug, ...]]. */
function get_menu_item_tags_bulk(array $menuItemIds): array
{
    $menuItemIds = array_values(array_unique(array_map('intval', $menuItemIds)));
    if (empty($menuItemIds)) {
        return [];
    }
    $db = Database::get();
    $placeholders = implode(',', array_fill(0, count($menuItemIds), '?'));
    $stmt = $db->prepare(
        "SELECT mic.menu_item_id, fc.slug FROM menu_item_categories mic
         INNER JOIN food_categories fc ON fc.id = mic.food_category_id
         WHERE mic.menu_item_id IN ($placeholders)
         ORDER BY fc.name ASC"
    );
    $stmt->execute($menuItemIds);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['menu_item_id']][] = $row['slug'];
    }
    return $out;
}
