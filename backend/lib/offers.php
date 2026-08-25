<?php
/**
 * Anydrop — Restaurant Offers Engine (recall.md Phase D item 28,
 * migration 47, doc 20 §1/§2/§12/§13).
 *
 * Single source of truth for "which restaurant-created offer applies
 * to this cart, and for how much" — used by lib/orders.php's
 * price_cart() (both the cart/validate.php preview and the real
 * POST /orders placement), same "one function, every caller" pattern
 * every other pricing helper in this codebase (lib/commission.php,
 * lib/delivery_pricing.php, lib/cod_rules.php) already follows so
 * numbers can never drift between a preview and the real order.
 *
 * STACKING RULE (doc 20 §13's own recommended initial rule, applied
 * exactly): at most ONE auto-applied item/restaurant offer, PLUS the
 * existing one coupon (lib/orders.php's own coupon block, unchanged),
 * PLUS at most one free-delivery offer. No unlimited stacking, no
 * restaurant offer + restaurant offer combos. Not admin-configurable
 * yet — doc 20 §13 flags that as a later step, this is the hardcoded
 * v1 rule.
 *
 * WHAT THIS FILE DOES NOT DO (see migration 47's own header for the
 * full list): combo/bundle offers, offer analytics, an admin
 * pre-publish approval queue. Every restaurant-created offer is live
 * the moment it's created (status defaults 'active') — admin's only
 * lever is pausing/disabling it after the fact.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Fetches every offer belonging to a restaurant that is currently
 * eligible to be considered at all — status='active' (an admin-
 * disabled or restaurant-paused offer never reaches the matching
 * logic below), not soft-deleted, and within its own start_date/
 * end_date window. Time-of-day (start_time/end_time happy-hour) and
 * weekday restrictions are checked separately per-offer in
 * is_offer_time_eligible() below, not here, since "is this offer type
 * active today" and "is it currently within its happy-hour window"
 * are different questions callers sometimes need separately (e.g. a
 * Restaurant App offers list showing "Scheduled for 4-6pm today" needs
 * to know the offer exists and is date-eligible even outside that
 * window).
 *
 * @return array[] raw promo_offers rows
 */
function get_date_eligible_offers_for_restaurant(PDO $db, int $restaurantId): array
{
    $stmt = $db->prepare(
        "SELECT * FROM promo_offers
         WHERE restaurant_id = :rid
           AND status = 'active'
           AND deleted_at IS NULL
           AND (start_date IS NULL OR start_date <= CURDATE())
           AND (end_date IS NULL OR end_date >= CURDATE())"
    );
    $stmt->execute(['rid' => $restaurantId]);
    return $stmt->fetchAll();
}

/**
 * Checks an offer's weekday + happy-hour time window against right
 * now. Both are optional independently — an offer with weekdays set
 * but no start_time/end_time is "these days, all day"; one with only
 * a time window is "every day, this window only" (doc 20's own happy-
 * hour example, "4 PM – 6 PM Happy Hour", sets only the time fields).
 */
function is_offer_time_eligible(array $offer, ?int $nowTs = null): bool
{
    $nowTs = $nowTs ?? time();

    if (!empty($offer['weekdays'])) {
        $currentDow = (int) date('N', $nowTs); // 1 (Mon) .. 7 (Sun) — same convention as restaurants.working_days
        $days = explode(',', (string) $offer['weekdays']);
        if (!in_array((string) $currentDow, $days, true)) {
            return false;
        }
    }

    if (!empty($offer['start_time']) && !empty($offer['end_time'])) {
        $currentTime = date('H:i:s', $nowTs);
        $start = (string) $offer['start_time'];
        $end = (string) $offer['end_time'];
        if ($start <= $end) {
            // Normal same-day window, e.g. 16:00–18:00.
            if ($currentTime < $start || $currentTime > $end) {
                return false;
            }
        } else {
            // Overnight window, e.g. 22:00–02:00 — "eligible" is
            // everything from start_time through midnight PLUS
            // everything from midnight through end_time, i.e. NOT the
            // gap strictly between end_time and start_time.
            if ($currentTime < $start && $currentTime > $end) {
                return false;
            }
        }
    }

    return true;
}

/**
 * new_customer = zero DELIVERED orders ever placed by this customer
 * (matches cod_rules.php's own "prepaid order count" style definition
 * of an established customer — a cancelled/rejected order doesn't
 * make someone a returning customer). existing_customer is just the
 * inverse. 'all' always returns true without a query.
 */
function is_offer_customer_eligible(PDO $db, array $offer, int $customerId): bool
{
    $eligibility = $offer['customer_eligibility'] ?? 'all';
    if ($eligibility === 'all') {
        return true;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS c FROM orders WHERE customer_id = :cid AND status = 'delivered'"
    );
    $stmt->execute(['cid' => $customerId]);
    $deliveredCount = (int) $stmt->fetch()['c'];

    if ($eligibility === 'new_customer') {
        return $deliveredCount === 0;
    }
    // existing_customer
    return $deliveredCount > 0;
}

/**
 * Checks daily_limit/total_limit/per_customer_limit against
 * offer_usages — same live-COUNT-not-cached-counter reasoning
 * coupon_usages already established (see lib/orders.php's coupon
 * block). Returns false the moment any configured limit is already
 * exhausted; a null limit is "unlimited", same convention coupons use.
 */
function is_offer_usage_available(PDO $db, array $offer, int $customerId): bool
{
    $offerId = (int) $offer['id'];

    if ($offer['per_customer_limit'] !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM offer_usages WHERE offer_id = :oid AND customer_id = :cid');
        $stmt->execute(['oid' => $offerId, 'cid' => $customerId]);
        if ((int) $stmt->fetch()['c'] >= (int) $offer['per_customer_limit']) {
            return false;
        }
    }

    if ($offer['daily_limit'] !== null) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS c FROM offer_usages WHERE offer_id = :oid AND DATE(created_at) = CURDATE()'
        );
        $stmt->execute(['oid' => $offerId]);
        if ((int) $stmt->fetch()['c'] >= (int) $offer['daily_limit']) {
            return false;
        }
    }

    if ($offer['total_limit'] !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) AS c FROM offer_usages WHERE offer_id = :oid');
        $stmt->execute(['oid' => $offerId]);
        if ((int) $stmt->fetch()['c'] >= (int) $offer['total_limit']) {
            return false;
        }
    }

    return true;
}

/**
 * Full eligibility gate for one offer against one cart/customer —
 * combines date (caller already filtered via
 * get_date_eligible_offers_for_restaurant), time-of-day, customer
 * type, usage limits, and min_order_amount. Does NOT check whether
 * the offer's scope actually matches anything in the cart — that's
 * compute_offer_discount()'s job, since "eligible but matches
 * nothing" (e.g. an item-scoped offer for an item not in this cart)
 * is a normal, frequent case, not an error.
 */
function is_offer_eligible(PDO $db, array $offer, array $lineItems, float $itemTotal, int $customerId): bool
{
    if (!is_offer_time_eligible($offer)) {
        return false;
    }
    if ((float) $offer['min_order_amount'] > 0 && $itemTotal < (float) $offer['min_order_amount']) {
        return false;
    }
    if (!is_offer_customer_eligible($db, $offer, $customerId)) {
        return false;
    }
    if (!is_offer_usage_available($db, $offer, $customerId)) {
        return false;
    }
    return true;
}

/**
 * Returns the subset of $lineItems this offer's scope matches:
 *   'item'       — lines whose menu_item_id equals the offer's
 *   'category'   — lines whose menu item carries the offer's
 *                  food_category_id (via menu_item_categories)
 *   'restaurant' — every line (the whole cart is in-scope)
 *
 * @param array $lineItems price_cart()'s in-progress line item array
 *   (each with at least menu_item_id, quantity, unit_price, subtotal)
 * @return array matching lines, same shape as input
 */
function get_offer_scoped_lines(PDO $db, array $offer, array $lineItems): array
{
    if ($offer['scope'] === 'restaurant') {
        return $lineItems;
    }

    if ($offer['scope'] === 'item') {
        $targetId = (int) $offer['menu_item_id'];
        return array_values(array_filter($lineItems, fn ($l) => (int) $l['menu_item_id'] === $targetId));
    }

    // 'category'
    $targetCategoryId = (int) $offer['food_category_id'];
    return array_values(array_filter($lineItems, function ($l) use ($db, $targetCategoryId) {
        $categoryIds = get_food_category_ids_for_menu_item($db, (int) $l['menu_item_id']);
        return in_array($targetCategoryId, $categoryIds, true);
    }));
}

/**
 * Computes the discount ONE offer would produce against this cart,
 * without applying it. Returns 0.0 discount (not an error) when the
 * offer's scope matches nothing in the cart, or matches too little to
 * complete even one set (e.g. a "buy 3 for ₹50" offer against a cart
 * with only 2 of that item) — that's a normal non-match, not a
 * failure; select_best_auto_offer() below simply won't pick a
 * zero-discount offer over a better one (or over none at all).
 *
 * @return array{discount: float, matched_qty: int}
 */
function compute_offer_discount(PDO $db, array $offer, array $lineItems): array
{
    $scopedLines = get_offer_scoped_lines($db, $offer, $lineItems);
    if (empty($scopedLines)) {
        return ['discount' => 0.0, 'matched_qty' => 0];
    }

    $matchedQty = (int) array_sum(array_column($scopedLines, 'quantity'));
    $scopedSubtotal = (float) array_sum(array_column($scopedLines, 'subtotal'));

    switch ($offer['offer_type']) {
        case 'quantity_deal':
        case 'buy_x_for_y':
            $requiredQty = (int) $offer['required_qty'];
            if ($requiredQty <= 0 || $matchedQty < $requiredQty) {
                return ['discount' => 0.0, 'matched_qty' => $matchedQty];
            }
            // Average per-unit price across the scoped lines (handles a
            // category-scoped offer where matched items don't all cost
            // the same) — same "blended rate" approach doc 20 §1.1's own
            // "backend should validate the customer receives exactly the
            // configured quantity" note implies is fine for a flat-price
            // deal, since the deal price replaces the normal cost of the
            // set regardless of which specific units within it.
            $avgUnitPrice = $scopedSubtotal / max(1, $matchedQty);
            $sets = intdiv($matchedQty, $requiredQty);
            $normalCostPerSet = $avgUnitPrice * $requiredQty;
            $offerPrice = (float) $offer['offer_price'];
            $discountPerSet = max(0.0, $normalCostPerSet - $offerPrice);
            return ['discount' => round($discountPerSet * $sets, 2), 'matched_qty' => $matchedQty];

        case 'buy_x_get_y':
            $requiredQty = (int) $offer['required_qty'];
            $getQty = (int) $offer['get_qty'];
            $setSize = $requiredQty + $getQty;
            if ($requiredQty <= 0 || $getQty <= 0 || $matchedQty < $setSize) {
                return ['discount' => 0.0, 'matched_qty' => $matchedQty];
            }
            $avgUnitPrice = $scopedSubtotal / max(1, $matchedQty);
            $sets = intdiv($matchedQty, $setSize);
            $freeUnits = $sets * $getQty;
            return ['discount' => round($freeUnits * $avgUnitPrice, 2), 'matched_qty' => $matchedQty];

        case 'percent_discount':
            $discount = round($scopedSubtotal * (float) $offer['discount_percent'] / 100, 2);
            if ($offer['max_discount_amount'] !== null) {
                $discount = min($discount, (float) $offer['max_discount_amount']);
            }
            return ['discount' => min($discount, $scopedSubtotal), 'matched_qty' => $matchedQty];

        case 'flat_discount':
            $discount = (float) $offer['discount_flat'];
            return ['discount' => min($discount, $scopedSubtotal), 'matched_qty' => $matchedQty];

        default:
            // free_delivery has its own dedicated selection function
            // below (it doesn't discount item lines at all) — reaching
            // here would be a caller bug, not a real cart state.
            return ['discount' => 0.0, 'matched_qty' => $matchedQty];
    }
}

/**
 * Picks the single best-value auto-applied item/restaurant offer for
 * this cart (doc 20 §13's "1 Item/Restaurant Offer" stacking slot) —
 * the discount-quantity/percent/flat types only, never free_delivery
 * (that has its own slot, see select_best_free_delivery_offer()).
 * "Best" = highest resulting discount amount; ties broken by lowest
 * offer id (oldest offer wins) purely for determinism, not a
 * documented business rule.
 *
 * @return array{offer: array, discount: float}|null
 */
function select_best_auto_offer(PDO $db, int $restaurantId, array $lineItems, float $itemTotal, int $customerId): ?array
{
    $offers = get_date_eligible_offers_for_restaurant($db, $restaurantId);
    $best = null;

    foreach ($offers as $offer) {
        if ($offer['offer_type'] === 'free_delivery') {
            continue; // separate slot, handled by select_best_free_delivery_offer()
        }
        if (!is_offer_eligible($db, $offer, $lineItems, $itemTotal, $customerId)) {
            continue;
        }
        $computed = compute_offer_discount($db, $offer, $lineItems);
        if ($computed['discount'] <= 0) {
            continue;
        }
        if ($best === null || $computed['discount'] > $best['discount']) {
            $best = ['offer' => $offer, 'discount' => $computed['discount']];
        }
    }

    return $best;
}

/**
 * Picks the best-value free_delivery offer (highest resulting
 * delivery discount — in practice these usually fully zero the fee,
 * so "best" mostly matters only when more than one free_delivery
 * offer somehow coexists with different min_order_amount thresholds).
 * Capped at $deliveryCharge so this can never show a "discount" larger
 * than the fee actually being waived.
 *
 * @return array{offer: array, discount: float}|null
 */
function select_best_free_delivery_offer(PDO $db, int $restaurantId, float $itemTotal, float $deliveryCharge, int $customerId): ?array
{
    if ($deliveryCharge <= 0) {
        return null; // nothing to waive
    }

    $offers = get_date_eligible_offers_for_restaurant($db, $restaurantId);
    $best = null;

    foreach ($offers as $offer) {
        if ($offer['offer_type'] !== 'free_delivery') {
            continue;
        }
        if (!is_offer_eligible($db, $offer, [], $itemTotal, $customerId)) {
            continue;
        }
        $discount = $deliveryCharge; // always fully waives, per doc 20 §2's own examples
        if ($best === null || $discount > $best['discount']) {
            $best = ['offer' => $offer, 'discount' => $discount];
        }
    }

    return $best;
}

/** Shapes a promo_offers row for a Restaurant App / customer-facing API response. */
function format_offer(array $offer): array
{
    return [
        'id' => (int) $offer['id'],
        'offer_type' => $offer['offer_type'],
        'title' => $offer['title'],
        'scope' => $offer['scope'],
        'menu_item_id' => $offer['menu_item_id'] !== null ? (int) $offer['menu_item_id'] : null,
        'food_category_id' => $offer['food_category_id'] !== null ? (int) $offer['food_category_id'] : null,
        'required_qty' => $offer['required_qty'] !== null ? (int) $offer['required_qty'] : null,
        'get_qty' => $offer['get_qty'] !== null ? (int) $offer['get_qty'] : null,
        'offer_price' => $offer['offer_price'] !== null ? (float) $offer['offer_price'] : null,
        'discount_percent' => $offer['discount_percent'] !== null ? (float) $offer['discount_percent'] : null,
        'discount_flat' => $offer['discount_flat'] !== null ? (float) $offer['discount_flat'] : null,
        'max_discount_amount' => $offer['max_discount_amount'] !== null ? (float) $offer['max_discount_amount'] : null,
        'min_order_amount' => (float) $offer['min_order_amount'],
        'customer_eligibility' => $offer['customer_eligibility'],
        'start_date' => $offer['start_date'],
        'end_date' => $offer['end_date'],
        'start_time' => $offer['start_time'],
        'end_time' => $offer['end_time'],
        'weekdays' => $offer['weekdays'],
        'daily_limit' => $offer['daily_limit'] !== null ? (int) $offer['daily_limit'] : null,
        'total_limit' => $offer['total_limit'] !== null ? (int) $offer['total_limit'] : null,
        'per_customer_limit' => $offer['per_customer_limit'] !== null ? (int) $offer['per_customer_limit'] : null,
        'status' => $offer['status'],
        'created_at' => $offer['created_at'],
    ];
}

/**
 * Browse-time offer set for a restaurant's menu screen (customer app
 * item tags + category discount icon, restaurants/menu.php).
 *
 * Deliberately a *different*, looser eligibility check than
 * is_offer_eligible()/price_cart() use — there's no cart yet at browse
 * time, so min_order_amount and the daily/total/per_customer usage
 * limits are skipped here on purpose (a badge means "this offer exists
 * and could apply to you", not "your current cart already qualifies" —
 * price_cart()/compute_offer_discount() remain the one authoritative
 * check at cart/validate.php and order-placement time; nothing here
 * changes what a customer actually gets charged). Date range, weekday/
 * happy-hour window, and per-customer new/existing eligibility are
 * still checked, since a badge that's wrong about "is this live right
 * now, for you" would be actively misleading rather than just
 * approximate. free_delivery is excluded outright — it's a
 * restaurant-wide checkout perk, not a per-item/category discount, and
 * has no natural home on a menu card.
 */
function get_browsable_offers_for_restaurant(PDO $db, int $restaurantId, int $customerId): array
{
    $offers = get_date_eligible_offers_for_restaurant($db, $restaurantId);
    $now = time();
    return array_values(array_filter($offers, function ($offer) use ($db, $now, $customerId) {
        if ($offer['offer_type'] === 'free_delivery') {
            return false;
        }
        if (!is_offer_time_eligible($offer, $now)) {
            return false;
        }
        return is_offer_customer_eligible($db, $offer, $customerId);
    }));
}

/**
 * Short display label for a menu-card offer tag / offers bottom sheet
 * row — intentionally terse (renders on a small pill, not a
 * paragraph), one line per offer_type mirroring offers-create.php's
 * own per-type field table. number_format(...,'0')-trimmed so a whole
 * number like 50.00 shows as "50", not "50.00".
 */
function offer_badge_label(array $offer): string
{
    $trimNum = fn(float $n, int $decimals) => rtrim(rtrim(number_format($n, $decimals), '0'), '.');
    switch ($offer['offer_type']) {
        case 'quantity_deal':
        case 'buy_x_for_y':
            return $offer['required_qty'] . ' @ ₹' . $trimNum((float) $offer['offer_price'], 2);
        case 'buy_x_get_y':
            return 'Buy ' . $offer['required_qty'] . ' Get ' . $offer['get_qty'] . ' Free';
        case 'percent_discount':
            return $trimNum((float) $offer['discount_percent'], 1) . '% OFF';
        case 'flat_discount':
            return '₹' . $trimNum((float) $offer['discount_flat'], 2) . ' OFF';
        default:
            return $offer['title'];
    }
}

/**
 * Picks the single best browsable offer for one menu item, out of
 * $browsableOffers (already restaurant-scoped and eligibility-
 * filtered by get_browsable_offers_for_restaurant()). Precedence is by
 * scope specificity — item-scoped beats category-scoped beats
 * restaurant-wide — same "more specific wins" intuition
 * select_best_auto_offer() applies at cart time; unlike that function
 * this doesn't compare discount *value*, since there's no cart amount
 * to compute an actual rupee discount against yet at browse time.
 * Oldest-id-first within a tier (array order, already ASC by id from
 * the DB query) breaks ties the same way select_best_auto_offer() does.
 */
function pick_item_badge_offer(array $browsableOffers, int $itemId, ?int $categoryId): ?array
{
    foreach ($browsableOffers as $offer) {
        if ($offer['scope'] === 'item' && (int) $offer['menu_item_id'] === $itemId) {
            return $offer;
        }
    }
    if ($categoryId !== null) {
        foreach ($browsableOffers as $offer) {
            if ($offer['scope'] === 'category' && (int) $offer['food_category_id'] === $categoryId) {
                return $offer;
            }
        }
    }
    foreach ($browsableOffers as $offer) {
        if ($offer['scope'] === 'restaurant') {
            return $offer;
        }
    }
    return null;
}
