-- ============================================================
-- Anydrop — Migration 12: auto-populate restaurant_gallery_photos from
-- each restaurant's own menu_items (Bug-tracker §2.7's recorded decision,
-- option (a) — "Auto from menu_items", re-confirmed with the user before
-- writing this rather than assuming).
--
-- Fixes the "every real restaurant shows the same generic cover photo"
-- bug: migration 09 only created an empty restaurant_gallery_photos
-- table, and the only seed that ever populated it
-- (10_seed_restaurant_gallery_photos.sql) covers exactly 5 hardcoded test
-- restaurants matched by owner_email. Every other restaurant — i.e. every
-- real restaurant on the live server — has an empty `gallery` array, so
-- DishPhotoCarouselView's own documented 0/1-photo fallback kicks in and
-- just shows restaurant.cover_url with no carousel. That's the "generic
-- image repeats on every card" symptom.
--
-- This is a data-population fix ONLY. It does not touch
-- DishPhotoCarouselView.kt or RestaurantAdapter.kt — both already read
-- restaurant.gallery correctly and are already proven working against
-- the 5 manually-seeded test restaurants.
--
-- Per restaurant, picks up to 5 of that restaurant's own available,
-- non-deleted menu items that have an image_url, ordered:
--   is_bestseller DESC, is_recommended DESC, price DESC
-- (same "top N by bestseller/recommended/highest-price" ranking the
-- bug-tracker doc's own §2.7 entry calls out, using columns that already
-- exist on menu_items — no schema change needed here).
--
-- Every gallery row's dish_name/price is taken directly from the source
-- menu item, so the "Dish Name · ₹price" overlay shows a real dish at a
-- real price, not a placeholder.
--
-- Scope / what this deliberately does NOT touch:
-- - The 5 restaurants seeded by 10_seed_restaurant_gallery_photos.sql
--   (spicejunction@anydroptest.com, urbanpizzaco@anydroptest.com,
--   dragonwok@anydroptest.com, burgerbarn@anydroptest.com,
--   southspice@anydroptest.com) are explicitly excluded below, so this
--   doesn't overwrite their curated Unsplash demo photos with their own
--   (likely placeholder-ier) menu_items.image_url rows.
-- - A restaurant with zero eligible menu items (no image_url set on
--   anything available) simply gets no gallery rows, same as today —
--   falls back to cover_url, no worse off than before this migration.
-- - Still no restaurant-panel upload UI — a restaurant owner can't yet
--   curate/reorder their own gallery from this. That remains open scope
--   (bug-tracker §2.7's option (b), not chosen for this pass).
--
-- Safe to re-run: deletes and rebuilds gallery rows for every eligible
-- restaurant each time (idempotent), so it can be re-run after new
-- restaurants/menu items are added without duplicating rows. Run AFTER
-- migrations 01–11 (needs restaurant_gallery_photos from migration 09).
-- ============================================================

-- Clear existing auto-seeded rows for every restaurant NOT in the
-- manually-curated test-restaurant set, so re-running this script stays
-- idempotent instead of accumulating duplicates.
DELETE FROM restaurant_gallery_photos WHERE restaurant_id NOT IN (
    SELECT id FROM restaurants WHERE owner_email IN (
        'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
        'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
    )
);

-- Rank each eligible restaurant's own menu items and take the top 5.
-- MySQL 8+ window-function version (ROW_NUMBER) — matches this project's
-- existing minimum-version assumption (no other migration here avoids
-- window functions).
INSERT INTO restaurant_gallery_photos (restaurant_id, image_url, dish_name, price, sort_order)
SELECT restaurant_id, image_url, name, price, rn
FROM (
    SELECT
        mi.restaurant_id,
        mi.image_url,
        mi.name,
        mi.price,
        ROW_NUMBER() OVER (
            PARTITION BY mi.restaurant_id
            ORDER BY mi.is_bestseller DESC, mi.is_recommended DESC, mi.price DESC, mi.id ASC
        ) AS rn
    FROM menu_items mi
    INNER JOIN restaurants r ON r.id = mi.restaurant_id
    WHERE mi.deleted_at IS NULL
      AND mi.is_available = 1
      AND mi.image_url IS NOT NULL
      AND mi.image_url <> ''
      AND r.owner_email NOT IN (
          'spicejunction@anydroptest.com', 'urbanpizzaco@anydroptest.com',
          'dragonwok@anydroptest.com', 'burgerbarn@anydroptest.com', 'southspice@anydroptest.com'
      )
) ranked
WHERE rn <= 5;
