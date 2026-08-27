<?php
/**
 * Anydrop — Rating System Helpers (Part 13)
 *
 * The `reviews` table (01_schema.sql §6) was designed early but never
 * wired up. This file adds the one piece of logic both the submit
 * endpoint and any future re-seed/backfill script need: keeping
 * `restaurants.rating_avg` / `rating_count` (denormalized for fast reads
 * on Home/Search/Restaurant-list, per features.md §6) in sync with the
 * `reviews` table, using `restaurant_rating` as the number that counts
 * toward a restaurant's public rating (food_rating/delivery_rating are
 * captured for the same review row but don't feed this average — they're
 * finer-grained signal for a future restaurant-side "how are we doing"
 * view, not the customer-facing star rating).
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Recomputes rating_avg (rounded to 2dp) and rating_count for a restaurant
 * from every review that has a non-null restaurant_rating. Called right
 * after a review insert — cheap enough (COUNT+AVG over one restaurant's
 * rows) that a full recompute is simpler and safer than incrementally
 * maintaining a running average.
 *
 * Migration 54: a review hidden by admin moderation no longer counts
 * toward the public rating — same principle as a delivered-order-only
 * average, an admin-hidden review shouldn't influence what customers see.
 */
function recalc_restaurant_rating(PDO $db, int $restaurantId): void
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c, AVG(restaurant_rating) AS a
         FROM reviews
         WHERE restaurant_id = :rid AND restaurant_rating IS NOT NULL AND moderation_status = \'visible\''
    );
    $stmt->execute(['rid' => $restaurantId]);
    $row = $stmt->fetch();

    $count = (int) ($row['c'] ?? 0);
    $avg = $count > 0 ? round((float) $row['a'], 2) : 0.0;

    $update = $db->prepare(
        'UPDATE restaurants SET rating_avg = :avg, rating_count = :count WHERE id = :id'
    );
    $update->execute(['avg' => $avg, 'count' => $count, 'id' => $restaurantId]);
}

/**
 * Fetches an order and checks it's owned by the given customer and
 * eligible to be rated (must be delivered). Returns the order row, or
 * calls respond_error()/exits if not eligible.
 */
function require_ratable_order(PDO $db, int $orderId, int $customerId): array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        respond_error('not_found', 404);
    }
    if ((int) $order['customer_id'] !== $customerId) {
        respond_error('forbidden', 403);
    }
    if ($order['status'] !== 'delivered') {
        respond_error('order_not_delivered', 422);
    }

    return $order;
}

/**
 * Admin action: hides a review (removes it from public rating/listing
 * without deleting the row — keeps history/audit intact). Recomputes the
 * restaurant's rating since a hidden review no longer counts toward it.
 * Caller (admin/review-moderation.php) is responsible for its own
 * write_audit_log() call — this function only touches the reviews table.
 */
function hide_review(PDO $db, int $reviewId, int $adminId, ?string $reason): void
{
    $stmt = $db->prepare('SELECT restaurant_id FROM reviews WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reviewId]);
    $review = $stmt->fetch();
    if (!$review) {
        throw new InvalidArgumentException('review_not_found');
    }

    $db->prepare(
        'UPDATE reviews
         SET moderation_status = \'hidden\', hidden_reason = :reason, moderated_by = :admin, moderated_at = NOW()
         WHERE id = :id'
    )->execute(['reason' => $reason, 'admin' => $adminId, 'id' => $reviewId]);

    recalc_restaurant_rating($db, (int) $review['restaurant_id']);
}

/**
 * Admin action: puts a hidden review back to visible (undo). Recomputes
 * the rating the same way hide_review() does, in the opposite direction.
 */
function restore_review(PDO $db, int $reviewId, int $adminId): void
{
    $stmt = $db->prepare('SELECT restaurant_id FROM reviews WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $reviewId]);
    $review = $stmt->fetch();
    if (!$review) {
        throw new InvalidArgumentException('review_not_found');
    }

    $db->prepare(
        'UPDATE reviews
         SET moderation_status = \'visible\', hidden_reason = NULL, moderated_by = :admin, moderated_at = NOW()
         WHERE id = :id'
    )->execute(['admin' => $adminId, 'id' => $reviewId]);

    recalc_restaurant_rating($db, (int) $review['restaurant_id']);
}

/**
 * Admin action: dismisses a report queue entry without hiding the review
 * — i.e. the report was checked and the review is fine. Clears
 * is_reported so it drops off the queue; the review_reports rows
 * themselves are kept (audit trail of who reported what, and why).
 */
function dismiss_review_report(PDO $db, int $reviewId): void
{
    $db->prepare('UPDATE reviews SET is_reported = 0 WHERE id = :id')->execute(['id' => $reviewId]);
}
