<?php
/**
 * GET /api/v1/rider/payout-bank-details-get.php
 * Auth: Rider token
 * Response: { "bank_details": {...} | null }
 *
 * Deep-plan §21, migration 74. Mirrors
 * customer/wallet-bank-details-get.php's shape exactly — see
 * lib/rider_payout.php's serialize_rider_bank_details() for why
 * account_number comes back masked.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/rider_payout.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$db = Database::get();
$row = get_rider_bank_details($db, $riderId);

respond_ok([
    'bank_details' => serialize_rider_bank_details($row),
]);
