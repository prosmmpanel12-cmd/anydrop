-- ============================================================
-- Anydrop — Seed: 5 test restaurants with multiple menu categories
--
-- Why: to test the horizontal category chip tab bar (§2.1) and the
-- floating "Menu" jump button (§2.5) on Restaurant Detail — both are
-- hidden when a restaurant has only 1 category, so you need at least one
-- restaurant with 2+ categories to see either feature. This adds 5.
--
-- Run once in phpMyAdmin > SQL tab > paste > Go. Safe to re-run: every
-- INSERT is preceded by a DELETE of any earlier run of this same seed
-- (matched by owner_email), so re-running just refreshes the same 5
-- restaurants instead of duplicating them.
--
-- Restaurant owner login (all 5 use the same test password):
--   Email: see owner_email below (e.g. spicejunction@anydroptest.com)
--   Password: Test@1234
-- ============================================================

-- ------------------------------------------------------------
-- 0. Clean up any earlier run of this seed first (safe re-run)
-- ------------------------------------------------------------
DELETE FROM menu_items WHERE restaurant_id IN (
  SELECT id FROM restaurants WHERE owner_email IN (
    'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
    'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
  )
);
DELETE FROM menu_categories WHERE restaurant_id IN (
  SELECT id FROM restaurants WHERE owner_email IN (
    'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
    'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
  )
);
DELETE FROM restaurant_tag_map WHERE restaurant_id IN (
  SELECT id FROM restaurants WHERE owner_email IN (
    'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
    'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
  )
);
DELETE FROM restaurants WHERE owner_email IN (
  'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
  'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
);

-- password_hash below = bcrypt of "Test@1234" (works with PHP password_verify)
-- Every restaurant: status='approved' + operational_status='open' + a full
-- day/week open window, so it shows up in listing and reads as "Open"
-- (not dimmed by the closed-restaurant-card fix) with no extra setup.

-- ------------------------------------------------------------
-- 1. Spice Junction — North Indian, 4 categories
-- ------------------------------------------------------------
INSERT INTO restaurants
  (name, owner_email, password_hash, address, latitude, longitude, cuisine_tags,
   is_veg_only, opening_time, closing_time, working_days, status, operational_status,
   rating_avg, rating_count)
VALUES
  ('Spice Junction', 'spicejunction@anydroptest.com',
   '$2b$12$W9UUoNOVs0Gy2Z/eT583d.D63kZlk5XbRPG2zwPMt1Nb1SHAdw0s6',
   'Karol Bagh, New Delhi', 28.65195000, 77.19060000, 'North Indian,Mughlai',
   0, '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 'approved', 'open', 4.3, 2100);
SET @r1 := LAST_INSERT_ID();

INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES
  (@r1, 'Starters', 1), (@r1, 'Main Course', 2), (@r1, 'Breads', 3), (@r1, 'Desserts', 4);
SET @c1 := LAST_INSERT_ID();

INSERT INTO menu_items (restaurant_id, category_id, name, description, price, is_veg) VALUES
  (@r1, @c1,   'Paneer Tikka', 'Char-grilled cottage cheese skewers', 189, 1),
  (@r1, @c1,   'Chicken Seekh Kebab', 'Minced chicken skewers with spices', 219, 0),
  (@r1, @c1+1, 'Dal Makhani', 'Slow-cooked black lentils in butter', 179, 1),
  (@r1, @c1+1, 'Butter Chicken', 'Classic tomato-butter chicken curry', 249, 0),
  (@r1, @c1+2, 'Butter Naan', 'Tandoor-baked leavened bread', 45, 1),
  (@r1, @c1+2, 'Lachha Paratha', 'Layered whole-wheat flatbread', 40, 1),
  (@r1, @c1+3, 'Gulab Jamun (2 pcs)', 'Milk-solid dumplings in sugar syrup', 79, 1),
  (@r1, @c1+3, 'Rasmalai (2 pcs)', 'Cottage cheese discs in saffron milk', 89, 1);

-- ------------------------------------------------------------
-- 2. Urban Pizza Co — Pizza/Italian, 3 categories
-- ------------------------------------------------------------
INSERT INTO restaurants
  (name, owner_email, password_hash, address, latitude, longitude, cuisine_tags,
   is_veg_only, opening_time, closing_time, working_days, status, operational_status,
   rating_avg, rating_count)
VALUES
  ('Urban Pizza Co', 'urbanpizzaco@anydroptest.com',
   '$2b$12$W9UUoNOVs0Gy2Z/eT583d.D63kZlk5XbRPG2zwPMt1Nb1SHAdw0s6',
   'Connaught Place, New Delhi', 28.63290000, 77.21950000, 'Pizza,Italian,Fast Food',
   0, '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 'approved', 'open', 4.1, 3400);
SET @r2 := LAST_INSERT_ID();

INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES
  (@r2, 'Pizzas', 1), (@r2, 'Sides', 2), (@r2, 'Beverages', 3);
SET @c2 := LAST_INSERT_ID();

INSERT INTO menu_items (restaurant_id, category_id, name, description, price, is_veg) VALUES
  (@r2, @c2,   'Margherita', 'Classic tomato, mozzarella, basil', 199, 1),
  (@r2, @c2,   'Chicken Pepperoni', 'Loaded with spicy pepperoni', 299, 0),
  (@r2, @c2,   'Farmhouse', 'Onion, capsicum, tomato, mushroom', 249, 1),
  (@r2, @c2+1, 'Garlic Breadsticks', 'Baked with garlic butter', 129, 1),
  (@r2, @c2+1, 'Cheesy Wedges', 'Potato wedges with cheese dip', 149, 1),
  (@r2, @c2+2, 'Coke (500ml)', 'Chilled soft drink', 60, 1),
  (@r2, @c2+2, 'Cold Coffee', 'Blended cold coffee with ice cream', 99, 1);

-- ------------------------------------------------------------
-- 3. Dragon Wok — Chinese, 4 categories
-- ------------------------------------------------------------
INSERT INTO restaurants
  (name, owner_email, password_hash, address, latitude, longitude, cuisine_tags,
   is_veg_only, opening_time, closing_time, working_days, status, operational_status,
   rating_avg, rating_count)
VALUES
  ('Dragon Wok', 'dragonwok@anydroptest.com',
   '$2b$12$W9UUoNOVs0Gy2Z/eT583d.D63kZlk5XbRPG2zwPMt1Nb1SHAdw0s6',
   'Rajouri Garden, New Delhi', 28.64920000, 77.12130000, 'Chinese,Asian',
   0, '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 'approved', 'open', 4.2, 1800);
SET @r3 := LAST_INSERT_ID();

INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES
  (@r3, 'Soups', 1), (@r3, 'Starters', 2), (@r3, 'Main Course', 3), (@r3, 'Rice & Noodles', 4);
SET @c3 := LAST_INSERT_ID();

INSERT INTO menu_items (restaurant_id, category_id, name, description, price, is_veg) VALUES
  (@r3, @c3,   'Hot & Sour Soup', 'Spicy-tangy vegetable soup', 99, 1),
  (@r3, @c3,   'Manchow Soup', 'Vegetable soup with crispy noodles', 109, 1),
  (@r3, @c3+1, 'Veg Manchurian', 'Fried veg balls in tangy sauce', 159, 1),
  (@r3, @c3+1, 'Chilli Chicken', 'Wok-tossed chicken in chilli sauce', 219, 0),
  (@r3, @c3+2, 'Kung Pao Chicken', 'Diced chicken with peanuts and chilli', 249, 0),
  (@r3, @c3+2, 'Veg in Garlic Sauce', 'Mixed vegetables in garlic sauce', 179, 1),
  (@r3, @c3+3, 'Veg Fried Rice', 'Wok-tossed rice with vegetables', 149, 1),
  (@r3, @c3+3, 'Chicken Hakka Noodles', 'Stir-fried noodles with chicken', 189, 0);

-- ------------------------------------------------------------
-- 4. Burger Barn — American/Burgers, 3 categories
-- ------------------------------------------------------------
INSERT INTO restaurants
  (name, owner_email, password_hash, address, latitude, longitude, cuisine_tags,
   is_veg_only, opening_time, closing_time, working_days, status, operational_status,
   rating_avg, rating_count)
VALUES
  ('Burger Barn', 'burgerbarn@anydroptest.com',
   '$2b$12$W9UUoNOVs0Gy2Z/eT583d.D63kZlk5XbRPG2zwPMt1Nb1SHAdw0s6',
   'Lajpat Nagar, New Delhi', 28.56650000, 77.24310000, 'American,Burgers,Fast Food',
   0, '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 'approved', 'open', 4.0, 2650);
SET @r4 := LAST_INSERT_ID();

INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES
  (@r4, 'Burgers', 1), (@r4, 'Sides', 2), (@r4, 'Shakes', 3);
SET @c4 := LAST_INSERT_ID();

INSERT INTO menu_items (restaurant_id, category_id, name, description, price, is_veg) VALUES
  (@r4, @c4,   'Classic Veg Burger', 'Crispy veg patty with lettuce & mayo', 99, 1),
  (@r4, @c4,   'Grilled Chicken Burger', 'Grilled chicken patty, cheese, mayo', 159, 0),
  (@r4, @c4,   'Double Cheese Burger', 'Two beef-style patties, double cheese', 199, 0),
  (@r4, @c4+1, 'French Fries', 'Crispy salted potato fries', 89, 1),
  (@r4, @c4+1, 'Onion Rings', 'Crispy battered onion rings', 99, 1),
  (@r4, @c4+2, 'Chocolate Shake', 'Thick chocolate milkshake', 119, 1),
  (@r4, @c4+2, 'Strawberry Shake', 'Fresh strawberry milkshake', 119, 1);

-- ------------------------------------------------------------
-- 5. South Spice Express — South Indian, 3 categories
-- ------------------------------------------------------------
INSERT INTO restaurants
  (name, owner_email, password_hash, address, latitude, longitude, cuisine_tags,
   is_veg_only, opening_time, closing_time, working_days, status, operational_status,
   rating_avg, rating_count)
VALUES
  ('South Spice Express', 'southspice@anydroptest.com',
   '$2b$12$W9UUoNOVs0Gy2Z/eT583d.D63kZlk5XbRPG2zwPMt1Nb1SHAdw0s6',
   'Vasant Kunj, New Delhi', 28.52440000, 77.15550000, 'South Indian',
   1, '00:00:00', '23:59:59', '1,2,3,4,5,6,7', 'approved', 'open', 4.5, 3900);
SET @r5 := LAST_INSERT_ID();

INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES
  (@r5, 'Breakfast', 1), (@r5, 'Rice Specials', 2), (@r5, 'Curries', 3);
SET @c5 := LAST_INSERT_ID();

INSERT INTO menu_items (restaurant_id, category_id, name, description, price, is_veg) VALUES
  (@r5, @c5,   'Masala Dosa', 'Crispy rice crepe with spiced potato', 99, 1),
  (@r5, @c5,   'Idli Sambar (4 pcs)', 'Steamed rice cakes with lentil sambar', 79, 1),
  (@r5, @c5,   'Medu Vada (2 pcs)', 'Crispy lentil doughnuts with chutney', 69, 1),
  (@r5, @c5+1, 'Curd Rice', 'Comfort rice mixed with fresh curd', 89, 1),
  (@r5, @c5+1, 'Lemon Rice', 'Tangy tempered rice with peanuts', 89, 1),
  (@r5, @c5+2, 'Sambar', 'Lentil and vegetable stew', 59, 1),
  (@r5, @c5+2, 'Coconut Chutney', 'Fresh coconut chutney', 29, 1);

-- Pure Veg badge on the one veg-only restaurant, same pattern as the
-- restaurant_tags/restaurant_tag_map seeded in 05_migration_categories_and_tags.sql
INSERT INTO restaurant_tag_map (restaurant_id, restaurant_tag_id)
SELECT @r5, id FROM restaurant_tags WHERE slug = 'pure_veg'
ON DUPLICATE KEY UPDATE restaurant_id = restaurant_id;

-- ------------------------------------------------------------
-- Done. All 5 have 3-4 categories each — open Customer app > any of these
-- 5 restaurants to see the chip tab bar + floating Menu jump button.
-- ------------------------------------------------------------
