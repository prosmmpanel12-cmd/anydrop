<?php
/**
 * POST /api/v1/restaurant/orders/{id}/status
 * Auth: Restaurant token (must own the order)
 * Request: { "status": "preparing" | "ready" }
 * Valid transitions: accepted -> preparing -> ready.
 * ('ready' now triggers the Phase 3 R3 assignment engine — see
 * dispatch_next_candidate() below — which creates the first rider
 * offer. ready -> rider_assigned happens when a rider accepts that
 * offer via rider/orders-accept.php, not from this endpoint.)
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';
require_once __DIR__ . '/../../../lib/dispatch.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_orders');
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

// UPDATED (2026-08-23, app owner request): trimmed back down to only
// "Order accepted" / "Order ready" / (future) "Out for delivery" —
// 'preparing' no longer gets its own customer notification. (The
// 2026-08-21 request that added 'preparing' here is superseded by
// this one.) The order.status transition itself is untouched — the
// restaurant app still moves the order through 'preparing' as a
// normal step, it just doesn't push a notification for it anymore.
// "Out for delivery" isn't in this map because that status transition
// (rider assignment) isn't built yet — Phase 4, see track.php's own
// kdoc — there's nothing to notify on until that ships; add it here
// the same shape as 'ready' once rider-assignment lands.
$statusNotifications = [
    'ready' => ['title' => 'Order ready', 'body' => "Your order {$order['order_code']} is ready"],
];
if (isset($statusNotifications[$newStatus])) {
    create_notification(
        'customer',
        (int) $order['customer_id'],
        $statusNotifications[$newStatus]['title'],
        $statusNotifications[$newStatus]['body'],
        'order',
        ['order_id' => $orderId, 'screen' => 'order_status']
    );
}

// Phase 3 R3 (doc 83/85) — 'ready' is the trigger point the assignment
// engine's own kdoc (dispatch.php) says it plugs into. Fire-and-forget:
// dispatch_next_candidate() no-ops safely if there are no eligible
// riders right now (order just stays 'ready', logged for admin
// attention) — that's not a reason to fail this request, the
// restaurant's status update itself already succeeded.
if ($newStatus === 'ready') {
    dispatch_next_candidate($db, $orderId);
}

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
