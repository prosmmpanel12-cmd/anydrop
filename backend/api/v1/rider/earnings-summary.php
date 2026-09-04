<?php
/**
 * GET /api/v1/rider/earnings-summary.php
 * Auth: Rider token
 * Response: { "today_total": <float>, "balance": <float>,
 *             "share_percent": <float>, "recent": [
 *               { id, entry_type, amount, order_id, order_code, note, created_at }, ...
 *             ] }
 *
 * Deep-plan §19-20 (migration 73). Backs RiderDashboardActivity's
 * "TODAY" earnings card (previously a static ₹0 placeholder, see that
 * layout's own comment) plus a small recent-activity list for a future
 * "Earnings" screen — built as one endpoint now rather than two, since
 * both reads are cheap and a rider opening the app once already wants
 * both numbers together.
 *
 * today_total sums only 'delivery_earning' entries created since local
 * midnight — deliberately excludes payouts/adjustments/incentives from
 * the "today" figure so it reads as "what did today's deliveries pay",
 * not a mixed running total; `balance` (riders.earnings_balance) is the
 * one true "what does the platform currently owe this rider" number
 * and already reflects every entry type.
 *
 * "Local midnight" uses the SERVER's timezone (same as every other
 * date-boundary calculation in this codebase — no per-rider timezone
 * concept exists anywhere, e.g. app-version.php's maintenance windows,
 * insights CSV export's date ranges).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/rider_earnings.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$db = Database::get();

$riderStmt = $db->prepare('SELECT earnings_balance FROM riders WHERE id = :id LIMIT 1');
$riderStmt->execute(['id' => $riderId]);
$rider = $riderStmt->fetch();
$balance = $rider ? (float) $rider['earnings_balance'] : 0.0;

$todayStmt = $db->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total
     FROM rider_earnings_ledger
     WHERE rider_id = :id
       AND entry_type = 'delivery_earning'
       AND created_at >= CURDATE()"
);
$todayStmt->execute(['id' => $riderId]);
$todayTotal = (float) ($todayStmt->fetch()['total'] ?? 0);

$recentStmt = $db->prepare(
    "SELECT rel.id, rel.entry_type, rel.amount, rel.order_id, o.order_code, rel.note, rel.created_at
     FROM rider_earnings_ledger rel
     LEFT JOIN orders o ON o.id = rel.order_id
     WHERE rel.rider_id = :id
     ORDER BY rel.created_at DESC, rel.id DESC
     LIMIT 20"
);
$recentStmt->execute(['id' => $riderId]);
$recentRows = $recentStmt->fetchAll();

$recent = array_map(static function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'entry_type' => $row['entry_type'],
        'amount' => (float) $row['amount'],
        'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
        'order_code' => $row['order_code'],
        'note' => $row['note'],
        'created_at' => $row['created_at'],
    ];
}, $recentRows);

respond_ok([
    'today_total' => round($todayTotal, 2),
    'balance' => round($balance, 2),
    'share_percent' => rider_earning_share_percent(),
    'recent' => $recent,
]);
