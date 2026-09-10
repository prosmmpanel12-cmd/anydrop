<?php
/**
 * GET /api/v1/rider/cod-deposit-history.php
 * Auth: Rider token
 *
 * Deep Plan Phase 6 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_
 * CashFlow_2026-09-09.md §6) — "list of past deposits: date, amount,
 * status (auto-verified / manual / pending), reference."
 *
 * Source of truth: rider_cod_ledger WHERE entry_type = 'settlement_to_admin'
 * for this rider — the exact same table Phase 5's self-service UPI
 * deposits (record_rider_cod_deposit_via_upi()) and the pre-existing
 * admin-side "Record Settlement" button (record_rider_settlement())
 * both already write to, per lib/rider_ledger.php. This endpoint does
 * not write anything, only reads.
 *
 * Row-shape note: a rider_cod_ledger 'settlement_to_admin' row can
 * come from two different origins, distinguished by
 * payment_transaction_id (nullable, migration 82):
 *   - NULL  -> admin manually clicked "Record Settlement"
 *              (rider-settlements.php). created_by = 'admin'.
 *   - set   -> this rider's own self-service UPI deposit (Phase 5).
 *              created_by = 'system'. Join payment_transactions to
 *              tell auto-verified apart from admin-approved-via-UTR:
 *              verified_by_admin_id IS NULL means the UPIPE auto-verify
 *              path confirmed it without any human review; NOT NULL
 *              means an admin approved it from the manual-UTR queue
 *              (admin/payment-pending.php) — same distinction that
 *              queue itself is built around, not a new concept.
 *
 * No pagination — a single rider's lifetime deposit count is small
 * (same "always small, re-fetch whole list" reasoning statement.php's
 * own kdoc already uses for a single day of orders); capped at the
 * last 200 rows as a sane hard ceiling, not an expected real limit.
 *
 * Response:
 * {
 *   "deposits": [
 *     { "id", "created_at", "amount" (positive, ledger amount is
 *       stored negative — this flips sign for display),
 *       "status": "auto_verified" | "manual_review" | "manual_settlement",
 *       "reference": string|null, "note" }, ...
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

$db = Database::get();

$stmt = $db->prepare(
    "SELECT
        l.id, l.amount, l.note, l.created_by, l.created_at,
        pt.verified_by_admin_id, pt.utr, pt.provider_bank_ref, pt.provider_txn_id
     FROM rider_cod_ledger l
     LEFT JOIN payment_transactions pt ON pt.id = l.payment_transaction_id
     WHERE l.rider_id = :rid AND l.entry_type = 'settlement_to_admin'
     ORDER BY l.created_at DESC
     LIMIT 200"
);
$stmt->execute(['rid' => $riderId]);
$rows = $stmt->fetchAll();

$deposits = array_map(static function (array $r): array {
    // l.amount is stored negative for this entry_type (write_rider_cod_ledger_entry's
    // signed-amount convention) — flip to positive for display, this
    // screen only ever shows deposits, never a mixed +/- ledger.
    $amount = round(abs((float) $r['amount']), 2);

    if ($r['created_by'] === 'admin') {
        // payment_transaction_id was NULL -> admin's manual "Record
        // Settlement" button, no UPI transaction behind it at all.
        $status = 'manual_settlement';
        $reference = null;
    } elseif ($r['verified_by_admin_id'] !== null) {
        $status = 'manual_review';
        $reference = $r['utr'] ?? $r['provider_bank_ref'] ?? $r['provider_txn_id'];
    } else {
        $status = 'auto_verified';
        $reference = $r['provider_bank_ref'] ?? $r['provider_txn_id'] ?? $r['utr'];
    }

    return [
        'id' => (int) $r['id'],
        'created_at' => $r['created_at'],
        'amount' => $amount,
        'status' => $status,
        'reference' => $reference,
        'note' => $r['note'],
    ];
}, $rows);

respond_ok(['deposits' => $deposits]);
