<?php
/**
 * Anydrop — Restaurant Due Ledger + Platform Cash Ledger writers
 * (recall.md Phase C items 20-23, migration 38, doc 19 §6/§6b).
 *
 * Single write path for both ledgers so a ledger row and its matching
 * balance update can never drift apart the way two separate inline
 * UPDATE+INSERT call sites would risk — same "one function everyone
 * calls" reasoning as lib/cod_rules.php.
 *
 * ---------- Sign convention ----------
 * `restaurants.current_due`: negative = admin owes restaurant (COD
 * and online order payouts not yet paid out — the normal, expected
 * state), positive = admin has already paid the restaurant MORE than
 * it was owed (e.g. a manual settlement overshoot), zero = settled.
 *
 * UPDATE 2026-09-12 (app-owner correction): a restaurant never
 * legitimately "owes admin" under this platform's own cash model —
 * every rupee, COD or online, ends up with admin first (COD: Customer
 * -> Rider -> Admin; online: straight into admin's payment gateway).
 * The restaurant is never in physical possession of any of it, so
 * there is nothing for them to owe — commission is simply subtracted
 * from what admin pays them, never billed as a separate debt. The old
 * `commission_cod` +commission_amount entry (billing the restaurant
 * for commission with no offsetting payable) was what made
 * `current_due` climb positive for COD-heavy restaurants; that entry
 * type is no longer written (see `record_cod_order_ledger_entry()`
 * below) — a positive `current_due` you see today should only ever be
 * a genuine overpayment, not a phantom balance owed.
 *
 * Every restaurant_due_ledger row's `amount` is the signed delta such
 * that `new_current_due = old_current_due + amount` — this file is the
 * only place that math happens. Under that rule:
 *   payout_payable          : -restaurant_share     (admin owes restaurant more —
 *                                                     written for BOTH COD and online orders now)
 *   settlement_to_restaurant: +paid_amount          (admin paid restaurant — current_due
 *                                                     rises back toward 0 from negative)
 *   settlement_from_restaurant: -paid_amount        (restaurant paid admin — only used if
 *                                                     current_due was pushed positive by an
 *                                                     overpayment and the restaurant refunds it)
 * (`commission_cod` remains in the entry_type ENUM for historical rows
 * only — see the 2026-09-12 update above; nothing new writes it.)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settlement_status.php';

if (!function_exists('write_due_ledger_entry')) {
    /**
     * Inserts one restaurant_due_ledger row and updates
     * restaurants.current_due to match. MUST be called inside an
     * already-open transaction by the caller (this function does not
     * open/commit one itself) — every caller so far (record_settlement()
     * below) wraps this together with a restaurant_payments write, and
     * the two must never land independently of each other.
     *
     * @param float $amount Signed, per this file's convention above.
     */
    function write_due_ledger_entry(
        PDO $db,
        int $restaurantId,
        ?int $orderId,
        string $entryType,
        float $amount,
        ?string $note,
        string $createdBy,
        ?int $adminId = null
    ): int {
        $lockStmt = $db->prepare('SELECT current_due FROM restaurants WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $restaurantId]);
        $row = $lockStmt->fetch();
        $oldDue = $row !== false ? (float) $row['current_due'] : 0.0;
        $newDue = round($oldDue + $amount, 2);

        $db->prepare('UPDATE restaurants SET current_due = :d WHERE id = :id')
            ->execute(['d' => $newDue, 'id' => $restaurantId]);

        $ins = $db->prepare(
            'INSERT INTO restaurant_due_ledger (restaurant_id, order_id, entry_type, amount, running_balance, note, created_by)
             VALUES (:rid, :oid, :type, :amount, :bal, :note, :by)'
        );
        $ins->execute([
            'rid' => $restaurantId,
            'oid' => $orderId,
            'type' => $entryType,
            'amount' => $amount,
            'bal' => $newDue,
            'note' => $note,
            'by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }
}

if (!function_exists('write_platform_ledger_entry')) {
    /**
     * Inserts one platform_ledger row. `platform_revenue` rows are
     * informational only (doc 19 §6b) — they carry the SAME
     * running_balance as the previous row rather than moving it, since
     * platform revenue isn't a separate cash transfer, just the
     * queryable gap between money in and money out for the same order.
     *
     * @param float $amount Signed: + = cash in, - = cash out. Ignored
     *   for running_balance purposes (but still stored as-is) when
     *   $entryType === 'platform_revenue'.
     */
    function write_platform_ledger_entry(
        PDO $db,
        string $entryType,
        float $amount,
        ?int $restaurantId,
        ?int $orderId,
        ?int $restaurantPaymentId,
        ?string $note,
        string $createdBy,
        ?int $adminId = null
    ): int {
        $lastStmt = $db->query('SELECT running_balance FROM platform_ledger ORDER BY id DESC LIMIT 1');
        $last = $lastStmt->fetch();
        $lastBalance = $last !== false ? (float) $last['running_balance'] : 0.0;
        $newBalance = $entryType === 'platform_revenue' ? $lastBalance : round($lastBalance + $amount, 2);

        $ins = $db->prepare(
            'INSERT INTO platform_ledger (entry_type, amount, running_balance, restaurant_id, order_id, restaurant_payment_id, note, created_by, admin_id)
             VALUES (:type, :amount, :bal, :rid, :oid, :pid, :note, :by, :aid)'
        );
        $ins->execute([
            'type' => $entryType,
            'amount' => $amount,
            'bal' => $newBalance,
            'rid' => $restaurantId,
            'oid' => $orderId,
            'pid' => $restaurantPaymentId,
            'note' => $note,
            'by' => $createdBy,
            'aid' => $adminId,
        ]);

        return (int) $db->lastInsertId();
    }
}

if (!function_exists('record_settlement')) {
    /**
     * The full admin "Pay Now" action (doc 19 §6) — one
     * restaurant_payments row (status='verified', admin did this
     * directly) + one restaurant_due_ledger entry + one platform_ledger
     * entry, in a single transaction so the three can never land
     * independently of each other. This is the one settlement entry
     * point every caller (backend/admin/settlements.php) should use —
     * never insert into restaurant_payments directly.
     *
     * @param string $direction 'admin_to_restaurant' | 'restaurant_to_admin'
     * @return int the new restaurant_payments.id
     */
    function record_settlement(
        PDO $db,
        int $restaurantId,
        string $direction,
        float $amount,
        int $adminId,
        ?string $utrNumber,
        ?string $screenshotUrl,
        ?string $remarks,
        ?string $paymentDate
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('settlement_amount_must_be_positive');
        }
        if (!in_array($direction, ['admin_to_restaurant', 'restaurant_to_admin'], true)) {
            throw new InvalidArgumentException('invalid_settlement_direction');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $ins = $db->prepare(
                'INSERT INTO restaurant_payments
                    (restaurant_id, amount, status, direction, utr_number, screenshot_url, remarks, payment_date, settled_by_admin_id, verified_by_admin_id, verified_at)
                 VALUES
                    (:rid, :amount, \'verified\', :dir, :utr, :ss, :remarks, :pdate, :aid, :aid, NOW())'
            );
            $ins->execute([
                'rid' => $restaurantId,
                'amount' => $amount,
                'dir' => $direction,
                'utr' => $utrNumber,
                'ss' => $screenshotUrl,
                'remarks' => $remarks,
                'pdate' => $paymentDate,
                'aid' => $adminId,
            ]);
            $paymentId = (int) $db->lastInsertId();

            if ($direction === 'admin_to_restaurant') {
                write_due_ledger_entry(
                    $db, $restaurantId, null, 'settlement_to_restaurant', $amount,
                    'Pay Now — admin paid restaurant' . ($utrNumber ? " (UTR $utrNumber)" : ''), 'admin', $adminId
                );
                write_platform_ledger_entry(
                    $db, 'restaurant_payout_out', -$amount, $restaurantId, null, $paymentId,
                    'Payout to restaurant #' . $restaurantId, 'admin', $adminId
                );
            } else {
                write_due_ledger_entry(
                    $db, $restaurantId, null, 'settlement_from_restaurant', -$amount,
                    'Pay Now — restaurant paid admin' . ($utrNumber ? " (UTR $utrNumber)" : ''), 'admin', $adminId
                );
                write_platform_ledger_entry(
                    $db, 'restaurant_settlement_in', $amount, $restaurantId, null, $paymentId,
                    'Settlement received from restaurant #' . $restaurantId, 'admin', $adminId
                );
            }

            // Deep Plan Phase 1 (docs/00_Deep_Plan_...2026-09-09.md) —
            // link every currently-'eligible' order of this restaurant to
            // this payment and flip them to 'settled', so the new
            // Restaurant Statement screen's per-order badge stays accurate.
            // Only fires for admin_to_restaurant (the payout direction —
            // this IS the T+1 payout an order was waiting on); a
            // restaurant_to_admin payment is the restaurant clearing its
            // own COD-commission debt, a different thing from "this
            // specific order got paid out", so it doesn't touch order
            // settlement_status.
            if ($direction === 'admin_to_restaurant') {
                $eligibleStmt = $db->prepare(
                    "SELECT id FROM orders WHERE restaurant_id = :rid AND settlement_status = 'eligible'"
                );
                $eligibleStmt->execute(['rid' => $restaurantId]);
                $eligibleOrderIds = array_column($eligibleStmt->fetchAll(), 'id');
                mark_orders_settled($paymentId, $eligibleOrderIds);
            }

            if ($ownTransaction) {
                $db->commit();
            }
            return $paymentId;
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('record_cod_order_ledger_entry')) {
    /**
     * UPDATE 2026-09-12 (app-owner correction — "sara cash to apne paas
     * aayega na, rider ke through?"): this function used to write ONLY
     * a `commission_cod` +commission_amount entry — i.e. "restaurant
     * owes admin the commission" — with nothing ever crediting the
     * restaurant back for the rest of that same order's value. That was
     * wrong given this platform's own COD model (docs/00_Deep_Plan...
     * §0): COD cash flows Customer -> Rider -> Admin, in full — admin
     * ends up holding 100% of every COD order's cash the same way it
     * holds 100% of every online order's cash (via the payment
     * gateway). The restaurant never receives a single rupee directly
     * either way. So exactly like `record_paid_order_ledger_entries()`
     * below already does for online orders, a COD order should ALSO
     * net straight to a single "admin owes restaurant" payable —
     * commission is simply subtracted out of what admin owes, never
     * billed to the restaurant as a separate debt. Writing a bare
     * `commission_cod` debit with no offsetting payable is what made
     * `current_due` climb positive ("restaurant owes admin") for
     * COD-heavy restaurants even though, in reality, admin was never
     * owed anything by them — admin already had their cash.
     *
     * Net result: this now writes ONE ledger entry per COD order —
     * `payout_payable` for -(grand_total - commission_amount -
     * platform_fee), same restaurant-share formula
     * `record_paid_order_ledger_entries()` uses for online orders — so
     * a restaurant's `current_due` behaves identically regardless of
     * how the customer paid, and only ever goes positive if admin
     * genuinely overpaid them via a manual settlement (a real
     * admin-caused state, not a phantom "commission owed" balance).
     *
     * The `commission_cod` entry_type itself is left in the ENUM
     * (harmless, other code/comments reference it) but is no longer
     * written here — nothing currently reads for that entry_type
     * specifically, they all read `current_due` or sum every
     * restaurant_due_ledger row regardless of type.
     *
     * Fires from the real 'delivered' transition, once it's a COD
     * order — see api/v1/rider/orders-deliver.php (rider-facing
     * delivery-confirmation flow, Phase G) and admin/orders.php's own
     * "mark delivered" action. Both call sites run this inside the
     * same transaction as the status flip, so a rolled-back delivery
     * never leaves a dangling ledger entry. Deliberately NOT called at
     * order creation time — a placed COD order can still be
     * rejected/cancelled before any cash actually changes hands.
     */
    function record_cod_order_ledger_entry(PDO $db, array $order): void
    {
        $grandTotal = (float) $order['grand_total'];
        $commissionAmount = (float) $order['commission_amount'];
        $platformFee = (float) ($order['platform_fee'] ?? 0);
        $restaurantShare = round($grandTotal - $commissionAmount - $platformFee, 2);

        write_due_ledger_entry(
            $db,
            (int) $order['restaurant_id'],
            (int) $order['id'],
            'payout_payable',
            -$restaurantShare,
            'COD order ' . $order['order_code'] . ' — net payable to restaurant (admin already holds the full COD cash via rider; commission ₹'
                . number_format($commissionAmount, 2) . ' already netted out)',
            'system'
        );
    }
}

if (!function_exists('record_paid_order_ledger_entries')) {
    /**
     * UPDATE (2026-08-26, docs/43): this WAS "not called anywhere yet"
     * when first written, but the native UPI payment gateway (docs/23,
     * migrations 40-42) since wired it up — called from both
     * PaymentService::promoteOrderIfNeeded() (the ordinary poll/webhook
     * confirmation path) and orders/create.php (the immediate-success
     * path for a payment that's already confirmed at order-placement
     * time). Called once, idempotently, the moment a specific order's
     * payment_status actually flips to 'paid' — see both call sites'
     * own guards against double-firing.
     */
    function record_paid_order_ledger_entries(PDO $db, array $order): void
    {
        $grandTotal = (float) $order['grand_total'];
        $commissionAmount = (float) $order['commission_amount'];
        $platformFee = (float) $order['platform_fee'];
        $restaurantShare = round($grandTotal - $commissionAmount - $platformFee, 2);

        write_platform_ledger_entry(
            $db, 'customer_payment_in', $grandTotal, (int) $order['restaurant_id'], (int) $order['id'], null,
            'Order ' . $order['order_code'] . ' paid online', 'system'
        );
        write_platform_ledger_entry(
            $db, 'platform_revenue', $commissionAmount + $platformFee, (int) $order['restaurant_id'], (int) $order['id'], null,
            'Order ' . $order['order_code'] . ' — commission + platform fee', 'system'
        );
        write_due_ledger_entry(
            $db, (int) $order['restaurant_id'], (int) $order['id'], 'payout_payable', -$restaurantShare,
            'Order ' . $order['order_code'] . ' — online payout owed to restaurant', 'system'
        );
    }
}
