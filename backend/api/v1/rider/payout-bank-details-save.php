<?php
/**
 * POST /api/v1/rider/payout-bank-details-save.php
 * Auth: Rider token
 * Request: { "payout_method": "bank"|"upi", "account_holder_name": "...",
 *            "bank_name"?: "...", "account_number"?: "...",
 *            "ifsc_code"?: "...", "upi_id"?: "..." }
 * Response: { "bank_details": {...} } — masked, same convention as
 *           payout-bank-details-get.php.
 *
 * Deep-plan §21, migration 74. Thin wrapper around
 * validate_rider_payout_fields()/save_rider_bank_details() — see
 * lib/rider_payout.php's kdoc for why bank fields are optional here
 * (a UPI-only save is a valid payout method).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/rider_payout.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['payout_method', 'account_holder_name']);

[$holder, $bank, $accountNumber, $ifsc, $upi] = validate_rider_payout_fields(
    (string) $body['payout_method'],
    (string) $body['account_holder_name'],
    isset($body['bank_name']) ? (string) $body['bank_name'] : null,
    isset($body['account_number']) ? (string) $body['account_number'] : null,
    isset($body['ifsc_code']) ? (string) $body['ifsc_code'] : null,
    isset($body['upi_id']) ? (string) $body['upi_id'] : null
);

$db = Database::get();
$row = save_rider_bank_details($db, $riderId, $holder, $bank, $accountNumber, $ifsc, $upi);

respond_ok(['bank_details' => serialize_rider_bank_details($row)]);
