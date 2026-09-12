<?php
/**
 * GET /api/v1/restaurant/statement.php?date=YYYY-MM-DD
 * Auth: Restaurant token
 *
 * Deep Plan Phase 1 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_CashFlow_2026-09-09.md).
 * Backs the new Restaurant App "Statement" screen: a day's orders with
 * amount + settlement status (Pending T+1 / Eligible / Settled), plus a
 * daily summary card.
 *
 * `date` defaults to today (server timezone, same convention every other
 * date-boundary calculation in this codebase already uses — see
 * lib/rider_earnings.php's earnings-summary "today" boundary, insights
 * CSV export, etc.). Orders are scoped by created_at falling on that
 * calendar day — NOT delivered_at — so a restaurant sees every order it
 * actually received that day, including ones still in progress or
 * cancelled, not just ones that finished delivering that day.
 *
 * Response:
 * {
 *   "date": "2026-09-09",
 *   "summary": {
 *     "total_orders": N, "total_amount": X,
 *     "settled_amount": Y, "pending_amount": Z,
 *     "total_commission": C, "total_net_payable": X-C
 *   },
 *   "orders": [
 *     { "order_id", "order_code", "created_at", "status", "grand_total",
 *       "payment_method", "commission_amount", "net_payable",
 *       "settlement_status", "settlement_eligible_at" }, ...
 *   ]
 * }
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settlement_status.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = (int) $owner['owner_id'];

$dateParam = trim((string) ($_GET['date'] ?? ''));
if ($dateParam !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    respond_error('invalid_date', 400);
}
$date = $dateParam !== '' ? $dateParam : date('Y-m-d');
$from = $date . ' 00:00:00';
$to = $date . ' 23:59:59';

$db = Database::get();

// Lazily flip any 'pending' orders whose T+1 window has already passed
// into 'eligible' — keeps the badge accurate without a cron job (see
// lib/settlement_status.php's own kdoc for why this is lazy-on-read).
refresh_settlement_eligibility($restaurantId);

$ordersStmt = $db->prepare(
    "SELECT id AS order_id, order_code, created_at, status, grand_total,
            payment_method, commission_amount, settlement_status, settlement_eligible_at
     FROM orders
     WHERE restaurant_id = :rid AND created_at BETWEEN :f AND :t
     ORDER BY created_at DESC"
);
$ordersStmt->execute(['rid' => $restaurantId, 'f' => $from, 't' => $to]);
$orders = $ordersStmt->fetchAll();

$totalOrders = count($orders);
$totalAmount = 0.0;
$settledAmount = 0.0;
$pendingAmount = 0.0;
// App-owner ask, 2026-09-11 — restaurant needs to see, at a glance,
// how much came in, how much commission was taken, and how much they
// actually get. commission_amount already existed per-order on this
// endpoint; net_payable (= grand_total - commission_amount) is the
// missing piece, both per-order and as a daily total.
$totalCommission = 0.0;
$totalNetPayable = 0.0;

foreach ($orders as $o) {
    // Only count revenue from orders that actually completed — mirrors
    // admin/settlements.php's own $payoutNonRevenueStatuses exclusion so
    // this screen's "Today's Sales" figure agrees with what the admin
    // settlement page would eventually pay out, not raw order-attempt
    // volume (a rejected/cancelled/failed order was never real revenue).
    if (!in_array($o['status'], ['delivered'], true)) {
        continue;
    }
    $amount = (float) $o['grand_total'];
    $commission = (float) $o['commission_amount'];
    $totalAmount += $amount;
    $totalCommission += $commission;
    $totalNetPayable += ($amount - $commission);
    if ($o['settlement_status'] === 'settled') {
        $settledAmount += $amount;
    } else {
        // 'pending' or 'eligible' both count as "not yet in hand" for the
        // summary card — the badge on each row still distinguishes them.
        $pendingAmount += $amount;
    }
}

respond_ok([
    'date' => $date,
    'summary' => [
        'total_orders' => $totalOrders,
        'total_amount' => round($totalAmount, 2),
        'settled_amount' => round($settledAmount, 2),
        'pending_amount' => round($pendingAmount, 2),
        'total_commission' => round($totalCommission, 2),
        'total_net_payable' => round($totalNetPayable, 2),
    ],
    'orders' => array_map(static function (array $o): array {
        $grandTotal = (float) $o['grand_total'];
        $commission = (float) $o['commission_amount'];
        return [
            'order_id' => (int) $o['order_id'],
            'order_code' => $o['order_code'],
            'created_at' => $o['created_at'],
            'status' => $o['status'],
            'grand_total' => $grandTotal,
            'payment_method' => $o['payment_method'],
            'commission_amount' => $commission,
            // Per-order "what you actually get for this one" — same
            // grand_total - commission_amount math as the summary total
            // above, just not pre-summed. Not gated on delivered/
            // settlement status — it's a plain arithmetic fact about the
            // order regardless of where it is in the settlement pipeline
            // (an in-progress or not-yet-settled order still has a
            // well-defined net payable, it just hasn't been paid out yet).
            'net_payable' => round($grandTotal - $commission, 2),
            'settlement_status' => $o['settlement_status'],
            'settlement_eligible_at' => $o['settlement_eligible_at'],
        ];
    }, $orders),
]);
