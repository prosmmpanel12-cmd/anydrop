<?php
/**
 * Anydrop — Admin Web UI: Order Control (doc 21 §4.6; docs/41 plan).
 *
 * "Admin should see every order" — a single searchable/filterable
 * table across every restaurant/customer/rider, with a full detail
 * view per order (customer, restaurant, items, pricing, payment,
 * timeline, rider, location, OTP, cancellation, refund) and one
 * heavily-gated override action (Force-Cancel).
 *
 * List/filter/pagination follows the same shape as customers.php
 * (dynamic $where/$params, LIMIT/OFFSET, http_build_query pagination
 * links). Detail view is a per-row <dialog> modal, same pattern
 * customers.php already uses, reusing format_order() from lib/orders.php
 * (items/status_history/refund) rather than re-deriving those shapes
 * here — this page also does its own extra joins (customer/restaurant/
 * rider/address/area names, latest rider_locations point) that
 * format_order() doesn't need for its API-response job.
 *
 * Refund LIFECYCLE actions (Approve/Reject/Mark Processing/Mark
 * Refunded) are NOT here — this page only reads the linked `refunds`
 * row read-only; those actions stay on refunds.php, which already owns
 * that whole state machine. Duplicating them here would split one
 * flow across two pages.
 *
 * Gated: `orders_view` for the list/detail (both already existed since
 * migration 29, just unused until now); `orders_manage` for the
 * Force-Cancel override action.
 *
 * STATUS: 🟡 BUILT 2026-08-26 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page (no PHP CLI or
 * live DB here). See docs/41's own verification checklist.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/orders.php';
require_once __DIR__ . '/../lib/refunds.php';

$admin = admin_require_login();
admin_require_permission($admin, 'orders_view');
$canManage = admin_has_permission((int) $admin['id'], 'orders_manage');
$db = Database::get();

// Non-terminal statuses — Force-Cancel is only offered from these (see
// docs/41 "Force-Cancel" section: there's no "un-deliver" action, and
// the other four states are already terminal).
$nonTerminalStatuses = ['pending', 'accepted', 'preparing', 'ready', 'rider_assigned', 'picked_up', 'out_for_delivery'];

$statusLabels = [
    'pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected',
    'preparing' => 'Preparing', 'ready' => 'Ready', 'rider_assigned' => 'Rider Assigned',
    'picked_up' => 'Picked Up', 'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered',
    'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed', 'expired' => 'Expired',
];

$flash = null;
$flashType = 'success';

// ---------- POST: Force-Cancel override ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'orders_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } elseif (($_POST['form_action'] ?? '') === 'force_cancel') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            $flash = 'Order not found.';
            $flashType = 'error';
        } elseif (!in_array($order['status'], $nonTerminalStatuses, true)) {
            $flash = 'This order is already in a final state (' . ($statusLabels[$order['status']] ?? $order['status']) . ') and can\'t be force-cancelled.';
            $flashType = 'error';
        } elseif ($reason === '') {
            $flash = 'A reason is required to force-cancel an order.';
            $flashType = 'error';
        } else {
            $fromStatus = $order['status'];
            $db->beginTransaction();
            $db->prepare(
                "UPDATE orders SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = :r WHERE id = :id"
            )->execute(['r' => $reason, 'id' => $orderId]);
            insert_status_history($db, $orderId, 'cancelled', 'admin', (int) $admin['id'], $reason);

            // Same "don't leave paid money unresolved" rule cancel.php/
            // orders-reject.php already enforce — see those files' own
            // kdoc. get_refund_for_order() guard avoids a duplicate-row
            // exception if a refund somehow already exists for this order.
            if ($order['payment_status'] === 'paid' && !get_refund_for_order($db, $orderId)) {
                create_refund_request($db, $order, 'Force-cancelled by admin: ' . $reason, 'admin');
            }
            $db->commit();

            write_audit_log('admin', $admin['id'], 'order_force_cancelled', [
                'order_id' => $orderId,
                'order_code' => $order['order_code'],
                'from_status' => $fromStatus,
                'reason' => $reason,
            ]);
            $flash = 'Order #' . admin_escape($order['order_code']) . ' force-cancelled.';
        }
    }
}

// ---------- Filters ----------
$fOrderCode = trim($_GET['order_code'] ?? '');
$fCustomer = trim($_GET['customer'] ?? '');
$fRestaurant = trim($_GET['restaurant'] ?? '');
$fRider = trim($_GET['rider'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fPayment = $_GET['payment'] ?? ''; // payment_method:payment_status style handled separately below
$fPaymentMethod = $_GET['payment_method'] ?? '';
$fPaymentStatus = $_GET['payment_status'] ?? '';
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo = trim($_GET['date_to'] ?? '');
$fAreaId = (int) ($_GET['area_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];

if ($fOrderCode !== '') {
    $where[] = 'o.order_code LIKE :order_code';
    $params['order_code'] = '%' . $fOrderCode . '%';
}
if ($fCustomer !== '') {
    $where[] = '(c.name LIKE :customer OR c.mobile LIKE :customer)';
    $params['customer'] = '%' . $fCustomer . '%';
}
if ($fRestaurant !== '') {
    $where[] = 'r.name LIKE :restaurant';
    $params['restaurant'] = '%' . $fRestaurant . '%';
}
if ($fRider !== '') {
    $where[] = 'rd.name LIKE :rider';
    $params['rider'] = '%' . $fRider . '%';
}
if ($fStatus !== '' && isset($statusLabels[$fStatus])) {
    $where[] = 'o.status = :status';
    $params['status'] = $fStatus;
}
if (in_array($fPaymentMethod, ['upi', 'cod'], true)) {
    $where[] = 'o.payment_method = :pm';
    $params['pm'] = $fPaymentMethod;
}
if (in_array($fPaymentStatus, ['pending', 'paid', 'failed', 'refunded'], true)) {
    $where[] = 'o.payment_status = :ps';
    $params['ps'] = $fPaymentStatus;
}
if ($fDateFrom !== '') {
    $where[] = 'o.created_at >= :date_from';
    $params['date_from'] = $fDateFrom . ' 00:00:00';
}
if ($fDateTo !== '') {
    $where[] = 'o.created_at <= :date_to';
    $params['date_to'] = $fDateTo . ' 23:59:59';
}
if ($fAreaId > 0) {
    $where[] = 'ca.area_id = :area_id';
    $params['area_id'] = $fAreaId;
}
$whereSql = implode(' AND ', $where);

// Area is resolved via delivery_address_id -> customer_addresses.area_id
// (same join path customers.php already reads for its own address
// breadcrumbs) — orders has no area_id column of its own.
$fromSql = "FROM orders o
    JOIN customers c ON c.id = o.customer_id
    JOIN restaurants r ON r.id = o.restaurant_id
    LEFT JOIN riders rd ON rd.id = o.rider_id
    LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id";

$countStmt = $db->prepare("SELECT COUNT(*) AS c {$fromSql} WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT o.id, o.order_code, o.status, o.payment_method, o.payment_status, o.grand_total, o.created_at,
            c.name AS customer_name, c.mobile AS customer_mobile,
            r.name AS restaurant_name,
            rd.name AS rider_name,
            ca.area_id AS area_id
     {$fromSql}
     WHERE {$whereSql}
     ORDER BY o.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$orders = $listStmt->fetchAll();

// service_areas map, for breadcrumb rendering on each row — same
// admin_area_breadcrumb_compact() helper customers.php/restaurants.php
// already use. Kept unfiltered (every level, including State/District)
// because a breadcrumb needs the full ancestor chain to render.
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}

// Filter dropdown options — City/Village + Area levels only, same
// restriction as restaurants.php's $areaOptions. An order's area_id is
// always the deepest node customer_addresses.area_id resolved to
// (resolve_service_area() picks the nearest match, and City/Village or
// Area nodes are what actually carry center_lat/center_lng/radius_km in
// practice), so a State or District node can never actually match an
// order — listing them just adds dead options to the dropdown.
$areaFilterOptions = array_filter($areaNodeById, fn($a) => in_array($a['level'], ['city_village', 'area'], true));

// Full detail (customer/restaurant/rider contact info, items, pricing,
// timeline, latest rider location, refund) for whichever row the admin
// opens — fetched up-front for this page's rows only, same "cheap at
// 20/page" approach customers.php uses for its own modals.
$detailById = [];
if (!empty($orders)) {
    foreach ($orders as $row) {
        $orderId = (int) $row['id'];
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $fullOrder = $stmt->fetch();
        if (!$fullOrder) {
            continue;
        }

        $formatted = format_order($db, $fullOrder);

        $custStmt = $db->prepare('SELECT name, email, mobile FROM customers WHERE id = :id LIMIT 1');
        $custStmt->execute(['id' => $fullOrder['customer_id']]);
        $customer = $custStmt->fetch() ?: null;

        $restStmt = $db->prepare('SELECT name, owner_name, owner_mobile FROM restaurants WHERE id = :id LIMIT 1');
        $restStmt->execute(['id' => $fullOrder['restaurant_id']]);
        $restaurant = $restStmt->fetch() ?: null;

        $rider = null;
        if ($fullOrder['rider_id']) {
            $riderStmt = $db->prepare('SELECT name, mobile FROM riders WHERE id = :id LIMIT 1');
            $riderStmt->execute(['id' => $fullOrder['rider_id']]);
            $rider = $riderStmt->fetch() ?: null;
        }

        $address = null;
        if ($fullOrder['delivery_address_id']) {
            $addrStmt = $db->prepare('SELECT full_address, area_id FROM customer_addresses WHERE id = :id LIMIT 1');
            $addrStmt->execute(['id' => $fullOrder['delivery_address_id']]);
            $address = $addrStmt->fetch() ?: null;
        }

        // Latest known point for this order — a static review-page
        // snapshot, not LiveTrackingActivity's moving stream (see
        // docs/41's own design note on why).
        $lastLoc = null;
        if ($fullOrder['rider_id']) {
            $locStmt = $db->prepare(
                'SELECT latitude, longitude, recorded_at FROM rider_locations
                 WHERE order_id = :oid ORDER BY recorded_at DESC LIMIT 1'
            );
            $locStmt->execute(['oid' => $orderId]);
            $lastLoc = $locStmt->fetch() ?: null;
        }

        $detailById[$orderId] = [
            'order' => $fullOrder,
            'formatted' => $formatted,
            'customer' => $customer,
            'restaurant' => $restaurant,
            'rider' => $rider,
            'address' => $address,
            'last_location' => $lastLoc,
        ];
    }
}

$csrf = admin_csrf_token();
$pageTitle = 'Order Control (' . $totalCount . ')';
$activeNav = 'orders';
require __DIR__ . '/_layout_head.php';
?>

<div class="card" style="margin-bottom:16px;">
    <form method="get" class="form-grid">
        <div>
            <label class="field-label">Order ID</label>
            <input type="text" name="order_code" value="<?= admin_escape($fOrderCode) ?>" placeholder="e.g. AD1023">
        </div>
        <div>
            <label class="field-label">Customer</label>
            <input type="text" name="customer" value="<?= admin_escape($fCustomer) ?>" placeholder="Name or mobile">
        </div>
        <div>
            <label class="field-label">Restaurant</label>
            <input type="text" name="restaurant" value="<?= admin_escape($fRestaurant) ?>" placeholder="Restaurant name">
        </div>
        <div>
            <label class="field-label">Rider</label>
            <input type="text" name="rider" value="<?= admin_escape($fRider) ?>" placeholder="Rider name">
        </div>
        <div>
            <label class="field-label">Status</label>
            <select name="status">
                <option value="">All</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">Payment method</label>
            <select name="payment_method">
                <option value="">All</option>
                <option value="upi" <?= $fPaymentMethod === 'upi' ? 'selected' : '' ?>>UPI</option>
                <option value="cod" <?= $fPaymentMethod === 'cod' ? 'selected' : '' ?>>COD</option>
            </select>
        </div>
        <div>
            <label class="field-label">Payment status</label>
            <select name="payment_status">
                <option value="">All</option>
                <option value="pending" <?= $fPaymentStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="paid" <?= $fPaymentStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="failed" <?= $fPaymentStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                <option value="refunded" <?= $fPaymentStatus === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>
        </div>
        <div>
            <label class="field-label">Area</label>
            <select name="area_id">
                <option value="0">All</option>
                <?php foreach ($areaFilterOptions as $areaId => $areaRow): ?>
                    <option value="<?= (int) $areaId ?>" <?= $fAreaId === (int) $areaId ? 'selected' : '' ?>>
                        <?= admin_escape(admin_area_breadcrumb_compact($areaRow, $areaNodeById)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">From date</label>
            <input type="date" name="date_from" value="<?= admin_escape($fDateFrom) ?>">
        </div>
        <div>
            <label class="field-label">To date</label>
            <input type="date" name="date_to" value="<?= admin_escape($fDateTo) ?>">
        </div>
        <div>
            <button type="submit" class="btn btn-primary" data-no-loading>Filter</button>
            <a href="orders.php" class="btn btn-outline">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <?php if (empty($orders)): ?>
        <p class="muted">No orders match these filters.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr>
            <th>Order</th><th>Customer</th><th>Restaurant</th><th>Rider</th>
            <th>Status</th><th>Payment</th><th>Total</th><th>Placed</th><th></th>
        </tr>
        <?php foreach ($orders as $o): ?>
        <tr>
            <td><?= admin_escape($o['order_code']) ?></td>
            <td><?= admin_escape($o['customer_name'] ?: $o['customer_mobile'] ?: '—') ?></td>
            <td><?= admin_escape($o['restaurant_name']) ?></td>
            <td><?= admin_escape($o['rider_name'] ?? '—') ?></td>
            <td><span class="badge <?= in_array($o['status'], ['delivered'], true) ? 'active' : (in_array($o['status'], ['cancelled', 'rejected', 'failed', 'expired'], true) ? 'inactive' : '') ?>">
                <?= admin_escape($statusLabels[$o['status']] ?? $o['status']) ?>
            </span></td>
            <td><?= strtoupper($o['payment_method']) ?> · <?= ucfirst($o['payment_status']) ?></td>
            <td>₹<?= number_format((float) $o['grand_total'], 2) ?></td>
            <td class="muted"><?= admin_escape($o['created_at']) ?></td>
            <td><button type="button" class="btn btn-outline" data-open-dialog="order-<?= (int) $o['id'] ?>">View</button></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="row-actions" style="margin-top:14px; justify-content:center;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn btn-outline <?= $p === $page ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php foreach ($orders as $o):
    $orderId = (int) $o['id'];
    $d = $detailById[$orderId] ?? null;
    if (!$d) { continue; }
    $ord = $d['order'];
    $fmt = $d['formatted'];
?>
<dialog class="modal" id="order-<?= $orderId ?>">
    <div class="modal-body">
        <h3 class="modal-title">Order #<?= admin_escape($ord['order_code']) ?></h3>
        <p class="modal-text">
            <span class="badge <?= $ord['status'] === 'delivered' ? 'active' : '' ?>"><?= admin_escape($statusLabels[$ord['status']] ?? $ord['status']) ?></span>
            · Placed <?= admin_escape($ord['created_at']) ?>
        </p>

        <div class="section-title" style="margin-top:10px;">Customer</div>
        <div class="muted">
            <?= admin_escape($d['customer']['name'] ?? '(no name)') ?> ·
            <?= admin_escape($d['customer']['mobile'] ?? 'no mobile') ?>
            <?php if ($d['customer']['email'] ?? null): ?> · <?= admin_escape($d['customer']['email']) ?><?php endif; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Restaurant</div>
        <div class="muted">
            <?= admin_escape($d['restaurant']['name'] ?? '—') ?>
            <?php if ($d['restaurant']['owner_mobile'] ?? null): ?> · Owner: <?= admin_escape($d['restaurant']['owner_mobile']) ?><?php endif; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Delivery address</div>
        <div class="muted">
            <?php if ($d['address']): ?>
                <?= admin_escape($d['address']['full_address']) ?>
                <?php if ($d['address']['area_id'] && isset($areaNodeById[(int) $d['address']['area_id']])): ?>
                    — <?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $d['address']['area_id']], $areaNodeById)) ?>
                <?php endif; ?>
            <?php else: ?>
                No saved address on file.
            <?php endif; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Rider</div>
        <div class="muted">
            <?php if ($d['rider']): ?>
                <?= admin_escape($d['rider']['name']) ?> · <?= admin_escape($d['rider']['mobile'] ?? '—') ?>
                <?php if ($d['last_location']): ?>
                    <br>Last known location: <?= number_format((float) $d['last_location']['latitude'], 5) ?>, <?= number_format((float) $d['last_location']['longitude'], 5) ?>
                    (<?= admin_escape($d['last_location']['recorded_at']) ?>)
                <?php else: ?>
                    <br>No location pings recorded for this order.
                <?php endif; ?>
            <?php else: ?>
                Not yet assigned.
            <?php endif; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Items</div>
        <div class="muted" style="line-height:1.7;">
            <?php foreach ($fmt['items'] as $item): ?>
                <?= (int) $item['quantity'] ?> × <?= admin_escape($item['name']) ?><?= $item['variant_name'] ? ' (' . admin_escape($item['variant_name']) . ')' : '' ?>
                — ₹<?= number_format((float) $item['subtotal'], 2) ?><br>
            <?php endforeach; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Pricing</div>
        <div class="muted" style="line-height:1.7;">
            Item total: ₹<?= number_format((float) $ord['item_total'], 2) ?><br>
            Delivery charge: ₹<?= number_format((float) $ord['delivery_charge'], 2) ?><br>
            Platform fee: ₹<?= number_format((float) $ord['platform_fee'], 2) ?><br>
            Packing charge: ₹<?= number_format((float) $ord['packing_charge'], 2) ?><br>
            Tax: ₹<?= number_format((float) $ord['tax_amount'], 2) ?><br>
            Discount: −₹<?= number_format((float) $ord['discount_amount'], 2) ?><br>
            <strong>Grand total: ₹<?= number_format((float) $ord['grand_total'], 2) ?></strong>
        </div>

        <div class="section-title" style="margin-top:10px;">Payment</div>
        <div class="muted">
            <?= strtoupper($ord['payment_method']) ?> · <?= ucfirst($ord['payment_status']) ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Delivery OTP</div>
        <div class="muted">
            <?php if ($ord['delivery_otp']): ?>
                <span class="otp-masked" data-otp="<?= admin_escape($ord['delivery_otp']) ?>" style="cursor:pointer;" title="Click to reveal">••••</span>
                <?= $ord['otp_verified_at'] ? ' · Verified at ' . admin_escape($ord['otp_verified_at']) : ' · Not yet verified' ?>
                <?php if ((int) $ord['otp_attempts'] > 0): ?> · <?= (int) $ord['otp_attempts'] ?> attempt(s)<?php endif; ?>
            <?php else: ?>
                No OTP generated for this order.
            <?php endif; ?>
        </div>

        <div class="section-title" style="margin-top:10px;">Timeline</div>
        <div class="muted" style="line-height:1.7;">
            <?php foreach ($fmt['status_history'] as $h): ?>
                <?= admin_escape($statusLabels[$h['status']] ?? $h['status']) ?>
                — by <?= admin_escape($h['changed_by_type']) ?>
                <?= $h['note'] ? ' (' . admin_escape($h['note']) . ')' : '' ?>
                — <?= admin_escape($h['created_at']) ?><br>
            <?php endforeach; ?>
        </div>

        <?php if ($ord['status'] === 'cancelled' || $ord['status'] === 'rejected'): ?>
        <div class="section-title" style="margin-top:10px;">Cancellation</div>
        <div class="muted">
            <?= admin_escape($ord['cancellation_reason'] ?? '—') ?>
            <?= $ord['cancelled_at'] ? ' (' . admin_escape($ord['cancelled_at']) . ')' : '' ?>
        </div>
        <?php endif; ?>

        <?php if ($fmt['refund']): ?>
        <div class="section-title" style="margin-top:10px;">Refund</div>
        <div class="muted">
            ₹<?= number_format((float) $fmt['refund']['amount'], 2) ?> —
            <?= admin_escape($statusLabels[$fmt['refund']['status']] ?? $fmt['refund']['status']) ?>
            · <?= admin_escape($fmt['refund']['reason']) ?>
            <br><span style="font-size:12px;">Manage this refund's status on the Refunds page.</span>
        </div>
        <?php endif; ?>

        <?php if ($canManage && in_array($ord['status'], $nonTerminalStatuses, true)): ?>
        <form method="post" style="margin-top:16px;" onsubmit="return promptForceCancelReason(this);">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="order_id" value="<?= $orderId ?>">
            <input type="hidden" name="form_action" value="force_cancel">
            <input type="hidden" name="reason" class="force-cancel-reason-field">
            <button type="submit" class="btn btn-outline danger" style="width:100%;">Force-Cancel order</button>
        </form>
        <?php endif; ?>

        <div class="modal-actions" style="margin-top:14px;">
            <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
        </div>
    </div>
</dialog>
<?php endforeach; ?>

<script>
function promptForceCancelReason(form) {
    var reason = prompt('Reason for force-cancelling this order (visible in the audit log):');
    if (!reason || !reason.trim()) { return false; }
    form.querySelector('.force-cancel-reason-field').value = reason.trim();
    return confirm('Force-cancel this order? This cannot be undone from here, and will queue a refund automatically if the order was already paid.');
}
document.querySelectorAll('.otp-masked').forEach(function (el) {
    el.addEventListener('click', function () {
        el.textContent = el.getAttribute('data-otp');
    });
});
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
