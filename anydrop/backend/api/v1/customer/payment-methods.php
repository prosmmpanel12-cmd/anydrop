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
 *
 * 2026-08-23 (item 26 §D.13) — also returns `wallet_allowed` +
 * `wallet_balance`. Unlike upi_allowed/cod_allowed, wallet has no
 * area-restriction concept at all (orders/create.php's own §D.12
 * comment already established this — a wallet debit is the
 * customer's own already-verified balance, not a new payment rail
 * an area needs to vet), so wallet_allowed here means something
 * different from the other two flags: it is NOT "is this rail
 * enabled in this area" but "does this customer currently have a
 * positive wallet balance worth showing a radio option for" — a
 * zero-balance wallet still exists (get_or_create_wallet() creates
 * it lazily) but there is nothing useful to spend, so the app should
 * hide the option rather than show it disabled-with-reason the way
 * cod/upi do. CheckoutActivity's own order-submit path
 * (orders/create.php) remains the actual source of truth either way
 * — this stays a convenience pre-check only, same as upi_allowed/
 * cod_allowed already are.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/payment_restrictions.php';
require_once __DIR__ . '/../../../lib/wallet.php';

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

$walletBalance = get_wallet_balance($db, $customerId);

respond_ok([
    'upi_allowed' => $restriction['upi_allowed'],
    'cod_allowed' => $restriction['cod_allowed'],
    'wallet_allowed' => $walletBalance > 0,
    'wallet_balance' => round($walletBalance, 2),
]);
