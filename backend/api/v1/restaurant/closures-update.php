<?php
/**
 * POST /api/v1/restaurant/closures-update.php?id={closure_id}
 * Auth: Restaurant token (must own the closure)
 * Request: same shape as closures-create.php — a full replace of the
 *          type-specific fields + reason, not a partial patch (a
 *          closure's whole point is its date/day scope, so editing one
 *          without the other doesn't make sense here; matches how
 *          ClosureScheduleActivity's single add/edit dialog works for
 *          both create and edit).
 * Response: { "closure": {...} }
 *
 * §3, today.md 2026-08-28 / migration 58.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/restaurant_closures.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_closures');
$restaurantId = $owner['owner_id'];
$closureId = (int) ($_GET['id'] ?? 0);

$body = get_json_body();
require_fields($body, ['closure_type']);

$closureType = (string) $body['closure_type'];
$startDate = isset($body['start_date']) ? (string) $body['start_date'] : null;
$endDate = isset($body['end_date']) ? (string) $body['end_date'] : null;
$dayOfWeek = isset($body['day_of_week']) ? (int) $body['day_of_week'] : null;
$reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
if ($reason === '') {
    $reason = null;
}

[$startDate, $endDate, $dayOfWeek] = validate_closure_fields($closureType, $startDate, $endDate, $dayOfWeek);

$db = Database::get();
require_owned_closure($db, $restaurantId, $closureId);

$update = $db->prepare(
    'UPDATE restaurant_closures
     SET closure_type = :type, start_date = :start_date, end_date = :end_date,
         day_of_week = :dow, reason = :reason
     WHERE id = :id'
);
$update->execute([
    'type' => $closureType,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'dow' => $dayOfWeek,
    'reason' => $reason,
    'id' => $closureId,
]);

respond_ok([
    'closure' => [
        'id' => $closureId,
        'closure_type' => $closureType,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'day_of_week' => $dayOfWeek,
        'reason' => $reason,
        'is_active' => true,
    ],
]);
