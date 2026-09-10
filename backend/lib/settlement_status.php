<?php
/**
 * Anydrop — Order Settlement Status (migration 80, Deep Plan Phase 1)
 *
 * Computes/refreshes the per-order `settlement_status` label used by
 * the Restaurant/Rider Statement screens. Deliberately lazy (computed
 * on read, "eligible" flip driven by wall-clock time passing
 * settlement_eligible_at) rather than a cron job — v1 scope, revisit
 * if a background job becomes available on this hosting setup.
 *
 * T+1 = calendar days from delivered_at (see migration 80 header).
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Lazily flips any of this restaurant's orders from 'pending' to
 * 'eligible' once their settlement_eligible_at has passed. Called at
 * the top of the Statement endpoint so the badge is always accurate
 * without needing a scheduled job. Cheap — single UPDATE, indexed on
 * restaurant_id + status.
 */
function refresh_settlement_eligibility(int $restaurantId): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        "UPDATE orders
         SET settlement_status = 'eligible'
         WHERE restaurant_id = :rid
           AND settlement_status = 'pending'
           AND settlement_eligible_at IS NOT NULL
           AND settlement_eligible_at <= NOW()"
    );
    $stmt->execute(['rid' => $restaurantId]);
}

/**
 * Called from lib/orders.php's existing "mark delivered" step (the
 * same place that already sets delivered_at) — additive only, does
 * not change any existing money calculation. Sets settlement_status
 * to 'pending' and eligible_at to delivered_at + 1 day for a
 * newly-delivered order. COD orders' cash flow is Customer -> Rider ->
 * Admin (never the restaurant, per rider_cod_ledger's own header) —
 * restaurant settlement status still applies to a COD order because
 * the restaurant is still owed its payout share / owes its commission
 * regardless of who's physically holding the cash.
 */
function initialize_order_settlement_status(int $orderId): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        "UPDATE orders
         SET settlement_eligible_at = DATE_ADD(delivered_at, INTERVAL 1 DAY),
             settlement_status = 'pending'
         WHERE id = :id AND delivered_at IS NOT NULL AND settlement_status = 'not_applicable'"
    );
    $stmt->execute(['id' => $orderId]);
}

/**
 * Called from admin/settlements.php's existing Pay Now action, right
 * after it inserts the restaurant_payments row — links every order in
 * the paid date-range to that payment (restaurant_payment_orders) and
 * flips them to 'settled'. Only touches orders that were 'eligible'
 * (never silently "settles" an order still inside its T+1 window, and
 * never double-links an order that's already settled — the table's
 * own UNIQUE KEY on order_id would reject that anyway, guarded here
 * first so one bad row can't abort the whole batch INSERT).
 */
function mark_orders_settled(int $paymentId, array $orderIds): void
{
    if (empty($orderIds)) {
        return;
    }
    $db = Database::get();

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $eligibleStmt = $db->prepare(
        "SELECT id FROM orders WHERE id IN ($placeholders) AND settlement_status = 'eligible'"
    );
    $eligibleStmt->execute($orderIds);
    $eligibleIds = array_column($eligibleStmt->fetchAll(), 'id');

    if (empty($eligibleIds)) {
        return;
    }

    $insert = $db->prepare('INSERT IGNORE INTO restaurant_payment_orders (payment_id, order_id) VALUES (:pid, :oid)');
    $updatePlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));
    $update = $db->prepare("UPDATE orders SET settlement_status = 'settled' WHERE id IN ($updatePlaceholders)");

    foreach ($eligibleIds as $oid) {
        $insert->execute(['pid' => $paymentId, 'oid' => $oid]);
    }
    $update->execute($eligibleIds);
}
