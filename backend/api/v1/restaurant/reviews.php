<?php
/**
 * GET  /api/v1/restaurant/reviews
 *      Query: ?page=1&per_page=20&unreplied_only=1 (optional)
 *      Paginated, newest first. Each item includes the customer's rating(s)
 *      + comment, and restaurant_reply/replied_at if this restaurant has
 *      already replied (null/null otherwise).
 *
 * POST /api/v1/restaurant/reviews/{id}/reply
 *      Request: { "reply": "..." }
 *      Sets/updates restaurant_reply on a review that belongs to this
 *      restaurant. Allowed to overwrite an existing reply (edit), same as
 *      how orders-status.php allows re-reading its own state — there's no
 *      product reason to make a reply a one-shot write. Notifies the
 *      customer once (type 'review'), same create_notification() used by
 *      order-status notifications.
 *
 * Auth: Restaurant token (must own the review's restaurant_id).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
$restaurantId = (int) $owner['owner_id'];
$db = Database::get();

function format_review(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'order_id' => (int) $row['order_id'],
        'customer_name' => $row['customer_name'],
        'restaurant_rating' => $row['restaurant_rating'] !== null ? (int) $row['restaurant_rating'] : null,
        'food_rating' => $row['food_rating'] !== null ? (int) $row['food_rating'] : null,
        'delivery_rating' => $row['delivery_rating'] !== null ? (int) $row['delivery_rating'] : null,
        'comment' => $row['comment'],
        'restaurant_reply' => $row['restaurant_reply'],
        'created_at' => $row['created_at'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $unrepliedOnly = isset($_GET['unreplied_only']) && $_GET['unreplied_only'] === '1';

    $where = 'r.restaurant_id = :rid' . ($unrepliedOnly ? ' AND r.restaurant_reply IS NULL' : '');

    $countStmt = $db->prepare("SELECT COUNT(*) FROM reviews r WHERE $where");
    $countStmt->execute(['rid' => $restaurantId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT r.id, r.order_id, r.restaurant_rating, r.food_rating, r.delivery_rating,
                r.comment, r.restaurant_reply, r.created_at,
                c.name AS customer_name
         FROM reviews r
         JOIN customers c ON c.id = r.customer_id
         WHERE $where
         ORDER BY r.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue('rid', $restaurantId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    respond_ok([
        'items' => array_map('format_review', $rows),
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'has_more' => $offset + count($rows) < $total,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewId = (int) ($_GET['id'] ?? 0);
    if ($reviewId <= 0) {
        respond_error('not_found', 404);
    }

    $body = get_json_body();
    require_fields($body, ['reply']);
    $reply = trim((string) $body['reply']);
    if ($reply === '') {
        respond_error('validation_error', 422, ['fields' => ['reply']]);
    }
    if (mb_strlen($reply) > 2000) {
        respond_error('validation_error', 422, ['fields' => ['reply'], 'reason' => 'too_long']);
    }

    $stmt = $db->prepare('SELECT * FROM reviews WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reviewId]);
    $review = $stmt->fetch();

    if (!$review) {
        respond_error('not_found', 404);
    }
    if ((int) $review['restaurant_id'] !== $restaurantId) {
        respond_error('forbidden', 403);
    }

    $wasUnreplied = $review['restaurant_reply'] === null;

    $upd = $db->prepare('UPDATE reviews SET restaurant_reply = :reply WHERE id = :id');
    $upd->execute(['reply' => $reply, 'id' => $reviewId]);

    // Only notify on the first reply, not on every edit — an edited reply
    // re-notifying would be noisy for a customer who already saw it once.
    if ($wasUnreplied) {
        create_notification(
            'customer',
            (int) $review['customer_id'],
            'The restaurant replied to your review',
            mb_strlen($reply) > 120 ? mb_substr($reply, 0, 117) . '...' : $reply,
            'review',
            ['review_id' => $reviewId, 'order_id' => (int) $review['order_id'], 'screen' => 'review_detail']
        );
    }

    $fetch = $db->prepare(
        'SELECT r.id, r.order_id, r.restaurant_rating, r.food_rating, r.delivery_rating,
                r.comment, r.restaurant_reply, r.created_at, c.name AS customer_name
         FROM reviews r JOIN customers c ON c.id = r.customer_id
         WHERE r.id = :id LIMIT 1'
    );
    $fetch->execute(['id' => $reviewId]);
    respond_ok(['review' => format_review($fetch->fetch())]);
}

respond_error('method_not_allowed', 405);
