<?php
/**
 * POST /api/v1/customer/wallet-bank-details-save.php
 * Auth: Customer token
 * Request: { "payout_method": "bank"|"upi", "account_holder_name": "...",
 *            "bank_name"?: "...", "account_number"?: "...",
 *            "ifsc_code"?: "...", "upi_id"?: "..." }
 * Response: { "bank_details": {...} } — masked, same convention as
 *           wallet-bank-details-get.php.
 *
 * PENDING.md §37, migration 65. Thin wrapper around
 * validate_wallet_payout_fields()/save_customer_bank_details() — see
 * lib/customer_wallet_withdrawal.php's kdoc for why bank fields are
 * optional here (a UPI-only save is valid, unlike the restaurant
 * version which always requires full bank details).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/customer_wallet_withdrawal.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['payout_method', 'account_holder_name']);

[$holder, $bank, $accountNumber, $ifsc, $upi] = validate_wallet_payout_fields(
    (string) $body['payout_method'],
    (string) $body['account_holder_name'],
    isset($body['bank_name']) ? (string) $body['bank_name'] : null,
    isset($body['account_number']) ? (string) $body['account_number'] : null,
    isset($body['ifsc_code']) ? (string) $body['ifsc_code'] : null,
    isset($body['upi_id']) ? (string) $body['upi_id'] : null
);

$db = Database::get();
$row = save_customer_bank_details($db, $customerId, $holder, $bank, $accountNumber, $ifsc, $upi);

respond_ok(['bank_details' => serialize_customer_bank_details($row)]);
