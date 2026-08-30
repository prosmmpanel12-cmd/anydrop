<?php
/**
 * POST /api/v1/restaurant/bank-details-save.php
 * Auth: Restaurant token
 * Request: { "account_holder_name": "...", "bank_name": "...",
 *            "account_number": "...", "ifsc_code": "...",
 *            "upi_id"?: "..." }
 * Response: { "bank_details": {...} } — account_number_masked here
 *           reflects the value just saved, same masking as
 *           bank-details-get.php.
 *
 * PENDING.md §15, migration 59. Every restaurant-initiated save
 * (create OR edit of an existing row) resets verification_status to
 * 'pending' and clears any prior admin_remarks/verified_by/verified_at
 * — an edited account number is a *new* claim about where money
 * should go, so it needs re-review the same as a first-time
 * submission; carrying forward a stale "verified" flag onto changed
 * account details would defeat the point of verification. This is
 * the one meaningful way this endpoint differs from
 * admin/settlements.php's save_bank_details action, which sets
 * verification_status = 'verified' directly (an admin typing values
 * on the restaurant's behalf is already supervised entry) — see
 * migration 59's kdoc.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/audit.php';
require_once __DIR__ . '/../../../lib/restaurant_bank.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_bank_details');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['account_holder_name', 'bank_name', 'account_number', 'ifsc_code']);

[$holder, $bank, $accountNumber, $ifsc, $upi] = validate_bank_fields(
    (string) $body['account_holder_name'],
    (string) $body['bank_name'],
    (string) $body['account_number'],
    (string) $body['ifsc_code'],
    isset($body['upi_id']) ? (string) $body['upi_id'] : null
);

$db = Database::get();

// INSERT ... ON DUPLICATE KEY UPDATE, same pattern as
// admin/settlements.php's save_bank_details action — restaurant_id is
// the primary key (migration 38), so this is always exactly one row
// per restaurant, create-or-replace.
$stmt = $db->prepare(
    'INSERT INTO restaurant_bank_details
        (restaurant_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id, verification_status, admin_remarks, verified_by_admin_id, verified_at)
     VALUES
        (:rid, :holder, :bank, :acc, :ifsc, :upi, \'pending\', NULL, NULL, NULL)
     ON DUPLICATE KEY UPDATE
        account_holder_name = :holder2, bank_name = :bank2, account_number = :acc2, ifsc_code = :ifsc2, upi_id = :upi2,
        verification_status = \'pending\', admin_remarks = NULL, verified_by_admin_id = NULL, verified_at = NULL'
);
$stmt->execute([
    'rid' => $restaurantId,
    'holder' => $holder, 'bank' => $bank, 'acc' => $accountNumber, 'ifsc' => $ifsc, 'upi' => $upi,
    'holder2' => $holder, 'bank2' => $bank, 'acc2' => $accountNumber, 'ifsc2' => $ifsc, 'upi2' => $upi,
]);

write_audit_log('restaurant', $restaurantId, 'bank_details_submitted', [
    'restaurant_id' => $restaurantId,
    'ifsc_code' => $ifsc,
    // Last 4 only in the audit log too — the log is meant to record
    // *that* a change happened and roughly what changed, not to
    // become a second place the full account number is stored in
    // plaintext.
    'account_number_last4' => substr($accountNumber, -4),
]);

$fetch = $db->prepare('SELECT * FROM restaurant_bank_details WHERE restaurant_id = :id LIMIT 1');
$fetch->execute(['id' => $restaurantId]);
$row = $fetch->fetch();

respond_ok(['bank_details' => serialize_bank_details_for_restaurant($row)]);
