<?php
/**
 * GET /api/v1/restaurant/orders?status=&page=&per_page=
 * Auth: Restaurant token
 * Response: paginated list of this restaurant's orders, newest first.
 * `status` can be a single status or a comma-separated list (e.g. "pending,accepted,preparing"
 * for an "active orders" tab vs "delivered,cancelled,rejected" for history).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;

$db = Database::get();

$where = ['restaurant_id = :rid'];
$params = ['rid' => $restaurantId];

// A UPI order's `status` is already 'pending' the instant it's created
// (before the customer has even paid) — the same row payment-upi-verify
// / admin approval later flips payment_status on. If we don't also
// filter on payment_status here, this endpoint (which both OrdersFragment
// and OrderPollingService's alarm poll call with status=pending) surfaces
// a not-yet-paid UPI order to the restaurant immediately on QR generation,
// firing the new-order alert before any money has moved. Exclude those
// rows until payment_status flips to 'paid' (matches the same guard
// orders/create.php already applies to the notification, and the one
// orders-accept.php applies before allowing Accept).
$where[] = "NOT (payment_method = 'upi' AND payment_status != 'paid')";

if (!empty($_GET['status'])) {
    $statuses = array_filter(array_map('trim', explode(',', $_GET['status'])));
    $placeholders = [];
    foreach (array_values($statuses) as $i => $s) {
        $key = "st$i";
        $placeholders[] = ":$key";
        $params[$key] = $s;
    }
    if ($placeholders) {
        $where[] = 'status IN (' . implode(',', $placeholders) . ')';
    }
}

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM orders WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['c'];

$sql = 'SELECT * FROM orders WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

respond_ok([
    'data' => array_map(fn($o) => format_order($db, $o), $orders),
    'meta' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
]);
