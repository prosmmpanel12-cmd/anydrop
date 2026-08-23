<?php
/**
 * Anydrop — Commission Rate Resolution (recall.md Phase C item 20-23,
 * migration 38).
 *
 * Single source of truth for "what commission % applies to this line
 * item", used by lib/orders.php's price_cart() (per-line, at order
 * time) and any future admin commission-preview UI, so pricing and
 * display can never drift apart — same "one function, two callers"
 * pattern as lib/cod_rules.php / lib/payment_restrictions.php.
 *
 * Priority, most specific first:
 *   1. commission_rules row matching (this category, this area)
 *   2. commission_rules row matching (this category, any area)
 *   3. commission_rules row matching (any category, this area)
 *   4. restaurants.commission_percent      — existing flat per-restaurant override
 *   5. app_settings.commission_default_percent — existing platform default
 *
 * "This area" = the RESTAURANT's own service_areas node
 * (restaurants.area_id), not the customer's delivery area — commission
 * is a platform/restaurant revenue-share question tied to where the
 * restaurant operates, not where a given order is being delivered.
 *
 * A menu item can carry more than one food_category (many-to-many via
 * menu_item_categories) — when a line item's categories match more
 * than one rule at the SAME specificity level with different rates,
 * the HIGHEST rate wins (protects platform revenue; a judgement call,
 * not something the owner explicitly specified — flag for review if a
 * different tie-break is wanted, e.g. lowest, or requiring a single
 * category per item).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

if (!function_exists('get_food_category_ids_for_menu_item')) {
    /** @return int[] */
    function get_food_category_ids_for_menu_item(PDO $db, int $menuItemId): array
    {
        $stmt = $db->prepare('SELECT food_category_id FROM menu_item_categories WHERE menu_item_id = :mid');
        $stmt->execute(['mid' => $menuItemId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'food_category_id'));
    }
}

if (!function_exists('get_effective_commission_rate')) {
    /**
     * Resolves the commission percent for one line item.
     *
     * @param int[] $foodCategoryIds This line item's category ids (may be empty — an
     *   uncategorised item just skips straight to the restaurant-flat/platform-default tiers).
     * @param int|null $restaurantAreaId restaurants.area_id for the restaurant selling this item.
     * @param float|null $restaurantFlatPercent restaurants.commission_percent for this restaurant.
     * @return array{percent: float, source: string, rule_id: ?int}
     *   source is one of: 'category_area_rule' | 'category_rule' | 'area_rule' | 'restaurant_flat' | 'platform_default'
     */
    function get_effective_commission_rate(
        PDO $db,
        array $foodCategoryIds,
        ?int $restaurantAreaId,
        ?float $restaurantFlatPercent
    ): array {
        $platformDefault = (float) get_setting('commission_default_percent', 15);

        // Tier 1 + 2: category-scoped rules (with or without an area match),
        // only relevant if this line item actually has categories.
        if (!empty($foodCategoryIds)) {
            $placeholders = implode(',', array_fill(0, count($foodCategoryIds), '?'));

            if ($restaurantAreaId !== null) {
                // Tier 1 — category AND area both match. Highest
                // commission_percent among ties, per this file's kdoc.
                $stmt = $db->prepare(
                    "SELECT id, commission_percent FROM commission_rules
                     WHERE is_active = 1 AND area_id = ? AND food_category_id IN ($placeholders)
                     ORDER BY commission_percent DESC LIMIT 1"
                );
                $stmt->execute(array_merge([$restaurantAreaId], $foodCategoryIds));
                $row = $stmt->fetch();
                if ($row) {
                    return ['percent' => (float) $row['commission_percent'], 'source' => 'category_area_rule', 'rule_id' => (int) $row['id']];
                }
            }

            // Tier 2 — category matches, any area (area_id IS NULL rows only —
            // an area-specific-but-different-area row must NOT apply here).
            $stmt = $db->prepare(
                "SELECT id, commission_percent FROM commission_rules
                 WHERE is_active = 1 AND area_id IS NULL AND food_category_id IN ($placeholders)
                 ORDER BY commission_percent DESC LIMIT 1"
            );
            $stmt->execute($foodCategoryIds);
            $row = $stmt->fetch();
            if ($row) {
                return ['percent' => (float) $row['commission_percent'], 'source' => 'category_rule', 'rule_id' => (int) $row['id']];
            }
        }

        // Tier 3 — area matches, any category (category_id IS NULL rows only).
        if ($restaurantAreaId !== null) {
            $stmt = $db->prepare(
                'SELECT id, commission_percent FROM commission_rules
                 WHERE is_active = 1 AND food_category_id IS NULL AND area_id = :aid LIMIT 1'
            );
            $stmt->execute(['aid' => $restaurantAreaId]);
            $row = $stmt->fetch();
            if ($row) {
                return ['percent' => (float) $row['commission_percent'], 'source' => 'area_rule', 'rule_id' => (int) $row['id']];
            }
        }

        // Tier 4 — restaurant's own flat override (existing behaviour, unchanged).
        if ($restaurantFlatPercent !== null) {
            return ['percent' => $restaurantFlatPercent, 'source' => 'restaurant_flat', 'rule_id' => null];
        }

        // Tier 5 — platform default.
        return ['percent' => $platformDefault, 'source' => 'platform_default', 'rule_id' => null];
    }
}
