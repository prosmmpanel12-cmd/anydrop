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
 * full list): offer analytics, an admin pre-publish approval queue.
 * Every restaurant-created offer is live the moment it's created
 * (status defaults 'active') — admin's only lever is pausing/
 * disabling it after the fact.
 *
 * COMBO/BUNDLE OFFERS (offer_type='combo', migration 50, docs/40) —
 * matching + discount calc live here (get_offer_combo_items() +
 * compute_offer_discount()'s 'combo' case below); a combo is the one
 * offer_type that does NOT go through get_offer_scoped_lines(), since
 * it needs a *set* of distinct menu items rather than one
 * item/category/restaurant scope — see docs/40 Step 2 for the full
 * reasoning. Checkout re-validation (docs/40 Step 3), Restaurant App
 * create UI (Step 4), Admin visibility (Step 5), and Customer App
 * display (Step 6) are separate, not-yet-done steps — see docs/40 for
 * per-step status.
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
 * Migration 49 — looks up a single coupon_based offer by the code the
 * customer typed at checkout, scoped to this restaurant (a
 * coupon_based offer, unlike a platform coupon, always belongs to one
 * restaurant — same restaurant_id NOT NULL every other promo_offers
 * row already has). Returns null on no match; caller (price_cart()'s
 * coupon block) treats that exactly like an unmatched coupons.code —
 * `invalid_coupon`. Does NOT check status/date/time/eligibility/usage
 * here — same "lookup vs eligibility are separate steps" split
 * get_date_eligible_offers_for_restaurant() + is_offer_eligible()
 * already follow for the auto path, so callers can distinguish "no
 * such code" from "code exists but isn't usable right now" (a min-
 * order-not-met message, not a flat invalid-code message).
 */
function find_coupon_based_offer_by_code(PDO $db, int $restaurantId, string $code): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM promo_offers
         WHERE restaurant_id = :rid
           AND apply_mode = 'coupon_based'
           AND code = :code
           AND status = 'active'
           AND deleted_at IS NULL
           AND (start_date IS NULL OR start_date <= CURDATE())
           AND (end_date IS NULL OR end_date >= CURDATE())
         LIMIT 1"
    );
    $stmt->execute(['rid' => $restaurantId, 'code' => strtoupper(trim($code))]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Migration 50 (docs/40) — the fixed item list for one combo offer:
 * every distinct menu item required in the bundle, plus how many of
 * each. Returns an empty array for a combo row that (incorrectly, but
 * not impossibly — e.g. mid-edit on the Restaurant App) has no rows
 * yet; compute_offer_discount()'s 'combo' case treats that as a
 * normal zero-discount non-match, same as every other "eligible but
 * matches nothing" case in this file, not an error.
 *
 * @return array[] each row: menu_item_id, required_qty (both already
 *   int-cast here so callers don't need to re-cast)
 */
function get_offer_combo_items(PDO $db, int $offerId): array
{
    $stmt = $db->prepare(
        'SELECT menu_item_id, required_qty FROM offer_combo_items WHERE offer_id = :oid'
    );
    $stmt->execute(['oid' => $offerId]);
    return array_map(
        fn ($row) => ['menu_item_id' => (int) $row['menu_item_id'], 'required_qty' => (int) $row['required_qty']],
        $stmt->fetchAll()
    );
}

/**
 * Migration 50/docs/40 Step 6 — resolves which menu items belong to
 * which combo offer, once per restaurant's already-fetched browsable-
 * offer set, so pick_item_badge_offer()/offer_badge_label() don't each
 * re-query offer_combo_items per item (same "batch once, not N+1"
 * discipline admin/offers.php's own Step 5 fix used).
 *
 * Two maps bundled in one return so callers needing both (every
 * current caller does) only run one query:
 *   - 'index': menu_item_id => the (lowest-id) combo offer id that
 *     requires it. "Lowest id wins" if an item is somehow named by two
 *     different live combos — same oldest-offer-first tie-break every
 *     other precedence tier in this file already applies.
 *   - 'names': offer_id => [menu_item_id => name], the combo's full
 *     item set with display names, for offer_badge_label()'s 'combo'
 *     case to list "the OTHER items" without a second query.
 *
 * Returns two empty arrays (no query run) when $offers has no combo
 * rows — the common case for most restaurants.
 */
function index_combo_offers(PDO $db, array $offers): array
{
    $comboOfferIds = array_values(array_map(
        fn ($o) => (int) $o['id'],
        array_filter($offers, fn ($o) => $o['offer_type'] === 'combo')
    ));
    if (empty($comboOfferIds)) {
        return ['index' => [], 'names' => []];
    }

    $placeholders = implode(',', array_fill(0, count($comboOfferIds), '?'));
    $stmt = $db->prepare(
        "SELECT oci.offer_id, oci.menu_item_id, m.name AS item_name
         FROM offer_combo_items oci
         JOIN menu_items m ON m.id = oci.menu_item_id
         WHERE oci.offer_id IN ($placeholders)"
    );
    $stmt->execute($comboOfferIds);

    $index = [];
    $names = [];
    foreach ($stmt->fetchAll() as $row) {
        $offerId = (int) $row['offer_id'];
        $itemId = (int) $row['menu_item_id'];
        $names[$offerId][$itemId] = $row['item_name'];
        if (!isset($index[$itemId])) {
            $index[$itemId] = $offerId;
        }
    }
    return ['index' => $index, 'names' => $names];
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
 * `free_units`/`item_label` (added for the app owner's "show the free
 * item in the cart, e.g. ₹0 extra item on a B1G1" ask) are populated
 * ONLY for buy_x_get_y — every other type discounts money off an
 * existing line rather than granting a distinct free unit, so there's
 * no single "free item" to label. `item_label` is the scoped line's
 * own item_name_snapshot (first matched line — a category/restaurant-
 * scoped B1G1 across mixed items still needs one name to show on the
 * cart's synthetic free-item row; picking the first is an arbitrary
 * but stable choice, same "array order, already deterministic" logic
 * select_best_auto_offer()'s own tie-break comment already documents
 * elsewhere in this file).
 *
 * @return array{discount: float, matched_qty: int, free_units: int, item_label: ?string}
 */
function compute_offer_discount(PDO $db, array $offer, array $lineItems): array
{
    $scopedLines = get_offer_scoped_lines($db, $offer, $lineItems);
    if (empty($scopedLines)) {
        return ['discount' => 0.0, 'matched_qty' => 0, 'free_units' => 0, 'item_label' => null];
    }

    $matchedQty = (int) array_sum(array_column($scopedLines, 'quantity'));
    $scopedSubtotal = (float) array_sum(array_column($scopedLines, 'subtotal'));

    switch ($offer['offer_type']) {
        case 'quantity_deal':
        case 'buy_x_for_y':
            $requiredQty = (int) $offer['required_qty'];
            if ($requiredQty <= 0 || $matchedQty < $requiredQty) {
                return ['discount' => 0.0, 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];
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
            return ['discount' => round($discountPerSet * $sets, 2), 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];

        case 'buy_x_get_y':
            $requiredQty = (int) $offer['required_qty'];
            $getQty = (int) $offer['get_qty'];
            $setSize = $requiredQty + $getQty;
            if ($requiredQty <= 0 || $getQty <= 0 || $matchedQty < $setSize) {
                return ['discount' => 0.0, 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];
            }
            $avgUnitPrice = $scopedSubtotal / max(1, $matchedQty);
            $sets = intdiv($matchedQty, $setSize);
            $freeUnits = $sets * $getQty;
            return [
                'discount' => round($freeUnits * $avgUnitPrice, 2),
                'matched_qty' => $matchedQty,
                'free_units' => $freeUnits,
                'item_label' => $scopedLines[0]['item_name_snapshot'] ?? null,
            ];

        case 'percent_discount':
            $discount = round($scopedSubtotal * (float) $offer['discount_percent'] / 100, 2);
            if ($offer['max_discount_amount'] !== null) {
                $discount = min($discount, (float) $offer['max_discount_amount']);
            }
            return ['discount' => min($discount, $scopedSubtotal), 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];

        case 'flat_discount':
            $discount = (float) $offer['discount_flat'];
            return ['discount' => min($discount, $scopedSubtotal), 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];

        case 'combo':
            // docs/40 Step 2 — a combo ignores $scopedLines/$matchedQty/
            // $scopedSubtotal above entirely (those come from `scope`,
            // which migration 50 deliberately leaves unused/'restaurant'
            // for a combo row — see this file's header comment and the
            // migration's own header). Matching here is driven purely by
            // offer_combo_items: a specific set of distinct menu items,
            // each with its own required_qty.
            $comboItems = get_offer_combo_items($db, (int) $offer['id']);
            if (empty($comboItems)) {
                return ['discount' => 0.0, 'matched_qty' => 0, 'free_units' => 0, 'item_label' => null];
            }

            // Collapse the cart into one qty/unit_price entry per
            // menu_item_id (a cart can carry the same item split across
            // multiple lines — e.g. two Burger lines with different
            // special_instructions — so this must sum, not overwrite;
            // unit_price is taken from whichever line is seen first,
            // same "one blended rate stands in for the item" reasoning
            // the quantity_deal/buy_x_get_y cases above already use via
            // their own $avgUnitPrice).
            $cartByItem = [];
            foreach ($lineItems as $line) {
                $mid = (int) $line['menu_item_id'];
                if (!isset($cartByItem[$mid])) {
                    $cartByItem[$mid] = ['quantity' => 0, 'unit_price' => (float) $line['unit_price']];
                }
                $cartByItem[$mid]['quantity'] += (int) $line['quantity'];
            }

            // All-or-nothing (docs/40: "a combo is all-or-nothing, not
            // partial credit") — every required ingredient must clear
            // its own required_qty or this combo contributes zero
            // discount. While checking, also track the smallest
            // "how many full sets fit" across ingredients (same
            // intdiv()-based approach quantity_deal uses above, applied
            // per-ingredient) — the whole combo is capped by whichever
            // ingredient runs out first.
            $maxSets = null;
            $normalCostPerSet = 0.0;
            $totalRequiredQtyPerSet = 0;
            foreach ($comboItems as $ci) {
                $mid = $ci['menu_item_id'];
                $reqQty = $ci['required_qty'];
                if ($reqQty <= 0 || !isset($cartByItem[$mid]) || $cartByItem[$mid]['quantity'] < $reqQty) {
                    return ['discount' => 0.0, 'matched_qty' => 0, 'free_units' => 0, 'item_label' => null];
                }
                $normalCostPerSet += $cartByItem[$mid]['unit_price'] * $reqQty;
                $totalRequiredQtyPerSet += $reqQty;
                $setsForThisItem = intdiv($cartByItem[$mid]['quantity'], $reqQty);
                $maxSets = $maxSets === null ? $setsForThisItem : min($maxSets, $setsForThisItem);
            }

            $offerPrice = (float) $offer['offer_price'];
            // Same "never negative" guard every other offer_type case
            // above already applies (e.g. quantity_deal's $discountPerSet).
            $discountPerSet = max(0.0, $normalCostPerSet - $offerPrice);
            return [
                'discount' => round($discountPerSet * $maxSets, 2),
                'matched_qty' => $totalRequiredQtyPerSet * $maxSets,
                'free_units' => 0,
                'item_label' => null,
            ];

        default:
            // free_delivery has its own dedicated selection function
            // below (it doesn't discount item lines at all) — reaching
            // here would be a caller bug, not a real cart state.
            return ['discount' => 0.0, 'matched_qty' => $matchedQty, 'free_units' => 0, 'item_label' => null];
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
        // Migration 49 — coupon_based offers are never auto-applied;
        // they only enter pricing when the customer types their code
        // (see find_coupon_based_offer_by_code(), wired into
        // price_cart()'s coupon block). ?? 'default' keeps this safe
        // against a pre-migration-49 row shape in case of a stale cache.
        if (($offer['apply_mode'] ?? 'default') !== 'default') {
            continue;
        }
        if (!is_offer_eligible($db, $offer, $lineItems, $itemTotal, $customerId)) {
            continue;
        }
        $computed = compute_offer_discount($db, $offer, $lineItems);
        if ($computed['discount'] <= 0) {
            continue;
        }
        if ($best === null || $computed['discount'] > $best['discount']) {
            $best = [
                'offer' => $offer,
                'discount' => $computed['discount'],
                'free_units' => $computed['free_units'],
                'item_label' => $computed['item_label'],
            ];
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
        // Migration 49 — same coupon_based exclusion as select_best_auto_offer().
        if (($offer['apply_mode'] ?? 'default') !== 'default') {
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
// docs/40 Step 3b — $db is optional (defaults null) purely so every
// existing call site that doesn't pass one still compiles/runs
// unchanged; combo_items is only ever populated when a $db connection
// is actually given AND the row is offer_type='combo' (get_offer_combo_items()
// needs a real PDO to query the child table — there's no way to derive
// combo_items from the promo_offers row alone, unlike every other
// mechanic field above which lives directly on that row). Passing $db
// is cheap (one extra indexed SELECT) so every restaurant-facing
// caller (create/update/list) now does; a caller that omits it just
// gets combo_items: [] for a combo row instead of erroring.
function format_offer(array $offer, ?PDO $db = null): array
{
    $comboItems = [];
    if ($db !== null && $offer['offer_type'] === 'combo') {
        $comboItems = array_map(
            fn ($ci) => ['menu_item_id' => (int) $ci['menu_item_id'], 'required_qty' => (int) $ci['required_qty']],
            get_offer_combo_items($db, (int) $offer['id'])
        );
    }
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
        // Migration 48 — per-offer opt-out of the "+1 coupon" stacking
        // slot doc 20 §13 otherwise always allows. Defaults true (1) so
        // an offer created before this column existed (or any row from
        // a DB that hasn't run migration 48 yet, via the ?? fallback)
        // behaves exactly like today — coupon stacking allowed.
        'allow_coupon_stacking' => (bool) ($offer['allow_coupon_stacking'] ?? 1),
        // Migration 49 — apply_mode/code/is_public. code is only ever
        // non-null for apply_mode='coupon_based' (offers-create.php/
        // offers-update.php enforce this at write time); returned here
        // regardless so the Restaurant App's edit screen can show it back.
        'apply_mode' => $offer['apply_mode'] ?? 'default',
        'code' => $offer['code'] ?? null,
        'is_public' => (bool) ($offer['is_public'] ?? 1),
        'created_at' => $offer['created_at'],
        // docs/40 Step 3b — [] for every non-combo offer_type (and for
        // any combo row fetched without a $db, see this function's own
        // kdoc above), never null, so the Restaurant App/Admin can
        // always safely iterate it without a null-check.
        'combo_items' => $comboItems,
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
        // Migration 49 — a coupon_based offer isn't something a
        // customer "gets" just by having the matching item/category in
        // their cart, unlike a default auto-applied offer — it needs
        // the code typed in, so it has no business badging a menu item
        // or category the way an auto offer does. It can still be
        // *listed* separately as a suggested coupon (coupons/list.php,
        // gated on is_public instead) — this only controls the item-tag
        // badge path.
        if (($offer['apply_mode'] ?? 'default') !== 'default') {
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
function offer_badge_label(array $offer, int $itemId = 0, array $comboNames = []): string
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
        case 'combo':
            // docs/40 Step 6 — a combo pill can't just repeat the type or
            // the offer's own title the way the default case below does:
            // "Combo/Bundle" tells a customer nothing about what else to
            // add or what it costs, unlike every other type's mechanic-
            // derived label above. Names the OTHER required items (the
            // item this badge is already sitting on doesn't need to name
            // itself) plus the bundle price.
            $others = [];
            foreach (($comboNames[(int) $offer['id']] ?? []) as $otherItemId => $name) {
                if ($otherItemId !== $itemId) {
                    $others[] = $name;
                }
            }
            $priceLabel = '₹' . $trimNum((float) $offer['offer_price'], 2);
            if (empty($others)) {
                // Defensive only — offers-create.php's own validation
                // requires 2+ distinct items in every combo, so this
                // item being the combo's ONLY resolved item shouldn't
                // happen; falls back to a plain price pill rather than
                // an empty "Combo w/" string if it ever does (e.g. a
                // combo mid-edit with a since-deleted menu item row).
                return 'Combo ' . $priceLabel;
            }
            // Pill has no max-width/ellipsize in any of its 5 layout
            // uses (wrap_content, no maxLines — checked all 5 XML
            // files this fix touches) — capped at 3 named items so a
            // large combo still renders as a pill, not a full-width
            // banner, on a small screen.
            if (count($others) > 3) {
                $shown = array_slice($others, 0, 3);
                $label = 'Combo w/ ' . implode(', ', $shown) . ' +' . (count($others) - 3) . ' more';
            } else {
                $label = 'Combo w/ ' . implode(', ', $others);
            }
            return $label . ' — ' . $priceLabel;
        default:
            return $offer['title'];
    }
}

/**
 * Picks the single best browsable offer for one menu item, out of
 * $browsableOffers (already restaurant-scoped and eligibility-
 * filtered by get_browsable_offers_for_restaurant()). Precedence is by
 * scope specificity — item-scoped beats category-scoped beats a combo
 * beats restaurant-wide — same "more specific wins" intuition
 * select_best_auto_offer() applies at cart time; unlike that function
 * this doesn't compare discount *value*, since there's no cart amount
 * to compute an actual rupee discount against yet at browse time.
 * Oldest-id-first within a tier (array order, already ASC by id from
 * the DB query) breaks ties the same way select_best_auto_offer() does.
 *
 * $comboIndex is index_combo_offers()'s 'index' map (menu_item_id =>
 * offer_id), built once per restaurant by the caller — a combo's own
 * `scope` column is forced to 'restaurant' at creation time (migration
 * 50) but is NOT actually restaurant-wide: it only ever applies to its
 * own named item set. Checked as its own tier here, and explicitly
 * EXCLUDED from the restaurant-wide tier below, so a live combo
 * doesn't incorrectly badge every other item on the menu with its tag.
 */
function pick_item_badge_offer(array $browsableOffers, int $itemId, ?int $categoryId, array $comboIndex = []): ?array
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
    if (isset($comboIndex[$itemId])) {
        $comboOfferId = $comboIndex[$itemId];
        foreach ($browsableOffers as $offer) {
            if ((int) $offer['id'] === $comboOfferId) {
                return $offer;
            }
        }
    }
    foreach ($browsableOffers as $offer) {
        if ($offer['scope'] === 'restaurant' && $offer['offer_type'] !== 'combo') {
            return $offer;
        }
    }
    return null;
}
