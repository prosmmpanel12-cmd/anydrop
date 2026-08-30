<?php
/**
 * POST /api/v1/restaurant/report-review.php
 * Auth: Restaurant token
 * Request: { "review_id": 123, "reason": "..." }
 *
 * Mirror of api/v1/customer/report-review.php, for a restaurant reporting
 * a review left on itself (e.g. suspected fake review) — see today.md §7
 * (2026-08-28) for the full plan this implements. Requires migration 56
 * (review_reports.restaurant_id + uq_review_report_once_restaurant).
 *
 * Adds a review_reports row (restaurant_id set, customer_id left NULL)
 * and flips reviews.is_reported so it surfaces in the admin Review
 * Moderation queue (admin/review-moderation.php), same as the customer
 * path.
 *
 * Abuse protection: uq_review_report_once_restaurant (migration 56) means
 * a second report from the same restaurant on the same review is a
 * DB-level no-op, not a growing queue entry — handled here as a friendly
 * "already reported" success rather than a 500, same as the customer
 * endpoint's uq_review_report_once handling.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_restaurant_profile');
$db = Database::get();

$body = get_json_body();
require_fields($body, ['review_id', 'reason']);

$reviewId = (int) $body['review_id'];
$restaurantId = (int) $owner['owner_id'];
$reason = trim((string) $body['reason']);

if ($reason === '' || mb_strlen($reason) > 255) {
    respond_error('validation_error', 422, ['fields' => ['reason']]);
}

$reviewStmt = $db->prepare('SELECT id, restaurant_id FROM reviews WHERE id = :id LIMIT 1');
$reviewStmt->execute(['id' => $reviewId]);
$review = $reviewStmt->fetch();

if (!$review) {
    respond_error('not_found', 404);
}
if ((int) $review['restaurant_id'] !== $restaurantId) {
    // Ownership check — a restaurant can only report reviews left on
    // itself, not on any other restaurant.
    respond_error('forbidden', 403);
}

try {
    $ins = $db->prepare(
        'INSERT INTO review_reports (review_id, restaurant_id, reason) VALUES (:rid, :restid, :reason)'
    );
    $ins->execute(['rid' => $reviewId, 'restid' => $restaurantId, 'reason' => $reason]);

    $db->prepare('UPDATE reviews SET is_reported = 1 WHERE id = :id')->execute(['id' => $reviewId]);
} catch (PDOException $e) {
    // 23000 = integrity constraint violation — here that's specifically
    // uq_review_report_once_restaurant, i.e. this restaurant already
    // reported this review. Treat as success (idempotent), not an error.
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

respond_ok(['reported' => true]);
