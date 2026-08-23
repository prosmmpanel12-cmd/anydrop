<?php
/**
 * GET /api/v1/customer/payment-methods.php?delivery_address_id=
 * (Mapped from clean URL GET /customer/payment-methods per this
 * project's usual clean-URL convention — see 02_API_Contract.md)
 * Auth: Customer token
 *
 * recall.md Phase B item 15 — lets checkout ask "which payment methods
 * can I even show here" BEFORE the customer picks one, same
 * "pre-check so the UI can grey things out with a reason instead of
 * only finding out from a 422 on submit" pattern as
 * customer/cod-eligibility.php (item 4/migration 35), which this
 * endpoint deliberately complements rather than duplicates:
 *
 * - THIS endpoint answers the coarse question — is UPI/COD allowed in
 *   this area AT ALL (migration 37, area_payment_restrictions).
 * - cod-eligibility.php answers the finer question — given COD is
 *   allowed at all, does THIS customer/order also clear the COD-specific
 *   sub-rules (min prepaid orders, max amount, daily cap, new-customer
 *   block — migration 35, area_cod_rules).
 *
 * A checkout screen showing COD as an option should call both: hide
 * the COD radio entirely if this endpoint says cod_allowed=false, or
 * show it but disabled with cod-eligibility.php's own reason if this
 * endpoint allows COD in general but the customer doesn't currently
 * meet the finer rule. Either way this is a convenience pre-check
 * only — orders/create.php re-evaluates both layers server-side
 * independently and is the actual source of truth.
 *
 * `delivery_address_id` is optional — omit it (no address picked yet)
 * and the platform-wide default restriction applies, same as
 * get_effective_payment_restrictions()'s null-lat/lng fallback.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment_restrictions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$db = Database::get();

$addressId = isset($_GET['delivery_address_id']) && $_GET['delivery_address_id'] !== ''
    ? (int) $_GET['delivery_address_id'] : null;

$lat = null;
$lng = null;
if ($addressId !== null) {
    $addrStmt = $db->prepare('SELECT latitude, longitude FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
    $addrStmt->execute(['id' => $addressId, 'cid' => $customerId]);
    $addressRow = $addrStmt->fetch();
    if (!$addressRow) {
        respond_error('validation_error', 422, ['fields' => ['delivery_address_id']]);
    }
    $lat = $addressRow['latitude'] !== null ? (float) $addressRow['latitude'] : null;
    $lng = $addressRow['longitude'] !== null ? (float) $addressRow['longitude'] : null;
}

$restriction = get_effective_payment_restrictions($db, $lat, $lng);

respond_ok([
    'upi_allowed' => $restriction['upi_allowed'],
    'cod_allowed' => $restriction['cod_allowed'],
]);
