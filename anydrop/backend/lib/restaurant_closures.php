<?php
/**
 * Anydrop — Temp Closure / Holiday Scheduling helpers (§3, today.md
 * 2026-08-28, migration 58)
 *
 * Shared ownership checks + validation + serialization for the
 * closures-*.php restaurant endpoints, same "one shared lib, several
 * thin endpoints" split as menu_item_addon_groups.php. Also carries the
 * batch lookup used by restaurants/list.php, search/search.php, and
 * restaurants/menu.php to fold a restaurant's scheduled closures into
 * its public-facing open/closed status.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

/** Closure row (only if it belongs to $restaurantId), or calls
 * respond_error and exits. Same "restaurant token only ever proves
 * ownership through a WHERE restaurant_id = :rid" pattern as every
 * other restaurant-scoped resource in this app (no join needed here —
 * unlike an addon group, a closure has restaurant_id directly on it). */
function require_owned_closure(PDO $db, int $restaurantId, int $closureId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM restaurant_closures WHERE id = :id AND restaurant_id = :rid LIMIT 1'
    );
    $stmt->execute(['id' => $closureId, 'rid' => $restaurantId]);
    $closure = $stmt->fetch();
    if (!$closure) {
        respond_error('not_found', 404);
    }
    return $closure;
}

/** Validates the combination of closure_type + its type-specific
 * fields, returning the normalized [startDate, endDate, dayOfWeek]
 * triple (whichever pair applies is populated, the other pair is
 * null), or calls respond_error and exits on an invalid combination.
 * Shared by closures-create.php and closures-update.php so the two
 * can't drift on what counts as valid. */
function validate_closure_fields(string $closureType, ?string $startDate, ?string $endDate, ?int $dayOfWeek): array
{
    if ($closureType === 'date_range') {
        if (!$startDate || !$endDate) {
            respond_error('validation_error', 422, ['fields' => ['start_date', 'end_date']]);
        }
        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$start || !$end || $start > $end) {
            respond_error('validation_error', 422, ['fields' => ['start_date', 'end_date']]);
        }
        return [$startDate, $endDate, null];
    }

    if ($closureType === 'weekly_recurring') {
        if ($dayOfWeek === null || $dayOfWeek < 1 || $dayOfWeek > 7) {
            respond_error('validation_error', 422, ['fields' => ['day_of_week']]);
        }
        return [null, null, $dayOfWeek];
    }

    respond_error('validation_error', 422, ['fields' => ['closure_type']]);
}

/** All closures (active only — closures-list.php is this screen's own
 * management list, a restaurant has no reason to see ones it already
 * cancelled) for one restaurant, newest first. */
function get_closures_for_restaurant(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM restaurant_closures WHERE restaurant_id = :rid AND is_active = 1 ORDER BY id DESC'
    );
    $stmt->execute(['rid' => $restaurantId]);
    return array_map('serialize_closure', $stmt->fetchAll());
}

function serialize_closure(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'closure_type' => $row['closure_type'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'day_of_week' => $row['day_of_week'] !== null ? (int) $row['day_of_week'] : null,
        'reason' => $row['reason'],
        'is_active' => (bool) $row['is_active'],
    ];
}

/** Batch version of "does this restaurant have an active closure
 * covering right now" — used by restaurants/list.php and
 * search/search.php's restaurant-results block, which each handle many
 * restaurants per request. One IN(...) query rather than N+1, same
 * "avoids N+1 queries" reasoning those two files already use for
 * tagsByRestaurant/galleryByRestaurant.
 *
 * Returns a set (restaurant_id => true) of restaurant ids that are
 * currently closed by a scheduled closure — either a date_range
 * covering $date, or a weekly_recurring on $dow. Deliberately a plain
 * bool set rather than returning the matched closure row(s): every
 * call site only needs the yes/no to fold into compute_restaurant_status(),
 * none of them currently surface *which* closure/reason is active on
 * the public-facing list/search/detail screens (a restaurant sees that
 * detail on its own ClosureScheduleActivity instead) — flagged as a
 * possible future enhancement (e.g. showing "Closed for Diwali" instead
 * of a plain "Closed" badge) rather than built speculatively now. */
function get_restaurants_with_active_closure(PDO $db, array $restaurantIds, string $date, int $dow): array
{
    if (empty($restaurantIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($restaurantIds), '?'));
    $sql = "SELECT DISTINCT restaurant_id FROM restaurant_closures
            WHERE is_active = 1 AND restaurant_id IN ($placeholders)
              AND (
                (closure_type = 'date_range' AND start_date <= ? AND end_date >= ?)
                OR (closure_type = 'weekly_recurring' AND day_of_week = ?)
              )";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($restaurantIds, [$date, $date, $dow]));

    $set = [];
    foreach ($stmt->fetchAll() as $row) {
        $set[(int) $row['restaurant_id']] = true;
    }
    return $set;
}
