<?php
/**
 * GET /api/v1/customer/wallet-bank-details-get.php
 * Auth: Customer token
 * Response: { "bank_details": {...} | null }
 *
 * PENDING.md §37, migration 65. Mirrors
 * restaurant/bank-details-get.php's shape exactly — see
 * lib/customer_wallet_withdrawal.php's serialize_customer_bank_details()
 * for why account_number comes back masked.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/customer_wallet_withdrawal.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$db = Database::get();
$row = get_customer_bank_details($db, $customerId);

respond_ok([
    'bank_details' => serialize_customer_bank_details($row),
]);
