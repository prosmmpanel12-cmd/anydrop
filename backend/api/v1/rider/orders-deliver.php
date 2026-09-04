<?php
/**
 * POST /api/v1/rider/orders-deliver.php?id={order_id}
 * Auth: Rider token
 * Request: { "otp": "1234" } — required only if this order actually has
 *          a delivery_otp on file (see below); send {} otherwise.
 * Response (success): { "order_id": ..., "status": "delivered" }
 * Response (wrong otp): 401 invalid_otp, { "attempts_remaining": N }
 * Response (locked):    400 otp_max_attempts_exceeded
 *
 * Phase 3 R4 (pickup/drop-off flow, deep-plan §16), built on top of R3.
 *
 * OTP requirement mirrors orders/create.php's own generation condition
 * exactly (bugs.md #1.2 already established "check delivery_otp !== null
 * directly" as the one true source of truth for whether an order needs
 * one — orders/track.php follows the same rule). If this particular
 * order was placed with no OTP required (delivery_otp IS NULL — e.g. COD
 * with otp_required_for_cod off), this endpoint delivers it straight
 * away without checking anything the client sent; there is nothing to
 * check.
 *
 * Lockout reuses the SAME otp_max_attempts app_setting every other OTP
 * flow in this codebase already reads (auth/rider-verify-otp.php etc.)
 * rather than inventing a parallel delivery-specific setting — deep-plan
 * §16 just says "lock after configured maximum attempts" and doesn't ask
 * for a separate knob. Attempts live on orders.otp_attempts (already in
 * schema, migration 01) — no new column needed. A locked order is left
 * at out_for_delivery for admin/support to resolve manually (deep-plan
 * §16: "never change order status" on a wrong OTP) — this endpoint has
 * no unlock path itself.
 *
 * COD wiring: lib/ledger.php's record_cod_order_ledger_entry() (restaurant
 * commission-owed entry) and lib/rider_ledger.php's
 * record_rider_cod_collected() (rider cash-held entry) were BOTH written
 * months ago already flagged "not called anywhere yet — call this once a
 * real 'delivered' transition exists" (see their own kdocs). This is that
 * transition. Both fire inside the same transaction as the status flip,
 * for a COD order only, so a rolled-back delivery never leaves a
 * dangling ledger entry. payment_status is also flipped to 'paid' for a
 * COD order here — cash has now actually changed hands, same "paid"
 * meaning payment_status already carries for a UPI order once its
 * webhook lands.
 *
 * Rider earning entry (deep-plan §16/§19-20, migration 73): fires for
 * EVERY delivery here (not payment-method-gated the way the COD calls
 * above are) via lib/rider_earnings.php's record_rider_delivery_earning()
 * — a % of this order's own delivery_charge, floored at an admin
 * minimum (2026-09-04 rate-model decision — see migration 73's own
 * comment for why "share of delivery_charge" was chosen over deep-plan
 * §19's literal flat-base/per-km wording). Same transaction as the
 * status flip, so a rolled-back delivery never leaves a dangling
 * earnings row, identical reasoning to the COD ledger calls.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/settings.php';
require_once __DIR__ . '/../../../lib/orders.php';
require_once __DIR__ . '/../../../lib/notifications.php';
require_once __DIR__ . '/../../../lib/ledger.php';
require_once __DIR__ . '/../../../lib/rider_ledger.php';
require_once __DIR__ . '/../../../lib/rider_earnings.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
if (($owner['status'] ?? null) !== 'approved') {
    respond_error('not_approved', 403);
}
$riderId = (int) $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$body = get_json_body();
$enteredOtp = isset($body['otp']) ? trim((string) $body['otp']) : '';

$db = Database::get();

$orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND rider_id = :rider_id LIMIT 1');
$orderStmt->execute(['id' => $orderId, 'rider_id' => $riderId]);
$order = $orderStmt->fetch();

if (!$order) {
    respond_error('not_found', 404);
}
if ($order['status'] !== 'out_for_delivery') {
    respond_error('invalid_state', 409);
}

if ($order['delivery_otp'] !== null) {
    $maxAttempts = (int) get_setting('otp_max_attempts', 3);

    if ((int) $order['otp_attempts'] >= $maxAttempts) {
        respond_error('otp_max_attempts_exceeded', 400);
    }

    if ($enteredOtp === '' || $enteredOtp !== $order['delivery_otp']) {
        $incStmt = $db->prepare(
            "UPDATE orders SET otp_attempts = otp_attempts + 1
             WHERE id = :id AND rider_id = :rider_id AND status = 'out_for_delivery'"
        );
        $incStmt->execute(['id' => $orderId, 'rider_id' => $riderId]);
        respond_error('invalid_otp', 401, [
            'attempts_remaining' => max(0, $maxAttempts - (int) $order['otp_attempts'] - 1),
        ]);
    }
}

$db->beginTransaction();

$isCod = $order['payment_method'] === 'cod';
$upd = $db->prepare(
    "UPDATE orders
     SET status = 'delivered', delivered_at = NOW()"
    . ($order['delivery_otp'] !== null ? ", otp_verified_at = NOW()" : "")
    . ($isCod ? ", payment_status = 'paid'" : "")
    . " WHERE id = :id AND rider_id = :rider_id AND status = 'out_for_delivery'"
);
$upd->execute(['id' => $orderId, 'rider_id' => $riderId]);

if ($upd->rowCount() !== 1) {
    // Lost a race with something else (shouldn't happen — a rider's own
    // order isn't touched by any other actor at this stage — but this
    // guard is what makes the whole endpoint safe regardless).
    $db->rollBack();
    respond_error('invalid_state', 409);
}

insert_status_history($db, $orderId, 'delivered', 'rider', $riderId);

if ($isCod) {
    record_cod_order_ledger_entry($db, $order);
    record_rider_cod_collected($db, $order);
}

// Rider earning fires for every delivery, COD or prepaid — unlike the
// two calls above, this isn't payment-method-gated (deep-plan §19: the
// rider is being paid for the DELIVERY, not for handling cash; a UPI
// order's rider earns exactly the same way a COD order's rider does).
$earningResult = record_rider_delivery_earning($db, $order);

$db->commit();

create_notification(
    'customer',
    (int) $order['customer_id'],
    'Order delivered',
    "Order {$order['order_code']} has been delivered. Enjoy!",
    'order',
    ['order_id' => $orderId, 'screen' => 'order_status']
);

respond_ok([
    'order_id' => $orderId,
    'status' => 'delivered',
    'earning_amount' => $earningResult['amount'],
]);
