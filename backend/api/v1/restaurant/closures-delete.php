<?php
/**
 * POST /api/v1/restaurant/closures-delete.php?id={closure_id}
 * Auth: Restaurant token (must own the closure)
 * Response: { "deleted": true }
 *
 * §3, today.md 2026-08-28 / migration 58. Soft-disable (is_active = 0),
 * same convention as addon-groups-delete.php — see restaurant_closures.sql's
 * kdoc on the is_active column.
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

$db = Database::get();
require_owned_closure($db, $restaurantId, $closureId);

$db->prepare('UPDATE restaurant_closures SET is_active = 0 WHERE id = :id')
    ->execute(['id' => $closureId]);

respond_ok(['deleted' => true]);
