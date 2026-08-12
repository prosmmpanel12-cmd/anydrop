<?php
/**
 * GET    /api/v1/customer/favorites            — list, split by type
 * POST   /api/v1/customer/favorites             — toggle add
 *   Request: { "favorite_type": "restaurant"|"menu_item", "favorite_id": 5 }
 * DELETE /api/v1/customer/favorites             — remove
 *   Request: { "favorite_type": "restaurant"|"menu_item", "favorite_id": 5 }
 * Auth: Customer token
 *
 * Backs the bookmark icon on restaurant/dish cards (§2.5) and the
 * Profile → Saved screen (§2.7). POST is idempotent (ON DUPLICATE KEY
 * does nothing) so a double-tap from an optimistic-UI client can't error.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = $owner['owner_id'];
$db = Database::get();

function validate_favorite_type(?string $type): void
{
    if (!in_array($type, ['restaurant', 'menu_item'], true)) {
        respond_error('validation_error', 422, ['fields' => ['favorite_type']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        "SELECT cf.favorite_type, cf.favorite_id, cf.created_at,
                r.name AS restaurant_name, r.logo_url AS restaurant_logo_url,
                r.cover_url AS restaurant_cover_url, r.rating_avg AS restaurant_rating,
                mi.name AS item_name, mi.image_url AS item_image_url, mi.price AS item_price,
                mi.is_veg AS item_is_veg, mi.restaurant_id AS item_restaurant_id,
                ir.name AS item_restaurant_name
         FROM customer_favorites cf
         LEFT JOIN restaurants r ON cf.favorite_type = 'restaurant' AND r.id = cf.favorite_id
         LEFT JOIN menu_items mi ON cf.favorite_type = 'menu_item' AND mi.id = cf.favorite_id
         LEFT JOIN restaurants ir ON ir.id = mi.restaurant_id
         WHERE cf.customer_id = :cid
         ORDER BY cf.created_at DESC"
    );
    $stmt->execute(['cid' => $customerId]);
    $rows = $stmt->fetchAll();

    $restaurants = [];
    $items = [];
    foreach ($rows as $row) {
        if ($row['favorite_type'] === 'restaurant') {
            if ($row['restaurant_name'] === null) {
                continue; // restaurant deleted since favoriting
            }
            $restaurants[] = [
                'id' => (int) $row['favorite_id'],
                'name' => $row['restaurant_name'],
                'logo_url' => $row['restaurant_logo_url'],
                'cover_url' => $row['restaurant_cover_url'],
                'rating_avg' => (float) $row['restaurant_rating'],
            ];
        } else {
            if ($row['item_name'] === null) {
                continue; // item deleted since favoriting
            }
            $items[] = [
                'id' => (int) $row['favorite_id'],
                'name' => $row['item_name'],
                'image_url' => $row['item_image_url'],
                'price' => (float) $row['item_price'],
                'is_veg' => (bool) $row['item_is_veg'],
                'restaurant_id' => (int) $row['item_restaurant_id'],
                'restaurant_name' => $row['item_restaurant_name'],
            ];
        }
    }

    respond_ok(['restaurants' => $restaurants, 'items' => $items]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    require_fields($body, ['favorite_type', 'favorite_id']);
    validate_favorite_type($body['favorite_type']);

    $stmt = $db->prepare(
        'INSERT INTO customer_favorites (customer_id, favorite_type, favorite_id)
         VALUES (:cid, :type, :fid)
         ON DUPLICATE KEY UPDATE customer_id = customer_id'
    );
    $stmt->execute([
        'cid' => $customerId,
        'type' => $body['favorite_type'],
        'fid' => (int) $body['favorite_id'],
    ]);

    respond_ok(['is_saved' => true], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = get_json_body();
    require_fields($body, ['favorite_type', 'favorite_id']);
    validate_favorite_type($body['favorite_type']);

    $stmt = $db->prepare(
        'DELETE FROM customer_favorites WHERE customer_id = :cid AND favorite_type = :type AND favorite_id = :fid'
    );
    $stmt->execute([
        'cid' => $customerId,
        'type' => $body['favorite_type'],
        'fid' => (int) $body['favorite_id'],
    ]);

    respond_ok(['is_saved' => false]);
}

respond_error('method_not_allowed', 405);
