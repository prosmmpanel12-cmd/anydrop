<?php
/**
 * Anydrop — Admin Web UI: Dashboard
 *
 * recall.md Phase A item 3 / docs/19_Admin_Panel_Full_Spec_And_Payment_
 * Email_Architecture_2026-08-14.md §3's "Admin Dashboard" list. Separate
 * page from index.php (which is the restaurant-approval queue, gated on
 * `restaurants_view`) — this is the actual stats overview, gated on
 * `dashboard_view`, so a role like Restaurant Manager (no dashboard_view)
 * reaches the approval queue directly without this page in the way, and
 * a role that only has dashboard_view can see the numbers without being
 * able to act on anything.
 *
 * Every widget is additionally gated on its own module's `_view`
 * permission (doc 19 §1's "frontend able to hide/disable proactively"
 * principle) — a role with dashboard_view but not payouts_view, say,
 * simply doesn't see the payouts widget rather than seeing a number it
 * has no permission to look at.
 *
 * "Revenue" here means the platform's own cut (commission_amount +
 * platform_fee columns on `orders`), not gross order value — kept
 * clearly labeled as two separate numbers since conflating them would
 * misstate how much money Anydrop itself made vs. how much customers
 * spent. Cancelled/rejected/failed/expired orders are excluded from
 * both, since neither delivered nor an actual charge.
 *
 * Riders/rider-approval widgets are intentionally omitted: the Rider
 * App itself is still 🔴 GENUINELY PENDING (recall.md item 8) — the
 * `riders` table exists in schema but nothing populates it yet, so a
 * "0 online riders" widget today would be noise, not signal. Add it
 * back once the Rider App ships.
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'dashboard_view');
$db = Database::get();

$has = fn(string $key): bool => admin_has_permission($admin['id'], $key);

// ---------- Orders / Revenue (today) ----------
$ordersToday = null;
$revenueToday = null;
$grossToday = null;
if ($has('orders_view')) {
    $ordersToday = (int) $db->query(
        "SELECT COUNT(*) AS c FROM orders WHERE DATE(created_at) = CURDATE()"
    )->fetch()['c'];

    $sums = $db->query(
        "SELECT
            COALESCE(SUM(grand_total), 0) AS gross,
            COALESCE(SUM(commission_amount + platform_fee), 0) AS revenue
         FROM orders
         WHERE DATE(created_at) = CURDATE()
           AND status NOT IN ('cancelled', 'rejected', 'failed', 'expired')"
    )->fetch();
    $grossToday = (float) $sums['gross'];
    $revenueToday = (float) $sums['revenue'];
}

// ---------- Customers ----------
$activeCustomers = null;
$customersOrderedRecently = null;
if ($has('customers_view')) {
    $activeCustomers = (int) $db->query(
        "SELECT COUNT(*) AS c FROM customers WHERE is_active = 1 AND deleted_at IS NULL"
    )->fetch()['c'];

    $customersOrderedRecently = (int) $db->query(
        "SELECT COUNT(DISTINCT customer_id) AS c FROM orders WHERE created_at >= NOW() - INTERVAL 30 DAY"
    )->fetch()['c'];
}

// ---------- Restaurants ----------
$approvedRestaurants = null;
$openRestaurants = null;
$pendingRestaurants = null;
if ($has('restaurants_view')) {
    $approvedRestaurants = (int) $db->query(
        "SELECT COUNT(*) AS c FROM restaurants WHERE status = 'approved' AND deleted_at IS NULL"
    )->fetch()['c'];

    $openRestaurants = (int) $db->query(
        "SELECT COUNT(*) AS c FROM restaurants
         WHERE status = 'approved' AND operational_status = 'open' AND deleted_at IS NULL"
    )->fetch()['c'];

    $pendingRestaurants = (int) $db->query(
        "SELECT COUNT(*) AS c FROM restaurants WHERE status = 'pending' AND deleted_at IS NULL"
    )->fetch()['c'];
}

// ---------- Payouts ----------
$pendingPayoutsCount = null;
$pendingPayoutsAmount = null;
if ($has('payouts_view')) {
    $payoutRow = $db->query(
        "SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total
         FROM restaurant_payments WHERE status = 'pending'"
    )->fetch();
    $pendingPayoutsCount = (int) $payoutRow['c'];
    $pendingPayoutsAmount = (float) $payoutRow['total'];
}

$rupees = fn(float $amount): string => '₹' . number_format($amount, 2);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/_layout_head.php';
?>
    <?php if ($ordersToday !== null): ?>
    <div class="section">
    <h2 class="section-title">Today</h2>
    <div class="grid">
        <div class="card stat">
            <div class="value"><?= $ordersToday ?></div>
            <div class="label">Orders today</div>
        </div>
        <div class="card stat">
            <div class="value"><?= $rupees($revenueToday) ?></div>
            <div class="label">Platform revenue today</div>
            <div class="sub">commission + platform fee</div>
        </div>
        <div class="card stat">
            <div class="value"><?= $rupees($grossToday) ?></div>
            <div class="label">Gross order value today</div>
        </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($approvedRestaurants !== null): ?>
    <div class="section">
    <h2 class="section-title">Restaurants</h2>
    <div class="grid">
        <div class="card stat">
            <div class="value"><?= $approvedRestaurants ?></div>
            <div class="label">Approved restaurants</div>
        </div>
        <div class="card stat">
            <div class="value"><?= $openRestaurants ?></div>
            <div class="label">Currently open</div>
        </div>
        <div class="card stat <?= $pendingRestaurants > 0 ? 'warn' : '' ?>">
            <div class="value"><?= $pendingRestaurants ?></div>
            <div class="label">Pending approval</div>
            <?php if ($pendingRestaurants > 0): ?>
                <div class="sub"><a href="index.php">Review now →</a></div>
            <?php endif; ?>
        </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($activeCustomers !== null): ?>
    <div class="section">
    <h2 class="section-title">Customers</h2>
    <div class="grid">
        <div class="card stat">
            <div class="value"><?= $activeCustomers ?></div>
            <div class="label">Active accounts</div>
        </div>
        <div class="card stat">
            <div class="value"><?= $customersOrderedRecently ?></div>
            <div class="label">Ordered in last 30 days</div>
        </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($pendingPayoutsCount !== null): ?>
    <div class="section">
    <h2 class="section-title">Payouts</h2>
    <div class="grid">
        <div class="card stat <?= $pendingPayoutsCount > 0 ? 'warn' : '' ?>">
            <div class="value"><?= $pendingPayoutsCount ?></div>
            <div class="label">Pending payout requests</div>
        </div>
        <div class="card stat">
            <div class="value"><?= $rupees($pendingPayoutsAmount) ?></div>
            <div class="label">Pending payout amount</div>
        </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($ordersToday === null && $approvedRestaurants === null && $activeCustomers === null && $pendingPayoutsCount === null): ?>
        <div class="empty">Your role has <code>dashboard_view</code> but no module <code>_view</code> permissions yet — nothing to show. Ask a Super Admin to grant the relevant view permissions from the Roles screen.</div>
    <?php endif; ?>

    <div class="section">
    <h2 class="section-title">Quick links</h2>
    <div class="row-actions">
        <?php if ($has('restaurants_view')): ?><a class="btn btn-outline" href="index.php">Pending Restaurant Approvals<?= $pendingRestaurants ? " ({$pendingRestaurants})" : '' ?></a><?php endif; ?>
        <?php if ($has('restaurants_view')): ?><a class="btn btn-outline" href="restaurants.php">Manage Restaurants</a><?php endif; ?>
        <?php if ($has('customers_view')): ?><a class="btn btn-outline" href="customers.php">Manage Customers</a><?php endif; ?>
        <?php if ($has('areas_view')): ?><a class="btn btn-outline" href="areas.php">Service Areas</a><?php endif; ?>
        <?php if ($has('roles_manage')): ?><a class="btn btn-outline" href="roles.php">Roles &amp; Permissions / Add Admin</a><?php endif; ?>
    </div>
    </div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
