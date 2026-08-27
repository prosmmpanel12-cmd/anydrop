<?php
/**
 * GET /api/v1/restaurant/insights.php
 *      Query: ?range=today|week|month (default: week)
 *
 * The backend piece docs/restorent/19_Restaurant_App_UI_Plan.md §6
 * flagged as not existing yet ("none of this data is exposed yet —
 * needs a new restaurant/insights.php endpoint aggregating the orders
 * table"), and PENDING.md item 3 lists as the reason the Insights tab
 * is still a placeholder (InsightsFragment.kt). This is that endpoint.
 *
 * Everything here is computed server-side off the `orders` table, same
 * "never client-aggregated" rule dashboard.php's own header states —
 * this endpoint is the ranged sibling of dashboard.php, which is
 * deliberately today-only (see its header).
 *
 * range=today  -> stats for CURDATE() only
 * range=week   -> stats for the last 7 days (today inclusive), plus a
 *                 day-by-day order count for the bar chart (§6: "Simple
 *                 bar chart: orders per day (last 7 days)" — always 7
 *                 points regardless of which range tab is selected,
 *                 since the chart itself isn't range-scoped in the
 *                 plan doc, only the stat cards are)
 * range=month  -> stats for the last 30 days (today inclusive)
 *
 * "Total orders"/"Total earnings"/"Average order value"/"Cancellation
 * rate" (§6's four cards) are computed over ALL orders placed in the
 * range regardless of final status, except earnings/AOV which only
 * count delivered orders — same revenue-recognition rule dashboard.php
 * already uses (SUM(item_total) WHERE status = 'delivered'). An order
 * that's still pending/preparing hasn't earned anything yet.
 *
 * Cancellation rate = (cancelled + rejected) / total placed, as a
 * percentage. Both count as "didn't happen" from the restaurant's own
 * performance-review point of view; failed/expired are payment-layer
 * outcomes the restaurant had no control over and are excluded so a
 * customer's timed-out UPI payment doesn't ding the restaurant's rate.
 *
 * Top 5 best-selling items: ranked by total quantity sold (not order
 * count, not revenue) across delivered orders in the range, joined via
 * order_items.menu_item_id. Deliberately NOT filtered to
 * is_bestseller = 1 — that flag is a restaurant-set manual badge shown
 * elsewhere (search/home), a different thing from "what actually sold
 * most this week." An item can be flagged bestseller and not appear
 * here, or appear here without the flag; this list is copy-labelled
 * "Top 5 this range" for that reason, not "Bestsellers."
 *
 * Repeat customers: distinct customers in range with 2+ delivered
 * orders in this restaurant's own order history (not just within the
 * selected range — a customer who ordered once last month and once
 * today is still a repeat customer today), reported as a count and a
 * percentage of the range's distinct customers.
 *
 * Auth: Restaurant token.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = (int) $owner['owner_id'];
$db = Database::get();

$range = $_GET['range'] ?? 'week';
if (!in_array($range, ['today', 'week', 'month'], true)) {
    $range = 'week';
}

$today = date('Y-m-d');
if ($range === 'today') {
    $fromDate = $today;
} elseif ($range === 'week') {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
} else { // month
    $fromDate = date('Y-m-d', strtotime('-29 days'));
}
$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime = $today . ' 23:59:59';

// ---------- Stat cards ----------
// Placed counts (all statuses) + delivered-only earnings, same
// delivered-only revenue rule as dashboard.php.
$statsStmt = $db->prepare(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(status = 'delivered') AS delivered_count,
        SUM(status IN ('cancelled','rejected')) AS cancelled_count,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN item_total ELSE 0 END), 0) AS total_earnings
     FROM orders
     WHERE restaurant_id = :rid AND created_at BETWEEN :f AND :t"
);
$statsStmt->execute(['rid' => $restaurantId, 'f' => $fromDateTime, 't' => $toDateTime]);
$stats = $statsStmt->fetch();

$totalOrders = (int) $stats['total_orders'];
$deliveredCount = (int) $stats['delivered_count'];
$cancelledCount = (int) $stats['cancelled_count'];
$totalEarnings = (float) $stats['total_earnings'];
$avgOrderValue = $deliveredCount > 0 ? round($totalEarnings / $deliveredCount, 2) : 0.0;
$cancellationRate = $totalOrders > 0 ? round(($cancelledCount / $totalOrders) * 100, 1) : 0.0;

// ---------- 7-day bar chart (always last 7 days, independent of $range) ----------
$chartFromDate = date('Y-m-d', strtotime('-6 days'));
$chartStmt = $db->prepare(
    "SELECT DATE(created_at) AS day, COUNT(*) AS order_count
     FROM orders
     WHERE restaurant_id = :rid AND created_at BETWEEN :f AND :t
     GROUP BY DATE(created_at)"
);
$chartStmt->execute([
    'rid' => $restaurantId,
    'f' => $chartFromDate . ' 00:00:00',
    't' => $today . ' 23:59:59',
]);
$chartRows = $chartStmt->fetchAll();
$countByDay = [];
foreach ($chartRows as $row) {
    $countByDay[$row['day']] = (int) $row['order_count'];
}
$dailyChart = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $dailyChart[] = [
        'date' => $day,
        'order_count' => $countByDay[$day] ?? 0,
    ];
}

// ---------- Top 5 best-selling items (by quantity, delivered orders, in range) ----------
$topItemsStmt = $db->prepare(
    "SELECT
        oi.menu_item_id,
        oi.item_name_snapshot AS name,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.subtotal) AS total_revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.restaurant_id = :rid AND o.status = 'delivered'
       AND o.created_at BETWEEN :f AND :t
     GROUP BY oi.menu_item_id, oi.item_name_snapshot
     ORDER BY total_quantity DESC
     LIMIT 5"
);
$topItemsStmt->execute(['rid' => $restaurantId, 'f' => $fromDateTime, 't' => $toDateTime]);
$topItemsRows = $topItemsStmt->fetchAll();
$topItems = array_map(static function (array $row): array {
    return [
        'menu_item_id' => $row['menu_item_id'] !== null ? (int) $row['menu_item_id'] : null,
        'name' => $row['name'],
        'quantity_sold' => (int) $row['total_quantity'],
        'revenue' => (float) $row['total_revenue'],
    ];
}, $topItemsRows);

// ---------- Repeat customers ----------
// Distinct customers who ordered (delivered) in range, and how many of
// those have 2+ delivered orders across their FULL history with this
// restaurant (not range-limited — see header note).
$rangeCustomersStmt = $db->prepare(
    "SELECT DISTINCT customer_id FROM orders
     WHERE restaurant_id = :rid AND status = 'delivered' AND created_at BETWEEN :f AND :t"
);
$rangeCustomersStmt->execute(['rid' => $restaurantId, 'f' => $fromDateTime, 't' => $toDateTime]);
$rangeCustomerIds = array_column($rangeCustomersStmt->fetchAll(), 'customer_id');

$repeatCount = 0;
$distinctCustomerCount = count($rangeCustomerIds);
if ($distinctCustomerCount > 0) {
    $placeholders = implode(',', array_fill(0, $distinctCustomerCount, '?'));
    $repeatStmt = $db->prepare(
        "SELECT customer_id, COUNT(*) AS c FROM orders
         WHERE restaurant_id = ? AND status = 'delivered' AND customer_id IN ($placeholders)
         GROUP BY customer_id
         HAVING COUNT(*) >= 2"
    );
    $repeatStmt->execute(array_merge([$restaurantId], $rangeCustomerIds));
    $repeatCount = count($repeatStmt->fetchAll());
}
$repeatCustomerPercent = $distinctCustomerCount > 0
    ? round(($repeatCount / $distinctCustomerCount) * 100, 1)
    : 0.0;

respond_ok([
    'range' => $range,
    'from_date' => $fromDate,
    'to_date' => $today,
    'stats' => [
        'total_orders' => $totalOrders,
        'total_earnings' => $totalEarnings,
        'average_order_value' => $avgOrderValue,
        'cancellation_rate_percent' => $cancellationRate,
    ],
    'daily_chart' => $dailyChart,
    'top_items' => $topItems,
    'repeat_customers' => [
        'count' => $repeatCount,
        'distinct_customers_in_range' => $distinctCustomerCount,
        'percent' => $repeatCustomerPercent,
    ],
]);
