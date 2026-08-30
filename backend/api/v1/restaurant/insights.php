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
 * range=custom -> requires from=YYYY-MM-DD&to=YYYY-MM-DD, for the CSV
 *                 export's date-range picker (PENDING.md's "Export
 *                 PDF/Excel" item) — not used by the normal Insights
 *                 tab UI, which only ever sends today/week/month.
 *
 * ---------------------------------------------------------------------
 * CSV export (?export=csv) — added alongside custom range, same session.
 * Same Content-Disposition/fputcsv convention backend/admin/settlements.php
 * already established — no new library, this is a real CSV file (opens
 * fine in Excel/Sheets), not a real .xlsx/.pdf. Unlike the admin export,
 * there's no separate reports_export permission gate here — a restaurant
 * owner exporting their own restaurant's own data needs no extra
 * permission beyond the restaurant auth token itself, same as viewing
 * the Insights tab in the first place.
 *
 * Sections: header (restaurant name + range), Summary stats (same 4
 * cards the UI shows), Top 5 items, 7-day daily chart, and a new
 * order-by-order ledger — this last one didn't exist in the JSON
 * response at all (insights.php only ever aggregated), so the CSV path
 * runs one extra raw-rows query no other caller needs. Capped at the
 * most recent 500 orders in range, same "cap it, don't paginate a CSV"
 * spirit as settlements.php's 200/50 caps — a restaurant reviewing a
 * month of orders in a spreadsheet is well served by 500 rows; anyone
 * needing more should narrow the date range instead.
 * ---------------------------------------------------------------------
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
 * ---------------------------------------------------------------------
 * Peak hours heatmap (today.md §1 wishlist item / PENDING.md item 3's
 * "Peak hours" line, deliberately left out of doc 49's original build —
 * see that doc's own "What stays out of scope" note: needed a design
 * decision, heatmap vs. single busiest-hour stat, that hadn't been made
 * yet). App owner has now chosen the full hour × day-of-week heatmap.
 *
 * Independent of `$range`, same reasoning `daily_chart` above already
 * documents for its own always-7-days window, one level further: a
 * `range=today` heatmap would have data for exactly one weekday, and
 * even `range=week` gives only one sample per weekday-hour cell — too
 * thin to show a real pattern. Fixed at the last 30 days (today
 * inclusive) instead, regardless of which range tab is selected, so
 * every cell has roughly 4 weeks of samples to draw a pattern from.
 *
 * Counts ALL orders placed in the window regardless of final status —
 * same "all statuses" choice `daily_chart` and the `total_orders` stat
 * already make. This is about *when demand arrives* (when customers are
 * placing orders), not revenue, so there's no reason to exclude a
 * cancelled or rejected order the way the earnings/AOV stats correctly
 * do.
 *
 * Day-of-week uses this project's existing ISO convention (1 = Monday
 * .. 7 = Sunday — same as `restaurants.working_days` and every
 * `$currentDow` in `restaurant_status.php`/`orders.php`/`offers.php`),
 * not MySQL's native `DAYOFWEEK()` (1 = Sunday .. 7 = Saturday) — the
 * query below explicitly remaps so this endpoint doesn't introduce a
 * second, conflicting day-numbering convention into the codebase.
 *
 * Returns all 168 cells (7 days × 24 hours) every time, zero-filled,
 * rather than only the non-zero ones — the heatmap needs the full grid
 * to draw empty cells correctly, and 168 small integers is negligible
 * payload. `max_count` is included so the client can normalize color
 * intensity without a second pass over the cells. `peak_slot` is the
 * single highest-count cell (null only if every cell is zero, i.e. no
 * orders at all in the window) — a convenience for a "Busiest: Fri
 * 7-8 PM" caption above the grid, since the app owner may still want
 * that alongside the heatmap even though the single-stat design was not
 * chosen as the *primary* display.
 * ---------------------------------------------------------------------
 *
 * Auth: Restaurant token.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'view_insights');
$restaurantId = (int) $owner['owner_id'];
$db = Database::get();

$range = $_GET['range'] ?? 'week';
if (!in_array($range, ['today', 'week', 'month', 'custom'], true)) {
    $range = 'week';
}

$today = date('Y-m-d');

// Custom range validation happens before $toDate is decided, since a
// bad/missing from|to should fall back to 'week' entirely rather than
// silently mixing a valid $fromDate with today's date — same
// fail-closed spirit auth.php uses elsewhere in this codebase.
if ($range === 'custom') {
    $customFrom = $_GET['from'] ?? '';
    $customTo = $_GET['to'] ?? '';
    $validFormat = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if ($validFormat($customFrom) && $validFormat($customTo) && $customFrom <= $customTo) {
        $fromDate = $customFrom;
        $toDate = $customTo;
    } else {
        $range = 'week';
    }
}
if ($range !== 'custom') {
    $toDate = $today;
    if ($range === 'today') {
        $fromDate = $today;
    } elseif ($range === 'week') {
        $fromDate = date('Y-m-d', strtotime('-6 days'));
    } else { // month
        $fromDate = date('Y-m-d', strtotime('-29 days'));
    }
}
$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime = $toDate . ' 23:59:59';

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

// ---------- Peak hours heatmap (always last 30 days, independent of $range) ----------
// See file header for the full window/day-numbering/status rationale.
$peakFromDate = date('Y-m-d', strtotime('-29 days'));
// MySQL DAYOFWEEK(): 1 (Sun) .. 7 (Sat). Remapped to this project's own
// ISO convention (1 Mon .. 7 Sun) with ((DAYOFWEEK()+5) % 7) + 1 —
// Sun(1)->7, Mon(2)->1, Tue(3)->2 ... Sat(7)->6.
$peakStmt = $db->prepare(
    "SELECT
        ((DAYOFWEEK(created_at) + 5) % 7) + 1 AS dow_iso,
        HOUR(created_at) AS hr,
        COUNT(*) AS order_count
     FROM orders
     WHERE restaurant_id = :rid AND created_at BETWEEN :f AND :t
     GROUP BY dow_iso, hr"
);
$peakStmt->execute([
    'rid' => $restaurantId,
    'f' => $peakFromDate . ' 00:00:00',
    't' => $today . ' 23:59:59',
]);
$peakCountByCell = [];
foreach ($peakStmt->fetchAll() as $row) {
    $peakCountByCell[(int) $row['dow_iso']][(int) $row['hr']] = (int) $row['order_count'];
}

$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$peakCells = [];
$maxCellCount = 0;
$peakSlot = null;
for ($dow = 1; $dow <= 7; $dow++) {
    for ($hr = 0; $hr <= 23; $hr++) {
        $count = $peakCountByCell[$dow][$hr] ?? 0;
        $peakCells[] = [
            'day_of_week' => $dow,
            'hour' => $hr,
            'order_count' => $count,
        ];
        if ($count > $maxCellCount) {
            $maxCellCount = $count;
            $peakSlot = [
                'day_of_week' => $dow,
                'day_name' => $dayNames[$dow],
                'hour' => $hr,
                'order_count' => $count,
            ];
        }
    }
}

// ---------- CSV export ----------
// See file header for the full rationale. Runs only when explicitly
// asked for — every normal Insights-tab load skips this whole block.
if (($_GET['export'] ?? '') === 'csv') {
    // Restaurant name for the CSV header line — insights.php never
    // needed this for the JSON response (the app already knows who's
    // logged in), so this is a new small lookup only the export path
    // pays for.
    $nameStmt = $db->prepare('SELECT name FROM restaurants WHERE id = :id LIMIT 1');
    $nameStmt->execute(['id' => $restaurantId]);
    $restaurantName = $nameStmt->fetch()['name'] ?? ('Restaurant #' . $restaurantId);

    // Order-by-order ledger — the one thing the JSON response never
    // returns (it only ever aggregates). Capped at 500 most-recent
    // orders in range; see file header for why.
    $ordersStmt = $db->prepare(
        "SELECT order_code, created_at, status, payment_method, item_total, grand_total
         FROM orders
         WHERE restaurant_id = :rid AND created_at BETWEEN :f AND :t
         ORDER BY created_at DESC
         LIMIT 500"
    );
    $ordersStmt->execute(['rid' => $restaurantId, 'f' => $fromDateTime, 't' => $toDateTime]);
    $orderRows = $ordersStmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anydrop_insights_' . $restaurantId . '_' . $fromDate . '_to_' . $toDate . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Anydrop Insights Export — ' . $restaurantName]);
    fputcsv($out, ['Range', $fromDate . ' to ' . $toDate]);
    fputcsv($out, []);

    fputcsv($out, ['Summary']);
    fputcsv($out, ['Total Orders', 'Total Earnings', 'Average Order Value', 'Cancellation Rate %']);
    fputcsv($out, [$totalOrders, $totalEarnings, $avgOrderValue, $cancellationRate]);
    fputcsv($out, []);

    fputcsv($out, ['Top 5 Items (by quantity sold)']);
    fputcsv($out, ['Item', 'Quantity Sold', 'Revenue']);
    foreach ($topItems as $item) {
        fputcsv($out, [$item['name'], $item['quantity_sold'], $item['revenue']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Daily Orders (last 7 days)']);
    fputcsv($out, ['Date', 'Order Count']);
    foreach ($dailyChart as $day) {
        fputcsv($out, [$day['date'], $day['order_count']]);
    }
    fputcsv($out, []);

    // Peak hours — always the fixed last-30-days window (see file
    // header), independent of the export's own from/to range, same as
    // the JSON response. Flagged with its own from/to line in the CSV
    // so this doesn't read as if it were scoped to the export range.
    fputcsv($out, ['Peak Hours (orders placed, last 30 days: ' . $peakFromDate . ' to ' . $today . ')']);
    fputcsv($out, array_merge(['Day / Hour'], array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23))));
    foreach ($dayNames as $dow => $dayName) {
        $rowValues = array_map(fn($h) => $peakCountByCell[$dow][$h] ?? 0, range(0, 23));
        fputcsv($out, array_merge([$dayName], $rowValues));
    }
    if ($peakSlot !== null) {
        fputcsv($out, []);
        fputcsv($out, ['Busiest slot', $peakSlot['day_name'] . ' ' . sprintf('%02d:00', $peakSlot['hour']), $peakSlot['order_count'] . ' orders']);
    }
    fputcsv($out, []);

    fputcsv($out, ['Orders (most recent 500 in range)']);
    fputcsv($out, ['Order Code', 'Date', 'Status', 'Payment Method', 'Item Total', 'Grand Total']);
    foreach ($orderRows as $row) {
        fputcsv($out, [
            $row['order_code'], $row['created_at'], $row['status'], $row['payment_method'],
            (float) $row['item_total'], (float) $row['grand_total'],
        ]);
    }

    fclose($out);
    exit;
}

respond_ok([
    'range' => $range,
    'from_date' => $fromDate,
    'to_date' => $toDate,
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
    'peak_hours' => [
        'from_date' => $peakFromDate,
        'to_date' => $today,
        'max_count' => $maxCellCount,
        'peak_slot' => $peakSlot,
        'cells' => $peakCells,
    ],
]);
