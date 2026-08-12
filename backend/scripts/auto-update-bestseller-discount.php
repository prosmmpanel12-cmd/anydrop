<?php
/**
 * Anydrop — Auto Bestseller + Discount Updater
 *
 * Unlike seed-test-data.php / seed-admin.php (one-time, refuses to run
 * twice), THIS script is safe to run repeatedly — it recomputes fresh
 * every time, so you can re-run it any time real orders come in, or wire
 * it up as a pseudo-cron endpoint later (same "external pinger hits a URL"
 * pattern already documented in docs/02_API_Contract.md §7 for
 * check-due-limits / expire-old-otps / cleanup-rider-locations).
 *
 * WHAT IT DOES
 * ------------
 * 1. Bestseller (real signal): for every restaurant, sums quantity sold
 *    per menu item from delivered orders (order_items + orders where
 *    status='delivered'), ranks them, and marks the top N as
 *    is_bestseller=1 — everything else on that restaurant's menu gets
 *    is_bestseller=0. This is genuine "people actually ordered this a
 *    lot" data once real orders exist.
 *
 * 2. Bestseller FALLBACK (no real signal yet): if a restaurant has zero
 *    delivered orders (true for a brand-new/test restaurant with no order
 *    history), there's nothing real to rank — the script instead marks
 *    that restaurant's first N menu items (by id) as bestseller, purely
 *    so the "Highly reordered" badge (features.md §3) has something to
 *    show while testing. This is a placeholder, not a real signal — flip
 *    to real ranking automatically the moment that restaurant gets its
 *    first delivered order.
 *
 * 3. Discount — THERE IS NO EXISTING SIGNAL IN THE SCHEMA FOR THIS.
 *    Discounts are a business/pricing decision, not something derivable
 *    from order history the way "bestseller" is. This script's discount
 *    logic is explicitly a DEMO/TEST placeholder: it randomly applies a
 *    discount to a slice of each restaurant's non-bestseller items so
 *    the corner badge (features.md §4) has something to show. Per the
 *    2026-08-09 decision logged in docs/Status.md, a real discount
 *    control (restaurant-side toggle, coupons, or similar) is a separate
 *    future feature — this is only to unblock visual testing today.
 *
 * 4. Spicy / Kid's choice (added 2026-08-09, same session as features.md
 *    §1's filters sheet) — SAME SITUATION AS DISCOUNT: no real signal in
 *    the schema (these are restaurant-declared dish attributes, not
 *    derivable from orders). Demo/test placeholder only: randomly flags
 *    a slice of each restaurant's items as is_spicy / is_kids_choice so
 *    the "Filters and Sorting" sheet's dietary chips have something to
 *    filter on while testing. A real per-item flag would eventually need
 *    a restaurant-side "mark this dish spicy / kid-friendly" control —
 *    same future-scope note as bestseller/discount above.
 *
 * 5. Restaurant offers (added 2026-08-09, features.md §6's offer strip) —
 *    SAME SITUATION AS DISCOUNT: no real coupon/promotions engine in the
 *    schema, restaurant_offers (14_migration_restaurant_offers_and_tags.sql)
 *    is a bare title/description table with nothing that writes to it yet.
 *    Demo/test placeholder only, and — unlike bestseller/discount/spicy/
 *    kids, which fully recompute every run — this step is ADDITIVE: it
 *    only seeds a restaurant that currently has ZERO offer rows, so
 *    re-running this script doesn't wipe out real offers a restaurant owner
 *    adds later once a real admin control exists for this.
 *
 * 6. "Frequently reordered" / "No packaging charges" restaurant tags (added
 *    2026-08-09, features.md §6) — reuses the existing generic
 *    restaurant_tags/restaurant_tag_map mechanism (migration 05), not a new
 *    boolean column. SAME SITUATION AS DISCOUNT/SPICY: no real signal, so
 *    this step randomly assigns both tags to a slice of restaurants, fully
 *    recomputed every run (old mappings for just these two tag ids cleared
 *    first) exactly like the item-level flags above — safe to re-run.
 *
 * USAGE (run via browser, same convention as the other scripts/*.php):
 *   http://localhost:8080/anydrop/scripts/auto-update-bestseller-discount.php?key=SEED_ME
 *
 * Optional tuning params (all optional, sensible defaults applied):
 *   &bestseller_top=3        how many items per restaurant to mark bestseller (default 3)
 *   &discount_percent=20     flat discount % applied to the randomly-picked items (default 20)
 *   &discount_ratio=0.3      fraction of each restaurant's remaining items that get a discount (default 0.3 = 30%)
 *   &spicy_ratio=0.25        fraction of each restaurant's items flagged is_spicy=1 (default 0.25 = 25%)
 *   &kids_ratio=0.15         fraction of each restaurant's items flagged is_kids_choice=1 (default 0.15 = 15%)
 *   &offer_count=2           demo offers seeded for a restaurant with none yet (default 2, max 3)
 *   &frequently_reordered_ratio=0.6   fraction of restaurants tagged "Frequently reordered" (default 0.6 = 60%)
 *   &no_packaging_ratio=0.5           fraction of restaurants tagged "No packaging charges" (default 0.5 = 50%)
 *
 * Example, more aggressive for a demo:
 *   ...auto-update-bestseller-discount.php?key=SEED_ME&bestseller_top=5&discount_percent=25&discount_ratio=0.5&spicy_ratio=0.4&kids_ratio=0.2
 *
 * This file is NOT deleted after use (unlike the one-time seed scripts)
 * since it's meant to be re-run — but it IS gated behind ?key=SEED_ME just
 * like the others, so don't leave it reachable on a public InfinityFree
 * deploy without at least changing that key.
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script.';
    exit;
}

$bestsellerTop   = max(1, (int) ($_GET['bestseller_top'] ?? 3));
$discountPercent = max(0, min(90, (float) ($_GET['discount_percent'] ?? 20)));
$discountRatio   = max(0, min(1, (float) ($_GET['discount_ratio'] ?? 0.3)));
$spicyRatio      = max(0, min(1, (float) ($_GET['spicy_ratio'] ?? 0.25)));
$kidsRatio       = max(0, min(1, (float) ($_GET['kids_ratio'] ?? 0.15)));
$offerCount      = max(0, min(3, (int) ($_GET['offer_count'] ?? 2)));
$freqReorderedRatio = max(0, min(1, (float) ($_GET['frequently_reordered_ratio'] ?? 0.6)));
$noPackagingRatio   = max(0, min(1, (float) ($_GET['no_packaging_ratio'] ?? 0.5)));

// Demo offer text pool (features.md §6, step 5) — picked per-restaurant,
// no restaurant-specific data to derive these from, purely to unblock
// visual testing of the "N offers" expandable strip.
$demoOfferPool = [
    ['title' => '60% OFF up to ₹120', 'description' => 'Use code WELCOME60 · valid on orders above ₹199'],
    ['title' => 'Free delivery on orders above ₹149', 'description' => null],
    ['title' => 'Flat ₹50 OFF on your first order', 'description' => 'Use code FIRST50'],
    ['title' => '20% OFF on weekdays', 'description' => 'Mon–Fri, 12 PM–4 PM'],
];

$db = Database::get();

header('Content-Type: text/plain');

$restaurants = $db->query("SELECT id, name FROM restaurants WHERE deleted_at IS NULL")->fetchAll();
if (!$restaurants) {
    echo "No restaurants found. Nothing to do.\n";
    exit;
}

$totalBestsellersSet = 0;
$totalDiscountsSet   = 0;
$totalSpicySet       = 0;
$totalKidsSet        = 0;
$totalOffersSeeded   = 0;
$totalFreqReorderedTagged = 0;
$totalNoPackagingTagged   = 0;

// ---- Step 6 setup: look up the two restaurant_tags ids once (from
// 14_migration_restaurant_offers_and_tags.sql) and clear existing mappings
// for just these two tags across all restaurants, so this step is a full
// recompute like the item-level flags — not additive like Step 5's offers. ----
$freqReorderedTagId = (int) ($db->query(
    "SELECT id FROM restaurant_tags WHERE slug = 'frequently_reordered' LIMIT 1"
)->fetchColumn() ?: 0);
$noPackagingTagId = (int) ($db->query(
    "SELECT id FROM restaurant_tags WHERE slug = 'no_packaging_charges' LIMIT 1"
)->fetchColumn() ?: 0);

if ($freqReorderedTagId > 0 && $noPackagingTagId > 0) {
    $db->prepare("DELETE FROM restaurant_tag_map WHERE restaurant_tag_id IN (?, ?)")
       ->execute([$freqReorderedTagId, $noPackagingTagId]);
} else {
    // Migration 14 hasn't run yet — skip Step 6 entirely rather than error,
    // same "backward-compatible if deployed before the SQL migration"
    // reasoning menu.php already uses for is_spicy/is_kids_choice.
    echo "Note: 'frequently_reordered'/'no_packaging_charges' restaurant_tags rows not found — "
       . "run 14_migration_restaurant_offers_and_tags.sql first. Skipping Step 6 (restaurant tags) this run.\n\n";
}

foreach ($restaurants as $restaurant) {
    $restaurantId = (int) $restaurant['id'];

    // Reset both flags for this restaurant first — every run is a full
    // recompute, not an incremental patch, so stale flags from a previous
    // run (e.g. an item that used to be top-3 and no longer is) get
    // cleared correctly.
    $db->prepare("UPDATE menu_items SET is_bestseller = 0, discount_percent = 0, is_spicy = 0, is_kids_choice = 0
                  WHERE restaurant_id = ? AND deleted_at IS NULL")
       ->execute([$restaurantId]);

    // ---- Step 1: try real order-history ranking ----
    $rankStmt = $db->prepare(
        "SELECT oi.menu_item_id, SUM(oi.quantity) AS total_qty
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE o.restaurant_id = ? AND o.status = 'delivered'
         GROUP BY oi.menu_item_id
         ORDER BY total_qty DESC
         LIMIT ?"
    );
    $rankStmt->bindValue(1, $restaurantId, PDO::PARAM_INT);
    $rankStmt->bindValue(2, $bestsellerTop, PDO::PARAM_INT);
    $rankStmt->execute();
    $topByOrders = $rankStmt->fetchAll();

    $bestsellerIds = [];
    $usedFallback = false;

    if (count($topByOrders) > 0) {
        $bestsellerIds = array_map(fn($row) => (int) $row['menu_item_id'], $topByOrders);
    } else {
        // ---- Step 2: fallback — no delivered orders yet for this
        // restaurant, so pick its first N items by id purely for demo
        // visibility (see file header comment). ----
        $usedFallback = true;
        $fallbackStmt = $db->prepare(
            "SELECT id FROM menu_items
             WHERE restaurant_id = ? AND deleted_at IS NULL AND is_available = 1
             ORDER BY id ASC
             LIMIT ?"
        );
        $fallbackStmt->bindValue(1, $restaurantId, PDO::PARAM_INT);
        $fallbackStmt->bindValue(2, $bestsellerTop, PDO::PARAM_INT);
        $fallbackStmt->execute();
        $bestsellerIds = array_map(fn($row) => (int) $row['id'], $fallbackStmt->fetchAll());
    }

    if ($bestsellerIds) {
        $placeholders = implode(',', array_fill(0, count($bestsellerIds), '?'));
        $db->prepare("UPDATE menu_items SET is_bestseller = 1 WHERE id IN ($placeholders)")
           ->execute($bestsellerIds);
        $totalBestsellersSet += count($bestsellerIds);
    }

    // ---- Step 3: discount — demo placeholder only (see file header) ----
    // Random slice of this restaurant's NON-bestseller items get the flat
    // discount, so bestseller badge and discount badge don't always land
    // on the exact same items in the demo.
    $remainingStmt = $db->prepare(
        "SELECT id FROM menu_items
         WHERE restaurant_id = ? AND deleted_at IS NULL AND is_available = 1
         AND is_bestseller = 0"
    );
    $remainingStmt->execute([$restaurantId]);
    $remainingIds = array_map(fn($row) => (int) $row['id'], $remainingStmt->fetchAll());

    shuffle($remainingIds);
    $discountCount = (int) round(count($remainingIds) * $discountRatio);
    $discountIds = array_slice($remainingIds, 0, $discountCount);

    if ($discountIds) {
        $placeholders = implode(',', array_fill(0, count($discountIds), '?'));
        $stmt = $db->prepare("UPDATE menu_items SET discount_percent = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$discountPercent], $discountIds));
        $totalDiscountsSet += count($discountIds);
    }

    // ---- Step 4: spicy / kid's choice — demo placeholder only (see file
    // header §4). Drawn independently from ALL of this restaurant's items
    // (not just the leftover-after-discount pool) since a real dish can be
    // both a bestseller AND spicy at the same time — no reason to force
    // them mutually exclusive in the demo data. ----
    $allItemsStmt = $db->prepare(
        "SELECT id FROM menu_items
         WHERE restaurant_id = ? AND deleted_at IS NULL AND is_available = 1"
    );
    $allItemsStmt->execute([$restaurantId]);
    $allItemIds = array_map(fn($row) => (int) $row['id'], $allItemsStmt->fetchAll());

    shuffle($allItemIds);
    $spicyCount = (int) round(count($allItemIds) * $spicyRatio);
    $spicyIds = array_slice($allItemIds, 0, $spicyCount);
    if ($spicyIds) {
        $placeholders = implode(',', array_fill(0, count($spicyIds), '?'));
        $db->prepare("UPDATE menu_items SET is_spicy = 1 WHERE id IN ($placeholders)")
           ->execute($spicyIds);
        $totalSpicySet += count($spicyIds);
    }

    shuffle($allItemIds);
    $kidsCount = (int) round(count($allItemIds) * $kidsRatio);
    $kidsIds = array_slice($allItemIds, 0, $kidsCount);
    if ($kidsIds) {
        $placeholders = implode(',', array_fill(0, count($kidsIds), '?'));
        $db->prepare("UPDATE menu_items SET is_kids_choice = 1 WHERE id IN ($placeholders)")
           ->execute($kidsIds);
        $totalKidsSet += count($kidsIds);
    }

    // ---- Step 5: restaurant offers — demo placeholder only (see file
    // header §5). ADDITIVE, not a reset: only seeds restaurants that
    // currently have zero offer rows, so a real offer added later (once an
    // actual admin control exists) is never wiped by re-running this
    // script. ----
    $offersSeededThisRestaurant = 0;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM restaurant_offers WHERE restaurant_id = ?");
    $countStmt->execute([$restaurantId]);
    $existingOfferCount = (int) $countStmt->fetchColumn();

    if ($existingOfferCount === 0 && $offerCount > 0) {
        $pool = $demoOfferPool;
        shuffle($pool);
        $picked = array_slice($pool, 0, $offerCount);
        $insertOfferStmt = $db->prepare(
            "INSERT INTO restaurant_offers (restaurant_id, title, description, sort_order, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        foreach ($picked as $index => $offer) {
            $insertOfferStmt->execute([$restaurantId, $offer['title'], $offer['description'], $index]);
        }
        $offersSeededThisRestaurant = count($picked);
        $totalOffersSeeded += $offersSeededThisRestaurant;
    }

    // ---- Step 6: "Frequently reordered" / "No packaging charges"
    // restaurant tags — demo placeholder only (see file header §6). ----
    $taggedFreqReordered = false;
    $taggedNoPackaging = false;
    if ($freqReorderedTagId > 0 && $noPackagingTagId > 0) {
        if (mt_rand() / mt_getrandmax() < $freqReorderedRatio) {
            $db->prepare(
                "INSERT IGNORE INTO restaurant_tag_map (restaurant_id, restaurant_tag_id) VALUES (?, ?)"
            )->execute([$restaurantId, $freqReorderedTagId]);
            $taggedFreqReordered = true;
            $totalFreqReorderedTagged++;
        }
        if (mt_rand() / mt_getrandmax() < $noPackagingRatio) {
            $db->prepare(
                "INSERT IGNORE INTO restaurant_tag_map (restaurant_id, restaurant_tag_id) VALUES (?, ?)"
            )->execute([$restaurantId, $noPackagingTagId]);
            $taggedNoPackaging = true;
            $totalNoPackagingTagged++;
        }
    }

    echo sprintf(
        "Restaurant #%d (%s): %d bestseller item(s) set%s, %d item(s) got %.0f%% discount, %d item(s) marked spicy, %d item(s) marked kid's choice, %d offer(s) seeded%s, tags: %s%s%s.\n",
        $restaurantId,
        $restaurant['name'],
        count($bestsellerIds),
        $usedFallback ? ' [fallback — no delivered orders yet]' : ' [real order-history ranking]',
        count($discountIds),
        $discountPercent,
        count($spicyIds),
        count($kidsIds),
        $offersSeededThisRestaurant,
        $existingOfferCount > 0 ? " [already had $existingOfferCount, left untouched]" : '',
        $taggedFreqReordered ? 'Frequently reordered' : '(none)',
        $taggedFreqReordered && $taggedNoPackaging ? ' + ' : '',
        $taggedNoPackaging ? 'No packaging charges' : ($taggedFreqReordered ? '' : '')
    );
}

echo "\nDone. Total: $totalBestsellersSet bestseller flags set, $totalDiscountsSet discount flags set, "
   . "$totalSpicySet spicy flags set, $totalKidsSet kid's choice flags set, $totalOffersSeeded offer(s) seeded, "
   . "$totalFreqReorderedTagged restaurant(s) tagged 'Frequently reordered', "
   . "$totalNoPackagingTagged restaurant(s) tagged 'No packaging charges', across "
   . count($restaurants) . " restaurant(s).\n";
echo "Re-run this any time to refresh — bestseller/discount/spicy/kids/tags are a full recompute each run, "
   . "offers are additive-only (won't touch a restaurant that already has some).\n";
