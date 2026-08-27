<?php
/**
 * POST /api/v1/customer/report-review.php
 * Auth: Customer token
 * Request: { "review_id": 123, "reason": "..." }
 *
 * Adds a report_reports row and flips reviews.is_reported so it surfaces
 * in the admin Review Moderation queue (admin/review-moderation.php).
 * Doesn't hide the review itself — only an admin action does that
 * (lib/reviews.php's hide_review()).
 *
 * Abuse protection: uq_review_report_once (migration 54) means a second
 * report from the same customer on the same review is a DB-level no-op,
 * not a growing queue entry — handled here as a friendly "already
 * reported" success rather than a 500.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$db = Database::get();

$body = get_json_body();
require_fields($body, ['review_id', 'reason']);

$reviewId = (int) $body['review_id'];
$customerId = (int) $owner['owner_id'];
$reason = trim((string) $body['reason']);

if ($reason === '' || mb_strlen($reason) > 255) {
    respond_error('validation_error', 422, ['fields' => ['reason']]);
}

$reviewStmt = $db->prepare('SELECT id, customer_id FROM reviews WHERE id = :id LIMIT 1');
$reviewStmt->execute(['id' => $reviewId]);
$review = $reviewStmt->fetch();

if (!$review) {
    respond_error('not_found', 404);
}
if ((int) $review['customer_id'] === $customerId) {
    // Reporting your own review makes no sense — surface as a clear
    // error rather than silently letting it into the moderation queue.
    respond_error('cannot_report_own_review', 422);
}

try {
    $ins = $db->prepare(
        'INSERT INTO review_reports (review_id, customer_id, reason) VALUES (:rid, :cid, :reason)'
    );
    $ins->execute(['rid' => $reviewId, 'cid' => $customerId, 'reason' => $reason]);

    $db->prepare('UPDATE reviews SET is_reported = 1 WHERE id = :id')->execute(['id' => $reviewId]);
} catch (PDOException $e) {
    // 23000 = integrity constraint violation — here that's specifically
    // uq_review_report_once, i.e. this customer already reported this
    // review. Treat as success (idempotent), not an error.
    if ($e->getCode() !== '23000') {
        throw $e;
    }
}

respond_ok(['reported' => true]);
