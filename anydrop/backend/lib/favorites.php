<?php
/**
 * Anydrop — Favorites helpers
 *
 * Shared by any endpoint that needs to stamp `is_saved` onto a restaurant
 * or menu item response for the logged-in customer (restaurants/list.php,
 * search.php, home/category-items.php, restaurants/menu.php). Kept as one
 * shared pair of functions instead of duplicating the query per endpoint.
 */

require_once __DIR__ . '/../config/database.php';

/** Returns a set (array with true values, keyed by id) of favorited restaurant ids for a customer. */
function get_saved_restaurant_ids(int $customerId): array
{
    return get_saved_ids($customerId, 'restaurant');
}

/** Returns a set (array with true values, keyed by id) of favorited menu_item ids for a customer. */
function get_saved_item_ids(int $customerId): array
{
    return get_saved_ids($customerId, 'menu_item');
}

function get_saved_ids(int $customerId, string $favoriteType): array
{
    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT favorite_id FROM customer_favorites WHERE customer_id = :cid AND favorite_type = :t'
    );
    $stmt->execute(['cid' => $customerId, 't' => $favoriteType]);

    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $set[(int) $row['favorite_id']] = true;
    }
    return $set;
}
