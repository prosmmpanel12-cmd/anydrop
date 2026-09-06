<?php
/**
 * Anydrop — Admin Web UI: Rider Detail (deep-plan §25, "Rider detail"
 * sub-section — the second of the three §25 asked for; "Rider list"
 * shipped in doc 108, this session).
 *
 * Deliberately a READ-ONLY consolidated view, not a fourth place that
 * can *write* rider state. Every action this page could plausibly host
 * already has an owner:
 *   - Status lifecycle / documents verify-reject / area assign
 *     -> riders.php's own Manage dialog (unchanged, linked back to here)
 *   - COD settlement recording   -> rider-settlements.php (linked out)
 *   - Earnings payout/adjustment -> rider-earnings.php (linked out)
 * This page only adds the THREE things deep-plan §25 wanted that had
 * genuinely nowhere to live yet: an orders-history list, a location-
 * ping history, and a per-rider audit trail. All three are read
 * straight off existing tables (`orders`, `rider_locations`,
 * `audit_logs`) — no new migration, no new write path, same "surface
 * existing data" scoping doc 108 used for the Rider list columns.
 *
 * Orders history: plain `orders WHERE rider_id = :id`, paginated. No
 * new column needed — `idx_orders_rider_status` already indexes this.
 *
 * Location history: `rider_locations WHERE rider_id = :id`, most
 * recent first. This table is already populated by
 * `api/v1/rider/location.php`'s active-delivery branch (only pings
 * sent with a valid in-progress order_id are stored — see that file's
 * own kdoc) so "location history" here means "GPS breadcrumbs during
 * deliveries", not a continuous 24/7 trail. That's the only location
 * history this codebase has ever recorded; this page surfaces it
 * as-is rather than pretending a denser trail exists.
 *
 * Audit trail: every admin action already writes `write_audit_log()`
 * with a `rider_id` key in `details_json` whenever it targets a rider
 * (riders.php's four status/doc transitions + area assign,
 * rider-settlements.php's settlement record, rider-earnings.php's
 * payout/adjustment) — grepped every write_audit_log() call site in
 * both files to confirm the key name is `rider_id` everywhere before
 * relying on it here (`rider_earning_rate_updated` is the one
 * rider-earnings.php action with no rider_id key, since it's a global
 * rate setting, not a per-rider one — correctly excluded by the
 * JSON_EXTRACT filter below rather than needing a special case).
 * JSON_UNQUOTE(JSON_EXTRACT(...)) rather than a bare JSON_EXTRACT
 * equality check — mirrors migration 43's own existing use of that
 * exact pairing (grepped for prior JSON-column-filter precedent before
 * writing a new one), since a raw JSON_EXTRACT comparison can be
 * quoting-sensitive on some MySQL/MariaDB versions and JSON_UNQUOTE
 * side-steps that rather than risking a silent zero-rows false negative.
 *
 * Gated on `riders_view` (same base gate riders.php itself uses) for
 * profile/orders/location/audit; the two linked-out money screens each
 * still enforce their own `payouts_view` gate independently when
 * clicked, so this page just conditionally shows/hides those two link
 * buttons on the same `payouts_view` check rather than duplicating
 * their money figures here (same "link out, don't re-display" call
 * doc 108 made for the Rider list's Manage dialog).
 *
 * NOT tested end-to-end (no PHP/MySQL in this sandbox) — same standing
 * caveat as every other page in this file's history.
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'riders_view');
$canViewDocuments = admin_has_permission($admin['id'], 'rider_documents_view');
$canViewPayouts = admin_has_permission($admin['id'], 'payouts_view');
$db = Database::get();

$riderId = (int) ($_GET['rider_id'] ?? 0);
if ($riderId <= 0) {
    header('Location: riders.php');
    exit;
}

$rStmt = $db->prepare(
    'SELECT id, name, email, mobile, status, documents_status, vehicle_type, vehicle_number,
            service_area_id, is_online, last_lat, last_lng, last_location_at,
            cod_cash_held, earnings_balance, created_at, rejection_reason
     FROM riders WHERE id = :id AND restaurant_id IS NULL LIMIT 1'
);
$rStmt->execute(['id' => $riderId]);
$rider = $rStmt->fetch();

if (!$rider) {
    $pageTitle = 'Rider Detail';
    $activeNav = 'riders';
    require __DIR__ . '/_layout_head.php';
    echo '<div class="section"><div class="card"><p class="muted">Rider not found.</p>'
        . '<a class="btn btn-outline" href="riders.php">Back to Riders</a></div></div>';
    require __DIR__ . '/_layout_foot.php';
    exit;
}

// Service area label (same breadcrumb helper riders.php's own list uses).
$areaLabel = '<span class="muted">Unassigned</span>';
if ($rider['service_area_id']) {
    $areaNodeById = [];
    foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
        $areaNodeById[(int) $row['id']] = $row;
    }
    if (isset($areaNodeById[(int) $rider['service_area_id']])) {
        $areaLabel = admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $rider['service_area_id']], $areaNodeById));
    }
}

$statusLabels = [
    'pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected',
    'preparing' => 'Preparing', 'ready' => 'Ready', 'rider_assigned' => 'Rider Assigned',
    'picked_up' => 'Picked Up', 'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered',
    'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed', 'expired' => 'Expired',
];

// ---------- Orders history (paginated) ----------
$ordersPage = max(1, (int) ($_GET['orders_page'] ?? 1));
$ordersPerPage = 15;
$ordersOffset = ($ordersPage - 1) * $ordersPerPage;

$ordersCountStmt = $db->prepare('SELECT COUNT(*) AS c FROM orders WHERE rider_id = :id');
$ordersCountStmt->execute(['id' => $riderId]);
$ordersTotal = (int) $ordersCountStmt->fetch()['c'];
$ordersTotalPages = max(1, (int) ceil($ordersTotal / $ordersPerPage));
$ordersPage = min($ordersPage, $ordersTotalPages);
$ordersOffset = ($ordersPage - 1) * $ordersPerPage;

$ordersStmt = $db->prepare(
    "SELECT o.id, o.order_code, o.status, o.payment_method, o.payment_status, o.grand_total,
            o.created_at, o.delivered_at, rs.name AS restaurant_name
     FROM orders o
     LEFT JOIN restaurants rs ON rs.id = o.restaurant_id
     WHERE o.rider_id = :id
     ORDER BY o.id DESC
     LIMIT {$ordersPerPage} OFFSET {$ordersOffset}"
);
$ordersStmt->execute(['id' => $riderId]);
$orderRows = $ordersStmt->fetchAll();

// ---------- Location history (most recent breadcrumbs) ----------
$locStmt = $db->prepare(
    'SELECT rl.latitude, rl.longitude, rl.speed_kmh, rl.recorded_at, o.order_code
     FROM rider_locations rl
     LEFT JOIN orders o ON o.id = rl.order_id
     WHERE rl.rider_id = :id
     ORDER BY rl.recorded_at DESC, rl.id DESC
     LIMIT 100'
);
$locStmt->execute(['id' => $riderId]);
$locationRows = $locStmt->fetchAll();

// ---------- Audit trail ----------
// See file header for why JSON_UNQUOTE(JSON_EXTRACT(...)) rather than
// a bare JSON_EXTRACT comparison, and why 'rider_earning_rate_updated'
// (no rider_id key) is correctly excluded rather than a bug.
$auditStmt = $db->prepare(
    "SELECT al.action, al.details_json, al.created_at, a.username AS admin_username
     FROM audit_logs al
     LEFT JOIN admins a ON a.id = al.actor_id
     WHERE al.actor_type = 'admin'
       AND JSON_UNQUOTE(JSON_EXTRACT(al.details_json, '$.rider_id')) = :id
     ORDER BY al.id DESC
     LIMIT 100"
);
$auditStmt->execute(['id' => (string) $riderId]);
$auditRows = $auditStmt->fetchAll();

$pageTitle = 'Rider Detail — ' . $rider['name'];
$activeNav = 'riders';
require __DIR__ . '/_layout_head.php';
?>
<div class="section">

    <div class="card">
        <a href="riders.php" class="btn btn-outline" style="margin-bottom:12px;">&larr; All riders</a>
        <h2><?= admin_escape($rider['name']) ?></h2>
        <p class="muted"><?= admin_escape($rider['mobile'] ?: '—') ?> &middot; <?= admin_escape($rider['email'] ?: '—') ?></p>
        <p>
            <span class="badge <?= $rider['status'] === 'approved' ? 'active' : ($rider['status'] === 'pending' ? 'system' : 'inactive') ?>">
                <?= ucfirst($rider['status']) ?>
            </span>
            <span class="badge <?= ((int) $rider['is_online']) === 1 ? 'active' : 'inactive' ?>">
                <?= ((int) $rider['is_online']) === 1 ? 'Online' : 'Offline' ?>
            </span>
            <?php if ($canViewDocuments): ?>
                <span class="badge <?= $rider['documents_status'] === 'verified' ? 'active' : ($rider['documents_status'] === 'pending' ? 'system' : 'inactive') ?>">
                    Docs: <?= ucfirst(str_replace('_', ' ', $rider['documents_status'])) ?>
                </span>
            <?php endif; ?>
            <?php if ($rider['rejection_reason']): ?>
                <br><span class="muted">Last status reason: <?= admin_escape($rider['rejection_reason']) ?></span>
            <?php endif; ?>
        </p>
        <p class="muted" style="font-size:13px;">
            Area: <?= $areaLabel ?> &middot;
            Vehicle: <?= admin_escape($rider['vehicle_type'] ?: '—') ?><?= $rider['vehicle_number'] ? ' (' . admin_escape($rider['vehicle_number']) . ')' : '' ?> &middot;
            Applied: <?= admin_escape(substr($rider['created_at'], 0, 10)) ?> &middot;
            Last seen: <?= admin_escape(admin_time_ago($rider['last_location_at'])) ?>
            <?php if ($rider['last_lat'] && $rider['last_lng']): ?>
                (<?= admin_escape((string) $rider['last_lat']) ?>, <?= admin_escape((string) $rider['last_lng']) ?>)
            <?php endif; ?>
        </p>

        <div class="row-actions" style="margin-top:10px;">
            <a class="btn btn-outline" href="riders.php?q=<?= urlencode($rider['mobile'] ?: $rider['name']) ?>">Manage status / documents / area</a>
            <?php if ($canViewPayouts): ?>
                <a class="btn btn-outline" href="rider-settlements.php?rider_id=<?= (int) $riderId ?>">
                    COD Settlement<?= (float) $rider['cod_cash_held'] > 0 ? ' — ₹' . admin_escape(number_format((float) $rider['cod_cash_held'], 2)) . ' held' : '' ?>
                </a>
                <a class="btn btn-outline" href="rider-earnings.php?rider_id=<?= (int) $riderId ?>">
                    Earnings Ledger<?= (float) $rider['earnings_balance'] > 0 ? ' — ₹' . admin_escape(number_format((float) $rider['earnings_balance'], 2)) . ' owed' : '' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Order History (<?= $ordersTotal ?>)</h2>
        <?php if (empty($orderRows)): ?>
            <p class="muted">No orders assigned to this rider yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <tr><th>Order</th><th>Restaurant</th><th>Status</th><th>Payment</th><th>Amount</th><th>Created</th><th>Delivered</th></tr>
                <?php foreach ($orderRows as $o): ?>
                <tr>
                    <td><?= admin_escape($o['order_code']) ?></td>
                    <td><?= admin_escape($o['restaurant_name'] ?: '—') ?></td>
                    <td>
                        <span class="badge <?= $o['status'] === 'delivered' ? 'active' : (in_array($o['status'], ['cancelled', 'rejected', 'failed', 'expired'], true) ? 'inactive' : '') ?>">
                            <?= admin_escape($statusLabels[$o['status']] ?? $o['status']) ?>
                        </span>
                    </td>
                    <td class="muted"><?= strtoupper(admin_escape($o['payment_method'])) ?> &middot; <?= admin_escape(ucfirst($o['payment_status'])) ?></td>
                    <td>₹<?= admin_escape(number_format((float) $o['grand_total'], 2)) ?></td>
                    <td class="muted"><?= admin_escape($o['created_at']) ?></td>
                    <td class="muted"><?= $o['delivered_at'] ? admin_escape($o['delivered_at']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php if ($ordersTotalPages > 1): ?>
            <div class="row-actions" style="margin-top:12px; justify-content:center;">
                <?php for ($p = 1; $p <= $ordersTotalPages; $p++): ?>
                    <a class="btn btn-outline <?= $p === $ordersPage ? 'active' : '' ?>"
                       href="?<?= http_build_query(array_merge($_GET, ['orders_page' => $p])) ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Location History</h2>
        <p class="muted">
            GPS pings recorded while an order was actively assigned to this
            rider (rider_assigned / picked_up / out_for_delivery) — most
            recent 100. This app doesn't record a continuous off-delivery
            trail, so an offline/idle rider will show nothing here even if
            <?= admin_escape(admin_time_ago($rider['last_location_at'])) ?> is recent
            (that figure comes from the separate always-on last-known-position
            field, not this table).
        </p>
        <?php if (empty($locationRows)): ?>
            <p class="muted">No location pings recorded for this rider yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <tr><th>Time</th><th>Order</th><th>Latitude</th><th>Longitude</th><th>Speed (km/h)</th></tr>
                <?php foreach ($locationRows as $loc): ?>
                <tr>
                    <td class="muted"><?= admin_escape($loc['recorded_at']) ?></td>
                    <td><?= $loc['order_code'] ? admin_escape($loc['order_code']) : '<span class="muted">—</span>' ?></td>
                    <td><?= admin_escape((string) $loc['latitude']) ?></td>
                    <td><?= admin_escape((string) $loc['longitude']) ?></td>
                    <td><?= $loc['speed_kmh'] !== null ? admin_escape((string) $loc['speed_kmh']) : '<span class="muted">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Audit Trail</h2>
        <p class="muted">Every admin action taken against this rider's account, documents, area, COD settlement, or earnings.</p>
        <?php if (empty($auditRows)): ?>
            <p class="muted">No admin actions recorded against this rider yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <tr><th>Time</th><th>Action</th><th>By</th><th>Detail</th></tr>
                <?php foreach ($auditRows as $log):
                    $details = json_decode($log['details_json'] ?? '{}', true) ?: [];
                    unset($details['rider_id']);
                    $detailParts = [];
                    foreach ($details as $k => $v) {
                        if ($v === null || $v === '') {
                            continue;
                        }
                        $detailParts[] = ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . (is_scalar($v) ? (string) $v : json_encode($v));
                    }
                ?>
                <tr>
                    <td class="muted"><?= admin_escape($log['created_at']) ?></td>
                    <td><?= admin_escape(ucfirst(str_replace('_', ' ', $log['action']))) ?></td>
                    <td class="muted"><?= admin_escape($log['admin_username'] ?: '—') ?></td>
                    <td class="muted"><?= admin_escape(implode(' · ', $detailParts)) ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
