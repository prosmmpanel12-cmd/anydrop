<?php
/**
 * One-time backfill — run ONCE after deploying the 2026-09-12
 * lib/ledger.php fix to record_cod_order_ledger_entry().
 *
 * WHY THIS IS NEEDED:
 * Before the fix, every COD order's `delivered` transition wrote only
 * a `commission_cod` +commission_amount entry to restaurant_due_ledger
 * — billing the restaurant for commission with nothing ever crediting
 * them back for the rest of that order's value. That's what made
 * `restaurants.current_due` climb positive ("restaurant owes admin")
 * for COD-heavy restaurants, even though admin already held 100% of
 * that COD cash via the rider and never actually needed to collect
 * anything from the restaurant.
 *
 * This script finds every historical COD order that has a
 * `commission_cod` ledger row but no matching `payout_payable` row
 * (i.e. every order affected by the old bug), and inserts the missing
 * `payout_payable` entry for it — same restaurant-share formula the
 * fixed function now uses going forward: -(grand_total -
 * commission_amount - platform_fee). It calls the SAME
 * write_due_ledger_entry() the live code uses, inside one transaction
 * per order, so running_balance and current_due stay exactly as
 * consistent as if this had been recorded live at delivery time.
 *
 * SAFE TO RE-RUN: an order already has a payout_payable row after its
 * first pass through this script, so the `NOT EXISTS` check skips it
 * on any later run — this can be run again with no double-crediting.
 *
 * It does NOT delete/reverse the old `commission_cod` rows — those
 * stay as a historical record (the entry_type remains in the ENUM for
 * exactly this reason, see lib/ledger.php's updated header comment).
 * Only a new offsetting row is added; nothing already-recorded is
 * rewritten. current_due before/after this script is logged per
 * restaurant so the app owner can see exactly what changed.
 *
 * Usage: php scripts/backfill-cod-payout-payable.php [--dry-run]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/ledger.php';

$dryRun = in_array('--dry-run', $argv, true);

$db = Database::get();

echo $dryRun ? "DRY RUN — no writes will be made.\n\n" : "LIVE RUN — writing corrective ledger entries.\n\n";

// Every order that has a commission_cod entry but no payout_payable
// entry yet — exactly the set the old bug left uncorrected.
$stmt = $db->query(
    "SELECT DISTINCT o.id, o.order_code, o.restaurant_id, o.grand_total, o.commission_amount, o.platform_fee
     FROM restaurant_due_ledger rdl
     JOIN orders o ON o.id = rdl.order_id
     WHERE rdl.entry_type = 'commission_cod'
       AND NOT EXISTS (
           SELECT 1 FROM restaurant_due_ledger p
           WHERE p.order_id = rdl.order_id AND p.entry_type = 'payout_payable'
       )
     ORDER BY o.restaurant_id, o.id"
);
$affectedOrders = $stmt->fetchAll();

if (empty($affectedOrders)) {
    echo "No affected orders found — nothing to backfill. current_due for every restaurant is already correct.\n";
    exit(0);
}

echo count($affectedOrders) . " affected order(s) found.\n\n";

$beforeStmt = $db->query('SELECT id, name, current_due FROM restaurants WHERE deleted_at IS NULL');
$beforeByRestaurant = [];
foreach ($beforeStmt->fetchAll() as $r) {
    $beforeByRestaurant[(int) $r['id']] = ['name' => $r['name'], 'due' => (float) $r['current_due']];
}

$touchedRestaurantIds = [];
$totalCorrected = 0.0;

foreach ($affectedOrders as $o) {
    $restaurantId = (int) $o['restaurant_id'];
    $grandTotal = (float) $o['grand_total'];
    $commissionAmount = (float) $o['commission_amount'];
    $platformFee = (float) ($o['platform_fee'] ?? 0);
    $restaurantShare = round($grandTotal - $commissionAmount - $platformFee, 2);

    echo "Order #{$o['id']} ({$o['order_code']}) — restaurant #{$restaurantId}: "
        . "backfilling payout_payable -₹" . number_format($restaurantShare, 2) . "\n";

    if (!$dryRun) {
        $db->beginTransaction();
        try {
            write_due_ledger_entry(
                $db, $restaurantId, (int) $o['id'], 'payout_payable', -$restaurantShare,
                'Backfill (2026-09-12 fix) — COD order ' . $o['order_code']
                    . ' net payable to restaurant, commission already netted out',
                'system'
            );
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            echo "  FAILED: " . $e->getMessage() . " — stopped, no further orders processed.\n";
            exit(1);
        }
    }

    $touchedRestaurantIds[$restaurantId] = true;
    $totalCorrected += $restaurantShare;
}

echo "\n--- Summary ---\n";
if ($dryRun) {
    echo "Would correct current_due for " . count($touchedRestaurantIds) . " restaurant(s), total ₹"
        . number_format($totalCorrected, 2) . " moved from 'owed by restaurant' to 'payable to restaurant'.\n";
    echo "Re-run without --dry-run to apply.\n";
    exit(0);
}

$afterStmt = $db->query('SELECT id, name, current_due FROM restaurants WHERE deleted_at IS NULL');
foreach ($afterStmt->fetchAll() as $r) {
    $rid = (int) $r['id'];
    if (!isset($touchedRestaurantIds[$rid])) {
        continue;
    }
    $before = $beforeByRestaurant[$rid]['due'] ?? 0.0;
    $after = (float) $r['current_due'];
    echo "{$r['name']} (#{$rid}): current_due ₹" . number_format($before, 2) . " -> ₹" . number_format($after, 2) . "\n";
}

echo "\nDone. " . count($touchedRestaurantIds) . " restaurant(s) corrected.\n";
