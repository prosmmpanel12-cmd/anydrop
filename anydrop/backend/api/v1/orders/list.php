<?php
/**
 * GET /api/v1/orders/list.php
 * Auth: Customer token
 * Query: page (default 1), per_page (default 15, max 50)
 * Response: paginated order history, most recent first, joined with
 * restaurant name/cover image for the Profile → Order History list card.
 * Deliberately lighter than orders/detail.php (no items/status_history) —
 * tapping a card in the list navigates to detail.php for the full view.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 15)));
$offset = ($page - 1) * $perPage;

$db = Database::get();

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM orders WHERE customer_id = :cid');
$countStmt->execute(['cid' => $owner['owner_id']]);
$total = (int) $countStmt->fetch()['c'];

$stmt = $db->prepare(
    'SELECT o.id, o.order_code, o.restaurant_id, o.status, o.grand_total, o.payment_method,
            o.created_at, o.rider_id, r.name AS restaurant_name, r.cover_url AS restaurant_cover_url,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
            (SELECT COUNT(*) FROM reviews rv WHERE rv.order_id = o.id) AS is_rated
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.customer_id = :cid
     ORDER BY o.created_at DESC
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':cid', $owner['owner_id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$orders = array_map(function ($row) {
    return [
        'id' => (int) $row['id'],
        'order_code' => $row['order_code'],
        'restaurant_id' => (int) $row['restaurant_id'],
        'restaurant_name' => $row['restaurant_name'],
        'restaurant_cover_url' => $row['restaurant_cover_url'],
        'status' => $row['status'],
        'grand_total' => (float) $row['grand_total'],
        'payment_method' => $row['payment_method'],
        'item_count' => (int) $row['item_count'],
        'is_rated' => ((int) $row['is_rated']) > 0,
        'has_rider' => $row['rider_id'] !== null,
        'created_at' => $row['created_at'],
    ];
}, $stmt->fetchAll());

respond_ok([
    'orders' => $orders,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'has_more' => ($offset + $perPage) < $total,
]);
