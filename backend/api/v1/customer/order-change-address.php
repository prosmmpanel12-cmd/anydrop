<?php
/**
 * POST /api/v1/customer/order-change-address.php?id={orderId}
 * Auth: Customer token (must own the order)
 * Body: { "delivery_address_id": <id of one of the customer's own saved
 *         customer_addresses rows> }
 *
 * Plan doc 127 §4 / 128 — the cancel-retention flow's "change delivery
 * address" option. Flagged in doc 127 as the single biggest unknown in
 * that whole plan ("needs orders/create.php traced for exactly which
 * area/COD/delivery-fee checks depend on delivery location") — traced
 * this session (create.php + lib/orders.php's price_cart() +
 * lib/delivery_pricing.php + lib/cod_rules.php + lib/payment_restrictions.php),
 * and this endpoint re-runs the same three location-dependent checks
 * create.php does at order-placement time, now against the NEW address
 * instead of the original one:
 *   1. get_effective_payment_restrictions() + is_payment_method_allowed_in_area()
 *      — is this order's existing payment_method even usable from the
 *      new address's area?
 *   2. get_effective_cod_rule() + evaluate_cod_eligibility() — only for
 *      'cod' orders; re-checked against the RECOMPUTED grand_total
 *      (delivery fee can change with the new address, which can push a
 *      COD order over an area's max_cod_order_amount cap).
 *   3. calculate_delivery_fee() — the new address is almost certainly a
 *      different distance from the restaurant, so delivery_charge (and
 *      therefore grand_total) is recomputed, never just carried over
 *      from the original address.
 *
 * Deliberately NOT re-run: price_cart()'s cart-validity / min-order /
 * coupon / restaurant-offer logic. Those all priced the CART, which
 * hasn't changed here — only the delivery destination has. Re-running
 * the full offers engine against an order that already has committed
 * coupon_usages/offer_usages rows would risk double-counting or
 * silently re-selecting a different "best offer" than what the
 * customer actually saw at checkout. item_total/discount_amount/
 * offer_discount_amount/tax_amount are therefore left untouched;
 * free_delivery_discount_amount (if this order used a free-delivery
 * offer) is simply re-capped at the NEW delivery_charge, since a
 * free-delivery discount can never legitimately exceed the fee it's
 * discounting — same cap select_best_free_delivery_offer() itself
 * enforces at order-creation time.
 *
 * Gating (deliberately conservative, per doc 127 §4's own flag that
 * this needed real scoping):
 *   - Only while order.status is 'pending' or 'accepted' — same set as
 *     CANCELLABLE_STATUSES on the Android side and orders/cancel.php's
 *     own gate. No rider assigned yet, so re-routing is a pure
 *     destination-pin change, not a mid-delivery re-route.
 *   - Only while order.payment_status is 'pending' — a 'paid' order
 *     (wallet, debited synchronously at creation; or UPI, confirmed by
 *     admin/webhook) has already collected/settled a specific
 *     grand_total. Changing delivery_charge after that would leave the
 *     collected amount and the order's own total row out of sync with
 *     no refund/top-up flow to reconcile it — out of scope for this
 *     session, flagged here rather than silently mishandled. A COD
 *     order's payment_status stays 'pending' for its entire lifecycle
 *     (no 'delivered' flip exists yet, per lib/orders.php's own note),
 *     so in practice this only ever blocks wallet orders and
 *     already-confirmed UPI orders — COD, the common case this feature
 *     is really for, is never blocked by this check.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/cod_rules.php';
require_once __DIR__ . '/../../../lib/payment_restrictions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);

$body = get_json_body();
require_fields($body, ['delivery_address_id']);
$newAddressId = (int) $body['delivery_address_id'];

$db = Database::get();

$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ((int) $order['customer_id'] !== (int) $customerId) {
    respond_error('forbidden', 403);
}
// Same status set orders/cancel.php and the Android app's
// CANCELLABLE_STATUSES use — no rider assigned yet.
if (!in_array($order['status'], ['pending', 'accepted'], true)) {
    respond_error('order_not_eligible_for_address_change', 409);
}
// See file kdoc — a settled grand_total can't silently drift after
// money has actually moved.
if ($order['payment_status'] === 'paid') {
    respond_error('order_already_paid', 409);
}

// New address must belong to this same customer — same ownership check
// orders/create.php runs on delivery_address_id.
$addrStmt = $db->prepare(
    'SELECT id, latitude, longitude FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1'
);
$addrStmt->execute(['id' => $newAddressId, 'cid' => $customerId]);
$addressRow = $addrStmt->fetch();
if (!$addressRow) {
    respond_error('validation_error', 422, ['fields' => ['delivery_address_id']]);
}
$newLat = $addressRow['latitude'] !== null ? (float) $addressRow['latitude'] : null;
$newLng = $addressRow['longitude'] !== null ? (float) $addressRow['longitude'] : null;

// Restaurant row — needed for its own lat/lng (delivery-fee distance)
// and its admin-assigned area_id (the "combine both sides, strictest
// wins" input every one of these checks takes), same as create.php.
$restStmt = $db->prepare('SELECT * FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$restStmt->execute(['id' => $order['restaurant_id']]);
$restaurant = $restStmt->fetch();
if (!$restaurant) {
    respond_error('restaurant_not_found', 404);
}
$restaurantLat = $restaurant['latitude'] !== null ? (float) $restaurant['latitude'] : null;
$restaurantLng = $restaurant['longitude'] !== null ? (float) $restaurant['longitude'] : null;
$restaurantAreaId = $restaurant['area_id'] !== null ? (int) $restaurant['area_id'] : null;

// Check 1 — general area-wide payment-method gate against the NEW address.
$paymentRestriction = get_effective_payment_restrictions($db, $newLat, $newLng, $restaurantAreaId);
$methodAllowed = is_payment_method_allowed_in_area($paymentRestriction, $order['payment_method']);
if (!$methodAllowed['allowed']) {
    respond_error('payment_method_not_allowed', 422, ['reason' => $methodAllowed['reason']]);
}

// Recompute delivery fee against the NEW address first — the COD
// amount-cap check below needs the recomputed grand_total, same
// ordering create.php itself uses (COD enabled/count checks before
// pricing, amount cap after).
$deliveryPricing = calculate_delivery_fee($db, $restaurantLat, $restaurantLng, $newLat, $newLng, $restaurantAreaId);
$newDeliveryCharge = (float) $deliveryPricing['fee'];

// A free-delivery offer's discount can never exceed the fee it's
// discounting — re-cap at the new fee rather than re-running the whole
// offer-selection logic (see file kdoc for why offers aren't re-run).
$newFreeDeliveryDiscount = min((float) $order['free_delivery_discount_amount'], $newDeliveryCharge);

// Everything else (item_total, discount_amount, offer_discount_amount,
// tax_amount, platform_fee, packing_charge) is untouched — only the
// delivery leg of the bill changes when only the destination changes.
$newGrandTotal = round(
    (float) $order['item_total']
    - (float) $order['discount_amount']
    - (float) $order['offer_discount_amount']
    + ($newDeliveryCharge - $newFreeDeliveryDiscount)
    + (float) $order['platform_fee']
    + (float) $order['packing_charge']
    + (float) $order['tax_amount'],
    2
);

// Check 2 — COD-specific eligibility, only for COD orders, against the
// NEW address and the RECOMPUTED grand_total.
if ($order['payment_method'] === 'cod') {
    $codRule = get_effective_cod_rule($db, $newLat, $newLng, $restaurantAreaId);
    $codCheck = evaluate_cod_eligibility($db, $codRule, $customerId, $newGrandTotal);
    if (!$codCheck['eligible']) {
        respond_error('cod_not_eligible', 422, ['reason' => $codCheck['reason']]);
    }
}

$db->beginTransaction();
$upd = $db->prepare(
    'UPDATE orders SET
        delivery_address_id = :addr_id,
        delivery_charge = :delivery_charge,
        free_delivery_discount_amount = :fd_discount,
        grand_total = :grand_total
     WHERE id = :id'
);
$upd->execute([
    'addr_id' => $newAddressId,
    'delivery_charge' => $newDeliveryCharge,
    'fd_discount' => $newFreeDeliveryDiscount,
    'grand_total' => $newGrandTotal,
    'id' => $orderId,
]);
// Visible in the order timeline the same way a status change is —
// makes an address swap show up alongside "Order placed"/"Accepted"
// rather than happening invisibly.
insert_status_history(
    $db,
    $orderId,
    $order['status'],
    'customer',
    $customerId,
    'Delivery address changed'
);
$db->commit();

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
