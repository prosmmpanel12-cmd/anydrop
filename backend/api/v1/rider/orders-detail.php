<?php
/**
 * GET /api/v1/rider/orders-detail.php?id={order_id}
 * Auth: Rider token
 * Response: { "order": { id, order_code, status, restaurant_name,
 *             restaurant_address, restaurant_lat, restaurant_lng,
 *             restaurant_phone, customer_name, customer_phone,
 *             delivery_address, house_flat_no, floor, landmark,
 *             receiver_name, receiver_phone, address_photo_url,
 *             delivery_lat, delivery_lng,
 *             delivery_instructions, item_count, item_total,
 *             delivery_charge, grand_total, payment_method, cod_amount,
 *             distance_km, delivery_otp_required, accepted_at } }
 *
 * Rider Order Detail (deep-plan §9), built this session. orders-current.php
 * (Phase 3 R3, doc 85) already backs the dashboard's compact card; this is
 * the dedicated detail screen §9 describes — same active-delivery scope
 * (rider_assigned/picked_up/out_for_delivery) and same ownership rule
 * (o.rider_id = this rider), but returns the fuller field set §9 lists:
 * restaurant lat/lng + phone for navigate/call, customer name + phone for
 * call, and estimated distance (restaurant -> delivery address, via the
 * same haversine_km() delivery_pricing.php/dispatch.php already use — no
 * new distance calc invented here).
 *
 * house_flat_no/floor/landmark/receiver_name/receiver_phone/photo_url
 * (added this session) — these columns already existed on
 * customer_addresses (migration 06/16, built for the customer app's own
 * address form/H6 door-photo feature) but orders-current.php and this
 * endpoint's first version never selected them, leaving the rider with
 * only the single concatenated full_address string. Selected here as
 * their own fields — not folded into full_address — so the Android side
 * can render "House 4B, near Ram Mandir" style detail lines the same way
 * the customer app's own address form does, rather than parsing them back
 * out of a sentence. receiver_name/receiver_phone are who's actually at
 * the door for this address (may differ from the ordering customer, e.g.
 * a gift order or an office address) — returned as address_photo_url
 * mapped from the address's own photo_url column, renamed on the wire so
 * it isn't confused with a future restaurant/customer profile photo.
 *
 * Per deep-plan §9's own line — "Do not return unnecessary customer
 * profile data" — this still only reads name+mobile off the customers
 * table itself; the additional fields above come from customer_addresses
 * (which already exists specifically to hold delivery-relevant detail,
 * not general profile data) and receiver_name/receiver_phone were
 * explicitly built for exactly this purpose (an address on file
 * belonging to a different person than the account holder), so they don't
 * fall under that caution. delivery_otp itself is never returned, only
 * whether one is required, matching orders-current.php's existing
 * convention exactly (see that file's kdoc for the reasoning).
 *
 * cod_amount is grand_total when payment_method is cod, else null — a
 * separate explicit field rather than making the Android side re-derive
 * it from payment_method + grand_total, since §9 lists "COD amount" as
 * its own field distinct from "order total".
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/geo.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];
$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();
$stmt = $db->prepare(
    "SELECT o.id, o.order_code, o.status, o.payment_method, o.item_total,
            o.delivery_charge, o.grand_total, o.delivery_instructions,
            o.delivery_otp, o.accepted_at,
            r.name AS restaurant_name, r.address AS restaurant_address,
            r.latitude AS restaurant_lat, r.longitude AS restaurant_lng,
            r.owner_mobile AS restaurant_phone,
            c.name AS customer_name, c.mobile AS customer_phone,
            ca.full_address AS delivery_address,
            ca.house_flat_no, ca.floor, ca.landmark,
            ca.receiver_name, ca.receiver_phone,
            ca.photo_url AS address_photo_url,
            ca.latitude AS delivery_lat, ca.longitude AS delivery_lng
     FROM orders o
     JOIN restaurants r ON r.id = o.restaurant_id
     JOIN customers c ON c.id = o.customer_id
     LEFT JOIN customer_addresses ca ON ca.id = o.delivery_address_id
     WHERE o.id = :order_id AND o.rider_id = :rider_id
       AND o.status IN ('rider_assigned','picked_up','out_for_delivery')
     LIMIT 1"
);
$stmt->execute(['order_id' => $orderId, 'rider_id' => $riderId]);
$row = $stmt->fetch();

if (!$row) {
    // Not this rider's order, wrong status, or doesn't exist — same
    // "app re-polls/re-renders, doesn't retry blindly" contract as
    // orders-pickup.php's invalid_state, but this is a read endpoint so
    // a plain 404 is enough; there's no state transition to protect.
    respond_error('not_found', 404);
}

$itemCountStmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) AS n FROM order_items WHERE order_id = :id');
$itemCountStmt->execute(['id' => $orderId]);

$distanceKm = null;
if ($row['restaurant_lat'] !== null && $row['restaurant_lng'] !== null
    && $row['delivery_lat'] !== null && $row['delivery_lng'] !== null) {
    $distanceKm = round(haversine_km(
        (float) $row['restaurant_lat'],
        (float) $row['restaurant_lng'],
        (float) $row['delivery_lat'],
        (float) $row['delivery_lng']
    ), 1);
}

respond_ok(['order' => [
    'id' => (int) $row['id'],
    'order_code' => $row['order_code'],
    'status' => $row['status'],
    'restaurant_name' => $row['restaurant_name'],
    'restaurant_address' => $row['restaurant_address'],
    'restaurant_lat' => $row['restaurant_lat'] !== null ? (float) $row['restaurant_lat'] : null,
    'restaurant_lng' => $row['restaurant_lng'] !== null ? (float) $row['restaurant_lng'] : null,
    'restaurant_phone' => $row['restaurant_phone'],
    'customer_name' => $row['customer_name'],
    'customer_phone' => $row['customer_phone'],
    'delivery_address' => $row['delivery_address'],
    'house_flat_no' => $row['house_flat_no'],
    'floor' => $row['floor'],
    'landmark' => $row['landmark'],
    'receiver_name' => $row['receiver_name'],
    'receiver_phone' => $row['receiver_phone'],
    'address_photo_url' => $row['address_photo_url'],
    'delivery_lat' => $row['delivery_lat'] !== null ? (float) $row['delivery_lat'] : null,
    'delivery_lng' => $row['delivery_lng'] !== null ? (float) $row['delivery_lng'] : null,
    'delivery_instructions' => $row['delivery_instructions'],
    'item_count' => (int) $itemCountStmt->fetch()['n'],
    'item_total' => (float) $row['item_total'],
    'delivery_charge' => (float) $row['delivery_charge'],
    'grand_total' => (float) $row['grand_total'],
    'payment_method' => $row['payment_method'],
    'cod_amount' => $row['payment_method'] === 'cod' ? (float) $row['grand_total'] : null,
    'distance_km' => $distanceKm,
    'delivery_otp_required' => $row['delivery_otp'] !== null,
    'accepted_at' => $row['accepted_at'],
]]);
