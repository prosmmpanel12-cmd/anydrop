<?php
/**
 * POST /api/v1/rider/orders-accept.php?id={order_id}
 * Auth: Rider token
 * Response: { "order_id": ..., "status": "rider_assigned" }
 *
 * Phase 3 R3 (doc 83/85) — deep-plan §7 "Accept transaction". Race
 * safety comes from two conditional UPDATEs (WHERE clause encodes the
 * expected current state, affected-row-count tells us whether we won),
 * matching this codebase's existing transaction style elsewhere
 * (backend/api/v1/orders/payment-switch-cod.php) rather than introducing
 * SELECT...FOR UPDATE row locking, which nothing else here uses:
 *
 * 1. rider_order_assignments: offered -> accepted, only if still
 *    offered and not expired. Fails (0 rows) if expire_stale_offers()
 *    (run first) already expired it, or it was somehow already
 *    responded to.
 * 2. orders: ready -> rider_assigned + rider_id set, only if still
 *    'ready' and rider_id is still NULL. This is the actual
 *    double-accept guard the deep-plan asks for — even if two
 *    assignment rows somehow both said "offered" for the same order
 *    (shouldn't happen given the sequential model, but this makes it
 *    safe regardless), only one UPDATE can win this WHERE clause.
 *
 * If either UPDATE affects 0 rows, the whole thing is rolled back and
 * the rider gets `offer_expired` — the app's job is just to show that
 * and go back to polling, not retry automatically.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';
require_once __DIR__ . '/../../../lib/dispatch.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
if (($owner['status'] ?? null) !== 'approved') {
    respond_error('not_approved', 403);
}
$riderId = (int) $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();
expire_stale_offers($db);

$db->beginTransaction();

$updAssignment = $db->prepare(
    "UPDATE rider_order_assignments
     SET status = 'accepted', responded_at = NOW()
     WHERE order_id = :order_id AND rider_id = :rider_id AND status = 'offered' AND expires_at >= NOW()"
);
$updAssignment->execute(['order_id' => $orderId, 'rider_id' => $riderId]);

if ($updAssignment->rowCount() !== 1) {
    $db->rollBack();
    respond_error('offer_expired', 409);
}

$updOrder = $db->prepare(
    "UPDATE orders SET rider_id = :rider_id, status = 'rider_assigned'
     WHERE id = :order_id AND status = 'ready' AND rider_id IS NULL"
);
$updOrder->execute(['order_id' => $orderId, 'rider_id' => $riderId]);

if ($updOrder->rowCount() !== 1) {
    // Assignment row said this rider won the offer, but the order
    // itself was no longer in a winnable state (shouldn't happen under
    // the sequential single-offer model — defensive only). Roll back
    // rather than leave an 'accepted' assignment pointing at an order
    // that didn't actually get assigned.
    $db->rollBack();
    respond_error('offer_expired', 409);
}

insert_status_history($db, $orderId, 'rider_assigned', 'rider', $riderId);
$db->commit();

$orderStmt = $db->prepare('SELECT customer_id, order_code FROM orders WHERE id = :id LIMIT 1');
$orderStmt->execute(['id' => $orderId]);
$order = $orderStmt->fetch();
if ($order) {
    create_notification(
        'customer',
        (int) $order['customer_id'],
        'Rider on the way',
        "A delivery partner has picked up order {$order['order_code']}",
        'order',
        ['order_id' => $orderId, 'screen' => 'order_status']
    );
}

respond_ok(['order_id' => $orderId, 'status' => 'rider_assigned']);
