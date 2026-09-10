<?php
/**
 * POST /api/v1/rider/cod-deposit-submit-utr.php?txn_id={id}   { "utr": "123456789012" }
 * Auth: Rider token
 *
 * Deep Plan Phase 5 — rider-side counterpart to
 * api/v1/orders/payment-upi-submit-utr.php. Fallback for when
 * auto-verify doesn't resolve the deposit within the UTR window (same
 * "same manual-UTR-entry fallback the customer flow already has" the
 * deep plan doc calls for). Submitting a UTR does NOT confirm the
 * deposit by itself — it only queues the transaction for admin review
 * on admin/payment-pending.php, exactly like the customer flow. The
 * Rider App should keep polling cod-deposit-status.php after this
 * call, same as UpiPaymentActivity does.
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
$riderId = (int) $owner['owner_id'];

$txnId = (int) ($_GET['txn_id'] ?? 0);
if ($txnId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['txn_id']]);
}

$body = get_json_body();
require_fields($body, ['utr']);
$utr = trim((string) $body['utr']);

$db = Database::get();

$result = PaymentService::submitRiderDepositUtr($db, $riderId, $txnId, $utr);

if (!$result['ok']) {
    respond_error($result['error'] ?? 'utr_submission_failed', 422);
}

respond_ok(['status' => $result['status']]);
