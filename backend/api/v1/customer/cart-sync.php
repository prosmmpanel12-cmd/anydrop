<?php
/**
 * GET  /api/v1/customer/cart-sync.php   — restore the customer's saved cart
 * POST /api/v1/customer/cart-sync.php   — replace the customer's saved cart
 *   Request: { "carts": [
 *     { "restaurant_id": 5, "coupon_code": "TEST50", "items": [
 *       { "menu_item_id": 12, "quantity": 2, "addon_ids": [3,4], "special_instructions": "less spicy" }, ...
 *     ] }, ...
 *   ] }
 * Auth: Customer token
 *
 * "Replace-all snapshot" design (see 07_migration_cart_persistence.sql) —
 * the app sends its full current cart state after every local change
 * (debounced client-side), and this endpoint deletes + reinserts that
 * customer's rows in one transaction. An empty `carts` array is a valid
 * request and simply clears the saved cart (e.g. after checkout).
 *
 * GET response mirrors the shape POST accepts, but with menu item / restaurant
 * details joined in so the app can rebuild full MenuItem objects without an
 * extra round trip. Items whose restaurant or menu item was deleted, or that
 * became unavailable, since being cart-synced are silently dropped — same
 * "don't surface stale references" pattern as /cart/validate's invalid_items.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = $owner['owner_id'];
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        "SELECT cci.restaurant_id, cci.menu_item_id, cci.quantity, cci.coupon_code,
                cci.addon_ids, cci.special_instructions,
                r.name AS restaurant_name,
                mi.name AS item_name, mi.description AS item_description,
                mi.price AS item_price, mi.discount_percent AS item_discount_percent,
                mi.is_veg AS item_is_veg, mi.image_url AS item_image_url,
                mi.is_recommended AS item_is_recommended, mi.is_bestseller AS item_is_bestseller,
                mi.prep_time_minutes AS item_prep_time_minutes, mi.is_available AS item_is_available
         FROM customer_cart_items cci
         JOIN restaurants r ON r.id = cci.restaurant_id
         JOIN menu_items mi ON mi.id = cci.menu_item_id
         WHERE cci.customer_id = :cid
         ORDER BY cci.restaurant_id, cci.id"
    );
    $stmt->execute(['cid' => $customerId]);
    $rows = $stmt->fetchAll();

    // §2.6 — batch-fetch the full addon list for every menu item that's in
    // the saved cart (same grouped-in-PHP pattern already used for
    // tags/gallery elsewhere, avoids N+1 queries), so a restored MenuItem
    // carries its addons and the client can price a customized line
    // correctly (unitPrice = price + selected addons) after an app restart.
    $addonsByItem = [];
    $itemIds = array_values(array_unique(array_map(fn($r) => (int) $r['menu_item_id'], $rows)));
    if (!empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $aStmt = $db->prepare(
            "SELECT id, menu_item_id, name, price FROM menu_item_addons
             WHERE menu_item_id IN ($placeholders) AND is_active = 1"
        );
        $aStmt->execute($itemIds);
        foreach ($aStmt->fetchAll() as $addonRow) {
            $mid = (int) $addonRow['menu_item_id'];
            $addonsByItem[$mid][] = [
                'id' => (int) $addonRow['id'],
                'name' => $addonRow['name'],
                'price' => (float) $addonRow['price'],
            ];
        }
    }

    $byRestaurant = [];
    foreach ($rows as $row) {
        // Deleted rows (soft-delete via deleted_at) or unavailable items
        // don't get restored into the cart — same reasoning as validate.php.
        if ($row['item_is_available'] == 0) {
            continue;
        }
        $rid = (int) $row['restaurant_id'];
        if (!isset($byRestaurant[$rid])) {
            $byRestaurant[$rid] = [
                'restaurant_id' => $rid,
                'restaurant_name' => $row['restaurant_name'],
                'coupon_code' => $row['coupon_code'],
                'items' => [],
            ];
        }
        $mid = (int) $row['menu_item_id'];
        $byRestaurant[$rid]['items'][] = [
            'menu_item_id' => $mid,
            'quantity' => (int) $row['quantity'],
            'name' => $row['item_name'],
            'description' => $row['item_description'],
            'price' => (float) $row['item_price'],
            'discount_percent' => (float) $row['item_discount_percent'],
            'is_veg' => (bool) $row['item_is_veg'],
            'image_url' => $row['item_image_url'],
            'is_recommended' => (bool) $row['item_is_recommended'],
            'is_bestseller' => (bool) $row['item_is_bestseller'],
            'prep_time_minutes' => (int) $row['item_prep_time_minutes'],
            'addons' => $addonsByItem[$mid] ?? [],
            'addon_ids' => $row['addon_ids'] ? json_decode($row['addon_ids'], true) : [],
            'special_instructions' => $row['special_instructions'],
        ];
    }

    respond_ok(['carts' => array_values($byRestaurant)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    $carts = is_array($body['carts'] ?? null) ? $body['carts'] : [];

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM customer_cart_items WHERE customer_id = :cid');
        $del->execute(['cid' => $customerId]);

        $ins = $db->prepare(
            'INSERT INTO customer_cart_items (customer_id, restaurant_id, menu_item_id, quantity, coupon_code, addon_ids, special_instructions)
             VALUES (:cid, :rid, :mid, :qty, :coupon, :addons, :instructions)'
        );
        // §2.6 bug fix (found this session): this INSERT already listed the
        // addon_ids/special_instructions columns, but the execute() call
        // below never actually bound :addons/:instructions — every POST
        // sync would have thrown "SQLSTATE[HY093]: Invalid parameter
        // number" the moment it ran, breaking cart-sync entirely (not just
        // for customized lines). Fixed by reading + binding both per item.

        foreach ($carts as $cart) {
            $restaurantId = (int) ($cart['restaurant_id'] ?? 0);
            $couponCode = isset($cart['coupon_code']) && $cart['coupon_code'] !== ''
                ? substr((string) $cart['coupon_code'], 0, 50)
                : null;
            $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];

            if ($restaurantId <= 0 || empty($items)) {
                continue;
            }

            foreach ($items as $item) {
                $menuItemId = (int) ($item['menu_item_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($menuItemId <= 0 || $quantity <= 0) {
                    continue; // skip malformed lines rather than failing the whole sync
                }
                $addonIds = is_array($item['addon_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $item['addon_ids'])))
                    : [];
                $specialInstructions = isset($item['special_instructions']) && $item['special_instructions'] !== ''
                    ? mb_substr(trim((string) $item['special_instructions']), 0, 200)
                    : null;
                $ins->execute([
                    'cid' => $customerId,
                    'rid' => $restaurantId,
                    'mid' => $menuItemId,
                    'qty' => $quantity,
                    'coupon' => $couponCode,
                    'addons' => !empty($addonIds) ? json_encode($addonIds) : null,
                    'instructions' => $specialInstructions,
                ]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        respond_error('cart_sync_failed', 500);
    }

    respond_ok(['synced' => true]);
}

respond_error('method_not_allowed', 405);
