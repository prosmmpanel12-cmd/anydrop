<?php
/**
 * GET  /api/v1/rider/payout.php  — payout request history
 * POST /api/v1/rider/payout.php  — request a payout
 * Auth: Rider token
 *
 * GET Response: { "requests": [ { id, amount, payout_method, status,
 *   payout_reference, reject_reason, requested_at, ... }, ... ] }
 *
 * POST Request: { "amount": 500.00, "payout_method": "bank"|"upi",
 *   "account_holder_name": "...", "bank_name"?, "account_number"?,
 *   "ifsc_code"?, "upi_id"? }
 * POST Response (success): { "request_id": 12, "balance": 0.00 }
 * POST Response (failure): standard respond_error with a specific
 *   `error` string ('insufficient_balance' | 'below_minimum_amount' |
 *   'invalid_amount') — see request_rider_payout()'s own kdoc.
 *
 * Deep-plan §21, migration 74. Thin wrapper around
 * lib/rider_payout.php's request_rider_payout()/
 * list_rider_payout_requests_for_rider() — this endpoint implements no
 * balance/lock logic of its own, same division of responsibility every
 * other endpoint in this codebase follows (see
 * customer/wallet-withdrawal.php, this feature's direct model).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/rider_payout.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requests = list_rider_payout_requests_for_rider($db, $riderId, 50);

    respond_ok([
        'requests' => array_map(function ($p) {
            return [
                'id' => (int) $p['id'],
                'amount' => (float) $p['amount'],
                'payout_method' => $p['payout_method'],
                'status' => $p['status'],
                'payout_reference' => $p['payout_reference'],
                'reject_reason' => $p['reject_reason'],
                'requested_at' => $p['requested_at'],
                'approved_at' => $p['approved_at'],
                'processing_at' => $p['processing_at'],
                'completed_at' => $p['completed_at'],
                'rejected_at' => $p['rejected_at'],
            ];
        }, $requests),
    ]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    require_fields($body, ['amount', 'payout_method', 'account_holder_name']);

    $amount = (float) $body['amount'];

    [$holder, $bank, $accountNumber, $ifsc, $upi] = validate_rider_payout_fields(
        (string) $body['payout_method'],
        (string) $body['account_holder_name'],
        isset($body['bank_name']) ? (string) $body['bank_name'] : null,
        isset($body['account_number']) ? (string) $body['account_number'] : null,
        isset($body['ifsc_code']) ? (string) $body['ifsc_code'] : null,
        isset($body['upi_id']) ? (string) $body['upi_id'] : null
    );

    $result = request_rider_payout(
        $db, $riderId, $amount, (string) $body['payout_method'],
        $holder, $bank, $accountNumber, $ifsc, $upi
    );

    if (!$result['ok']) {
        $httpCode = in_array($result['error'], ['insufficient_balance', 'below_minimum_amount', 'invalid_amount'], true) ? 422 : 400;
        respond_error($result['error'], $httpCode, $result);
    }

    respond_ok([
        'request_id' => $result['request_id'],
        'balance' => $result['balance'],
    ]);
} else {
    respond_error('method_not_allowed', 405);
}
