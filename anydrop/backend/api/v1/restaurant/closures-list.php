<?php
/**
 * GET /api/v1/restaurant/closures-list.php
 * Auth: Restaurant token
 * Response: { "closures": [{id, closure_type, start_date, end_date,
 *                            day_of_week, reason, is_active}] }
 *
 * §3, today.md 2026-08-28 / migration 58. Backs ClosureScheduleActivity
 * — a restaurant's own list of scheduled multi-day/recurring closures
 * (the plain on-demand "temp closed" switch stays in AccountFragment,
 * unrelated to this list — see status-update.php).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/restaurant_closures.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();
respond_ok(['closures' => get_closures_for_restaurant($db, $restaurantId)]);
