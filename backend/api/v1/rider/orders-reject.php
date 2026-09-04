<?php
/**
 * POST /api/v1/rider/orders-reject.php?id={order_id}
 * Auth: Rider token
 * Request: { "reason": "..." } (optional)
 * Response: { "ok": true }
 *
 * Phase 3 R3 (doc 83/85). Marks this rider's offer rejected and
 * immediately dispatches the next candidate (deep-plan §6: Reject ->
 * NEXT RIDER) — the rejecting rider doesn't wait for a timeout sweep to
 * free the order up for someone else.
 *
 * Same conditional-UPDATE approach as orders-accept.php: if the offer
 * isn't in 'offered' state for this rider (already expired/responded),
 * this just returns success anyway — rejecting something that's no
 * longer actionable isn't an error from the rider's point of view, the
 * end state (this rider has no open offer) is what they wanted either way.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/dispatch.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$body = get_json_body();
$reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
if ($reason === '') {
    $reason = null;
}

$db = Database::get();
expire_stale_offers($db);

$upd = $db->prepare(
    "UPDATE rider_order_assignments
     SET status = 'rejected', responded_at = NOW(), reject_reason = :reason
     WHERE order_id = :order_id AND rider_id = :rider_id AND status = 'offered' AND expires_at >= NOW()"
);
$upd->execute(['order_id' => $orderId, 'rider_id' => $riderId, 'reason' => $reason]);

if ($upd->rowCount() === 1) {
    dispatch_next_candidate($db, $orderId);
}

respond_ok(['ok' => true]);
