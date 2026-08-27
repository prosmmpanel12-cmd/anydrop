<?php
/**
 * Anydrop — Rider COD Cash Ledger writer (migration 53).
 *
 * Tracks cash a rider is physically holding after collecting COD payment
 * from a customer, until they hand it over to admin. This is SEPARATE
 * from restaurant_due_ledger's 'commission_cod' entry (lib/ledger.php) —
 * that one tracks the commission the restaurant owes on the order; this
 * one tracks the actual cash sitting with the rider. Both fire off the
 * same COD-order-delivered event once that event exists.
 *
 * Rider payout (how much of the delivery fee the rider actually earns)
 * is deliberately NOT part of this file yet — rate model wasn't decided
 * as of 2026-08-27. This is cash-collected tracking only.
 *
 * NOT YET WIRED to a live trigger: same blocker as
 * record_cod_order_ledger_entry() in lib/ledger.php — no 'delivered'
 * status transition exists anywhere yet (no rider-facing API namespace
 * built). Call record_rider_cod_collected() from that transition's own
 * transaction once it exists. Everything below is ready to use as soon
 * as that call site appears.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

if (!function_exists('write_rider_cod_ledger_entry')) {
    /**
     * Inserts one rider_cod_ledger row and updates riders.cod_cash_held
     * to match. MUST be called inside an already-open transaction by the
     * caller, same convention as write_due_ledger_entry() in lib/ledger.php.
     *
     * @param float $amount Signed: + = rider now holding more cash
     *   (collected an order), - = rider handed cash to admin (settlement).
     */
    function write_rider_cod_ledger_entry(
        PDO $db,
        int $riderId,
        ?int $orderId,
        string $entryType,
        float $amount,
        ?string $note,
        string $createdBy
    ): int {
        $lockStmt = $db->prepare('SELECT cod_cash_held FROM riders WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $riderId]);
        $row = $lockStmt->fetch();
        $oldHeld = $row !== false ? (float) $row['cod_cash_held'] : 0.0;
        $newHeld = round($oldHeld + $amount, 2);

        $db->prepare('UPDATE riders SET cod_cash_held = :h WHERE id = :id')
            ->execute(['h' => $newHeld, 'id' => $riderId]);

        $ins = $db->prepare(
            'INSERT INTO rider_cod_ledger (rider_id, order_id, entry_type, amount, running_balance, note, created_by)
             VALUES (:rid, :oid, :type, :amount, :bal, :note, :by)'
        );
        $ins->execute([
            'rid' => $riderId,
            'oid' => $orderId,
            'type' => $entryType,
            'amount' => $amount,
            'bal' => $newHeld,
            'note' => $note,
            'by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }
}

if (!function_exists('record_rider_cod_collected')) {
    /**
     * NOT YET CALLED ANYWHERE — see file kdoc. Fire this once a COD
     * order's rider-facing "delivered, cash in hand" transition exists.
     * Writing this at order creation would be wrong for the same reason
     * record_cod_order_ledger_entry() in lib/ledger.php flags: a placed
     * COD order can still be rejected/cancelled before cash ever changes
     * hands.
     */
    function record_rider_cod_collected(PDO $db, array $order): void
    {
        write_rider_cod_ledger_entry(
            $db,
            (int) $order['rider_id'],
            (int) $order['id'],
            'cod_collected',
            (float) $order['grand_total'],
            'COD order ' . $order['order_code'] . ' — cash collected from customer',
            'system'
        );
    }
}

if (!function_exists('record_rider_settlement')) {
    /**
     * Admin-side "rider handed over cash" action — the Rider Settlements
     * page's Record Settlement button calls this directly (only direction
     * that exists: rider → admin, unlike restaurants which can owe or be
     * owed). Single write path so the ledger row and cod_cash_held update
     * can't drift apart.
     *
     * @return int the new rider_cod_ledger row id
     */
    function record_rider_settlement(
        PDO $db,
        int $riderId,
        float $amount,
        int $adminId,
        ?string $remarks
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('settlement_amount_must_be_positive');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $id = write_rider_cod_ledger_entry(
                $db, $riderId, null, 'settlement_to_admin', -$amount,
                'Rider handed over COD cash' . ($remarks ? " — $remarks" : ''), 'admin'
            );

            if ($ownTransaction) {
                $db->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('rider_cod_settlement_limit')) {
    function rider_cod_settlement_limit(): float
    {
        return (float) get_setting('rider_cod_settlement_limit', 2000);
    }
}
