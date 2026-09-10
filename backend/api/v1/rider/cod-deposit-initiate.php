<?php
/**
 * POST /api/v1/rider/cod-deposit-initiate.php   { "amount": 500.00 }
 * Auth: Rider token
 *
 * Deep Plan Phase 5 (docs/00_Deep_Plan_Statement_CODLimit_QRPay_
 * CashFlow_2026-09-09.md §5) — the rider-side counterpart to
 * api/v1/orders/payment-upi-create.php. Starts (or idempotently
 * resumes) a UPI QR payment FROM the rider TO admin's own UPI ID,
 * reusing PaymentService/UpipeProvider exactly as-is — see
 * PaymentService::initiateRiderCodDeposit()'s own kdoc for how the
 * same payment_transactions table represents this shape.
 *
 * Partial deposits are allowed (app owner decision, 2026-09-09) — the
 * only server-side amount rule is 0 < amount <= rider's current
 * cod_cash_held. A rider who wants to clear their whole balance can
 * still just send the full cod_cash_held value; nothing forces a
 * partial amount, it's simply not required to be the full amount.
 *
 * Response `data` = the same client_payload shape doc 23 §2/§8
 * documents for the customer flow (method, txn_ref, upi_link, upi_id,
 * payee_name, amount, expires_in_sec, utr_required, utr_window_sec,
 * poll_interval_sec, instructions[]) — the Rider App's deposit screen
 * renders the QR client-side exactly like UpiPaymentActivity does,
 * plus a `txn_id` the app must keep for the status-poll/UTR-submit
 * calls below (there is no order id to key off of here).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment/PaymentService.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
if (($owner['status'] ?? null) !== 'approved') {
    respond_error('not_approved', 403);
}
$riderId = (int) $owner['owner_id'];

$body = get_json_body();
$amountRaw = $body['amount'] ?? null;
if ($amountRaw === null || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
    respond_error('validation_error', 422, ['fields' => ['amount']]);
}
$amount = round((float) $amountRaw, 2);

$db = Database::get();

$riderStmt = $db->prepare('SELECT cod_cash_held FROM riders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$riderStmt->execute(['id' => $riderId]);
$rider = $riderStmt->fetch();
if (!$rider) {
    respond_error('account_suspended', 403);
}

$codCashHeld = (float) $rider['cod_cash_held'];
if ($codCashHeld <= 0) {
    respond_error('nothing_to_deposit', 422);
}
if ($amount > $codCashHeld + 0.01) {
    respond_error('amount_exceeds_cod_held', 422, ['cod_cash_held' => $codCashHeld]);
}

$result = PaymentService::initiateRiderCodDeposit($db, $riderId, $amount);

if (!$result['ok']) {
    respond_error($result['error'] ?? 'deposit_initiation_failed', 422, ['message' => $result['message'] ?? null]);
}

$payload = $result['client_payload'];
$payload['txn_id'] = $result['txn_id'];

respond_ok($payload);
