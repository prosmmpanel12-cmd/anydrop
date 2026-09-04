<?php
/**
 * Anydrop — Rider Delivery Assignment Engine (Phase 3 R3, doc 83/85)
 *
 * Implements Rider_Deep_Plan.md sections 4-8: sequential single-candidate
 * offering (never broadcast an order to every online rider at once — see
 * section 6's state machine, which is explicitly "offer one -> reject/
 * timeout -> next", not a race). This avoids the double-accept race the
 * plan's section 7 warns about ("if another rider wins the order first,
 * return a clear conflict") by construction, not just by locking at
 * accept time — only one rider ever has an open offer for a given order.
 *
 * V1 simplifications, deliberate (plan section 4.2: "start simple, do
 * not build a machine-learning dispatch system"):
 * - Candidate ranking is plain distance-ascending. No freshness-penalty/
 *   active-work-penalty/fairness scoring yet — those are noted in the
 *   plan as later refinements, not needed to prove the flow end to end.
 * - "Service-area eligibility" (plan section 4.1, item 4) is implemented
 *   as a straight-line radius from the restaurant (rider_dispatch_radius_km
 *   setting) rather than matching the rider's signup service_area_id
 *   against the restaurant's area hierarchy. A rider's signup area and a
 *   specific restaurant's delivery area don't necessarily nest cleanly
 *   (deep-plan doesn't specify the matching rule), and a radius check is
 *   both simpler and consistent with how restaurants/list.php already
 *   filters restaurants for customers. Revisit if riders start getting
 *   offers clearly outside where they actually operate.
 * - No cron/worker (plan section 8 anticipates this — shared hosting).
 *   Expiry is opportunistic: expire_stale_offers() runs at the top of
 *   every rider-facing dispatch-adjacent endpoint call (orders-available,
 *   orders-accept, orders-reject), so a stale offer never lives past the
 *   next time *any* rider touches the API — worst case it lingers a few
 *   seconds longer than expires_at if the app is idle, not indefinitely.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/orders.php';

/**
 * Finds eligible online riders for an order's restaurant, nearest first.
 * Excludes any rider who already has an offered/accepted/rejected/expired
 * assignment row for THIS order (so a sweep never re-offers the same
 * rider twice on the same order).
 *
 * @return array<int, array{rider_id:int, distance_km:float}>
 */
function find_eligible_riders(PDO $db, array $order): array
{
    $restStmt = $db->prepare('SELECT latitude, longitude FROM restaurants WHERE id = :id LIMIT 1');
    $restStmt->execute(['id' => $order['restaurant_id']]);
    $rest = $restStmt->fetch();
    if (!$rest || $rest['latitude'] === null || $rest['longitude'] === null) {
        // Restaurant has no coordinates on file — can't rank by distance,
        // can't dispatch. Same "nothing to do" outcome as no candidates.
        return [];
    }

    $freshnessSeconds = (int) get_setting('rider_location_freshness_seconds', 300);
    $radiusKm = (float) get_setting('rider_dispatch_radius_km', 8);
    $isCod = $order['payment_method'] === 'cod';
    $codLimit = (float) get_setting('rider_cod_settlement_limit', 2000);

    $sql = "SELECT r.id, r.last_lat, r.last_lng, r.cod_cash_held
            FROM riders r
            WHERE r.status = 'approved'
              AND r.is_online = 1
              AND r.deleted_at IS NULL
              AND r.last_lat IS NOT NULL AND r.last_lng IS NOT NULL
              AND r.last_location_at IS NOT NULL
              AND r.last_location_at >= (NOW() - INTERVAL :freshness SECOND)
              AND NOT EXISTS (
                  SELECT 1 FROM orders o
                  WHERE o.rider_id = r.id
                    AND o.status IN ('rider_assigned','picked_up','out_for_delivery')
              )
              AND NOT EXISTS (
                  SELECT 1 FROM rider_order_assignments a
                  WHERE a.order_id = :order_id AND a.rider_id = r.id
              )";
    $stmt = $db->prepare($sql);
    $stmt->execute(['freshness' => $freshnessSeconds, 'order_id' => $order['id']]);
    $rows = $stmt->fetchAll();

    $candidates = [];
    foreach ($rows as $row) {
        if ($isCod && (float) $row['cod_cash_held'] >= $codLimit) {
            continue; // deep-plan §4.1 item 8 — COD cash-held limit
        }
        $distance = haversine_km(
            (float) $rest['latitude'],
            (float) $rest['longitude'],
            (float) $row['last_lat'],
            (float) $row['last_lng']
        );
        if ($distance > $radiusKm) {
            continue;
        }
        $candidates[] = ['rider_id' => (int) $row['id'], 'distance_km' => $distance];
    }

    usort($candidates, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return $candidates;
}

/**
 * Creates one offer for the next eligible candidate on a 'ready' order
 * with no currently-open offer. No-op (returns false) if the order isn't
 * 'ready' anymore, already has an open offer, or has no eligible riders
 * left — in the last case, a system note is logged on the order so
 * admins can see it needs manual attention (deep-plan §6: "no candidate
 * -> UNASSIGNED / ADMIN ATTENTION"), but the order is left as-is rather
 * than force-failed; an admin or a newly-online rider may still resolve it.
 */
function dispatch_next_candidate(PDO $db, int $orderId): bool
{
    $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch();
    if (!$order || $order['status'] !== 'ready') {
        return false;
    }

    $openStmt = $db->prepare("SELECT 1 FROM rider_order_assignments WHERE order_id = :id AND status = 'offered' LIMIT 1");
    $openStmt->execute(['id' => $orderId]);
    if ($openStmt->fetch()) {
        return false; // already has a live offer out
    }

    $attemptStmt = $db->prepare('SELECT COALESCE(MAX(attempt_no), 0) AS n FROM rider_order_assignments WHERE order_id = :id');
    $attemptStmt->execute(['id' => $orderId]);
    $nextAttempt = (int) $attemptStmt->fetch()['n'] + 1;

    $candidates = find_eligible_riders($db, $order);
    if (empty($candidates)) {
        if ($nextAttempt > 1) {
            // Only log once things were tried and ran out, not on an
            // order's very first (successful) dispatch attempt.
            insert_status_history($db, $orderId, $order['status'], 'system', null, 'no_riders_available');
        }
        return false;
    }

    $riderId = $candidates[0]['rider_id'];
    $timeoutSeconds = (int) get_setting('rider_assignment_timeout_seconds', 40);

    $ins = $db->prepare(
        'INSERT INTO rider_order_assignments (order_id, rider_id, status, attempt_no, expires_at)
         VALUES (:order_id, :rider_id, "offered", :attempt_no, NOW() + INTERVAL :timeout SECOND)'
    );
    $ins->execute([
        'order_id' => $orderId,
        'rider_id' => $riderId,
        'attempt_no' => $nextAttempt,
        'timeout' => $timeoutSeconds,
    ]);

    create_notification(
        'rider',
        $riderId,
        'New delivery available',
        "Order {$order['order_code']} is ready for pickup",
        'order',
        ['order_id' => $orderId, 'screen' => 'order_offer']
    );

    return true;
}

/**
 * Opportunistic sweep — expires any offer past its expires_at and moves
 * its order on to the next candidate. Called at the top of every
 * rider-facing dispatch endpoint (see file header) instead of relying on
 * a cron/worker process. Cheap: the idx_assignment_expiry index makes
 * the SELECT a single index range scan, and this only does real work
 * when something has actually expired.
 */
function expire_stale_offers(PDO $db): void
{
    $stmt = $db->query(
        "SELECT id, order_id FROM rider_order_assignments
         WHERE status = 'offered' AND expires_at < NOW()"
    );
    $expired = $stmt->fetchAll();
    if (empty($expired)) {
        return;
    }

    $upd = $db->prepare("UPDATE rider_order_assignments SET status = 'expired', responded_at = NOW() WHERE id = :id");
    foreach ($expired as $row) {
        $upd->execute(['id' => $row['id']]);
        dispatch_next_candidate($db, (int) $row['order_id']);
    }
}
