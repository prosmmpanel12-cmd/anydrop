<?php
/**
 * GET /api/v1/restaurant/banners-list.php — list the authenticated
 * restaurant's own banners, for BannerManagerActivity (Restaurant app,
 * app-owner feedback item #3, 2026-08-17). Auth: Restaurant token.
 * Returns { banners: [{ id, image_url }, ...] }, ordered same as the
 * Customer app's carousel (restaurants/menu.php) so the owner's manager
 * screen shows banners in the same order customers will see them.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$db = Database::get();
$stmt = $db->prepare(
    'SELECT id, image_url FROM restaurant_banners WHERE restaurant_id = ? ORDER BY sort_order, id'
);
$stmt->execute([$restaurantId]);

$banners = array_map(fn($b) => [
    'id' => (int) $b['id'],
    'image_url' => $b['image_url'],
], $stmt->fetchAll());

respond_ok(['banners' => $banners]);
