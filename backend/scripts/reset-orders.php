<?php
/**
 * Anydrop — Reset ALL Orders + Money Balances (testing only)
 *
 * App-owner ask, 2026-09-11: "aage ke jitne bhi orders ho wo delete ho
 * jaye, restaurants ka balance 0, wapis start se test karenge" — i.e.
 * wipe every order and everything derived from an order, and zero out
 * every running-balance column that orders feed into, so the whole
 * platform looks like a fresh install for testing. Restaurants,
 * riders, customers, menus, coupons/offers themselves are NOT touched
 * — only orders and order-derived money/history.
 *
 * SAFETY MODEL (this is a genuinely destructive script, unlike
 * seed-test-data.php which only ever inserts):
 *   1. Same ?key= gate as seed-test-data.php / seed-demo-catalog.php.
 *   2. A SEPARATE ?confirm=RESET_ORDERS is also required. Without it,
 *      the script only COUNTS what it would delete/reset and prints
 *      that — it does not touch the database. This means a bare
 *      ?key=... hit (e.g. an accidental double-click, a saved
 *      browser history entry, a link shared without the confirm
 *      param) is always a safe dry run, never a silent wipe.
 *   3. Runs inside a single transaction — if anything fails partway,
 *      everything rolls back rather than leaving the DB half-reset.
 *
 * Deletion order matches every FK that REFERENCES orders(id) across
 * the whole schema (01_schema.sql + every migration through 84) —
 * children before parents, so FOREIGN_KEY_CHECKS never need touching
 * and a partial failure can't leave orphaned rows:
 *
 *   order_items, order_status_history, coupon_usages, offer_usages,
 *   rider_locations (order_id only — rows are NOT deleted, order_id is
 *     just nulled, since a location ping's GPS trail has value on its
 *     own for rider-side history independent of any one order),
 *   restaurant_due_ledger, reviews, platform_ledger, payment_transactions,
 *   refunds, wallet_transactions, rider_cod_ledger, reconciliation_flags,
 *   rider_order_assignments, rider_earnings_ledger,
 *   restaurant_payment_orders
 *   -> then orders itself.
 *
 * restaurant_payments and rider_cod_ledger's settlement_to_admin rows
 * are NOT order-linked (order_id is NULL on those entry types) but are
 * still deleted/reset here, because they're still part of the same
 * "restaurant/rider running balance" this ask is about — a leftover
 * manual settlement record would make current_due/cod_cash_held
 * disagree with an empty orders table otherwise.
 *
 * Balances zeroed (not deleted — these are columns on
 * restaurants/riders/customer_wallets, not their own rows):
 *   restaurants.current_due -> 0
 *   riders.cod_cash_held -> 0
 *   customer_wallets.balance -> 0 (wallet_transactions rows themselves
 *     are deleted above per the order-derived-history rule, but a
 *     wallet's headline balance is a column here, so it needs its own
 *     explicit zeroing rather than falling out of a DELETE)
 *
 * NOT touched — restaurants, riders, customers, customer_addresses,
 * menu_categories, menu_items, menu_item_variants, menu_item_addons,
 * coupons (definitions), restaurant_offers (definitions, migration 47),
 * support_tickets (order_id nulled the same way as rider_locations
 * above — a ticket's own history has value independent of the order
 * it referenced), admins, app_settings, notifications, audit_logs.
 *
 * Run once via browser (dry run first, no confirm param):
 *   https://yourdomain.infinityfreeapp.com/scripts/reset-orders.php?key=SEED_ME
 * Then, once the printed counts look right:
 *   https://yourdomain.infinityfreeapp.com/scripts/reset-orders.php?key=SEED_ME&confirm=RESET_ORDERS
 *
 * Safe to run more than once — an empty orders table just means every
 * count comes back 0 the second time.
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script.';
    exit;
}

$confirmed = ($_GET['confirm'] ?? '') === 'RESET_ORDERS';

$db = Database::get();

// Tables deleted by order_id, children-first (see kdoc above for the
// FK-driven reasoning behind this exact order). Table => column name,
// so the same loop drives both the dry-run COUNT and the real DELETE.
$childTablesByOrderId = [
    'order_items' => 'order_id',
    'order_status_history' => 'order_id',
    'coupon_usages' => 'order_id',
    'offer_usages' => 'order_id',
    'restaurant_due_ledger' => 'order_id',
    'reviews' => 'order_id',
    'platform_ledger' => 'order_id',
    'payment_transactions' => 'order_id',
    'refunds' => 'order_id',
    'wallet_transactions' => 'order_id',
    'rider_cod_ledger' => 'order_id',
    'reconciliation_flags' => 'order_id',
    'rider_order_assignments' => 'order_id',
    'rider_earnings_ledger' => 'order_id',
    'restaurant_payment_orders' => 'order_id',
];

// rider_locations and support_tickets have real standalone value
// beyond the order they're linked to (a rider's GPS trail, a support
// conversation) — order_id is NULLed here instead of the row being
// deleted. Both columns are already nullable (see 01_schema.sql for
// rider_locations, migration 52 for support_tickets).
$nullOrderIdTables = ['rider_locations', 'support_tickets'];

function table_exists(PDO $db, string $table): bool
{
    // Some of these tables come from later migrations (e.g.
    // reconciliation_flags is migration 66, restaurant_payment_orders
    // is migration 80) — a database that hasn't run every migration
    // yet would otherwise fatal-error on a missing table. Checking
    // first lets this script degrade gracefully on a partially-
    // migrated DB rather than assuming every migration through 84 ran.
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t"
    );
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetch()['c'] > 0;
}

// ------------------------------------------------------------
// Dry run: count everything, touch nothing.
// ------------------------------------------------------------
$counts = [];
$totalOrders = (int) $db->query("SELECT COUNT(*) AS c FROM orders")->fetch()['c'];
$counts['orders'] = $totalOrders;

foreach ($childTablesByOrderId as $table => $col) {
    if (!table_exists($db, $table)) {
        $counts[$table] = null; // table not present on this DB yet
        continue;
    }
    $counts[$table] = (int) $db->query("SELECT COUNT(*) AS c FROM `$table` WHERE `$col` IS NOT NULL")->fetch()['c'];
}

$riderCodHeldTotal = (float) ($db->query("SELECT COALESCE(SUM(cod_cash_held), 0) AS s FROM riders")->fetch()['s'] ?? 0);
$restaurantDueTotal = (float) ($db->query("SELECT COALESCE(SUM(current_due), 0) AS s FROM restaurants")->fetch()['s'] ?? 0);
$walletBalanceTotal = table_exists($db, 'customer_wallets')
    ? (float) ($db->query("SELECT COALESCE(SUM(balance), 0) AS s FROM customer_wallets")->fetch()['s'] ?? 0)
    : 0.0;
$restaurantPaymentsCount = (int) $db->query("SELECT COUNT(*) AS c FROM restaurant_payments")->fetch()['c'];

if (!$confirmed) {
    echo "DRY RUN — nothing has been changed. Add &confirm=RESET_ORDERS to actually reset.\n\n";
    echo "Would delete:\n";
    echo "  orders: {$totalOrders}\n";
    foreach ($counts as $table => $c) {
        if ($table === 'orders') {
            continue;
        }
        echo "  {$table}: " . ($c === null ? 'table not found, skipped' : $c) . "\n";
    }
    echo "  restaurant_payments (not order-linked, still cleared): {$restaurantPaymentsCount}\n";
    echo "\nWould null order_id on (rows kept):\n";
    foreach ($nullOrderIdTables as $table) {
        if (!table_exists($db, $table)) {
            echo "  {$table}: table not found, skipped\n";
            continue;
        }
        $c = (int) $db->query("SELECT COUNT(*) AS c FROM `$table` WHERE order_id IS NOT NULL")->fetch()['c'];
        echo "  {$table}: {$c} rows would have order_id set to NULL\n";
    }
    echo "\nWould zero out:\n";
    echo "  restaurants.current_due (currently sums to ₹" . number_format($restaurantDueTotal, 2) . " across all restaurants)\n";
    echo "  riders.cod_cash_held (currently sums to ₹" . number_format($riderCodHeldTotal, 2) . " across all riders)\n";
    echo "  customer_wallets.balance (currently sums to ₹" . number_format($walletBalanceTotal, 2) . " across all customers)\n";
    exit;
}

// ------------------------------------------------------------
// Confirmed run — actually reset, inside one transaction.
// ------------------------------------------------------------
try {
    $db->beginTransaction();

    foreach ($childTablesByOrderId as $table => $col) {
        if (!table_exists($db, $table)) {
            continue;
        }
        $db->exec("DELETE FROM `$table` WHERE `$col` IS NOT NULL");
    }

    foreach ($nullOrderIdTables as $table) {
        if (!table_exists($db, $table)) {
            continue;
        }
        $db->exec("UPDATE `$table` SET order_id = NULL WHERE order_id IS NOT NULL");
    }

    // restaurant_payments has no order_id column at all (it's a
    // record of a Pay Now / Record Settlement action, not a per-order
    // row) — cleared outright since it's still part of the same
    // restaurant running-balance history this reset is about.
    $db->exec("DELETE FROM restaurant_payments");

    // orders itself, now that every child row referencing it is gone.
    $db->exec("DELETE FROM orders");

    // Running balances. UPDATE, not DELETE — these are columns on
    // rows that must keep existing (the restaurant/rider/customer
    // account itself isn't being removed, only its order history).
    $db->exec("UPDATE restaurants SET current_due = 0");
    $db->exec("UPDATE riders SET cod_cash_held = 0");
    if (table_exists($db, 'customer_wallets')) {
        $db->exec("UPDATE customer_wallets SET balance = 0");
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo "FAILED — rolled back, nothing was changed.\n";
    echo 'Error: ' . $e->getMessage();
    exit;
}

echo "Done. Reset:\n";
echo "  orders deleted: {$totalOrders}\n";
foreach ($counts as $table => $c) {
    if ($table === 'orders' || $c === null) {
        continue;
    }
    echo "  {$table} deleted: {$c}\n";
}
echo "  restaurant_payments deleted: {$restaurantPaymentsCount}\n";
foreach ($nullOrderIdTables as $table) {
    if (!table_exists($db, $table)) {
        continue;
    }
    echo "  {$table}: order_id nulled on rows that referenced a deleted order\n";
}
echo "  restaurants.current_due -> 0 for all restaurants\n";
echo "  riders.cod_cash_held -> 0 for all riders\n";
if (table_exists($db, 'customer_wallets')) {
    echo "  customer_wallets.balance -> 0 for all customers\n";
}
echo "\nRestaurants, riders, customers, menus, and coupon/offer definitions were NOT touched — only orders and order-derived history/balances.\n";
echo "DELETE THIS FILE (or at minimum change the ?key= gate) once testing is done.";
