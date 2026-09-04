<?php
/**
 * POST /api/v1/customer/reviews.php
 * Auth: Customer token
 * Request: {
 *   "order_id": 123,
 *   "restaurant_rating": 1-5 (required),
 *   "food_rating": 1-5 (optional),
 *   "delivery_rating": 1-5 (optional, only meaningful if the order had a rider),
 *   "comment": "..." (optional)
 * }
 * Rules: order must belong to the caller and be `delivered`; one review
 * per order (DB-enforced via uniq_reviews_order_id, checked here first
 * for a clean error). On success, recalculates the restaurant's
 * denormalized rating_avg/rating_count (see lib/reviews.php).
 *
 * GET /api/v1/customer/reviews.php?order_id=123
 * Auth: Customer token
 * Returns the existing review for that order, or { "review": null } if
 * the customer hasn't rated it yet — lets the app show "Rate this order"
 * vs. "You rated this order X★" without guessing from order status alone.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/reviews.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $orderId = (int) ($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        respond_error('validation_error', 422, ['fields' => ['order_id']]);
    }

    $stmt = $db->prepare(
        'SELECT id, order_id, restaurant_rating, food_rating, delivery_rating, comment, restaurant_reply, created_at
         FROM reviews WHERE order_id = :oid AND customer_id = :cid LIMIT 1'
    );
    $stmt->execute(['oid' => $orderId, 'cid' => $owner['owner_id']]);
    $review = $stmt->fetch();

    if (!$review) {
        respond_ok(['review' => null]);
    }

    respond_ok(['review' => [
        'id' => (int) $review['id'],
        'order_id' => (int) $review['order_id'],
        'restaurant_rating' => $review['restaurant_rating'] !== null ? (int) $review['restaurant_rating'] : null,
        'food_rating' => $review['food_rating'] !== null ? (int) $review['food_rating'] : null,
        'delivery_rating' => $review['delivery_rating'] !== null ? (int) $review['delivery_rating'] : null,
        'comment' => $review['comment'],
        'restaurant_reply' => $review['restaurant_reply'],
        'created_at' => $review['created_at'],
    ]]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = get_json_body();
require_fields($body, ['order_id', 'restaurant_rating']);

$orderId = (int) $body['order_id'];
$customerId = (int) $owner['owner_id'];

$restaurantRating = (int) $body['restaurant_rating'];
if ($restaurantRating < 1 || $restaurantRating > 5) {
    respond_error('validation_error', 422, ['fields' => ['restaurant_rating']]);
}

$foodRating = null;
if (isset($body['food_rating']) && $body['food_rating'] !== '') {
    $foodRating = (int) $body['food_rating'];
    if ($foodRating < 1 || $foodRating > 5) {
        respond_error('validation_error', 422, ['fields' => ['food_rating']]);
    }
}

$deliveryRating = null;
if (isset($body['delivery_rating']) && $body['delivery_rating'] !== '') {
    $deliveryRating = (int) $body['delivery_rating'];
    if ($deliveryRating < 1 || $deliveryRating > 5) {
        respond_error('validation_error', 422, ['fields' => ['delivery_rating']]);
    }
}

$comment = isset($body['comment']) && $body['comment'] !== '' ? trim($body['comment']) : null;

$order = require_ratable_order($db, $orderId, $customerId);

$existing = $db->prepare('SELECT id FROM reviews WHERE order_id = :oid LIMIT 1');
$existing->execute(['oid' => $orderId]);
if ($existing->fetch()) {
    respond_error('already_reviewed', 409);
}

$stmt = $db->prepare(
    'INSERT INTO reviews
        (order_id, customer_id, restaurant_id, rider_id, restaurant_rating, food_rating, delivery_rating, comment)
     VALUES
        (:order_id, :customer_id, :restaurant_id, :rider_id, :restaurant_rating, :food_rating, :delivery_rating, :comment)'
);
$stmt->execute([
    'order_id' => $orderId,
    'customer_id' => $customerId,
    'restaurant_id' => (int) $order['restaurant_id'],
    'rider_id' => $order['rider_id'] !== null ? (int) $order['rider_id'] : null,
    'restaurant_rating' => $restaurantRating,
    'food_rating' => $foodRating,
    'delivery_rating' => $deliveryRating,
    'comment' => $comment,
]);

recalc_restaurant_rating($db, (int) $order['restaurant_id']);

respond_ok(['id' => (int) $db->lastInsertId()], 201);
