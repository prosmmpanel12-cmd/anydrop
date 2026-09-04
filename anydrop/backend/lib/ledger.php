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
 * ---------- Sign convention (documented here because doc 19 §6's own
 * inline comments on the entry_type ENUM are inconsistent about it) ----------
 * `restaurants.current_due`: positive = restaurant owes admin (COD
 * commissions not yet settled), negative = admin owes restaurant
 * (online-order payouts not yet paid out), zero = settled — this part
 * of doc 19 §6 is unambiguous and kept as-is.
 *
 * Every restaurant_due_ledger row's `amount` is the signed delta such
 * that `new_current_due = old_current_due + amount` — this file is the
 * only place that math happens. Under that rule:
 *   commission_cod          : +commission_amount   (restaurant owes admin more)
 *   payout_payable          : -restaurant_share     (admin owes restaurant more)
 *   settlement_to_restaurant: +paid_amount          (admin paid restaurant — current_due
 *                                                     rises back toward 0 from negative)
 *   settlement_from_restaurant: -paid_amount        (restaurant paid admin — current_due
 *                                                     falls back toward 0 from positive)
 * (doc 19 §6's own ALTER-statement comments label the two settlement
 * rows "-amount" — that contradicts the same doc's own prose two lines
 * above it, which says a `settlement_to_restaurant` entry "brings due
 * back toward 0 from negative". This file implements the prose
 * description, since that's the actual required behaviour; flag to the
 * app owner if the ENUM comment was meant literally instead.)
 */

require_once __DIR__ . '/../config/database.php';

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
     * STILL NOT CALLED ANYWHERE (confirmed again 2026-08-26, docs/43 —
     * this is the one real remaining gap, unlike
     * record_paid_order_ledger_entries() below which has since been
     * wired up). Ready for the moment a COD order reaches a
     * genuinely-final "restaurant collected the cash" state — the
     * codebase still has no such transition (no rider-facing API
     * namespace exists at all yet; nothing ever sets orders.status =
     * 'delivered' — grepped the whole backend/api tree to confirm).
     * That's the Rider App, Phase G (recall.md items 43-48) — a
     * separate, much larger build than a one-line wire-up, so this
     * stays flagged rather than half-built. Writing this at order
     * CREATION time instead would be wrong — a placed COD order can
     * still be rejected/cancelled before any cash actually changes
     * hands, and that would leave a ledger entry for an order that
     * never completed. Call this once a real 'delivered' transition
     * exists, from that transition's own transaction.
     */
    function record_cod_order_ledger_entry(PDO $db, array $order): void
    {
        write_due_ledger_entry(
            $db,
            (int) $order['restaurant_id'],
            (int) $order['id'],
            'commission_cod',
            (float) $order['commission_amount'],
            'COD order ' . $order['order_code'] . ' — commission owed to admin',
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
