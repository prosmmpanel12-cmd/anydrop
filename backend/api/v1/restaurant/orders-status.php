<?php
/**
 * POST /api/v1/restaurant/orders/{id}/status
 * Auth: Restaurant token (must own the order)
 * Request: { "status": "preparing" | "ready" }
 * Valid transitions: accepted -> preparing -> ready.
 * (ready -> rider_assigned happens via assign-rider, which is Phase 4 scope
 * since it needs a rider to assign; not built yet.)
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$orderId = (int) ($_GET['id'] ?? 0);
$body = get_json_body();
require_fields($body, ['status']);

$newStatus = $body['status'];
$allowedTransitions = [
    'accepted' => 'preparing',
    'preparing' => 'ready',
];

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ((int) $order['restaurant_id'] !== (int) $owner['owner_id']) {
    respond_error('forbidden', 403);
}

$expectedNext = $allowedTransitions[$order['status']] ?? null;
if ($expectedNext === null || $newStatus !== $expectedNext) {
    respond_error('invalid_status_transition', 409, [
        'current_status' => $order['status'],
        'allowed_next' => $expectedNext,
    ]);
}

$readyAtSql = $newStatus === 'ready' ? ', ready_at = NOW()' : '';
$upd = $db->prepare("UPDATE orders SET status = :s $readyAtSql WHERE id = :id");
$upd->execute(['s' => $newStatus, 'id' => $orderId]);
insert_status_history($db, $orderId, $newStatus, 'restaurant', $owner['owner_id']);

// 'preparing' is a low-signal internal step (the customer already knows
// their order was accepted) — only 'ready' is worth a separate
// notification, same "don't spam every micro-transition" instinct as the
// rest of this project's notification/alert design.
if ($newStatus === 'ready') {
    create_notification(
        'customer',
        (int) $order['customer_id'],
        'Order ready',
        "Your order {$order['order_code']} is ready",
        'order',
        ['order_id' => $orderId, 'screen' => 'order_status']
    );
}

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
