<?php
/**
 * GET  /api/v1/customer/wallet-withdrawal.php  — withdrawal history
 * POST /api/v1/customer/wallet-withdrawal.php  — request a withdrawal
 * Auth: Customer token
 *
 * GET Response: { "withdrawals": [ { id, amount, payout_method,
 *   status, payout_reference, requested_at, ... }, ... ] }
 *
 * POST Request: { "amount": 250.00, "payout_method": "bank"|"upi",
 *   "account_holder_name": "...", "bank_name"?, "account_number"?,
 *   "ifsc_code"?, "upi_id"? }
 * POST Response (success): { "withdrawal_id": 12, "balance": 0.00 }
 * POST Response (failure): standard respond_error with a specific
 *   `error` string ('insufficient_balance' | 'below_minimum_amount' |
 *   'invalid_amount') — see request_wallet_withdrawal()'s own kdoc.
 *
 * PENDING.md §37, migration 65. Thin wrapper around
 * lib/customer_wallet_withdrawal.php's request_wallet_withdrawal()/
 * list_wallet_withdrawals_for_customer() — this endpoint does not
 * implement any balance/lock logic of its own, it only validates
 * input shape and calls the library, same division of responsibility
 * every other endpoint in this codebase follows.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/customer_wallet_withdrawal.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $withdrawals = list_wallet_withdrawals_for_customer($db, $customerId, 50);

    respond_ok([
        'withdrawals' => array_map(function ($w) {
            return [
                'id' => (int) $w['id'],
                'amount' => (float) $w['amount'],
                'payout_method' => $w['payout_method'],
                'status' => $w['status'],
                'payout_reference' => $w['payout_reference'],
                'reject_reason' => $w['reject_reason'],
                'requested_at' => $w['requested_at'],
                'approved_at' => $w['approved_at'],
                'processing_at' => $w['processing_at'],
                'completed_at' => $w['completed_at'],
                'rejected_at' => $w['rejected_at'],
            ];
        }, $withdrawals),
    ]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    require_fields($body, ['amount', 'payout_method', 'account_holder_name']);

    $amount = (float) $body['amount'];

    [$holder, $bank, $accountNumber, $ifsc, $upi] = validate_wallet_payout_fields(
        (string) $body['payout_method'],
        (string) $body['account_holder_name'],
        isset($body['bank_name']) ? (string) $body['bank_name'] : null,
        isset($body['account_number']) ? (string) $body['account_number'] : null,
        isset($body['ifsc_code']) ? (string) $body['ifsc_code'] : null,
        isset($body['upi_id']) ? (string) $body['upi_id'] : null
    );

    $result = request_wallet_withdrawal(
        $db, $customerId, $amount, (string) $body['payout_method'],
        $holder, $bank, $accountNumber, $ifsc, $upi
    );

    if (!$result['ok']) {
        $httpCode = in_array($result['error'], ['insufficient_balance', 'below_minimum_amount', 'invalid_amount'], true) ? 422 : 400;
        respond_error($result['error'], $httpCode, $result);
    }

    respond_ok([
        'withdrawal_id' => $result['withdrawal_id'],
        'balance' => $result['balance'],
    ]);
} else {
    respond_error('method_not_allowed', 405);
}
