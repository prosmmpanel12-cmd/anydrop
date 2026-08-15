<?php
/**
 * GET /api/v1/restaurant/dashboard
 * Auth: Restaurant token
 * Response: today's order/earnings summary, computed server-side (never
 * client-aggregated, so the restaurant app can't be tricked by stale local math).
 * Also returns operational_status — Part B's "Accepting orders" toggle
 * (see status-update.php) reads its initial on/off state from here, since
 * this is already the dashboard screen's first load call.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();

$pendingStmt = $db->prepare("SELECT COUNT(*) AS c FROM orders WHERE restaurant_id = :rid AND status = 'pending'");
$pendingStmt->execute(['rid' => $restaurantId]);
$pendingCount = (int) $pendingStmt->fetch()['c'];

$activeStmt = $db->prepare(
    "SELECT COUNT(*) AS c FROM orders WHERE restaurant_id = :rid
     AND status IN ('accepted','preparing','ready','rider_assigned','picked_up','out_for_delivery')"
);
$activeStmt->execute(['rid' => $restaurantId]);
$activeCount = (int) $activeStmt->fetch()['c'];

$todayStmt = $db->prepare(
    "SELECT COUNT(*) AS orders_count,
            COALESCE(SUM(item_total), 0) AS earnings,
            COALESCE(SUM(commission_amount), 0) AS commission
     FROM orders
     WHERE restaurant_id = :rid AND status = 'delivered' AND DATE(created_at) = CURDATE()"
);
$todayStmt->execute(['rid' => $restaurantId]);
$today = $todayStmt->fetch();

// UI plan §4 "Today" snapshot strip (docs/restorent/19) — average time from
// accept to ready, today's orders only, orders that reached 'ready' or
// beyond (accepted_at/ready_at are both set by orders-accept.php /
// orders-status.php respectively; an order still short of 'ready' has a
// null ready_at and is correctly excluded rather than counted as 0 minutes).
$avgPrepStmt = $db->prepare(
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, accepted_at, ready_at)) AS avg_minutes
     FROM orders
     WHERE restaurant_id = :rid AND DATE(created_at) = CURDATE()
       AND accepted_at IS NOT NULL AND ready_at IS NOT NULL"
);
$avgPrepStmt->execute(['rid' => $restaurantId]);
$avgPrepRow = $avgPrepStmt->fetch();
$avgPrepMinutes = $avgPrepRow['avg_minutes'] !== null ? (int) round((float) $avgPrepRow['avg_minutes']) : null;

$dueStmt = $db->prepare('SELECT current_due, operational_status FROM restaurants WHERE id = :rid LIMIT 1');
$dueStmt->execute(['rid' => $restaurantId]);
$restaurantRow = $dueStmt->fetch();
$currentDue = (float) ($restaurantRow['current_due'] ?? 0);
// Part B — dashboard is the natural first load for the Restaurant App
// screen that also hosts the "Accepting orders" toggle, so it doubles as
// the read side for initializing that switch's on/off state.
$operationalStatus = $restaurantRow['operational_status'] ?? 'closed';

respond_ok([
    'pending_orders' => $pendingCount,
    'active_orders' => $activeCount,
    'today' => [
        'orders_count' => (int) $today['orders_count'],
        'earnings' => (float) $today['earnings'],
        'commission_owed' => (float) $today['commission'],
        'avg_prep_minutes' => $avgPrepMinutes,
    ],
    'current_due' => $currentDue,
    'operational_status' => $operationalStatus,
]);
