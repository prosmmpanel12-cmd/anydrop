<?php
/**
 * Anydrop — One-Time Test Data Seed Script
 *
 * Creates ONE sample restaurant + menu so you can test the Phase 1 APIs
 * immediately (restaurant login, GET /restaurants, GET /restaurants/{id}/menu)
 * without needing the Restaurant App (Phase 3) built yet.
 *
 * Run once via browser:
 *   https://yourdomain.infinityfreeapp.com/scripts/seed-test-data.php?key=SEED_ME
 *
 * Then DELETE this file.
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script.';
    exit;
}

$db = Database::get();

$check = $db->prepare("SELECT id FROM restaurants WHERE owner_email = 'demo@anydrop.test' LIMIT 1");
$check->execute();
if ($check->fetch()) {
    echo 'Demo restaurant already exists. Nothing done.';
    exit;
}

$passwordHash = password_hash('Demo@1234', PASSWORD_BCRYPT);

$stmt = $db->prepare(
    "INSERT INTO restaurants
        (name, owner_name, owner_mobile, owner_email, password_hash, address, latitude, longitude,
         cuisine_tags, is_veg_only, opening_time, closing_time, working_days,
         delivery_radius_km, min_order_amount, status, operational_status)
     VALUES
        ('Demo Tandoori House', 'Demo Owner', '9999999999', 'demo@anydrop.test', :ph,
         '123 Test Street, Demo City', 28.6139, 77.2090,
         'North Indian,Tandoori', 0, '10:00:00', '23:00:00', '1,2,3,4,5,6,7',
         5.0, 99, 'approved', 'open')"
);
$stmt->execute(['ph' => $passwordHash]);
$restaurantId = (int) $db->lastInsertId();

$catStmt = $db->prepare(
    "INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (:rid, :name, :sort)"
);
$catStmt->execute(['rid' => $restaurantId, 'name' => 'Starters', 'sort' => 1]);
$startersId = (int) $db->lastInsertId();

$catStmt->execute(['rid' => $restaurantId, 'name' => 'Main Course', 'sort' => 2]);
$mainsId = (int) $db->lastInsertId();

$itemStmt = $db->prepare(
    "INSERT INTO menu_items
        (restaurant_id, category_id, name, description, price, is_veg, is_available, is_recommended, is_bestseller, prep_time_minutes)
     VALUES (:rid, :cid, :name, :desc, :price, :veg, 1, :rec, :best, :prep)"
);

$itemStmt->execute([
    'rid' => $restaurantId, 'cid' => $startersId, 'name' => 'Paneer Tikka',
    'desc' => 'Char-grilled cottage cheese marinated in spices', 'price' => 220,
    'veg' => 1, 'rec' => 1, 'best' => 1, 'prep' => 15,
]);
$itemStmt->execute([
    'rid' => $restaurantId, 'cid' => $startersId, 'name' => 'Chicken Seekh Kebab',
    'desc' => 'Minced chicken skewers grilled in tandoor', 'price' => 260,
    'veg' => 0, 'rec' => 0, 'best' => 1, 'prep' => 18,
]);
$itemStmt->execute([
    'rid' => $restaurantId, 'cid' => $mainsId, 'name' => 'Butter Chicken',
    'desc' => 'Classic creamy tomato-based curry', 'price' => 320,
    'veg' => 0, 'rec' => 1, 'best' => 1, 'prep' => 20,
]);
$itemStmt->execute([
    'rid' => $restaurantId, 'cid' => $mainsId, 'name' => 'Dal Makhani',
    'desc' => 'Slow-cooked black lentils in butter and cream', 'price' => 210,
    'veg' => 1, 'rec' => 0, 'best' => 0, 'prep' => 15,
]);

echo "Demo data created.\n";
echo "Restaurant ID: {$restaurantId}\n";
echo "Login: demo@anydrop.test / Demo@1234\n";
echo "DELETE THIS FILE NOW.";
