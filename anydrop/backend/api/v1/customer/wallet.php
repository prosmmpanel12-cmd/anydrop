<?php
/**
 * GET /api/v1/customer/wallet
 * Auth: Customer token
 * Response: { "balance": 125.50, "transactions": [ { id, type, amount,
 *              reason, note, balance_after, created_at }, ... ] }
 *
 * Item 26 (Customer Wallet, recall.md section 18; doc 19 §3). Read-
 * only endpoint — v1 has no customer-initiated top-up or withdrawal
 * (recall.md section 18's feature list is refund/cashback/admin-
 * adjustment credits and wallet-as-payment debits, all system/admin-
 * triggered, never a customer "add money" action), so there's no POST
 * here yet. `transactions` is capped at 50 most-recent rows — same
 * cap list_wallet_transactions() defaults to; add ?page= pagination
 * later if a customer's history ever realistically exceeds that.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/wallet.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$db = Database::get();

$balance = get_wallet_balance($db, $customerId);
$transactions = list_wallet_transactions($db, $customerId, 50);

respond_ok([
    'balance' => $balance,
    'transactions' => array_map(function ($t) {
        return [
            'id' => (int) $t['id'],
            'order_id' => $t['order_id'] !== null ? (int) $t['order_id'] : null,
            'type' => $t['type'],
            'amount' => (float) $t['amount'],
            'reason' => $t['reason'],
            'note' => $t['note'],
            'balance_after' => (float) $t['balance_after'],
            'created_at' => $t['created_at'],
        ];
    }, $transactions),
]);
