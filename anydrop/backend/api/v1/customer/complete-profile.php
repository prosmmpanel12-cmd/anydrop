<?php
/**
 * POST /api/v1/customer/complete-profile.php
 * Auth: Customer token
 * Request:  { "name": "...", "mobile": "9876543210" }
 * Response: { "customer": {...full row} }
 *
 * customers.name / customers.mobile (01_Database_Schema.md) are both
 * nullable — email-OTP signup (auth/customer-verify-otp.php) creates the
 * row with just an email, so a brand-new customer has neither set yet.
 * The app calls this right after its first successful verify-otp (when
 * the returned `customer.name` or `customer.mobile` comes back null) to
 * collect both in one screen before letting the customer into Home.
 *
 * Both fields are required here — this is the "complete your profile"
 * step, not a general partial-update endpoint (that's a job for a future
 * profile-edit screen in Settings, if/when one is built; this one only
 * ever fills in the two fields OTP signup leaves blank).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['name', 'mobile']);

$name = trim((string) $body['name']);
if ($name === '' || mb_strlen($name) > 100) {
    respond_error('validation_error', 422, ['fields' => ['name']]);
}

// mobile column is VARCHAR(15) — same 10-digit Indian mobile shape the
// rest of the backend assumes (restaurants.owner_mobile has no explicit
// validation either, but this is the first customer-facing mobile field
// ever collected, so it's worth actually checking here).
$mobile = preg_replace('/\D/', '', (string) $body['mobile']);
if (strlen($mobile) !== 10) {
    respond_error('validation_error', 422, ['fields' => ['mobile']]);
}

$db = Database::get();

// Two different customer rows landing on the same mobile number is legal
// today (mobile has no UNIQUE constraint, unlike email) — but worth a
// clear error instead of a silent duplicate, same spirit as email's own
// UNIQUE-backed conflict handling elsewhere.
$dupStmt = $db->prepare('SELECT id FROM customers WHERE mobile = :mobile AND id != :id LIMIT 1');
$dupStmt->execute(['mobile' => $mobile, 'id' => $customerId]);
if ($dupStmt->fetch()) {
    respond_error('mobile_already_in_use', 409, ['fields' => ['mobile']]);
}

$upd = $db->prepare('UPDATE customers SET name = :name, mobile = :mobile WHERE id = :id');
$upd->execute(['name' => $name, 'mobile' => $mobile, 'id' => $customerId]);

$fetch = $db->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $customerId]);
$customer = $fetch->fetch();

if (!$customer) {
    respond_error('not_found', 404);
}

respond_ok(['customer' => $customer]);
