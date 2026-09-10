<?php
/**
 * POST /api/v1/rider/payout-bank-details-save.php
 * Auth: Rider token
 * Request: { "payout_method": "bank"|"upi", "account_holder_name": "...",
 *            "bank_name"?: "...", "account_number"?: "...",
 *            "ifsc_code"?: "...", "upi_id"?: "...", "otp": "123456" }
 * Response: { "bank_details": {...} } — masked, same convention as
 *           payout-bank-details-get.php.
 *
 * Deep-plan §21, migration 74. Thin wrapper around
 * validate_rider_payout_fields()/save_rider_bank_details() — see
 * lib/rider_payout.php's kdoc for why bank fields are optional here
 * (a UPI-only save is a valid payout method).
 *
 * App-owner ask, 2026-09-09: confirm-before-save, rider-style (rider
 * login is OTP-based, so the confirm step is an OTP too, not a
 * password). The app must call payout-bank-details-request-otp.php
 * first to get one sent, then include it here as "otp". Checked against
 * the same email_otps table / same expiry+max-attempts rules
 * rider-verify-otp.php uses, keyed off the rider's own registered email
 * (not off anything in the request body — a rider can't confirm with
 * someone else's OTP). Nothing is written to
 * rider_payout_bank_details unless the OTP check passes.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/rider_payout.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['payout_method', 'account_holder_name', 'otp']);

[$holder, $bank, $accountNumber, $ifsc, $upi] = validate_rider_payout_fields(
    (string) $body['payout_method'],
    (string) $body['account_holder_name'],
    isset($body['bank_name']) ? (string) $body['bank_name'] : null,
    isset($body['account_number']) ? (string) $body['account_number'] : null,
    isset($body['ifsc_code']) ? (string) $body['ifsc_code'] : null,
    isset($body['upi_id']) ? (string) $body['upi_id'] : null
);

$db = Database::get();

$riderStmt = $db->prepare('SELECT email FROM riders WHERE id = :id LIMIT 1');
$riderStmt->execute(['id' => $riderId]);
$email = $riderStmt->fetchColumn();

if ($email === false || $email === null || $email === '') {
    respond_error('rider_email_missing', 500);
}

$otp = trim((string) $body['otp']);
$maxAttempts = (int) get_setting('otp_max_attempts', 3);

$otpStmt = $db->prepare(
    'SELECT * FROM email_otps WHERE email = :e AND is_used = 0 ORDER BY id DESC LIMIT 1'
);
$otpStmt->execute(['e' => $email]);
$record = $otpStmt->fetch();

if (!$record) {
    respond_error('otp_not_found', 400);
}

if (strtotime($record['expires_at']) < time()) {
    respond_error('otp_expired', 400);
}

if ((int) $record['attempts'] >= $maxAttempts) {
    respond_error('otp_max_attempts_exceeded', 400);
}

if ($record['otp_code'] !== $otp) {
    $upd = $db->prepare('UPDATE email_otps SET attempts = attempts + 1 WHERE id = :id');
    $upd->execute(['id' => $record['id']]);
    respond_error('invalid_otp', 401, ['attempts_remaining' => max(0, $maxAttempts - (int) $record['attempts'] - 1)]);
}

$upd = $db->prepare('UPDATE email_otps SET is_used = 1 WHERE id = :id');
$upd->execute(['id' => $record['id']]);

$row = save_rider_bank_details($db, $riderId, $holder, $bank, $accountNumber, $ifsc, $upi);

write_audit_log('rider', $riderId, 'payout_bank_details_saved', [
    'rider_id' => $riderId,
    'payout_method' => $body['payout_method'],
]);

respond_ok(['bank_details' => serialize_rider_bank_details($row)]);
