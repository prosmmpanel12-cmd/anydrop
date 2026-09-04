-- ============================================================
-- Anydrop — Migration 12b: same as 12_seed_gallery_from_menu_items.sql,
-- rewritten WITHOUT window functions (ROW_NUMBER() OVER ...), because
-- this environment's MySQL/MariaDB version doesn't support them ("You
-- have an error in your SQL syntax ... near '(PARTITION BY ...'").
--
-- Uses the classic MySQL user-variable ranking trick instead (same
-- family of technique migration 10 already leans on for its @r1..@r5
-- restaurant lookups, just generalized to rank rows within a group):
-- a running counter (@rn) that resets to 1 every time restaurant_id
-- changes, over rows pre-sorted so each restaurant's own rows are
-- grouped together and ordered bestseller/recommended/price-first.
--
-- Same intent, same output as migration 12 — top 5 eligible menu items
-- per restaurant (excluding the 5 migration-10 test restaurants),
-- ranked is_bestseller DESC, is_recommended DESC, price DESC.
--
-- Use 12b, not 12, going forward on this database.
-- ============================================================

DELETE FROM restaurant_gallery_photos WHERE restaurant_id NOT IN (
    SELECT id FROM restaurants WHERE owner_email IN (
        'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
        'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
    )
);

INSERT INTO restaurant_gallery_photos (restaurant_id, image_url, dish_name, price, sort_order)
SELECT restaurant_id, image_url, name, price, rn
FROM (
    SELECT
        mi.restaurant_id AS restaurant_id,
        mi.image_url AS image_url,
        mi.name AS name,
        mi.price AS price,
        @rn := IF(@prev_r = mi.restaurant_id, @rn + 1, 1) AS rn,
        @prev_r := mi.restaurant_id AS prev_r
    FROM menu_items mi
    INNER JOIN restaurants r ON r.id = mi.restaurant_id
    CROSS JOIN (SELECT @rn := 0, @prev_r := NULL) init_vars
    WHERE mi.deleted_at IS NULL
      AND mi.is_available = 1
      AND mi.image_url IS NOT NULL
      AND mi.image_url <> ''
      AND r.owner_email NOT IN (
          'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
          'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
      )
    ORDER BY mi.restaurant_id, mi.is_bestseller DESC, mi.is_recommended DESC, mi.price DESC, mi.id ASC
) ranked
WHERE rn <= 5;
