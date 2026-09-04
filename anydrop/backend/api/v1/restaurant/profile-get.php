<?php
/**
 * GET /api/v1/restaurant/profile-get.php
 * Auth: Restaurant token
 * Response: { "restaurant": {...full restaurants row, minus password_hash} }
 *
 * docs/restorent/19_Restaurant_App_UI_Plan.md §7 (Account tab) / §10 item 5.
 * restaurant-login.php already returns the full row once at login time, but
 * the token can outlive that single response (30-day TOKEN_LIFETIME_DAYS,
 * see lib/auth.php) and nothing since then has re-fetched it — the Account
 * tab and its Edit Profile screen need a way to load current values without
 * forcing a re-login. Same select-all-minus-password_hash shape as
 * restaurant-login.php's response, so RestaurantProfileDetail (Kotlin side)
 * can deserialize either one identically.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM restaurants WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $restaurantId]);
$restaurant = $stmt->fetch();

if (!$restaurant) {
    respond_error('not_found', 404);
}

unset($restaurant['password_hash']);

respond_ok(['restaurant' => $restaurant]);
