<?php
/**
 * GET /api/v1/restaurant/bank-details-get.php
 * Auth: Restaurant token
 * Response: { "bank_details": {...} | null }
 *
 * PENDING.md §15, migration 59. account_number comes back masked —
 * see serialize_bank_details_for_restaurant()'s kdoc in
 * lib/restaurant_bank.php for why.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';
require_once __DIR__ . '/../../../lib/restaurant_bank.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_bank_details');
$restaurantId = $owner['owner_id'];

$db = Database::get();
$stmt = $db->prepare('SELECT * FROM restaurant_bank_details WHERE restaurant_id = :id LIMIT 1');
$stmt->execute(['id' => $restaurantId]);
$row = $stmt->fetch();

respond_ok([
    'bank_details' => $row ? serialize_bank_details_for_restaurant($row) : null,
]);
