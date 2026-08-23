<?php
/**
 * GET /api/v1/customer/cod-eligibility.php?delivery_address_id=&order_amount=
 * (Mapped from clean URL GET /customer/cod-eligibility per this project's
 * usual clean-URL convention — see 02_API_Contract.md)
 * Auth: Customer token
 *
 * recall.md item 4 — lets the checkout screen ask "can I even offer COD
 * as an option here" BEFORE the customer picks it and tries to place the
 * order, so a customer in a COD-ineligible area/situation sees the
 * option greyed out (with the reason) rather than only finding out after
 * tapping "Place Order" and getting a 422 back from orders/create.php.
 *
 * This is a convenience pre-check only — orders/create.php re-evaluates
 * the same rule server-side and is the actual source of truth; a client
 * that skips calling this endpoint (or an old app build that doesn't
 * know about it) still can't place an ineligible COD order, since
 * orders/create.php enforces evaluate_cod_eligibility() independently
 * either way.
 *
 * `order_amount` is optional — omit it (e.g. showing the payment method
 * list before the cart total is final) and only the amount-cap check is
 * skipped; every other check (enabled/disabled, order-count based)
 * still runs. `delivery_address_id` is optional too — omit it (no
 * address picked yet) and the platform-wide default rule applies, same
 * as get_effective_cod_rule()'s null-lat/lng fallback.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/cod_rules.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$db = Database::get();

$addressId = isset($_GET['delivery_address_id']) && $_GET['delivery_address_id'] !== ''
    ? (int) $_GET['delivery_address_id'] : null;
$orderAmount = isset($_GET['order_amount']) && $_GET['order_amount'] !== ''
    ? (float) $_GET['order_amount'] : null;

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

$rule = get_effective_cod_rule($db, $lat, $lng);
$eligibility = evaluate_cod_eligibility($db, $rule, $customerId, $orderAmount);

respond_ok([
    'eligible' => $eligibility['eligible'],
    'reason' => $eligibility['reason'],
    // Echoed back so the app can show a specific message ("COD available
    // for orders under ₹500" / "Available after 5 prepaid orders")
    // without needing its own copy of the rule thresholds — same
    // "server decides, app only displays" principle as the rest of
    // this item.
    'rule' => [
        'min_prepaid_orders' => $rule['min_prepaid_orders'],
        'max_cod_order_amount' => $rule['max_cod_order_amount'],
        'max_cod_orders_per_day' => $rule['max_cod_orders_per_day'],
    ],
]);
