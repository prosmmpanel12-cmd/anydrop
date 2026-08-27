<?php
/**
 * Anydrop — Admin Web UI: Analytics (doc 21 §4.19; docs/43 plan;
 * PENDING.md item 2's remaining-scope pass, 2026-08-27).
 *
 * The ranged (Today/7d/30d/Custom) reporting view that dashboard.php
 * deliberately doesn't do — that page is "today only" by design (see
 * its own header). This page is read-only: Orders, Revenue,
 * Restaurants, Items, Customers, Areas, Riders, Payments, Coupons —
 * all scoped to the selected date range (LTV is the one deliberate
 * exception — see docs/43).
 *
 * Filters: date range, State, District, Restaurant, Category — on top
 * of the original Area filter, all AND-combined. State/District walk
 * up service_areas.parent_id from a matched Area/City-Village leaf
 * (there is no direct state_id/district_id column anywhere — the
 * hierarchy is a single self-referencing table, migration 30) so the
 * filter resolves "every leaf area under this State/District node" up
 * front into an ID list, same shape the existing Area filter already
 * used (`ca.area_id IN (...)`), rather than inventing a second query
 * pattern.
 *
 * Rider/Payment/Coupon sections added this session:
 * - Riders: no dedicated Rider App/session exists yet (doc 44 flagged
 *   this as the reason to skip it originally), but `orders.rider_id`
 *   and `riders.name` are both real, populated columns (Order
 *   Control's own detail view already joins on them) — a
 *   deliveries-per-rider report needs neither a Rider App nor new
 *   schema, so it's in scope now on that basis, re-derived from
 *   source rather than trusting doc 44's older "no Rider App data"
 *   framing at face value.
 * - Payments: `orders.payment_method`/`payment_status` already exist
 *   and are populated for every order regardless of range.
 * - Coupons: `coupon_usages` already has the coupon_id/customer_id/
 *   order_id join keys this needs.
 *
 * Gated on `reports_view` (migration 29's existing key). Export
 * (`reports_export`, same migration, unused until now) added this
 * session as a CSV download of the current filtered view — no prior
 * export/Content-Disposition pattern existed anywhere in backend/ to
 * follow (checked before writing), so this establishes one: a single
 * `&export=csv` flag on the same GET request, same filters applied,
 * streamed instead of rendered.
 *
 * STATUS: 🟡 BUILT 2026-08-27 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page (no PHP CLI or
 * live DB here). See this file's own verification checklist at the
 * bottom of the corresponding handover doc.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'reports_view');
$db = Database::get();
$canExport = admin_has_permission((int) $admin['id'], 'reports_export');

// ---------- Date range ----------
$range = $_GET['range'] ?? '7d';
if (!in_array($range, ['today', '7d', '30d', 'custom'], true)) {
    $range = '7d';
}

$today = date('Y-m-d');
if ($range === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($range === '7d') {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
    $toDate = $today;
} elseif ($range === '30d') {
    $fromDate = date('Y-m-d', strtotime('-29 days'));
    $toDate = $today;
} else { // custom
    $fromDate = trim($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
    $toDate = trim($_GET['to'] ?? $today);
}
$fromDateTime = $fromDate . ' 00:00:00';
$toDateTime = $toDate . ' 23:59:59';

$nonRevenueStatuses = "'cancelled','rejected','failed','expired'";

// ---------- Extra filters (State/District/Restaurant/Category/Area) ----------
// Same $_GET-driven, AND-combined shape as orders.php's filter block.
$fStateId = (int) ($_GET['state_id'] ?? 0);
$fDistrictId = (int) ($_GET['district_id'] ?? 0);
$fAreaId = (int) ($_GET['area_id'] ?? 0);
$fRestaurantId = (int) ($_GET['restaurant_id'] ?? 0);
$fCategoryId = (int) ($_GET['category_id'] ?? 0);
$exportCsv = ($_GET['export'] ?? '') === 'csv';

// Full service_areas map — needed both for breadcrumb rendering and to
// walk parent_id chains for the State/District filters below.
$allAreaRows = $db->query('SELECT id, name, level, parent_id FROM service_areas')->fetchAll();
$areaNodeById = [];
foreach ($allAreaRows as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}

/**
 * Every service_areas id that is $ancestorId itself or a descendant of
 * it. Used to turn a State/District pick into the same "list of leaf
 * area ids" shape the pre-existing Area filter already produces, so
 * one WHERE clause (`ca.area_id IN (...)`) covers all three filters
 * without three different query shapes.
 */
function admin_area_descendant_ids(int $ancestorId, array $allAreaRows): array
{
    $childrenByParent = [];
    foreach ($allAreaRows as $row) {
        $childrenByParent[(int) ($row['parent_id'] ?? 0)][] = (int) $row['id'];
    }
    $ids = [$ancestorId];
    $queue = [$ancestorId];
    while ($queue) {
        $current = array_pop($queue);
        foreach ($childrenByParent[$current] ?? [] as $childId) {
            $ids[] = $childId;
            $queue[] = $childId;
        }
    }
    return $ids;
}

// Resolve the most specific area-scoping filter the admin picked, most
// specific wins if somehow more than one is set (Area > District >
// State) — an Area pick is already a single leaf id, no walk needed.
$scopedAreaIds = null;
if ($fAreaId > 0) {
    $scopedAreaIds = [$fAreaId];
} elseif ($fDistrictId > 0) {
    $scopedAreaIds = admin_area_descendant_ids($fDistrictId, $allAreaRows);
} elseif ($fStateId > 0) {
    $scopedAreaIds = admin_area_descendant_ids($fStateId, $allAreaRows);
}

// Dropdown option lists.
$stateOptions = array_filter($areaNodeById, fn($a) => $a['level'] === 'state');
$districtOptions = $fStateId > 0
    ? array_filter($areaNodeById, fn($a) => $a['level'] === 'district' && (int) $a['parent_id'] === $fStateId)
    : array_filter($areaNodeById, fn($a) => $a['level'] === 'district');
$areaFilterOptions = array_filter($areaNodeById, fn($a) => in_array($a['level'], ['city_village', 'area'], true));

$restaurantOptions = $db->query('SELECT id, name FROM restaurants ORDER BY name')->fetchAll();
$categoryOptions = $db->query('SELECT id, name FROM restaurant_categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();

// Shared WHERE fragment for anything joining orders -> customer_addresses
// (area scoping) and/or restaurants (restaurant/category scoping).
// Built once, reused by every section below that touches `orders o`
// with the standard `o` alias, same as orders.php's single $whereSql
// approach.
$extraWhere = [];
$extraParams = [];
if ($scopedAreaIds !== null) {
    $placeholders = [];
    foreach ($scopedAreaIds as $i => $id) {
        $key = "area_scope_{$i}";
        $placeholders[] = ":{$key}";
        $extraParams[$key] = $id;
    }
    $extraWhere[] = 'ca.area_id IN (' . implode(',', $placeholders) . ')';
}
if ($fRestaurantId > 0) {
    $extraWhere[] = 'o.restaurant_id = :f_restaurant_id';
    $extraParams['f_restaurant_id'] = $fRestaurantId;
}
if ($fCategoryId > 0) {
    $extraWhere[] = 'r.restaurant_category_id = :f_category_id';
    $extraParams['f_category_id'] = $fCategoryId;
}
$extraWhereSql = $extraWhere ? (' AND ' . implode(' AND ', $extraWhere)) : '';
// Any query using $extraWhereSql that references `ca.` or `r.` must
// join customer_addresses/restaurants under those aliases even when
// the filter isn't active, since the SQL string is static per-query —
// see each section below for its own join list.

// ---------- Orders ----------
$orderCounts = $db->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(o.status = 'delivered') AS completed,
        SUM(o.status = 'cancelled') AS cancelled,
        SUM(o.status = 'rejected') AS rejected,
        SUM(o.status IN ('failed','expired')) AS failed
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t{$extraWhereSql}"
);
$orderCounts->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$orders = $orderCounts->fetch();

// ---------- Revenue ----------
$revenueStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(o.grand_total), 0) AS gmv,
        COALESCE(SUM(o.commission_amount + o.platform_fee), 0) AS platform_revenue,
        COALESCE(SUM(o.commission_amount), 0) AS commission,
        COALESCE(SUM(o.discount_amount + o.offer_discount_amount + o.free_delivery_discount_amount), 0) AS discounts
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}"
);
$revenueStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$revenue = $revenueStmt->fetch();

// Refunds counted by when they actually completed (refunded_at), not
// when requested — a refund only actually left the platform once done.
// Area/restaurant/category filters apply here too, via the linked
// order (refunds has no address/restaurant column of its own).
$refundsFilterJoin = ($scopedAreaIds !== null || $fRestaurantId > 0 || $fCategoryId > 0)
    ? "JOIN orders o ON o.id = refunds.order_id
       LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
       JOIN restaurants r ON r.id = o.restaurant_id"
    : '';
$refundsStmt = $db->prepare(
    "SELECT COALESCE(SUM(refunds.amount), 0) AS c FROM refunds
     {$refundsFilterJoin}
     WHERE refunds.status = 'refunded' AND refunds.refunded_at BETWEEN :f AND :t{$extraWhereSql}"
);
$refundsStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$refundsOut = (float) $refundsStmt->fetch()['c'];

// ---------- Restaurants (top/bottom 5 by revenue in range; acceptance rate) ----------
$restaurantRevenue = $db->prepare(
    "SELECT r.id, r.name, COALESCE(SUM(o.grand_total), 0) AS revenue, COUNT(o.id) AS order_count
     FROM restaurants r
     JOIN orders o ON o.restaurant_id = r.id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}
     GROUP BY r.id, r.name
     ORDER BY revenue DESC"
);
$restaurantRevenue->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$restaurantRows = $restaurantRevenue->fetchAll();
$topRestaurants = array_slice($restaurantRows, 0, 5);
$bottomRestaurants = array_slice(array_reverse($restaurantRows), 0, 5);

$acceptanceStmt = $db->prepare(
    "SELECT
        SUM(o.status NOT IN ('pending','rejected')) AS accepted,
        SUM(o.status = 'rejected') AS rejected
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t
       AND o.status IN ('rejected','accepted','preparing','ready','rider_assigned','picked_up','out_for_delivery','delivered','cancelled')
       {$extraWhereSql}"
);
$acceptanceStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$acc = $acceptanceStmt->fetch();
$acceptedCount = (int) ($acc['accepted'] ?? 0);
$rejectedCount = (int) ($acc['rejected'] ?? 0);
$acceptanceRate = ($acceptedCount + $rejectedCount) > 0
    ? round(100 * $acceptedCount / ($acceptedCount + $rejectedCount), 1)
    : null;

// ---------- Items (top-selling, most profitable, most cancelled) ----------
$topSelling = $db->prepare(
    "SELECT oi.item_name_snapshot AS name, SUM(oi.quantity) AS qty
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}
     GROUP BY oi.item_name_snapshot ORDER BY qty DESC LIMIT 5"
);
$topSelling->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$topSellingItems = $topSelling->fetchAll();

$mostProfitable = $db->prepare(
    "SELECT oi.item_name_snapshot AS name, SUM(oi.subtotal) AS total
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}
     GROUP BY oi.item_name_snapshot ORDER BY total DESC LIMIT 5"
);
$mostProfitable->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$mostProfitableItems = $mostProfitable->fetchAll();

$mostCancelled = $db->prepare(
    "SELECT oi.item_name_snapshot AS name, SUM(oi.quantity) AS qty
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status IN ('cancelled','rejected'){$extraWhereSql}
     GROUP BY oi.item_name_snapshot ORDER BY qty DESC LIMIT 5"
);
$mostCancelled->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$mostCancelledItems = $mostCancelled->fetchAll();

// ---------- Customers ----------
// "New" is a pure customers-table signup count — no restaurant/area
// tie exists on that row, so the State/District/Restaurant/Category
// filters deliberately don't apply here (a new signup isn't "for" a
// restaurant the way an order is).
$newCustomers = $db->prepare("SELECT COUNT(*) AS c FROM customers WHERE created_at BETWEEN :f AND :t");
$newCustomers->execute(['f' => $fromDateTime, 't' => $toDateTime]);
$newCustomerCount = (int) $newCustomers->fetch()['c'];

// Returning = placed an order in range AND had at least one order before the range started.
// Filters apply to the in-range order (o), same as every order-scoped
// section above — deliberately NOT applied to the "before range" EXISTS
// check, since a customer who ordered from a filtered-out restaurant
// last month should still count as "returning" if this range's order
// (at the filtered restaurant/area) is their second overall.
$returningStmt = $db->prepare(
    "SELECT COUNT(DISTINCT o.customer_id) AS c
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t{$extraWhereSql}
       AND EXISTS (SELECT 1 FROM orders o2 WHERE o2.customer_id = o.customer_id AND o2.created_at < :f2)"
);
$returningStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime, 'f2' => $fromDateTime], $extraParams));
$returningCount = (int) $returningStmt->fetch()['c'];

$rangedOrderCount = (int) $orders['total'];
$gmv = (float) $revenue['gmv'];
$aov = $rangedOrderCount > 0 ? round($gmv / $rangedOrderCount, 2) : 0.0;

// LTV — deliberately lifetime, not range-scoped (see docs/43).
$ltvStmt = $db->query(
    "SELECT COALESCE(AVG(customer_total), 0) AS avg_ltv FROM (
        SELECT customer_id, SUM(grand_total) AS customer_total
        FROM orders WHERE status NOT IN ($nonRevenueStatuses)
        GROUP BY customer_id
     ) t"
);
$avgLtv = (float) $ltvStmt->fetch()['avg_ltv'];

// ---------- Areas ----------
// Resolved the same way docs/41's Order Control page resolves area:
// delivery_address_id -> customer_addresses.area_id. $areaNodeById was
// already built up top (needed there for the State/District dropdown
// walk) — reused here, not rebuilt.
$areaStmt = $db->prepare(
    "SELECT ca.area_id,
            COUNT(o.id) AS order_count,
            COALESCE(SUM(o.grand_total), 0) AS revenue,
            COUNT(DISTINCT o.customer_id) AS customer_count,
            COUNT(DISTINCT o.restaurant_id) AS restaurant_count
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}
     GROUP BY ca.area_id
     ORDER BY revenue DESC"
);
$areaStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$areaRows = $areaStmt->fetchAll();

// ---------- Riders ----------
// Deliveries/earnings-adjacent counts per rider, in range. Deliberately
// NOT touching rider_locations (live GPS ping stream) — that's out of
// scope for a reporting page (flagged in NEXT_SESSION_PROMPT.md before
// this session started); this is delivery counts/times only, off
// orders.rider_id, which every rider-assigned order already has.
$riderStmt = $db->prepare(
    "SELECT rd.id, rd.name,
            COUNT(o.id) AS delivered_count,
            COALESCE(SUM(o.grand_total), 0) AS revenue,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, o.picked_up_at, o.delivered_at)), 0) AS avg_delivery_minutes
     FROM riders rd
     JOIN orders o ON o.rider_id = rd.id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status = 'delivered'{$extraWhereSql}
     GROUP BY rd.id, rd.name
     ORDER BY delivered_count DESC
     LIMIT 10"
);
$riderStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$riderRows = $riderStmt->fetchAll();

// ---------- Payments ----------
$paymentStmt = $db->prepare(
    "SELECT o.payment_method,
            COUNT(*) AS order_count,
            COALESCE(SUM(o.grand_total), 0) AS gmv,
            SUM(o.payment_status = 'failed') AS failed_count
     FROM orders o
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE o.created_at BETWEEN :f AND :t AND o.status NOT IN ($nonRevenueStatuses){$extraWhereSql}
     GROUP BY o.payment_method"
);
$paymentStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$paymentRows = $paymentStmt->fetchAll();
$paymentByMethod = [];
foreach ($paymentRows as $pr) {
    $paymentByMethod[$pr['payment_method']] = $pr;
}

// ---------- Coupons ----------
// coupon_usages already has coupon_id/customer_id/order_id — join
// through order_id for the same range/area/restaurant/category scoping
// every other section uses. discount_amount comes off the order itself
// (the order-level discount snapshot), not recomputed from the coupon's
// current discount_value — a coupon's rules can change after the fact,
// the order row is what the customer actually got.
$couponStmt = $db->prepare(
    "SELECT c.code, COUNT(cu.id) AS uses, COUNT(DISTINCT cu.customer_id) AS unique_customers,
            COALESCE(SUM(o.discount_amount), 0) AS total_discount
     FROM coupon_usages cu
     JOIN coupons c ON c.id = cu.coupon_id
     JOIN orders o ON o.id = cu.order_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE cu.used_at BETWEEN :f AND :t{$extraWhereSql}
     GROUP BY c.id, c.code
     ORDER BY uses DESC
     LIMIT 10"
);
$couponStmt->execute(array_merge(['f' => $fromDateTime, 't' => $toDateTime], $extraParams));
$couponRows = $couponStmt->fetchAll();

// ---------- Export (CSV) ----------
// Gated separately on reports_export (reports_view alone isn't enough,
// same "view vs export" split migration 29 already defined for this
// permission pair). Streams a summary CSV of everything above, same
// filters, instead of rendering the HTML page — must run before any
// HTML output (including _layout_head.php) since it sends its own
// Content-Type/Content-Disposition headers.
if ($exportCsv) {
    admin_require_permission($admin, 'reports_export');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anydrop_analytics_' . $fromDate . '_to_' . $toDate . '.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, ['Anydrop Analytics Export']);
    fputcsv($out, ['Range', $fromDate . ' to ' . $toDate]);
    fputcsv($out, []);

    fputcsv($out, ['Orders']);
    fputcsv($out, ['Total', 'Completed', 'Cancelled', 'Rejected', 'Failed']);
    fputcsv($out, [(int) $orders['total'], (int) ($orders['completed'] ?? 0), (int) ($orders['cancelled'] ?? 0), (int) ($orders['rejected'] ?? 0), (int) ($orders['failed'] ?? 0)]);
    fputcsv($out, []);

    fputcsv($out, ['Revenue']);
    fputcsv($out, ['GMV', 'Platform Revenue', 'Commission', 'Discounts', 'Refunds']);
    fputcsv($out, [$gmv, (float) $revenue['platform_revenue'], (float) $revenue['commission'], (float) $revenue['discounts'], $refundsOut]);
    fputcsv($out, []);

    fputcsv($out, ['Restaurants — Top 5 by revenue']);
    fputcsv($out, ['Restaurant', 'Orders', 'Revenue']);
    foreach ($topRestaurants as $rr) {
        fputcsv($out, [$rr['name'], (int) $rr['order_count'], (float) $rr['revenue']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Restaurants — Bottom 5 by revenue']);
    fputcsv($out, ['Restaurant', 'Orders', 'Revenue']);
    foreach ($bottomRestaurants as $rr) {
        fputcsv($out, [$rr['name'], (int) $rr['order_count'], (float) $rr['revenue']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Top-selling items']);
    fputcsv($out, ['Item', 'Quantity sold']);
    foreach ($topSellingItems as $it) {
        fputcsv($out, [$it['name'], (int) $it['qty']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Customers']);
    fputcsv($out, ['New', 'Returning', 'Avg Order Value', 'Avg LTV (lifetime)']);
    fputcsv($out, [$newCustomerCount, $returningCount, $aov, $avgLtv]);
    fputcsv($out, []);

    fputcsv($out, ['Areas']);
    fputcsv($out, ['Area', 'Orders', 'Revenue', 'Customers', 'Restaurants']);
    foreach ($areaRows as $ar) {
        $areaLabel = ($ar['area_id'] && isset($areaNodeById[(int) $ar['area_id']]))
            ? admin_area_breadcrumb_compact($areaNodeById[(int) $ar['area_id']], $areaNodeById)
            : 'Unresolved / no address';
        fputcsv($out, [$areaLabel, (int) $ar['order_count'], (float) $ar['revenue'], (int) $ar['customer_count'], (int) $ar['restaurant_count']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Riders']);
    fputcsv($out, ['Rider', 'Delivered orders', 'Revenue', 'Avg delivery minutes']);
    foreach ($riderRows as $rr) {
        fputcsv($out, [$rr['name'], (int) $rr['delivered_count'], (float) $rr['revenue'], round((float) $rr['avg_delivery_minutes'], 1)]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Payments']);
    fputcsv($out, ['Method', 'Orders', 'GMV', 'Failed count']);
    foreach ($paymentByMethod as $method => $pr) {
        fputcsv($out, [strtoupper($method), (int) $pr['order_count'], (float) $pr['gmv'], (int) $pr['failed_count']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Coupons']);
    fputcsv($out, ['Code', 'Uses', 'Unique customers', 'Total discount']);
    foreach ($couponRows as $cr) {
        fputcsv($out, [$cr['code'], (int) $cr['uses'], (int) $cr['unique_customers'], (float) $cr['total_discount']]);
    }

    fclose($out);
    write_audit_log('admin', $admin['id'], 'analytics_exported', [
        'range' => $range, 'from' => $fromDate, 'to' => $toDate,
    ]);
    exit;
}

$pageTitle = 'Analytics';
$activeNav = 'analytics';
require __DIR__ . '/_layout_head.php';
?>

<div class="card" style="margin-bottom:16px;">
    <form method="get" class="form-grid">
        <div>
            <label class="field-label">Range</label>
            <select name="range" onchange="this.form.submit()">
                <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom range</option>
            </select>
        </div>
        <?php if ($range === 'custom'): ?>
        <div>
            <label class="field-label">From</label>
            <input type="date" name="from" value="<?= admin_escape($fromDate) ?>">
        </div>
        <div>
            <label class="field-label">To</label>
            <input type="date" name="to" value="<?= admin_escape($toDate) ?>">
        </div>
        <?php endif; ?>
        <div>
            <label class="field-label">State</label>
            <select name="state_id" onchange="this.form.submit()">
                <option value="0">All</option>
                <?php foreach ($stateOptions as $id => $s): ?>
                    <option value="<?= (int) $id ?>" <?= $fStateId === (int) $id ? 'selected' : '' ?>><?= admin_escape($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">District</label>
            <select name="district_id">
                <option value="0">All</option>
                <?php foreach ($districtOptions as $id => $d): ?>
                    <option value="<?= (int) $id ?>" <?= $fDistrictId === (int) $id ? 'selected' : '' ?>><?= admin_escape($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">City/Village or Area</label>
            <select name="area_id">
                <option value="0">All</option>
                <?php foreach ($areaFilterOptions as $id => $a): ?>
                    <option value="<?= (int) $id ?>" <?= $fAreaId === (int) $id ? 'selected' : '' ?>>
                        <?= admin_escape(admin_area_breadcrumb_compact($a, $areaNodeById)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">Restaurant</label>
            <select name="restaurant_id">
                <option value="0">All</option>
                <?php foreach ($restaurantOptions as $ro): ?>
                    <option value="<?= (int) $ro['id'] ?>" <?= $fRestaurantId === (int) $ro['id'] ? 'selected' : '' ?>><?= admin_escape($ro['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">Category</label>
            <select name="category_id">
                <option value="0">All</option>
                <?php foreach ($categoryOptions as $co): ?>
                    <option value="<?= (int) $co['id'] ?>" <?= $fCategoryId === (int) $co['id'] ? 'selected' : '' ?>><?= admin_escape($co['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary" data-no-loading>Apply</button>
            <a href="analytics.php" class="btn btn-outline">Clear</a>
            <?php if ($canExport): ?>
                <a class="btn btn-outline" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">Export CSV</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="muted" style="margin-top:8px;">Showing <?= admin_escape($fromDate) ?> to <?= admin_escape($toDate) ?>. LTV below is lifetime, not limited to this range. State/District/Restaurant/Category filters narrow every section except New Customers and Avg LTV (both deliberately account-wide — see this page's own comments).</p>
</div>

<div class="card">
    <h2>Orders</h2>
    <div class="grid">
        <div class="card stat"><div class="value"><?= (int) $orders['total'] ?></div><div class="label">Total</div></div>
        <div class="card stat"><div class="value"><?= (int) ($orders['completed'] ?? 0) ?></div><div class="label">Completed</div></div>
        <div class="card stat"><div class="value"><?= (int) ($orders['cancelled'] ?? 0) ?></div><div class="label">Cancelled</div></div>
        <div class="card stat"><div class="value"><?= (int) ($orders['rejected'] ?? 0) ?></div><div class="label">Rejected</div></div>
        <div class="card stat"><div class="value"><?= (int) ($orders['failed'] ?? 0) ?></div><div class="label">Failed</div></div>
    </div>
</div>

<div class="card">
    <h2>Revenue</h2>
    <div class="grid">
        <div class="card stat"><div class="value">₹<?= number_format($gmv, 2) ?></div><div class="label">GMV</div></div>
        <div class="card stat"><div class="value">₹<?= number_format((float) $revenue['platform_revenue'], 2) ?></div><div class="label">Platform Revenue</div></div>
        <div class="card stat"><div class="value">₹<?= number_format((float) $revenue['commission'], 2) ?></div><div class="label">Commission</div></div>
        <div class="card stat"><div class="value">₹<?= number_format((float) $revenue['discounts'], 2) ?></div><div class="label">Discounts Given</div></div>
        <div class="card stat"><div class="value">₹<?= number_format($refundsOut, 2) ?></div><div class="label">Refunds</div></div>
    </div>
</div>

<div class="card">
    <h2>Restaurants</h2>
    <p class="muted">Acceptance rate in range: <?= $acceptanceRate !== null ? admin_escape((string) $acceptanceRate) . '%' : 'no accept/reject decisions yet' ?></p>
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:240px;">
            <div class="section-title">Top 5 by revenue</div>
            <?php if (empty($topRestaurants)): ?>
                <p class="muted">No orders in range.</p>
            <?php else: ?>
                <table>
                    <tr><th>Restaurant</th><th>Orders</th><th>Revenue</th></tr>
                    <?php foreach ($topRestaurants as $rr): ?>
                    <tr><td><?= admin_escape($rr['name']) ?></td><td><?= (int) $rr['order_count'] ?></td><td>₹<?= number_format((float) $rr['revenue'], 2) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        <div style="flex:1; min-width:240px;">
            <div class="section-title">Bottom 5 by revenue (with ≥1 order)</div>
            <?php if (empty($bottomRestaurants)): ?>
                <p class="muted">No orders in range.</p>
            <?php else: ?>
                <table>
                    <tr><th>Restaurant</th><th>Orders</th><th>Revenue</th></tr>
                    <?php foreach ($bottomRestaurants as $rr): ?>
                    <tr><td><?= admin_escape($rr['name']) ?></td><td><?= (int) $rr['order_count'] ?></td><td>₹<?= number_format((float) $rr['revenue'], 2) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h2>Items</h2>
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
        <div style="flex:1; min-width:200px;">
            <div class="section-title">Top-selling</div>
            <?php if (empty($topSellingItems)): ?><p class="muted">No data.</p><?php else: ?>
                <?php foreach ($topSellingItems as $it): ?>
                    <?= admin_escape($it['name']) ?> — <?= (int) $it['qty'] ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="flex:1; min-width:200px;">
            <div class="section-title">Most profitable</div>
            <?php if (empty($mostProfitableItems)): ?><p class="muted">No data.</p><?php else: ?>
                <?php foreach ($mostProfitableItems as $it): ?>
                    <?= admin_escape($it['name']) ?> — ₹<?= number_format((float) $it['total'], 2) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div style="flex:1; min-width:200px;">
            <div class="section-title">Most cancelled</div>
            <?php if (empty($mostCancelledItems)): ?><p class="muted">No data.</p><?php else: ?>
                <?php foreach ($mostCancelledItems as $it): ?>
                    <?= admin_escape($it['name']) ?> — <?= (int) $it['qty'] ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <h2>Customers</h2>
    <div class="grid">
        <div class="card stat"><div class="value"><?= $newCustomerCount ?></div><div class="label">New</div></div>
        <div class="card stat"><div class="value"><?= $returningCount ?></div><div class="label">Returning</div></div>
        <div class="card stat"><div class="value">₹<?= number_format($aov, 2) ?></div><div class="label">Avg Order Value</div></div>
        <div class="card stat"><div class="value">₹<?= number_format($avgLtv, 2) ?></div><div class="label">Avg Customer LTV (lifetime)</div></div>
    </div>
</div>

<div class="card">
    <h2>Areas</h2>
    <?php if (empty($areaRows)): ?>
        <p class="muted">No orders in range.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Area</th><th>Orders</th><th>Revenue</th><th>Customers</th><th>Restaurants</th></tr>
        <?php foreach ($areaRows as $ar): ?>
        <tr>
            <td>
                <?php if ($ar['area_id'] && isset($areaNodeById[(int) $ar['area_id']])): ?>
                    <?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $ar['area_id']], $areaNodeById)) ?>
                <?php else: ?>
                    <span class="muted">Unresolved / no address</span>
                <?php endif; ?>
            </td>
            <td><?= (int) $ar['order_count'] ?></td>
            <td>₹<?= number_format((float) $ar['revenue'], 2) ?></td>
            <td><?= (int) $ar['customer_count'] ?></td>
            <td><?= (int) $ar['restaurant_count'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Riders</h2>
    <p class="muted">Delivered orders only, in range. Live GPS location isn't shown here — see Order Control for a specific order's tracking point.</p>
    <?php if (empty($riderRows)): ?>
        <p class="muted">No deliveries in range.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Rider</th><th>Delivered orders</th><th>Revenue</th><th>Avg delivery time</th></tr>
        <?php foreach ($riderRows as $rr): ?>
        <tr>
            <td><?= admin_escape($rr['name']) ?></td>
            <td><?= (int) $rr['delivered_count'] ?></td>
            <td>₹<?= number_format((float) $rr['revenue'], 2) ?></td>
            <td><?= round((float) $rr['avg_delivery_minutes'], 1) ?> min</td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Payments</h2>
    <div class="grid">
        <?php foreach (['upi' => 'UPI', 'cod' => 'COD'] as $methodKey => $methodLabel): ?>
            <?php $pr = $paymentByMethod[$methodKey] ?? null; ?>
            <div class="card stat">
                <div class="value">₹<?= number_format((float) ($pr['gmv'] ?? 0), 2) ?></div>
                <div class="label"><?= $methodLabel ?> · <?= (int) ($pr['order_count'] ?? 0) ?> orders<?php if ((int) ($pr['failed_count'] ?? 0) > 0): ?>, <?= (int) $pr['failed_count'] ?> failed<?php endif; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h2>Coupons</h2>
    <?php if (empty($couponRows)): ?>
        <p class="muted">No coupon usage in range.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Code</th><th>Uses</th><th>Unique customers</th><th>Total discount</th></tr>
        <?php foreach ($couponRows as $cr): ?>
        <tr>
            <td><?= admin_escape($cr['code']) ?></td>
            <td><?= (int) $cr['uses'] ?></td>
            <td><?= (int) $cr['unique_customers'] ?></td>
            <td>₹<?= number_format((float) $cr['total_discount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
