<?php
/**
 * Anydrop — Admin Web UI: Order Control (doc 21 §4.6; docs/41 plan).
 *
 * "Admin should see every order" — a single searchable/filterable
 * table across every restaurant/customer/rider, with a full detail
 * view per order (customer, restaurant, items, pricing, payment,
 * timeline, rider, location, OTP, cancellation, refund) and three
 * heavily-gated override actions: Force-Cancel, and (docs/111 §16
 * re-audit gap 1) Reset OTP attempts / Force-Deliver for an order
 * that's hit otp_max_attempts and would otherwise be stuck at
 * out_for_delivery forever with no resolution path.
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
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/ledger.php';
require_once __DIR__ . '/../lib/rider_ledger.php';
require_once __DIR__ . '/../lib/rider_earnings.php';

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
$otpMaxAttempts = (int) get_setting('otp_max_attempts', 3);

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

            // Rider_Deep_Plan.md §23, Order category ("Admin
            // cancellation") / Assignment category ("Assignment
            // cancelled") — a force-cancel can land on an order in any
            // non-terminal state, which includes both "a rider is
            // already assigned and working it" and "a rider has an
            // open, unresponded offer out for it" (dispatch only starts
            // once the order hits 'ready', so these are the only two
            // rider-facing possibilities; 'pending'/'accepted'/
            // 'preparing' have no rider involved yet). Exactly one of
            // these two branches can apply, never both — an order only
            // ever has either an assigned rider_id or a live 'offered'
            // row, not both at once (see orders-accept.php's own
            // transaction for why).
            //
            // Notification is deferred until after $db->commit() below
            // (doc 106 fix) rather than fired here: create_notification()
            // sends a live outbound FCM push as a side effect, and
            // Database::get() is a per-request PDO singleton, so a push
            // fired here would go out to the rider's phone even if a
            // later statement in this same transaction (the refund
            // insert) throws and everything up to here rolls back —
            // "order cancelled" would reach the rider for an order that,
            // in the DB, was never actually cancelled. $notifyRiderId/
            // $notifyPayload capture what to send without sending it yet.
            $notifyRiderId = null;
            $notifyTitle = null;
            $notifyBody = null;
            $notifyPayload = null;
            if ($order['rider_id'] !== null) {
                $notifyRiderId = (int) $order['rider_id'];
                $notifyTitle = 'Order cancelled';
                $notifyBody = "Order {$order['order_code']} was cancelled by Anydrop support. No further action is needed.";
                $notifyPayload = ['order_id' => $orderId, 'screen' => 'order_status'];
            } else {
                $openOffer = $db->prepare(
                    "SELECT rider_id FROM rider_order_assignments WHERE order_id = :id AND status = 'offered' LIMIT 1"
                );
                $openOffer->execute(['id' => $orderId]);
                if ($offerRider = $openOffer->fetch()) {
                    $db->prepare(
                        "UPDATE rider_order_assignments SET status = 'cancelled', responded_at = NOW() WHERE order_id = :id AND status = 'offered'"
                    )->execute(['id' => $orderId]);
                    $notifyRiderId = (int) $offerRider['rider_id'];
                    $notifyTitle = 'Delivery offer cancelled';
                    $notifyBody = "Order {$order['order_code']} is no longer available — it was cancelled before pickup.";
                    $notifyPayload = ['order_id' => $orderId, 'screen' => 'order_offer'];
                }
            }

            // Same "don't leave paid money unresolved" rule cancel.php/
            // orders-reject.php already enforce — see those files' own
            // kdoc. get_refund_for_order() guard avoids a duplicate-row
            // exception if a refund somehow already exists for this order.
            if ($order['payment_status'] === 'paid' && !get_refund_for_order($db, $orderId)) {
                create_refund_request($db, $order, 'Force-cancelled by admin: ' . $reason, 'admin');
            }
            $db->commit();

            // Fired only after a successful commit — see comment above.
            if ($notifyRiderId !== null) {
                create_notification('rider', $notifyRiderId, $notifyTitle, $notifyBody, 'order', $notifyPayload);
            }

            write_audit_log('admin', $admin['id'], 'order_force_cancelled', [
                'order_id' => $orderId,
                'order_code' => $order['order_code'],
                'from_status' => $fromStatus,
                'reason' => $reason,
            ]);
            $flash = 'Order #' . admin_escape($order['order_code']) . ' force-cancelled.';
        }
    } elseif (($_POST['form_action'] ?? '') === 'otp_reset_attempts') {
        // docs/111 re-audit gap 1 — an order that hit otp_max_attempts
        // was previously stuck at out_for_delivery forever (deep-plan
        // §16's "never change order status" on a wrong OTP applies to
        // the rider-facing endpoint, not to a deliberate admin action).
        // This is the "customer re-reads/gives the right code, rider
        // just fat-fingered it" resolution: give the rider fresh
        // attempts without touching status/payment/ledger at all —
        // orders-deliver.php's own OTP check runs exactly as before on
        // the rider's next real attempt, so no verification logic is
        // duplicated here.
        $orderId = (int) ($_POST['order_id'] ?? 0);

        $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        $maxAttempts = (int) get_setting('otp_max_attempts', 3);

        if (!$order) {
            $flash = 'Order not found.';
            $flashType = 'error';
        } elseif ($order['status'] !== 'out_for_delivery' || $order['delivery_otp'] === null) {
            $flash = 'This order isn\'t awaiting OTP delivery, so there\'s nothing to reset.';
            $flashType = 'error';
        } elseif ((int) $order['otp_attempts'] < $maxAttempts) {
            $flash = 'This order isn\'t OTP-locked yet — nothing to reset.';
            $flashType = 'error';
        } else {
            $db->prepare(
                "UPDATE orders SET otp_attempts = 0 WHERE id = :id AND status = 'out_for_delivery'"
            )->execute(['id' => $orderId]);

            write_audit_log('admin', $admin['id'], 'order_otp_attempts_reset', [
                'order_id' => $orderId,
                'order_code' => $order['order_code'],
                'rider_id' => $order['rider_id'] !== null ? (int) $order['rider_id'] : null,
                'previous_attempts' => (int) $order['otp_attempts'],
            ]);
            $flash = 'OTP attempts reset for order #' . admin_escape($order['order_code']) . ' — the rider can try again.';
        }
    } elseif (($_POST['form_action'] ?? '') === 'force_deliver') {
        // docs/111 re-audit gap 1, other half — the "customer genuinely
        // handed the code over but it's unrecoverable now (lost/can't
        // reread it), support has otherwise confirmed the delivery
        // happened" resolution: mark delivered without ever checking
        // the OTP. Runs the SAME downstream effects
        // orders-deliver.php's own success path does (status flip, COD
        // ledger entry, rider earning entry, customer notification) so
        // money/notifications aren't silently skipped just because this
        // took the admin path instead of the rider app — only the OTP
        // check itself and otp_verified_at are skipped/left null, and
        // the reason is recorded on the status-history row so it's
        // clearly distinguishable from a normal rider-confirmed
        // delivery in the order's own timeline.
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();

        $maxAttempts = (int) get_setting('otp_max_attempts', 3);

        if (!$order) {
            $flash = 'Order not found.';
            $flashType = 'error';
        } elseif ($order['status'] !== 'out_for_delivery') {
            $flash = 'This order is already in a final or earlier state (' . ($statusLabels[$order['status']] ?? $order['status']) . ') and can\'t be force-delivered from here.';
            $flashType = 'error';
        } elseif ((int) $order['otp_attempts'] < $maxAttempts) {
            $flash = 'This order isn\'t OTP-locked — use the normal rider app flow, or reset attempts instead of bypassing verification.';
            $flashType = 'error';
        } elseif ($reason === '') {
            $flash = 'A reason is required to force-deliver an order without OTP verification.';
            $flashType = 'error';
        } else {
            $db->beginTransaction();

            $isCod = $order['payment_method'] === 'cod';
            $upd = $db->prepare(
                "UPDATE orders SET status = 'delivered', delivered_at = NOW()"
                . ($isCod ? ", payment_status = 'paid'" : "")
                . " WHERE id = :id AND status = 'out_for_delivery'"
            );
            $upd->execute(['id' => $orderId]);

            if ($upd->rowCount() !== 1) {
                $db->rollBack();
                $flash = 'This order changed state before the override could be applied — please re-check it.';
                $flashType = 'error';
            } else {
                insert_status_history($db, $orderId, 'delivered', 'admin', (int) $admin['id'], 'Force-delivered (OTP bypassed): ' . $reason);

                if ($isCod) {
                    record_cod_order_ledger_entry($db, $order);
                    record_rider_cod_collected($db, $order);
                }
                $earningResult = record_rider_delivery_earning($db, $order);

                $db->commit();

                create_notification(
                    'customer',
                    (int) $order['customer_id'],
                    'Order delivered',
                    "Order {$order['order_code']} has been delivered. Enjoy!",
                    'order',
                    ['order_id' => $orderId, 'screen' => 'order_status']
                );
                if ($order['rider_id'] !== null) {
                    create_notification(
                        'rider',
                        (int) $order['rider_id'],
                        'Earning posted',
                        'You earned ₹' . number_format((float) $earningResult['amount'], 2) . " for order {$order['order_code']}.",
                        'payout',
                        ['order_id' => $orderId, 'screen' => 'earnings']
                    );
                }

                write_audit_log('admin', $admin['id'], 'order_force_delivered', [
                    'order_id' => $orderId,
                    'order_code' => $order['order_code'],
                    'rider_id' => $order['rider_id'] !== null ? (int) $order['rider_id'] : null,
                    'reason' => $reason,
                ]);
                $flash = 'Order #' . admin_escape($order['order_code']) . ' force-delivered (OTP bypassed).';
            }
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

        <?php $otpLocked = $ord['delivery_otp'] && (int) $ord['otp_attempts'] >= $otpMaxAttempts; ?>
        <div class="section-title" style="margin-top:10px;">Delivery OTP</div>
        <div class="muted">
            <?php if ($ord['delivery_otp']): ?>
                <span class="otp-masked" data-otp="<?= admin_escape($ord['delivery_otp']) ?>" style="cursor:pointer;" title="Click to reveal">••••</span>
                <?= $ord['otp_verified_at'] ? ' · Verified at ' . admin_escape($ord['otp_verified_at']) : ' · Not yet verified' ?>
                <?php if ((int) $ord['otp_attempts'] > 0): ?> · <?= (int) $ord['otp_attempts'] ?> attempt(s)<?php endif; ?>
                <?php if ($otpLocked): ?>
                    <br><span class="badge inactive">Locked — max attempts reached</span>
                <?php endif; ?>
            <?php else: ?>
                No OTP generated for this order.
            <?php endif; ?>
        </div>
        <?php if ($canManage && $otpLocked && $ord['status'] === 'out_for_delivery'): ?>
        <div style="margin-top:8px; display:flex; gap:8px;">
            <form method="post" style="flex:1;" onsubmit="return confirm('Reset OTP attempts for this order? The rider will be able to try entering the code again.');">
                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                <input type="hidden" name="form_action" value="otp_reset_attempts">
                <button type="submit" class="btn btn-outline" style="width:100%;">Reset attempts</button>
            </form>
            <form method="post" style="flex:1;" onsubmit="return promptForceDeliverReason(this);">
                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                <input type="hidden" name="order_id" value="<?= $orderId ?>">
                <input type="hidden" name="form_action" value="force_deliver">
                <input type="hidden" name="reason" class="force-deliver-reason-field">
                <button type="submit" class="btn btn-outline danger" style="width:100%;">Force-deliver (bypass OTP)</button>
            </form>
        </div>
        <p class="muted" style="font-size:12px; margin-top:4px;">
            Reset lets the rider retry the real code. Force-deliver skips verification entirely — use only once you've confirmed the delivery some other way.
        </p>
        <?php endif; ?>

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
function promptForceDeliverReason(form) {
    var reason = prompt('Reason for force-delivering this order without OTP verification (visible in the audit log):');
    if (!reason || !reason.trim()) { return false; }
    form.querySelector('.force-deliver-reason-field').value = reason.trim();
    return confirm('Force-deliver this order without checking the OTP? This marks it delivered, records the COD/earning entries as usual, and cannot be undone from here.');
}
document.querySelectorAll('.otp-masked').forEach(function (el) {
    el.addEventListener('click', function () {
        el.textContent = el.getAttribute('data-otp');
    });
});
</script>

<?php require __DIR__ . '/_layout_foot.php'; ?>
