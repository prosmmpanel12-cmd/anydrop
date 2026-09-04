<?php
/**
 * POST /api/v1/restaurant/closures-create.php
 * Auth: Restaurant token
 * Request: { "closure_type": "date_range" | "weekly_recurring",
 *            "start_date"?: "YYYY-MM-DD", "end_date"?: "YYYY-MM-DD",
 *            "day_of_week"?: int (1=Mon..7=Sun), "reason"?: "..." }
 *          date_range requires start_date+end_date; weekly_recurring
 *          requires day_of_week — see validate_closure_fields().
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
$insert = $db->prepare(
    'INSERT INTO restaurant_closures
        (restaurant_id, closure_type, start_date, end_date, day_of_week, reason, is_active)
     VALUES
        (:rid, :type, :start_date, :end_date, :dow, :reason, 1)'
);
$insert->execute([
    'rid' => $restaurantId,
    'type' => $closureType,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'dow' => $dayOfWeek,
    'reason' => $reason,
]);
$newId = (int) $db->lastInsertId();

respond_ok([
    'closure' => [
        'id' => $newId,
        'closure_type' => $closureType,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'day_of_week' => $dayOfWeek,
        'reason' => $reason,
        'is_active' => true,
    ],
], 201);
