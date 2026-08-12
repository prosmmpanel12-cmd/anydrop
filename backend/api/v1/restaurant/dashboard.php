<?php
/**
 * GET /api/v1/restaurant/dashboard
 * Auth: Restaurant token
 * Response: today's order/earnings summary, computed server-side (never
 * client-aggregated, so the restaurant app can't be tricked by stale local math).
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

$dueStmt = $db->prepare('SELECT current_due FROM restaurants WHERE id = :rid LIMIT 1');
$dueStmt->execute(['rid' => $restaurantId]);
$currentDue = (float) ($dueStmt->fetch()['current_due'] ?? 0);

respond_ok([
    'pending_orders' => $pendingCount,
    'active_orders' => $activeCount,
    'today' => [
        'orders_count' => (int) $today['orders_count'],
        'earnings' => (float) $today['earnings'],
        'commission_owed' => (float) $today['commission'],
    ],
    'current_due' => $currentDue,
]);
