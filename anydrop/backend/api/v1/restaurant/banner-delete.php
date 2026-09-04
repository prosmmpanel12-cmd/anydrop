<?php
/**
 * POST /api/v1/restaurant/banner-delete.php — remove one of the
 * authenticated restaurant's own banners (app-owner feedback item #3,
 * 2026-08-17). JSON body { "id": <banner id> }. Auth: Restaurant token.
 *
 * Scoped to `restaurant_id = ?` in the DELETE itself (not just a WHERE id
 * lookup first) so one restaurant's token can never delete another
 * restaurant's banner by guessing/incrementing an id — same defensive
 * pattern as every other owner-scoped delete endpoint in this app
 * (menu-items-delete.php, categories-delete.php).
 *
 * Deletes the DB row only; the underlying file in
 * uploads/restaurant_banners/ is left on disk (an orphaned upload is a
 * cheap, harmless cost — same call every other upload endpoint's kdoc in
 * this codebase already makes, not worth a race-condition-prone unlink()
 * here).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_restaurant_profile');
$restaurantId = $owner['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
$bannerId = isset($body['id']) ? (int) $body['id'] : null;

if (!$bannerId) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();
$stmt = $db->prepare('DELETE FROM restaurant_banners WHERE id = ? AND restaurant_id = ?');
$stmt->execute([$bannerId, $restaurantId]);

if ($stmt->rowCount() === 0) {
    respond_error('not_found', 404);
}

respond_ok(['deleted' => true]);
