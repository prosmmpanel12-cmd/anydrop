<?php
/**
 * GET /api/v1/rider/statement.php?date=YYYY-MM-DD
 * Auth: Rider token
 *
 * Deep Plan Phase 1 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md).
 * Backs the new Rider App "Statement" screen: every order this rider
 * delivered on a given day, with the COD cash collected (if any), the
 * rider's own delivery earning for that order, and the running
 * cod_cash_held balance immediately after that order — so the rider
 * can see exactly which delivery pushed them toward their cash-hold
 * limit, not just a lump daily total.
 *
 * Scoped by delivered_at (unlike the restaurant statement, which scopes
 * by created_at) — deliberate: a rider's day is "what did I deliver
 * today", not "what orders exist that happen to be assigned to me
 * today" (an order can sit assigned/picked-up across a shift boundary;
 * it only belongs to a rider's statement once actually delivered).
 *
 * Response:
 * {
 *   "date": "2026-09-09",
 *   "summary": {
 *     "orders_delivered": N, "cod_collected": X,
 *     "total_earnings": Y, "cod_cash_held_now": Z
 *   },
 *   "orders": [
 *     { "order_id", "order_code", "delivered_at", "payment_method",
 *       "cod_amount_collected", "delivery_earning",
 *       "running_cod_cash_held" }, ...
 *   ]
 * }
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$dateParam = trim((string) ($_GET['date'] ?? ''));
if ($dateParam !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    respond_error('invalid_date', 400);
}
$date = $dateParam !== '' ? $dateParam : date('Y-m-d');
$from = $date . ' 00:00:00';
$to = $date . ' 23:59:59';

$db = Database::get();

$ordersStmt = $db->prepare(
    "SELECT id AS order_id, order_code, delivered_at, payment_method, grand_total
     FROM orders
     WHERE rider_id = :rid AND status = 'delivered' AND delivered_at BETWEEN :f AND :t
     ORDER BY delivered_at ASC"
);
$ordersStmt->execute(['rid' => $riderId, 'f' => $from, 't' => $to]);
$orders = $ordersStmt->fetchAll();

$orderIds = array_column($orders, 'order_id');

// Pull the exact per-order ledger amounts rather than re-deriving them
// (grand_total for COD, a % formula for earnings) — the ledger rows are
// the actual source of truth (what was really written to
// rider_cod_ledger / rider_earnings_ledger), so if a manual_adjustment
// or incentive ever touched a given order's numbers, this screen
// reflects that instead of silently recomputing a "should be" figure.
$codByOrder = [];
$earningByOrder = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    $codStmt = $db->prepare(
        "SELECT order_id, amount, running_balance FROM rider_cod_ledger
         WHERE rider_id = ? AND entry_type = 'cod_collected' AND order_id IN ($placeholders)"
    );
    $codStmt->execute(array_merge([$riderId], $orderIds));
    foreach ($codStmt->fetchAll() as $row) {
        $codByOrder[(int) $row['order_id']] = $row;
    }

    $earnStmt = $db->prepare(
        "SELECT order_id, amount FROM rider_earnings_ledger
         WHERE rider_id = ? AND entry_type = 'delivery_earning' AND order_id IN ($placeholders)"
    );
    $earnStmt->execute(array_merge([$riderId], $orderIds));
    foreach ($earnStmt->fetchAll() as $row) {
        $earningByOrder[(int) $row['order_id']] = (float) $row['amount'];
    }
}

$codCollectedTotal = 0.0;
$earningsTotal = 0.0;

$orderRows = array_map(static function (array $o) use ($codByOrder, $earningByOrder, &$codCollectedTotal, &$earningsTotal): array {
    $oid = (int) $o['order_id'];
    $codRow = $codByOrder[$oid] ?? null;
    $codAmount = $codRow ? (float) $codRow['amount'] : 0.0;
    $earning = $earningByOrder[$oid] ?? 0.0;

    $codCollectedTotal += $codAmount;
    $earningsTotal += $earning;

    return [
        'order_id' => $oid,
        'order_code' => $o['order_code'],
        'delivered_at' => $o['delivered_at'],
        'payment_method' => $o['payment_method'],
        'cod_amount_collected' => round($codAmount, 2),
        'delivery_earning' => round($earning, 2),
        'running_cod_cash_held' => $codRow ? round((float) $codRow['running_balance'], 2) : null,
    ];
}, $orders);

$riderStmt = $db->prepare('SELECT cod_cash_held FROM riders WHERE id = :id LIMIT 1');
$riderStmt->execute(['id' => $riderId]);
$rider = $riderStmt->fetch();
$codCashHeldNow = $rider ? (float) $rider['cod_cash_held'] : 0.0;

respond_ok([
    'date' => $date,
    'summary' => [
        'orders_delivered' => count($orders),
        'cod_collected' => round($codCollectedTotal, 2),
        'total_earnings' => round($earningsTotal, 2),
        'cod_cash_held_now' => round($codCashHeldNow, 2),
    ],
    'orders' => $orderRows,
]);
