<?php
/**
 * Anydrop — Demo Catalog Seed Script (15 restaurants, ~38 menu items)
 *
 * Purpose: populate a realistic-looking Home screen (categories row,
 * "Recommended with deals" cards, veg/non-veg mix, Near&Fast / Pure Veg
 * badges) matching the reference screenshots, using free hosted images
 * (Unsplash source URLs — no API key needed) so nothing needs to be
 * uploaded manually right now.
 *
 * Run ONCE via browser after 05_migration_categories_and_tags.sql:
 *   https://yourdomain.infinityfreeapp.com/scripts/seed-demo-catalog.php?key=SEED_ME
 *
 * Safe to re-run — every insert checks for an existing row first
 * (matched by owner_email for restaurants, by name+restaurant for items)
 * so running it twice will not create duplicates.
 *
 * DELETE THIS FILE once you're happy with the data, same as
 * seed-test-data.php — it has no auth beyond the ?key= check.
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script.';
    exit;
}

$db = Database::get();

// ---- helpers ----------------------------------------------------------

function getOrCreateRestaurant(PDO $db, array $r): int
{
    $stmt = $db->prepare('SELECT id FROM restaurants WHERE owner_email = :e LIMIT 1');
    $stmt->execute(['e' => $r['owner_email']]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }

    $passwordHash = password_hash('Demo@1234', PASSWORD_BCRYPT);
    $stmt = $db->prepare(
        "INSERT INTO restaurants
            (name, owner_name, owner_mobile, owner_email, password_hash, address, latitude, longitude,
             logo_url, cover_url, cuisine_tags, is_veg_only, opening_time, closing_time, working_days,
             delivery_radius_km, min_order_amount, status, operational_status, rating_avg, offer_badge_text)
         VALUES
            (:name, :owner_name, :owner_mobile, :owner_email, :ph, :address, :lat, :lng,
             :logo, :cover, :cuisine, :veg_only, :open_t, :close_t, '1,2,3,4,5,6,7',
             5.0, :min_order, 'approved', 'open', :rating, :offer_badge)"
    );
    $stmt->execute([
        'name' => $r['name'],
        'owner_name' => $r['owner_name'],
        'owner_mobile' => $r['owner_mobile'],
        'owner_email' => $r['owner_email'],
        'ph' => $passwordHash,
        'address' => $r['address'],
        'lat' => $r['lat'],
        'lng' => $r['lng'],
        'logo' => $r['logo'],
        'cover' => $r['cover'],
        'cuisine' => $r['cuisine'],
        'veg_only' => $r['veg_only'] ? 1 : 0,
        'open_t' => $r['open_t'],
        'close_t' => $r['close_t'],
        'min_order' => $r['min_order'],
        'rating' => $r['rating'],
        'offer_badge' => $r['offer_badge'],
    ]);
    return (int) $db->lastInsertId();
}

function getOrCreateCategory(PDO $db, int $restaurantId, string $name, int $sort): int
{
    $stmt = $db->prepare('SELECT id FROM menu_categories WHERE restaurant_id = :rid AND name = :name LIMIT 1');
    $stmt->execute(['rid' => $restaurantId, 'name' => $name]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }
    $stmt = $db->prepare('INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (:rid, :name, :sort)');
    $stmt->execute(['rid' => $restaurantId, 'name' => $name, 'sort' => $sort]);
    return (int) $db->lastInsertId();
}

function getOrCreateItem(PDO $db, int $restaurantId, int $categoryId, array $item): int
{
    $stmt = $db->prepare('SELECT id FROM menu_items WHERE restaurant_id = :rid AND name = :name LIMIT 1');
    $stmt->execute(['rid' => $restaurantId, 'name' => $item['name']]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }
    $stmt = $db->prepare(
        "INSERT INTO menu_items
            (restaurant_id, category_id, name, description, price, discount_percent, is_veg, image_url,
             is_available, is_recommended, is_bestseller, prep_time_minutes)
         VALUES (:rid, :cid, :name, :desc, :price, :disc, :veg, :img, 1, :rec, :best, :prep)"
    );
    $stmt->execute([
        'rid' => $restaurantId,
        'cid' => $categoryId,
        'name' => $item['name'],
        'desc' => $item['desc'],
        'price' => $item['price'],
        'disc' => $item['disc'] ?? 0,
        'veg' => $item['veg'] ? 1 : 0,
        'img' => $item['img'],
        'rec' => $item['rec'] ?? 0,
        'best' => $item['best'] ?? 0,
        'prep' => $item['prep'] ?? 20,
    ]);
    return (int) $db->lastInsertId();
}

function mapItemToFoodCategory(PDO $db, int $menuItemId, string $categorySlug): void
{
    $stmt = $db->prepare('SELECT id FROM food_categories WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $categorySlug]);
    $cat = $stmt->fetch();
    if (!$cat) {
        return;
    }
    $stmt = $db->prepare(
        'INSERT IGNORE INTO menu_item_categories (menu_item_id, food_category_id) VALUES (:mid, :cid)'
    );
    $stmt->execute(['mid' => $menuItemId, 'cid' => (int) $cat['id']]);
}

function mapRestaurantTag(PDO $db, int $restaurantId, string $tagSlug): void
{
    $stmt = $db->prepare('SELECT id FROM restaurant_tags WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $tagSlug]);
    $tag = $stmt->fetch();
    if (!$tag) {
        return;
    }
    $stmt = $db->prepare(
        'INSERT IGNORE INTO restaurant_tag_map (restaurant_id, restaurant_tag_id) VALUES (:rid, :tid)'
    );
    $stmt->execute(['rid' => $restaurantId, 'tid' => (int) $tag['id']]);
}

// ---- image helper (Unsplash "source" style free hosted images) --------
// Using picsum/unsplash direct photo IDs (stable, free, no key) rather
// than the old unreliable source.unsplash.com redirect endpoint.
function img(string $unsplashPhotoId): string
{
    return "https://images.unsplash.com/{$unsplashPhotoId}?auto=format&fit=crop&w=800&q=70";
}

// Jodhpur-area coordinates (spread around Pratap Nagar / Ratanada, matching
// the reference screenshots) so distance_km / ETA look realistic.
$base = ['lat' => 26.2921, 'lng' => 73.0243];

// ---- 1. Restaurants -----------------------------------------------------

$restaurants = [
    [
        'name' => 'Burger King', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000001',
        'owner_email' => 'burgerking@anydrop.demo', 'address' => 'Pratap Nagar, Jodhpur',
        'lat' => 26.2945, 'lng' => 73.0210,
        'logo' => img('photo-1568901346375-23c9450c58cd'), 'cover' => img('photo-1568901346375-23c9450c58cd'),
        'cuisine' => 'Burgers,American,Fast Food', 'veg_only' => false,
        'open_t' => '10:00:00', 'close_t' => '23:30:00', 'min_order' => 99, 'rating' => 4.1,
        'offer_badge' => '50% OFF select items', 'tags' => ['near_fast'],
    ],
    [
        'name' => "Rominus Pizza And More", 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000002',
        'owner_email' => 'rominus@anydrop.demo', 'address' => 'Ratanada, Jodhpur',
        'lat' => 26.2830, 'lng' => 73.0300,
        'logo' => img('photo-1513104890138-7c749659a591'), 'cover' => img('photo-1513104890138-7c749659a591'),
        'cuisine' => 'Pizza,Italian', 'veg_only' => false,
        'open_t' => '11:00:00', 'close_t' => '23:00:00', 'min_order' => 149, 'rating' => 3.8,
        'offer_badge' => '60% OFF select items', 'tags' => [],
    ],
    [
        'name' => "La Pino'z Pizza", 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000003',
        'owner_email' => 'lapinoz@anydrop.demo', 'address' => 'Sardarpura, Jodhpur',
        'lat' => 26.2880, 'lng' => 73.0180,
        'logo' => img('photo-1594007654729-407eedc4be65'), 'cover' => img('photo-1594007654729-407eedc4be65'),
        'cuisine' => 'Pizza,Italian', 'veg_only' => false,
        'open_t' => '11:00:00', 'close_t' => '23:30:00', 'min_order' => 199, 'rating' => 4.0,
        'offer_badge' => '₹120 OFF above ₹199', 'tags' => ['near_fast'],
    ],
    [
        'name' => 'Shahi Sabji Wala — The Food Of Fables', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000004',
        'owner_email' => 'shahisabjiwala@anydrop.demo', 'address' => 'Kamla Nehru Nagar, Jodhpur',
        'lat' => 26.2960, 'lng' => 73.0260,
        'logo' => img('photo-1601050690597-df0568f70950'), 'cover' => img('photo-1601050690597-df0568f70950'),
        'cuisine' => 'North Indian,Thali', 'veg_only' => true,
        'open_t' => '10:00:00', 'close_t' => '22:30:00', 'min_order' => 99, 'rating' => 4.1,
        'offer_badge' => 'Items at ₹136', 'tags' => ['near_fast', 'pure_veg'],
    ],
    [
        'name' => 'Jantawala Sweets & Restaurant', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000005',
        'owner_email' => 'jantawala@anydrop.demo', 'address' => 'Nai Sarak, Jodhpur',
        'lat' => 26.2920, 'lng' => 73.0330,
        'logo' => img('photo-1631452180519-c014fe946bc7'), 'cover' => img('photo-1631452180519-c014fe946bc7'),
        'cuisine' => 'North Indian,Sweets,Thali', 'veg_only' => true,
        'open_t' => '09:00:00', 'close_t' => '22:00:00', 'min_order' => 149, 'rating' => 3.8,
        'offer_badge' => '₹80 OFF above ₹149', 'tags' => ['near_fast', 'pure_veg'],
    ],
    [
        'name' => "The Roll 'A' Wrap", 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000006',
        'owner_email' => 'rollawrap@anydrop.demo', 'address' => 'Chopasni Road, Jodhpur',
        'lat' => 26.2870, 'lng' => 73.0150,
        'logo' => img('photo-1626700051175-6818013e1d4f'), 'cover' => img('photo-1626700051175-6818013e1d4f'),
        'cuisine' => 'Rolls,Wraps,Fast Food', 'veg_only' => true,
        'open_t' => '11:00:00', 'close_t' => '23:00:00', 'min_order' => 99, 'rating' => 4.1,
        'offer_badge' => '50% OFF on select items', 'tags' => ['near_fast', 'pure_veg'],
    ],
    [
        'name' => 'Hot Wheels', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000007',
        'owner_email' => 'hotwheels@anydrop.demo', 'address' => 'Paota, Jodhpur',
        'lat' => 26.3010, 'lng' => 73.0100,
        'logo' => img('photo-1599487488170-d11ec9c172f0'), 'cover' => img('photo-1599487488170-d11ec9c172f0'),
        'cuisine' => 'Snacks,Chaat,Fast Food', 'veg_only' => true,
        'open_t' => '12:00:00', 'close_t' => '23:00:00', 'min_order' => 99, 'rating' => 4.1,
        'offer_badge' => 'Extra 10% OFF', 'tags' => ['pure_veg', 'gold_extra_10'],
    ],
    [
        'name' => 'New Lucky Namkeen', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000008',
        'owner_email' => 'newlucky@anydrop.demo', 'address' => 'Ratanada, Jodhpur',
        'lat' => 26.2800, 'lng' => 73.0280,
        'logo' => img('photo-1606491956689-2ea866880c84'), 'cover' => img('photo-1606491956689-2ea866880c84'),
        'cuisine' => 'Snacks,Namkeen,Sweets', 'veg_only' => true,
        'open_t' => '09:00:00', 'close_t' => '21:30:00', 'min_order' => 99, 'rating' => 4.3,
        'offer_badge' => null, 'tags' => ['pure_veg'],
    ],
    [
        'name' => 'Dilip Fast Food', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000009',
        'owner_email' => 'dilipfastfood@anydrop.demo', 'address' => 'Shastri Nagar, Jodhpur',
        'lat' => 26.3050, 'lng' => 73.0200,
        'logo' => img('photo-1565299624946-b28f40a0ae38'), 'cover' => img('photo-1565299624946-b28f40a0ae38'),
        'cuisine' => 'Pizza,Fast Food', 'veg_only' => true,
        'open_t' => '11:00:00', 'close_t' => '23:00:00', 'min_order' => 99, 'rating' => 3.8,
        'offer_badge' => '50% OFF select items', 'tags' => ['pure_veg'],
    ],
    [
        'name' => 'Nandi', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000010',
        'owner_email' => 'nandi@anydrop.demo', 'address' => 'Airport Road, Jodhpur',
        'lat' => 26.2600, 'lng' => 73.0490,
        'logo' => img('photo-1589301760014-d929f3979dbc'), 'cover' => img('photo-1589301760014-d929f3979dbc'),
        'cuisine' => 'South Indian,North Indian', 'veg_only' => true,
        'open_t' => '08:00:00', 'close_t' => '22:00:00', 'min_order' => 99, 'rating' => 4.3,
        'offer_badge' => '₹40 OFF above ₹149', 'tags' => ['near_fast', 'pure_veg'],
    ],
    [
        'name' => 'Pavitras', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000011',
        'owner_email' => 'pavitras@anydrop.demo', 'address' => 'Sardarpura, Jodhpur',
        'lat' => 26.2895, 'lng' => 73.0175,
        'logo' => img('photo-1601315379734-6c0e8b1d5bb2'), 'cover' => img('photo-1601315379734-6c0e8b1d5bb2'),
        'cuisine' => 'North Indian,Punjabi,Thali', 'veg_only' => true,
        'open_t' => '10:00:00', 'close_t' => '22:30:00', 'min_order' => 149, 'rating' => 4.3,
        'offer_badge' => null, 'tags' => ['pure_veg'],
    ],
    [
        'name' => 'Chopstix Chinese Corner', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000012',
        'owner_email' => 'chopstix@anydrop.demo', 'address' => 'Residency Road, Jodhpur',
        'lat' => 26.2980, 'lng' => 73.0320,
        'logo' => img('photo-1585032226651-759b368d7246'), 'cover' => img('photo-1585032226651-759b368d7246'),
        'cuisine' => 'Chinese,Asian', 'veg_only' => false,
        'open_t' => '12:00:00', 'close_t' => '23:30:00', 'min_order' => 149, 'rating' => 4.0,
        'offer_badge' => '30% OFF above ₹249', 'tags' => ['near_fast'],
    ],
    [
        'name' => 'Sweet Treats Dessert House', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000013',
        'owner_email' => 'sweettreats@anydrop.demo', 'address' => 'Pal Road, Jodhpur',
        'lat' => 26.2700, 'lng' => 73.0400,
        'logo' => img('photo-1551024506-0bccd828d307'), 'cover' => img('photo-1551024506-0bccd828d307'),
        'cuisine' => 'Desserts,Bakery,Cafe', 'veg_only' => true,
        'open_t' => '10:00:00', 'close_t' => '23:00:00', 'min_order' => 99, 'rating' => 4.4,
        'offer_badge' => 'Flat ₹50 OFF above ₹199', 'tags' => ['pure_veg'],
    ],
    [
        'name' => 'Biryani Central', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000014',
        'owner_email' => 'biryanicentral@anydrop.demo', 'address' => 'Basni, Jodhpur',
        'lat' => 26.2500, 'lng' => 73.0350,
        'logo' => img('photo-1633945274405-b6c8069047b0'), 'cover' => img('photo-1633945274405-b6c8069047b0'),
        'cuisine' => 'Biryani,Mughlai,North Indian', 'veg_only' => false,
        'open_t' => '11:00:00', 'close_t' => '23:30:00', 'min_order' => 149, 'rating' => 4.2,
        'offer_badge' => '20% OFF above ₹299', 'tags' => ['near_fast'],
    ],
    [
        'name' => 'Chai Point Beverages', 'owner_name' => 'Demo Owner', 'owner_mobile' => '9000000015',
        'owner_email' => 'chaipoint@anydrop.demo', 'address' => 'Ratanada, Jodhpur',
        'lat' => 26.2845, 'lng' => 73.0290,
        'logo' => img('photo-1544787219-7f47ccb76574'), 'cover' => img('photo-1544787219-7f47ccb76574'),
        'cuisine' => 'Beverages,Cafe,Snacks', 'veg_only' => true,
        'open_t' => '07:00:00', 'close_t' => '23:00:00', 'min_order' => 79, 'rating' => 4.0,
        'offer_badge' => null, 'tags' => ['near_fast', 'pure_veg'],
    ],
];

// ---- 2. Items per restaurant (category name, food-category slug for the
//        Home chips row, item fields) --------------------------------

$itemsByOwnerEmail = [
    'burgerking@anydrop.demo' => [
        'cat' => 'Burgers',
        'items' => [
            ['name' => 'Whopper', 'desc' => 'Flame-grilled beef patty with fresh veggies', 'price' => 229, 'veg' => false, 'img' => img('photo-1568901346375-23c9450c58cd'), 'slug' => 'burger', 'best' => 1, 'rec' => 1],
            ['name' => 'Veg Whopper', 'desc' => 'Flame-grilled veg patty with lettuce and mayo', 'price' => 189, 'veg' => true, 'img' => img('photo-1571091718767-18b5b1457add'), 'slug' => 'burger'],
            ['name' => 'Crispy Veg Burger', 'desc' => 'Crunchy veg patty, cheese, tangy sauce', 'price' => 129, 'disc' => 50, 'veg' => true, 'img' => img('photo-1550547660-d9450f859349'), 'slug' => 'burger'],
            ['name' => 'Peri Peri Fries', 'desc' => 'Crispy fries tossed in peri-peri seasoning', 'price' => 99, 'veg' => true, 'img' => img('photo-1573080496219-bb080dd4f877'), 'slug' => 'burger'],
        ],
    ],
    'rominus@anydrop.demo' => [
        'cat' => 'Pizza',
        'items' => [
            ['name' => 'Farmhouse Pizza', 'desc' => 'Loaded with onion, capsicum, tomato, mushroom', 'price' => 249, 'disc' => 60, 'veg' => true, 'img' => img('photo-1513104890138-7c749659a591'), 'slug' => 'pizza', 'best' => 1],
            ['name' => 'Mini Pizza Trio', 'desc' => 'Three mini pizzas with assorted toppings', 'price' => 199, 'disc' => 60, 'veg' => true, 'img' => img('photo-1548365328-9f547fb0953b'), 'slug' => 'pizza', 'rec' => 1],
        ],
    ],
    'lapinoz@anydrop.demo' => [
        'cat' => 'Pizza',
        'items' => [
            ['name' => 'Cheese Burst Pizza', 'desc' => 'Extra cheese oozing from every slice', 'price' => 299, 'veg' => true, 'img' => img('photo-1594007654729-407eedc4be65'), 'slug' => 'pizza', 'best' => 1, 'rec' => 1],
            ['name' => 'Pepperoni Pizza', 'desc' => 'Classic pepperoni with mozzarella', 'price' => 349, 'veg' => false, 'img' => img('photo-1628840042765-356cda07504e'), 'slug' => 'pizza'],
            ['name' => 'Paneer Tikka Pizza', 'desc' => 'Spiced paneer tikka on a cheesy base', 'price' => 279, 'veg' => true, 'img' => img('photo-1571407970349-bc81e7e96d47'), 'slug' => 'pizza'],
        ],
    ],
    'shahisabjiwala@anydrop.demo' => [
        'cat' => 'Thali & Sabji',
        'items' => [
            ['name' => 'Shahi Paneer Kebab', 'desc' => 'Char-grilled paneer chunks, tangy chutney', 'price' => 136, 'veg' => true, 'img' => img('photo-1631452180519-c014fe946bc7'), 'slug' => 'thali', 'best' => 1],
            ['name' => 'Deluxe Veg Thali', 'desc' => 'Roti, rice, dal, sabji, curd, salad', 'price' => 179, 'veg' => true, 'img' => img('photo-1546833999-b9f581a1996d'), 'slug' => 'thali', 'rec' => 1],
        ],
    ],
    'jantawala@anydrop.demo' => [
        'cat' => 'Thali & Sweets',
        'items' => [
            ['name' => 'Rajasthani Thali', 'desc' => 'Dal baati churma style full meal', 'price' => 189, 'veg' => true, 'img' => img('photo-1601050690597-df0568f70950'), 'slug' => 'thali', 'best' => 1],
            ['name' => 'Dhokla Chaat', 'desc' => 'Steamed gram-flour cake with chutneys', 'price' => 89, 'veg' => true, 'img' => img('photo-1606491956689-2ea866880c84'), 'slug' => 'thali'],
        ],
    ],
    'rollawrap@anydrop.demo' => [
        'cat' => 'Rolls',
        'items' => [
            ['name' => 'Paneer Roll', 'desc' => 'Grilled paneer, onions, mint chutney in a wrap', 'price' => 99, 'disc' => 50, 'veg' => true, 'img' => img('photo-1626700051175-6818013e1d4f'), 'slug' => 'rolls', 'best' => 1],
            ['name' => 'Veg Kathi Roll', 'desc' => 'Spiced mixed veggies rolled in a paratha', 'price' => 89, 'veg' => true, 'img' => img('photo-1600628421066-f6bda6a7b976'), 'slug' => 'rolls'],
            ['name' => 'Cheese Corn Roll', 'desc' => 'Melted cheese and sweet corn wrap', 'price' => 109, 'veg' => true, 'img' => img('photo-1621996346565-e3dbc353d2e5'), 'slug' => 'rolls', 'rec' => 1],
        ],
    ],
    'hotwheels@anydrop.demo' => [
        'cat' => 'Snacks',
        'items' => [
            ['name' => 'Veg Seekh Kebab', 'desc' => 'Spiced veg skewers grilled to perfection', 'price' => 149, 'veg' => true, 'img' => img('photo-1599487488170-d11ec9c172f0'), 'slug' => 'south-indian', 'best' => 1],
            ['name' => 'Mixed Namkeen Platter', 'desc' => 'Assorted crispy fried snacks', 'price' => 129, 'veg' => true, 'img' => img('photo-1606491956689-2ea866880c84'), 'slug' => 'south-indian'],
        ],
    ],
    'newlucky@anydrop.demo' => [
        'cat' => 'Namkeen',
        'items' => [
            ['name' => 'Samosa Kachori Combo', 'desc' => 'Crispy samosa and kachori with chutney', 'price' => 79, 'veg' => true, 'img' => img('photo-1601050690597-df0568f70950'), 'slug' => 'south-indian', 'best' => 1],
        ],
    ],
    'dilipfastfood@anydrop.demo' => [
        'cat' => 'Pizza & Fast Food',
        'items' => [
            ['name' => 'Veggie Delight Pizza', 'desc' => 'Bell peppers, onion, tomato, jalapeno', 'price' => 189, 'disc' => 50, 'veg' => true, 'img' => img('photo-1565299624946-b28f40a0ae38'), 'slug' => 'pizza', 'best' => 1],
        ],
    ],
    'nandi@anydrop.demo' => [
        'cat' => 'South Indian',
        'items' => [
            ['name' => 'Masala Dosa', 'desc' => 'Crispy dosa with spiced potato filling', 'price' => 99, 'veg' => true, 'img' => img('photo-1589301760014-d929f3979dbc'), 'slug' => 'south-indian', 'best' => 1, 'rec' => 1],
            ['name' => 'Idli Sambar (4 pcs)', 'desc' => 'Steamed rice cakes with sambar and chutney', 'price' => 79, 'veg' => true, 'img' => img('photo-1589301773859-3b5c7a1e8c7c'), 'slug' => 'south-indian'],
            ['name' => 'Rava Uttapam', 'desc' => 'Semolina pancake topped with veggies', 'price' => 89, 'veg' => true, 'img' => img('photo-1630383249896-424e482df921'), 'slug' => 'south-indian'],
        ],
    ],
    'pavitras@anydrop.demo' => [
        'cat' => 'North Indian',
        'items' => [
            ['name' => 'Dal Makhani', 'desc' => 'Slow-cooked black lentils in butter and cream', 'price' => 210, 'veg' => true, 'img' => img('photo-1546833999-b9f581a1996d'), 'slug' => 'thali', 'best' => 1],
            ['name' => 'Paneer Butter Masala', 'desc' => 'Cottage cheese in a rich tomato gravy', 'price' => 249, 'veg' => true, 'img' => img('photo-1631452180519-c014fe946bc7'), 'slug' => 'thali', 'rec' => 1],
        ],
    ],
    'chopstix@anydrop.demo' => [
        'cat' => 'Chinese',
        'items' => [
            ['name' => 'Veg Hakka Noodles', 'desc' => 'Wok-tossed noodles with fresh vegetables', 'price' => 179, 'veg' => true, 'img' => img('photo-1585032226651-759b368d7246'), 'slug' => 'chinese', 'best' => 1],
            ['name' => 'Chilli Chicken', 'desc' => 'Crispy chicken tossed in spicy chilli sauce', 'price' => 249, 'veg' => false, 'img' => img('photo-1626200926749-2a0ffcaa9c4d'), 'slug' => 'chinese', 'rec' => 1],
            ['name' => 'Veg Manchurian', 'desc' => 'Fried veg balls in a tangy sauce', 'price' => 189, 'veg' => true, 'img' => img('photo-1626200419199-391ae4be7a41'), 'slug' => 'chinese'],
        ],
    ],
    'sweettreats@anydrop.demo' => [
        'cat' => 'Desserts',
        'items' => [
            ['name' => 'Chocolate Truffle Cake Slice', 'desc' => 'Rich chocolate layered cake', 'price' => 129, 'veg' => true, 'img' => img('photo-1551024506-0bccd828d307'), 'slug' => 'desserts', 'best' => 1],
            ['name' => 'Tiramisu', 'desc' => 'Classic Italian coffee-flavoured dessert', 'price' => 159, 'veg' => true, 'img' => img('photo-1571877227200-a0d98ea607e9'), 'slug' => 'desserts', 'rec' => 1],
            ['name' => 'Red Velvet Pastry', 'desc' => 'Soft red velvet sponge with cream cheese', 'price' => 119, 'veg' => true, 'img' => img('photo-1586985289906-406988974504'), 'slug' => 'desserts'],
        ],
    ],
    'biryanicentral@anydrop.demo' => [
        'cat' => 'Biryani',
        'items' => [
            ['name' => 'Chicken Dum Biryani', 'desc' => 'Slow-cooked basmati rice with spiced chicken', 'price' => 259, 'veg' => false, 'img' => img('photo-1633945274405-b6c8069047b0'), 'slug' => 'biryani', 'best' => 1, 'rec' => 1],
            ['name' => 'Veg Biryani', 'desc' => 'Fragrant basmati rice with mixed vegetables', 'price' => 199, 'veg' => true, 'img' => img('photo-1589302168068-964664d93dc0'), 'slug' => 'biryani'],
            ['name' => 'Mutton Biryani', 'desc' => 'Tender mutton pieces in aromatic rice', 'price' => 299, 'veg' => false, 'img' => img('photo-1631515243349-e0cb75fb8d3a'), 'slug' => 'biryani'],
        ],
    ],
    'chaipoint@anydrop.demo' => [
        'cat' => 'Beverages',
        'items' => [
            ['name' => 'Masala Chai', 'desc' => 'Classic spiced Indian tea', 'price' => 29, 'veg' => true, 'img' => img('photo-1544787219-7f47ccb76574'), 'slug' => 'beverages', 'best' => 1],
            ['name' => 'Cold Coffee', 'desc' => 'Chilled coffee blended with ice cream', 'price' => 89, 'veg' => true, 'img' => img('photo-1461023058943-07fcbe16d735'), 'slug' => 'beverages', 'rec' => 1],
            ['name' => 'Fresh Lime Soda', 'desc' => 'Refreshing lime soda, sweet or salted', 'price' => 49, 'veg' => true, 'img' => img('photo-1621263764928-df1444c5e859'), 'slug' => 'beverages'],
        ],
    ],
];

// ---- Run it -------------------------------------------------------------

$summary = [];
foreach ($restaurants as $r) {
    $rid = getOrCreateRestaurant($db, $r);
    foreach ($r['tags'] as $tagSlug) {
        mapRestaurantTag($db, $rid, $tagSlug);
    }

    $group = $itemsByOwnerEmail[$r['owner_email']] ?? null;
    $itemCount = 0;
    if ($group) {
        $catId = getOrCreateCategory($db, $rid, $group['cat'], 1);
        foreach ($group['items'] as $it) {
            $itemId = getOrCreateItem($db, $rid, $catId, $it);
            mapItemToFoodCategory($db, $itemId, $it['slug']);
            $itemCount++;
        }
    }
    $summary[] = "{$r['name']} (id={$rid}): {$itemCount} items";
}

header('Content-Type: text/plain');
echo "Demo catalog seeded.\n\n";
echo implode("\n", $summary);
echo "\n\nTotal restaurants: " . count($restaurants) . "\n";
echo "Login for any demo restaurant: <owner_email above> / Demo@1234\n";
echo "\nDELETE THIS FILE once you've confirmed the data looks right.";
