<?php
/**
 * GET /api/v1/rider/cod-deposit-status.php?txn_id={id}
 * Auth: Rider token
 *
 * Deep Plan Phase 5 — rider-side counterpart to
 * api/v1/orders/payment-upi-verify.php. Polled every
 * poll_interval_sec (server-supplied at initiate time, same 10s
 * default) while a deposit QR is on screen. Same spoof-safety rule as
 * the customer flow: this endpoint never accepts a client-asserted
 * "I paid" claim, status always comes from `payment_transactions` via
 * PaymentService::getRiderDepositClientStatus(), which re-runs the
 * provider's own verify() (including Paytm auto-verify, if an MID is
 * configured) on every call.
 *
 * Response `data.status` — one of:
 *   not_found | initiated | utr_pending_window | utr_available |
 *   utr_submitted | success | failed | expired
 * plus `utr_allowed_in_sec` / `reject_reason` where applicable — same
 * vocabulary as the customer UPI flow, deliberately, so the Rider
 * App's polling screen can share most of its state-machine logic with
 * a future rider-side reuse of UpiPaymentActivity's own pattern.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment/PaymentService.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$txnId = (int) ($_GET['txn_id'] ?? 0);
if ($txnId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['txn_id']]);
}

$db = Database::get();

$status = PaymentService::getRiderDepositClientStatus($db, $riderId, $txnId);
respond_ok($status);
