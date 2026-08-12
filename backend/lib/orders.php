<?php
/**
 * Anydrop — Order Pricing & Validation Helpers (Phase 3)
 *
 * Single source of truth for cart pricing, used by both
 * POST /cart/validate (preview) and POST /orders (actual placement),
 * so the two can never drift apart. Never trusts client-sent prices/totals —
 * always re-reads menu_items/variants/addons from the DB.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

/** Generates a unique order code like QRX-8F3K9A. Retries on the rare collision. */
function generate_order_code(PDO $db): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no confusable 0/O/1/I
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = 'QRX-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $db->prepare('SELECT id FROM orders WHERE order_code = :c LIMIT 1');
        $stmt->execute(['c' => $code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    // Astronomically unlikely fallback.
    return 'QRX-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Validates + prices a cart against live DB data.
 *
 * @param array $items [{ menu_item_id, variant_id?, addon_ids?: [], quantity }]
 * @return array{
 *   valid: bool,
 *   restaurant: array|null,
 *   line_items: array,
 *   invalid_items: array,
 *   item_total: float, discount_amount: float, delivery_charge: float,
 *   platform_fee: float, tax_amount: float, packing_charge: float,
 *   grand_total: float, commission_amount: float, min_order_amount: float,
 *   coupon_id: int|null, error: string|null
 * }
 */
function price_cart(PDO $db, int $restaurantId, array $items, ?string $couponCode, int $customerId): array
{
    $result = [
        'valid' => false,
        'restaurant' => null,
        'line_items' => [],
        'invalid_items' => [],
        'item_total' => 0.0,
        'discount_amount' => 0.0,
        'delivery_charge' => 0.0,
        'platform_fee' => 0.0,
        'tax_amount' => 0.0,
        'packing_charge' => 0.0,
        'grand_total' => 0.0,
        'commission_amount' => 0.0,
        'min_order_amount' => 0.0,
        'coupon_id' => null,
        'error' => null,
    ];

    if (empty($items)) {
        $result['error'] = 'empty_cart';
        return $result;
    }

    $rStmt = $db->prepare("SELECT * FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $rStmt->execute(['id' => $restaurantId]);
    $restaurant = $rStmt->fetch();
    if (!$restaurant) {
        $result['error'] = 'restaurant_not_found';
        return $result;
    }
    $result['restaurant'] = $restaurant;
    // Exposed always (not just on the below_min_order_amount error) so
    // callers like cart/validate.php can show a live "add ₹X more" amount
    // as the user edits their cart, not only once they hit Place Order.
    $result['min_order_amount'] = (float) $restaurant['min_order_amount'];

    if ($restaurant['status'] !== 'approved') {
        $result['error'] = 'restaurant_unavailable';
        return $result;
    }

    $dueLimit = (float) get_setting('restaurant_due_limit', 2000);
    if ((float) $restaurant['current_due'] >= $dueLimit) {
        $result['error'] = 'restaurant_unavailable';
        return $result;
    }

    $itemTotal = 0.0;
    $lineItems = [];
    $invalid = [];

    foreach ($items as $line) {
        $menuItemId = (int) ($line['menu_item_id'] ?? 0);
        $qty = max(1, (int) ($line['quantity'] ?? 1));

        $iStmt = $db->prepare(
            'SELECT * FROM menu_items WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1'
        );
        $iStmt->execute(['id' => $menuItemId, 'rid' => $restaurantId]);
        $item = $iStmt->fetch();

        if (!$item || !$item['is_available']) {
            $invalid[] = ['menu_item_id' => $menuItemId, 'reason' => !$item ? 'not_found' : 'unavailable'];
            continue;
        }

        $unitPrice = (float) $item['price'];
        if ((float) $item['discount_percent'] > 0) {
            $unitPrice = round($unitPrice * (1 - (float) $item['discount_percent'] / 100), 2);
        }

        $variantName = null;
        if (!empty($line['variant_id'])) {
            $vStmt = $db->prepare('SELECT * FROM menu_item_variants WHERE id = :id AND menu_item_id = :mid LIMIT 1');
            $vStmt->execute(['id' => (int) $line['variant_id'], 'mid' => $menuItemId]);
            $variant = $vStmt->fetch();
            if (!$variant) {
                $invalid[] = ['menu_item_id' => $menuItemId, 'reason' => 'invalid_variant'];
                continue;
            }
            $unitPrice += (float) $variant['price_delta'];
            $variantName = $variant['name'];
        }

        $addonNames = [];
        $addonTotal = 0.0;
        foreach ((array) ($line['addon_ids'] ?? []) as $addonId) {
            $aStmt = $db->prepare(
                'SELECT * FROM menu_item_addons WHERE id = :id AND menu_item_id = :mid AND is_active = 1 LIMIT 1'
            );
            $aStmt->execute(['id' => (int) $addonId, 'mid' => $menuItemId]);
            $addon = $aStmt->fetch();
            if (!$addon) {
                $invalid[] = ['menu_item_id' => $menuItemId, 'reason' => 'invalid_addon'];
                continue 2;
            }
            $addonNames[] = ['id' => (int) $addon['id'], 'name' => $addon['name'], 'price' => (float) $addon['price']];
            $addonTotal += (float) $addon['price'];
        }

        $lineUnitPrice = round($unitPrice + $addonTotal, 2);
        $subtotal = round($lineUnitPrice * $qty, 2);
        $itemTotal += $subtotal;

        // §2.6 — per-item cooking request from the dish-customization sheet
        // (e.g. "less spicy", "no onion or garlic"). Trimmed and capped to a
        // sane length server-side — never trust client-sent free text length.
        $specialInstructions = trim((string) ($line['special_instructions'] ?? ''));
        if ($specialInstructions !== '') {
            $specialInstructions = mb_substr($specialInstructions, 0, 200);
        } else {
            $specialInstructions = null;
        }

        $lineItems[] = [
            'menu_item_id' => $menuItemId,
            'item_name_snapshot' => $item['name'],
            'variant_name' => $variantName,
            'quantity' => $qty,
            'unit_price' => $lineUnitPrice,
            'addons_json' => json_encode($addonNames),
            'special_instructions' => $specialInstructions,
            'subtotal' => $subtotal,
        ];
    }

    $result['invalid_items'] = $invalid;
    $result['line_items'] = $lineItems;

    if (empty($lineItems)) {
        $result['error'] = 'no_valid_items';
        return $result;
    }

    // H4 fix (2026-08-10): this used to `return $result` immediately here,
    // same class of bug the coupon block below already had a fix + comment
    // for — an early return here skips delivery/platform/tax/grand_total
    // entirely, so a below-minimum cart (e.g. a ₹30 item under a ₹50
    // min_order_amount) shipped the app a ₹0.00 bill instead of a real one.
    // Stash the error and fall through so pricing (and any coupon) still
    // computes normally; the app decides whether to block "Place Order" on
    // `warning === 'below_min_order_amount'` separately from bill preview.
    if ($itemTotal < (float) $restaurant['min_order_amount']) {
        $result['error'] = 'below_min_order_amount';
    }

    // Coupon
    // NOTE: every early-return below (invalid_coupon, coupon_min_order_not_met,
    // coupon_usage_limit_reached) must still fall through to the normal totals
    // calculation with $discount left at 0 — a bad coupon code should zero out
    // the *discount*, not the entire bill. We used to `return $result` directly
    // from inside this block, which skipped delivery/platform/tax/grand_total
    // entirely and sent the app a ₹0.00 bill. Instead, stash the error and
    // `break` out of the coupon check so pricing always continues below.
    $discount = 0.0;
    $couponId = null;
    if (!empty($couponCode)) {
        do {
            $cStmt = $db->prepare(
                'SELECT * FROM coupons WHERE code = :code AND is_active = 1
                 AND (restaurant_id IS NULL OR restaurant_id = :rid)
                 AND (valid_from IS NULL OR valid_from <= NOW())
                 AND (valid_until IS NULL OR valid_until >= NOW()) LIMIT 1'
            );
            $cStmt->execute(['code' => strtoupper(trim($couponCode)), 'rid' => $restaurantId]);
            $coupon = $cStmt->fetch();

            if (!$coupon) {
                $result['error'] = 'invalid_coupon';
                break;
            }
            if ($itemTotal < (float) $coupon['min_order_amount']) {
                $result['error'] = 'coupon_min_order_not_met';
                break;
            }
            if ($coupon['usage_limit_per_user'] !== null) {
                $uStmt = $db->prepare(
                    'SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid AND customer_id = :uid'
                );
                $uStmt->execute(['cid' => $coupon['id'], 'uid' => $customerId]);
                if ((int) $uStmt->fetch()['c'] >= (int) $coupon['usage_limit_per_user']) {
                    $result['error'] = 'coupon_usage_limit_reached';
                    break;
                }
            }
            if ($coupon['usage_limit_total'] !== null) {
                $tStmt = $db->prepare('SELECT COUNT(*) AS c FROM coupon_usages WHERE coupon_id = :cid');
                $tStmt->execute(['cid' => $coupon['id']]);
                if ((int) $tStmt->fetch()['c'] >= (int) $coupon['usage_limit_total']) {
                    $result['error'] = 'coupon_usage_limit_reached';
                    break;
                }
            }

            $discount = $coupon['discount_type'] === 'percent'
                ? round($itemTotal * (float) $coupon['discount_value'] / 100, 2)
                : (float) $coupon['discount_value'];
            if ($coupon['max_discount_amount'] !== null) {
                $discount = min($discount, (float) $coupon['max_discount_amount']);
            }
            $discount = min($discount, $itemTotal);
            $couponId = (int) $coupon['id'];
        } while (false);
    }

    $deliveryCharge = (float) get_setting('delivery_charge_flat', 25);
    $platformFee = (float) get_setting('platform_fee_flat', 5);
    $packingCharge = (float) get_setting('packing_charge_flat', 0);
    $taxPercent = (float) get_setting('tax_percent', 5);
    $taxAmount = round(($itemTotal - $discount) * $taxPercent / 100, 2);

    $grandTotal = round($itemTotal - $discount + $deliveryCharge + $platformFee + $packingCharge + $taxAmount, 2);
    // Per-restaurant commission_percent wins over the global default (restaurants.commission_percent
    // is the "admin override for this restaurant" column referenced in 02_API_Contract.md section 6).
    $commissionPercent = (float) ($restaurant['commission_percent'] ?? get_setting('commission_default_percent', 15));
    $commissionAmount = round($itemTotal * $commissionPercent / 100, 2);

    $result['valid'] = empty($invalid);
    $result['item_total'] = round($itemTotal, 2);
    $result['discount_amount'] = $discount;
    $result['delivery_charge'] = $deliveryCharge;
    $result['platform_fee'] = $platformFee;
    $result['packing_charge'] = $packingCharge;
    $result['tax_amount'] = $taxAmount;
    $result['grand_total'] = $grandTotal;
    $result['commission_amount'] = $commissionAmount;
    $result['coupon_id'] = $couponId;

    return $result;
}

/** Shapes an `orders` row + its items for API responses (customer/restaurant views). */
function format_order(PDO $db, array $order): array
{
    $itemsStmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :id');
    $itemsStmt->execute(['id' => $order['id']]);
    $items = array_map(function ($i) {
        return [
            'id' => (int) $i['id'],
            'menu_item_id' => $i['menu_item_id'] !== null ? (int) $i['menu_item_id'] : null,
            'name' => $i['item_name_snapshot'],
            'variant_name' => $i['variant_name'],
            'quantity' => (int) $i['quantity'],
            'unit_price' => (float) $i['unit_price'],
            'addons' => $i['addons_json'] ? json_decode($i['addons_json'], true) : [],
            'subtotal' => (float) $i['subtotal'],
        ];
    }, $itemsStmt->fetchAll());

    $histStmt = $db->prepare('SELECT status, changed_by_type, note, created_at FROM order_status_history WHERE order_id = :id ORDER BY id ASC');
    $histStmt->execute(['id' => $order['id']]);

    // Part 13 — restaurant name + rider_id, so the app can build the "Rate
    // this order" prompt (label + whether to show a delivery-rating row)
    // without a second round-trip to restaurants/menu.php.
    $rStmt = $db->prepare('SELECT name FROM restaurants WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $order['restaurant_id']]);
    $restaurantName = $rStmt->fetch()['name'] ?? null;

    return [
        'id' => (int) $order['id'],
        'order_code' => $order['order_code'],
        'restaurant_id' => (int) $order['restaurant_id'],
        'restaurant_name' => $restaurantName,
        'rider_id' => $order['rider_id'] !== null ? (int) $order['rider_id'] : null,
        'status' => $order['status'],
        'item_total' => (float) $order['item_total'],
        'delivery_charge' => (float) $order['delivery_charge'],
        'platform_fee' => (float) $order['platform_fee'],
        'packing_charge' => (float) $order['packing_charge'],
        'tax_amount' => (float) $order['tax_amount'],
        'discount_amount' => (float) $order['discount_amount'],
        'grand_total' => (float) $order['grand_total'],
        'payment_method' => $order['payment_method'],
        'payment_status' => $order['payment_status'],
        'delivery_instructions' => $order['delivery_instructions'],
        'estimated_prep_minutes' => $order['estimated_prep_minutes'] !== null ? (int) $order['estimated_prep_minutes'] : null,
        'created_at' => $order['created_at'],
        'items' => $items,
        'status_history' => $histStmt->fetchAll(),
    ];
}

function insert_status_history(PDO $db, int $orderId, string $status, string $changedByType, ?int $changedById = null, ?string $note = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO order_status_history (order_id, status, changed_by_type, changed_by_id, note) VALUES (:o, :s, :t, :i, :n)'
    );
    $stmt->execute(['o' => $orderId, 's' => $status, 't' => $changedByType, 'i' => $changedById, 'n' => $note]);
}
