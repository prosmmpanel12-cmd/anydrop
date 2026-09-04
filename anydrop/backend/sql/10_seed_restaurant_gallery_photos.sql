-- ============================================================
-- Anydrop — Seed: gallery photos for the 5 test restaurants from
-- 08_seed_multi_category_test_restaurants.sql, so the new auto-advancing
-- card carousel (Phase E §2.7) has something to actually demo/scroll
-- through. Run 08 first — this looks the 5 restaurants up by owner_email.
--
-- Restaurants that AREN'T in this seed (i.e. any real restaurant that
-- hasn't uploaded gallery photos yet) are unaffected — they just keep
-- showing the old single static cover image, same as before this feature
-- existed. There's no restaurant-panel UI to upload these yet (that's a
-- separate, not-yet-built task) — for now the only way to add gallery
-- photos is a SQL insert into restaurant_gallery_photos, same as this
-- file does.
--
-- Safe to re-run: deletes each restaurant's existing gallery rows first.
-- Photos are stock Unsplash images (same convention already used by
-- 03_migration_splash_login_settings.sql / 06_migration_phase36.sql for
-- placeholder imagery) — swap for real photos whenever ready, no app
-- update needed since the app just reads whatever URL is in this table.
-- ============================================================

DELETE FROM restaurant_gallery_photos WHERE restaurant_id IN (
    SELECT id FROM restaurants WHERE owner_email IN (
        'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
        'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
    )
);

SET @r1 := (SELECT id FROM restaurants WHERE owner_email = 'spicejunction@anydroptest.com');
SET @r2 := (SELECT id FROM restaurants WHERE owner_email = 'urbanpizzaco@anydroptest.com');
SET @r3 := (SELECT id FROM restaurants WHERE owner_email = 'dragonwok@anydroptest.com');
SET @r4 := (SELECT id FROM restaurants WHERE owner_email = 'burgerbarn@anydroptest.com');
SET @r5 := (SELECT id FROM restaurants WHERE owner_email = 'southspice@anydroptest.com');

INSERT INTO restaurant_gallery_photos (restaurant_id, image_url, dish_name, price, sort_order) VALUES
-- Spice Junction
(@r1, 'https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?w=800&q=80', 'Paneer Tikka', 189, 1),
(@r1, 'https://images.unsplash.com/photo-1588166524941-3bf61a9c41db?w=800&q=80', 'Butter Chicken', 249, 2),
(@r1, 'https://images.unsplash.com/photo-1626777553635-be6b4b2c9dd6?w=800&q=80', 'Dal Makhani', 179, 3),

-- Urban Pizza Co
(@r2, 'https://images.unsplash.com/photo-1574071318508-1cdbab80d002?w=800&q=80', 'Margherita', 199, 1),
(@r2, 'https://images.unsplash.com/photo-1628840042765-356cda07504e?w=800&q=80', 'Chicken Pepperoni', 299, 2),
(@r2, 'https://images.unsplash.com/photo-1594007654729-407eedc4be65?w=800&q=80', 'Farmhouse', 249, 3),

-- Dragon Wok
(@r3, 'https://images.unsplash.com/photo-1585032226651-759b368d7246?w=800&q=80', 'Chilli Chicken', 219, 1),
(@r3, 'https://images.unsplash.com/photo-1585238342024-78d387f4a707?w=800&q=80', 'Veg Fried Rice', 149, 2),
(@r3, 'https://images.unsplash.com/photo-1552611052-33e04de081de?w=800&q=80', 'Hot & Sour Soup', 99, 3),

-- Burger Barn
(@r4, 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=800&q=80', 'Double Cheese Burger', 199, 1),
(@r4, 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=800&q=80', 'Grilled Chicken Burger', 159, 2),
(@r4, 'https://images.unsplash.com/photo-1541592106381-b31e9677c0e5?w=800&q=80', 'French Fries', 89, 3),

-- South Spice Express
(@r5, 'https://images.unsplash.com/photo-1668236543090-82eba5ee5976?w=800&q=80', 'Masala Dosa', 99, 1),
(@r5, 'https://images.unsplash.com/photo-1589301760014-d929f3979dbc?w=800&q=80', 'Idli Sambar', 79, 2),
(@r5, 'https://images.unsplash.com/photo-1626132647523-66b1c7f8f9e8?w=800&q=80', 'Medu Vada', 69, 3);
