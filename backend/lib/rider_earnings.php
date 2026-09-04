<?php
/**
 * Anydrop — Rider Earnings Ledger writer + payout calculation
 * (deep-plan §19-20, migration 73).
 *
 * Tracks money the PLATFORM OWES THE RIDER for completed deliveries —
 * the opposite direction from lib/rider_ledger.php's rider_cod_ledger
 * (cash the rider is physically holding and owes back to admin).
 * Deliberately a separate file/table, never mixed — see migration 73's
 * own comment and deep-plan §20's explicit instruction.
 *
 * Rate model (person's decision, 2026-09-04): rider_earning is a
 * configurable PERCENTAGE of the order's own delivery_charge (already
 * computed by lib/delivery_pricing.php's calculate_delivery_fee() —
 * real distance, area-configurable base/rate), floored at a
 * configurable minimum — NOT an independent flat+per-km formula of its
 * own. See migration 73's header for the full reasoning.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';

if (!function_exists('rider_earning_share_percent')) {
    function rider_earning_share_percent(): float
    {
        $pct = (float) get_setting('rider_earning_share_percent', 80);
        // Clamp defensively — an admin fat-fingering 800 into the
        // settings field shouldn't be able to make a delivery pay out
        // 8x its own delivery_charge; 0 is allowed (a temporary "no
        // payout" state is a valid admin choice, not a bug).
        return max(0.0, min(100.0, $pct));
    }
}

if (!function_exists('rider_earning_minimum')) {
    function rider_earning_minimum(): float
    {
        return max(0.0, (float) get_setting('rider_earning_minimum', 20));
    }
}

if (!function_exists('calculate_rider_earning')) {
    /**
     * Pure calculation, no DB writes — kept separate from
     * record_rider_delivery_earning() so the admin settings page (or a
     * future "preview payout" UI) can show what an order WOULD earn
     * without actually writing a ledger row, same split
     * calculate_delivery_fee() itself already models for the customer
     * side.
     *
     * @return array{amount: float, share_percent: float, minimum: float, delivery_charge: float, floored: bool}
     */
    function calculate_rider_earning(float $deliveryCharge): array
    {
        $sharePercent = rider_earning_share_percent();
        $minimum = rider_earning_minimum();

        $shareAmount = round($deliveryCharge * $sharePercent / 100, 2);
        $floored = $shareAmount < $minimum;
        $amount = $floored ? $minimum : $shareAmount;

        return [
            'amount' => $amount,
            'share_percent' => $sharePercent,
            'minimum' => $minimum,
            'delivery_charge' => $deliveryCharge,
            'floored' => $floored,
        ];
    }
}

if (!function_exists('write_rider_earnings_ledger_entry')) {
    /**
     * Inserts one rider_earnings_ledger row and updates
     * riders.earnings_balance to match. MUST be called inside an
     * already-open transaction by the caller — same convention
     * write_rider_cod_ledger_entry() (lib/rider_ledger.php) and
     * write_due_ledger_entry() (lib/ledger.php) both already use.
     *
     * @param float $amount Signed: + = platform now owes the rider
     *   more (a delivery earning, incentive, bonus, credit), - = owes
     *   less (a payout to the rider, or a debit adjustment).
     */
    function write_rider_earnings_ledger_entry(
        PDO $db,
        int $riderId,
        ?int $orderId,
        string $entryType,
        float $amount,
        ?string $note,
        string $createdBy
    ): int {
        $lockStmt = $db->prepare('SELECT earnings_balance FROM riders WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $riderId]);
        $row = $lockStmt->fetch();
        $oldBalance = $row !== false ? (float) $row['earnings_balance'] : 0.0;
        $newBalance = round($oldBalance + $amount, 2);

        $db->prepare('UPDATE riders SET earnings_balance = :b WHERE id = :id')
            ->execute(['b' => $newBalance, 'id' => $riderId]);

        $ins = $db->prepare(
            'INSERT INTO rider_earnings_ledger (rider_id, order_id, entry_type, amount, running_balance, note, created_by)
             VALUES (:rid, :oid, :type, :amount, :bal, :note, :by)'
        );
        $ins->execute([
            'rid' => $riderId,
            'oid' => $orderId,
            'type' => $entryType,
            'amount' => $amount,
            'bal' => $newBalance,
            'note' => $note,
            'by' => $createdBy,
        ]);

        return (int) $db->lastInsertId();
    }
}

if (!function_exists('record_rider_delivery_earning')) {
    /**
     * Fire this once per delivered order, in the SAME transaction as
     * the status flip to 'delivered' — same call-site convention
     * record_rider_cod_collected() (COD side) and
     * record_cod_order_ledger_entry() (restaurant commission side)
     * already use in orders-deliver.php. Idempotency is the caller's
     * responsibility via that same transactional status-flip guard
     * (orders-deliver.php's `rowCount() !== 1` check already prevents
     * this from firing twice for one order — a second call against an
     * already-`delivered` order fails the `status = 'out_for_delivery'`
     * WHERE clause before this function is ever reached).
     *
     * @return array The result of calculate_rider_earning(), plus the
     *   ledger row id, so the caller (or a future push-notification
     *   hook) can report the exact amount without a second query.
     */
    function record_rider_delivery_earning(PDO $db, array $order): array
    {
        $calc = calculate_rider_earning((float) $order['delivery_charge']);

        $ledgerId = write_rider_earnings_ledger_entry(
            $db,
            (int) $order['rider_id'],
            (int) $order['id'],
            'delivery_earning',
            $calc['amount'],
            'Order ' . $order['order_code'] . ' delivered — '
                . $calc['share_percent'] . '% of ₹' . number_format($calc['delivery_charge'], 2) . ' delivery charge'
                . ($calc['floored'] ? ' (raised to configured minimum)' : ''),
            'system'
        );

        $calc['ledger_id'] = $ledgerId;
        return $calc;
    }
}

if (!function_exists('record_rider_payout')) {
    /**
     * Admin-side "paid the rider out" action — mirrors
     * record_rider_settlement()'s shape in lib/rider_ledger.php
     * exactly, but for the opposite ledger/direction. Single write
     * path so the ledger row and earnings_balance update can't drift
     * apart, same reasoning as that function's own kdoc.
     *
     * @return int the new rider_earnings_ledger row id
     */
    function record_rider_payout(
        PDO $db,
        int $riderId,
        float $amount,
        int $adminId,
        ?string $remarks
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('payout_amount_must_be_positive');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $id = write_rider_earnings_ledger_entry(
                $db, $riderId, null, 'payout', -$amount,
                'Rider paid out' . ($remarks ? " — $remarks" : ''), 'admin'
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

if (!function_exists('record_rider_earnings_adjustment')) {
    /**
     * Admin-side manual correction (deep-plan §20's
     * adjustment_credit/adjustment_debit entry types) — e.g. a
     * dispute resolution, a mis-calculated historical order, a
     * one-off incentive/bonus not tied to any specific delivered
     * order. $amount is always POSITIVE here; $isCredit picks the
     * sign and the entry_type, so the caller (admin UI) never has to
     * remember to negate a debit itself — same "the function owns the
     * sign convention" shape record_rider_settlement() already
     * established for -$amount on the COD side.
     */
    function record_rider_earnings_adjustment(
        PDO $db,
        int $riderId,
        float $amount,
        bool $isCredit,
        int $adminId,
        ?string $remarks
    ): int {
        if ($amount <= 0) {
            throw new InvalidArgumentException('adjustment_amount_must_be_positive');
        }

        $signedAmount = $isCredit ? $amount : -$amount;
        $entryType = $isCredit ? 'adjustment_credit' : 'adjustment_debit';

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $id = write_rider_earnings_ledger_entry(
                $db, $riderId, null, $entryType, $signedAmount,
                'Manual adjustment by admin' . ($remarks ? " — $remarks" : ''), 'admin'
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
