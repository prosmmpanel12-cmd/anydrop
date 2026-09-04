<?php
/**
 * POST /api/v1/orders/{id}/payment/switch-to-cod
 * (Mapped via .htaccess to api/v1/orders/payment-switch-cod.php?id=$1,
 * same clean-URL convention as the other payment-upi-*.php endpoints.)
 * Auth: Customer token (must own the order)
 *
 * Real backend for the Native UPI payment screen's "Cancel and pay by
 * Cash on Delivery instead" button (docs/23_Native_UPI_Payment_
 * Gateway_Architecture_2026-08-23.md's own NEXT-SESSION follow-up
 * item #4 — UpiPaymentActivity.kt previously just left the screen and
 * showed the button's own label back as a toast, without touching the
 * backend at all; the order stayed payment_method='upi' the whole
 * time). This endpoint is the missing "actually do it" half.
 *
 * Only allowed when:
 *  - the order belongs to this customer,
 *  - payment_method is currently 'upi',
 *  - payment_status is not already 'paid' (a paid order has nothing
 *    to switch — reject outright rather than silently no-op, so the
 *    app doesn't show a false "switched" success state),
 *  - order.status hasn't reached a terminal not-payable state
 *    (cancelled/rejected/expired) — same set orders/create.php and
 *    payment-upi-create.php already treat as "can't pay this",
 *  - COD is actually usable for this order: BOTH the general
 *    area-payment-restriction gate (migration 37 / recall.md item 15)
 *    AND the fine-grained COD rule (migration 35 / recall.md item 4)
 *    must allow it — same two-function pair orders/create.php calls
 *    for a brand-new COD order, so a customer can never end up with a
 *    COD order that a fresh checkout in the same area/circumstance
 *    couldn't have created in the first place. grand_total is already
 *    known (the order was already priced at creation), so this is a
 *    single eligibility pass, not the two-pass split create.php needs.
 *
 * Any outstanding (unresolved) payment_transactions row for this
 * order is marked 'failed' with reject_reason='switched_to_cod' so it
 * stops showing up in admin/payment-pending.php's queue — an admin
 * reviewing that page shouldn't see a UPI transaction still awaiting
 * verification for an order that's now COD.
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
$customerId = (int) $owner['owner_id'];

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND customer_id = :cid LIMIT 1');
$orderStmt->execute(['id' => $orderId, 'cid' => $customerId]);
$order = $orderStmt->fetch();

if (!$order) {
    respond_error('order_not_found', 404);
}
if ($order['payment_method'] !== 'upi') {
    // Nothing to switch — already COD, or some future third method.
    respond_error('order_not_upi', 422);
}
if ($order['payment_status'] === 'paid') {
    respond_error('order_already_paid', 422);
}
if (in_array($order['status'], ['cancelled', 'rejected', 'expired'], true)) {
    respond_error('order_not_payable', 422, ['order_status' => $order['status']]);
}

// Resolve the order's own delivery address (not re-validated against
// the customer here beyond what order ownership above already
// guarantees — this is the same address the order was placed with).
$addressLat = null;
$addressLng = null;
if ($order['delivery_address_id'] !== null) {
    $addrStmt = $db->prepare('SELECT latitude, longitude FROM customer_addresses WHERE id = :id LIMIT 1');
    $addrStmt->execute(['id' => $order['delivery_address_id']]);
    $addressRow = $addrStmt->fetch();
    if ($addressRow) {
        $addressLat = $addressRow['latitude'] !== null ? (float) $addressRow['latitude'] : null;
        $addressLng = $addressRow['longitude'] !== null ? (float) $addressRow['longitude'] : null;
    }
}

// Same general gate orders/create.php runs first, for 'cod' specifically.
$paymentRestriction = get_effective_payment_restrictions($db, $addressLat, $addressLng);
$methodAllowed = is_payment_method_allowed_in_area($paymentRestriction, 'cod');
if (!$methodAllowed['allowed']) {
    respond_error('payment_method_not_allowed', 422, ['reason' => $methodAllowed['reason']]);
}

// Same fine-grained COD rule orders/create.php runs, single pass since
// grand_total is already known (order was priced at creation time).
$codRule = get_effective_cod_rule($db, $addressLat, $addressLng);
$codCheck = evaluate_cod_eligibility($db, $codRule, $customerId, (float) $order['grand_total']);
if (!$codCheck['eligible']) {
    respond_error('cod_not_eligible', 422, ['reason' => $codCheck['reason']]);
}

$db->beginTransaction();

$upd = $db->prepare("UPDATE orders SET payment_method = 'cod' WHERE id = :id");
$upd->execute(['id' => $orderId]);

// Void any outstanding UPI transaction so it drops out of the admin
// pending-payments queue — same status/column shape a manual admin
// rejection uses (migration 40/41), just no verified_by_admin_id
// since no admin acted here.
$voidStmt = $db->prepare(
    "UPDATE payment_transactions SET status = 'failed', reject_reason = 'switched_to_cod'
     WHERE order_id = :oid AND status IN ('initiated', 'utr_submitted')"
);
$voidStmt->execute(['oid' => $orderId]);

insert_status_history($db, $orderId, (string) $order['status'], 'customer', $customerId, 'Switched payment method from UPI to Cash on Delivery');

$db->commit();

$fetch = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
$fetch->execute(['id' => $orderId]);
respond_ok(['order' => format_order($db, $fetch->fetch())]);
