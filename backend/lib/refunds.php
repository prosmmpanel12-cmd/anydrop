<?php
/**
 * Anydrop — Refund System (recall.md Phase C item 25, section 19;
 * doc 21 §2.2/§5.7). Migration 42.
 *
 * Same "one function everyone calls" shape as lib/ledger.php — every
 * refund state transition goes through this file so a refunds row,
 * the linked payment_transactions row, orders.payment_status, and the
 * platform_ledger 'refund_out' entry can never drift apart the way
 * separate inline UPDATEs at each call site would risk.
 *
 * NO REAL GATEWAY REFUND API EXISTS (UpipeProvider::refund() already
 * documents this — always returns 'manual_refund_required'). v1 is
 * therefore: admin manually sends the money back via their own
 * UPI/bank app, then records that transfer's reference here. This
 * file tracks and reconciles that manual movement; it does not
 * perform it. Same division of labour as settlements.php's Pay Now
 * flow for restaurant payouts.
 *
 * Lifecycle: requested -> under_review -> approved -> processing -> refunded
 *            (rejected is an off-ramp from under_review or approved)
 *
 * Two DISTINCT completion paths from 'approved' depending on
 * refunds.method (migration 42's ENUM):
 *   - 'manual_upi_bank_transfer' (default): approved -> processing
 *     (mark_refund_processing(), reference captured) -> refunded
 *     (complete_refund(), platform_ledger 'refund_out' written).
 *   - 'wallet' (item 15, added this session): approved -> refunded
 *     directly (complete_refund_to_wallet()) — no 'processing' step,
 *     no platform_ledger entry (money never leaves the platform, see
 *     that function's own kdoc for why).
 * Nothing currently sets method='wallet' on refund CREATION —
 * create_refund_request() always defaults to manual_upi_bank_transfer
 * per migration 42's DEFAULT. An admin (or a future customer-facing
 * "refund to wallet instead" choice) picking wallet happens at
 * approve time — see admin/refunds.php's Approve-as-Wallet button.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ledger.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/wallet.php'; // item 15 — complete_refund_to_wallet() calls credit_wallet()

if (!function_exists('create_refund_request')) {
    /**
     * Creates a 'requested' refund row for an order that already has
     * money sitting with the admin (order['payment_status'] === 'paid')
     * but is being cancelled/rejected. Idempotent per order — migration
     * 42's UNIQUE(order_id) means a second call for the same order
     * throws; callers should check get_refund_for_order() first if
     * they can't guarantee this is the first call (both current
     * callers — cancel.php, orders-reject.php — only reach here once
     * per order, since an order can only be cancelled/rejected once).
     *
     * $order must be a full `orders` row. Does not require an
     * already-open transaction (this is the only write for a fresh
     * request), but callers that already have one open (e.g. inside
     * the same transaction as the status-flip UPDATE) may still call
     * this — PDO nested calls are fine since no explicit
     * beginTransaction() happens in here.
     */
    function create_refund_request(
        PDO $db,
        array $order,
        string $reason,
        string $initiatedBy
    ): int {
        if (!in_array($initiatedBy, ['customer', 'restaurant', 'admin', 'system'], true)) {
            throw new InvalidArgumentException('invalid_initiated_by');
        }

        $txnStmt = $db->prepare(
            "SELECT id FROM payment_transactions WHERE order_id = :oid AND status = 'success' ORDER BY id DESC LIMIT 1"
        );
        $txnStmt->execute(['oid' => $order['id']]);
        $txnRow = $txnStmt->fetch();

        $expectedDays = (int) get_setting('refund_expected_days', 5);
        $expectedBy = date('Y-m-d', time() + $expectedDays * 86400);

        $ins = $db->prepare(
            'INSERT INTO refunds
                (order_id, payment_transaction_id, customer_id, amount, reason, initiated_by, status, expected_by_date)
             VALUES
                (:oid, :txnid, :cid, :amount, :reason, :by, \'requested\', :expected)'
        );
        $ins->execute([
            'oid' => $order['id'],
            'txnid' => $txnRow ? (int) $txnRow['id'] : null,
            'cid' => $order['customer_id'],
            'amount' => $order['grand_total'],
            'reason' => $reason,
            'by' => $initiatedBy,
            'expected' => $expectedBy,
        ]);
        $refundId = (int) $db->lastInsertId();

        create_notification(
            'customer',
            (int) $order['customer_id'],
            'Refund requested',
            'A refund of ₹' . $order['grand_total'] . ' for order ' . $order['order_code'] . ' has been requested and is under review.',
            'order',
            ['order_id' => (int) $order['id'], 'refund_id' => $refundId, 'screen' => 'order_status']
        );

        return $refundId;
    }
}

if (!function_exists('get_refund_for_order')) {
    function get_refund_for_order(PDO $db, int $orderId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM refunds WHERE order_id = :oid LIMIT 1');
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('admin_list_refunds')) {
    /** @param string|null $statusFilter One status, or null for everything not yet terminal (requested/under_review/approved/processing). */
    function admin_list_refunds(PDO $db, ?string $statusFilter = null): array
    {
        if ($statusFilter !== null) {
            $stmt = $db->prepare(
                'SELECT r.*, o.order_code, o.grand_total AS order_grand_total, c.name AS customer_name, c.mobile AS customer_phone
                 FROM refunds r
                 JOIN orders o ON o.id = r.order_id
                 JOIN customers c ON c.id = r.customer_id
                 WHERE r.status = :status
                 ORDER BY r.requested_at ASC'
            );
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt = $db->query(
                "SELECT r.*, o.order_code, o.grand_total AS order_grand_total, c.name AS customer_name, c.mobile AS customer_phone
                 FROM refunds r
                 JOIN orders o ON o.id = r.order_id
                 JOIN customers c ON c.id = r.customer_id
                 WHERE r.status IN ('requested','under_review','approved','processing')
                 ORDER BY r.requested_at ASC"
            );
        }
        return $stmt->fetchAll();
    }
}

if (!function_exists('mark_refund_under_review')) {
    function mark_refund_under_review(PDO $db, int $refundId, int $adminId): bool
    {
        $upd = $db->prepare(
            "UPDATE refunds SET status = 'under_review', admin_id = :aid WHERE id = :id AND status = 'requested'"
        );
        $upd->execute(['aid' => $adminId, 'id' => $refundId]);
        return $upd->rowCount() > 0;
    }
}

if (!function_exists('approve_refund')) {
    /**
     * $method (new, item 15) lets the admin pick 'wallet' instead of
     * the default 'manual_upi_bank_transfer' at approve time — the
     * natural decision point, since this is also when they're
     * deciding the expected-by date. Passing null keeps the row's
     * existing method column untouched (whatever create_refund_
     * request() defaulted it to), so every existing caller of this
     * function keeps working unchanged.
     */
    function approve_refund(PDO $db, int $refundId, int $adminId, ?string $expectedByDate = null, ?string $method = null): array
    {
        if ($method !== null && !in_array($method, ['manual_upi_bank_transfer', 'wallet'], true)) {
            return ['ok' => false, 'error' => 'invalid_method'];
        }

        $stmt = $db->prepare("SELECT * FROM refunds WHERE id = :id AND status IN ('requested','under_review') LIMIT 1");
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE refunds SET status = 'approved', approved_at = NOW(), admin_id = :aid"
                . ($expectedByDate ? ', expected_by_date = :exp' : '')
                . ($method !== null ? ', method = :method' : '')
                . ' WHERE id = :id'
        );
        $params = ['aid' => $adminId, 'id' => $refundId];
        if ($expectedByDate) {
            $params['exp'] = $expectedByDate;
        }
        if ($method !== null) {
            $params['method'] = $method;
        }
        $upd->execute($params);

        write_audit_log('admin', $adminId, 'refund_approved', ['refund_id' => $refundId, 'order_id' => (int) $refund['order_id'], 'method' => $method ?? $refund['method']]);

        create_notification(
            'customer', (int) $refund['customer_id'], 'Refund approved',
            'Your refund request has been approved and is being processed.',
            'order', ['order_id' => (int) $refund['order_id'], 'refund_id' => $refundId, 'screen' => 'order_status']
        );

        return ['ok' => true];
    }
}

if (!function_exists('mark_refund_processing')) {
    /**
     * Admin has actually sent the money back via their own UPI/bank
     * app and is recording the reference — same moment settlements.php
     * captures a UTR for an outgoing restaurant payout. This does NOT
     * yet write the ledger entry (that happens at complete_refund(),
     * §below) — processing is "money is in flight," not "confirmed
     * landed," which matters if the admin wants to record intent
     * before reconciling later the same day.
     */
    function mark_refund_processing(PDO $db, int $refundId, int $adminId, string $refundReference): array
    {
        if (trim($refundReference) === '') {
            return ['ok' => false, 'error' => 'reference_required'];
        }

        $stmt = $db->prepare("SELECT * FROM refunds WHERE id = :id AND status = 'approved' LIMIT 1");
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE refunds SET status = 'processing', processing_at = NOW(), admin_id = :aid, refund_reference = :ref WHERE id = :id"
        );
        $upd->execute(['aid' => $adminId, 'ref' => trim($refundReference), 'id' => $refundId]);

        write_audit_log('admin', $adminId, 'refund_processing', ['refund_id' => $refundId, 'order_id' => (int) $refund['order_id'], 'reference' => $refundReference]);

        return ['ok' => true];
    }
}

if (!function_exists('complete_refund')) {
    /**
     * Terminal success step — admin confirms the transfer landed
     * (checked against their own bank/UPI statement, same procedural
     * trust model doc 23 §5/§A5 already documents for the payment
     * side; there's no automatic bank confirmation here either).
     * Writes the platform_ledger 'refund_out' entry, flips
     * payment_transactions.status to 'refunded' if one is linked, and
     * flips orders.payment_status to 'refunded'. Does NOT touch
     * orders.status — that still correctly reflects WHY the order
     * ended (cancelled vs rejected); payment_status independently
     * tracks the money.
     *
     * Manual-transfer path only (refunds.method = 'manual_upi_bank_
     * transfer') — requires 'processing' state (a reference must
     * already be on file). A wallet-method refund uses
     * complete_refund_to_wallet() below instead, which skips
     * 'processing' entirely since there's no external transfer to be
     * "in flight."
     */
    function complete_refund(PDO $db, int $refundId, int $adminId): array
    {
        $stmt = $db->prepare(
            "SELECT r.*, o.order_code, o.grand_total AS order_grand_total, o.restaurant_id
             FROM refunds r JOIN orders o ON o.id = r.order_id
             WHERE r.id = :id AND r.status = 'processing' AND r.method = 'manual_upi_bank_transfer' LIMIT 1"
        );
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $db->prepare("UPDATE refunds SET status = 'refunded', refunded_at = NOW(), admin_id = :aid WHERE id = :id")
                ->execute(['aid' => $adminId, 'id' => $refundId]);

            $db->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = :id")
                ->execute(['id' => (int) $refund['order_id']]);

            if ($refund['payment_transaction_id'] !== null) {
                $db->prepare("UPDATE payment_transactions SET status = 'refunded' WHERE id = :id AND status = 'success'")
                    ->execute(['id' => (int) $refund['payment_transaction_id']]);
            }

            write_platform_ledger_entry(
                $db, 'refund_out', -(float) $refund['amount'], (int) $refund['restaurant_id'], (int) $refund['order_id'], null,
                'Refund for order ' . $refund['order_code'] . ' (ref ' . $refund['refund_reference'] . ')', 'admin', $adminId
            );

            write_audit_log('admin', $adminId, 'refund_completed', [
                'refund_id' => $refundId,
                'order_id' => (int) $refund['order_id'],
                'amount' => (float) $refund['amount'],
                'reference' => $refund['refund_reference'],
            ]);

            create_notification(
                'customer', (int) $refund['customer_id'], 'Refund completed',
                'Your refund of ₹' . $refund['amount'] . ' for order ' . $refund['order_code'] . ' has been sent.',
                'order', ['order_id' => (int) $refund['order_id'], 'refund_id' => $refundId, 'screen' => 'order_status']
            );

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('complete_refund_to_wallet')) {
    /**
     * item 15's reserved wallet-refund path (migration 42's `method`
     * ENUM already includes 'wallet'; nothing wrote it until now).
     * Admin action from the 'approved' state — deliberately skips
     * 'processing' entirely, unlike complete_refund()'s manual-
     * transfer path: 'processing' exists to record "money is in
     * flight" for an OUTSIDE transfer the admin performs by hand, but
     * a wallet credit is instant and happens inside this same
     * transaction, so there is no in-flight window to represent.
     *
     * Does NOT write a platform_ledger 'refund_out' entry the way
     * complete_refund() does — that entry represents money actually
     * leaving the platform's bank account, which a wallet credit
     * doesn't do; the money stays on the platform's books, just moves
     * from "owed back to this customer in cash" into "sitting in this
     * customer's wallet balance." credit_wallet() itself is the
     * complete record of that movement (wallet_transactions row +
     * customer_wallets.balance), same way debit_wallet_for_order()
     * needs no separate ledger entry for the wallet side of a wallet
     * order payment.
     *
     * refund_reference is set to a synthetic "WALLET-CREDIT-{txn_id}"
     * marker (not a real UTR — there is no external transfer) so the
     * admin UI's existing reference column still shows something
     * traceable back to the actual wallet_transactions row, rather
     * than a blank the manual-transfer path would never otherwise
     * leave.
     */
    function complete_refund_to_wallet(PDO $db, int $refundId, int $adminId): array
    {
        $stmt = $db->prepare(
            "SELECT r.*, o.order_code, o.grand_total AS order_grand_total, o.restaurant_id
             FROM refunds r JOIN orders o ON o.id = r.order_id
             WHERE r.id = :id AND r.status = 'approved' AND r.method = 'wallet' LIMIT 1"
        );
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $creditResult = credit_wallet(
                $db,
                (int) $refund['customer_id'],
                (float) $refund['amount'],
                'refund',
                (int) $refund['order_id'],
                'Refund for order ' . $refund['order_code'],
                'admin',
                $adminId
            );
            $reference = 'WALLET-CREDIT-' . $creditResult['txn_id'];

            $db->prepare(
                "UPDATE refunds SET status = 'refunded', refunded_at = NOW(), admin_id = :aid, refund_reference = :ref WHERE id = :id"
            )->execute(['aid' => $adminId, 'ref' => $reference, 'id' => $refundId]);

            $db->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = :id")
                ->execute(['id' => (int) $refund['order_id']]);

            if ($refund['payment_transaction_id'] !== null) {
                $db->prepare("UPDATE payment_transactions SET status = 'refunded' WHERE id = :id AND status = 'success'")
                    ->execute(['id' => (int) $refund['payment_transaction_id']]);
            }

            write_audit_log('admin', $adminId, 'refund_completed_to_wallet', [
                'refund_id' => $refundId,
                'order_id' => (int) $refund['order_id'],
                'amount' => (float) $refund['amount'],
                'wallet_txn_id' => $creditResult['txn_id'],
            ]);

            // credit_wallet() already sends its own "Wallet credited"
            // notification — a second "Refund completed" notification
            // here (as complete_refund()'s manual path sends) would be
            // redundant/confusing for the same money movement, so this
            // path deliberately sends none of its own.

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'wallet_txn_id' => $creditResult['txn_id']];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('reject_refund')) {
    function reject_refund(PDO $db, int $refundId, int $adminId, string $reason): array
    {
        if (trim($reason) === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        $stmt = $db->prepare("SELECT * FROM refunds WHERE id = :id AND status IN ('requested','under_review','approved') LIMIT 1");
        $stmt->execute(['id' => $refundId]);
        $refund = $stmt->fetch();
        if (!$refund) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $db->prepare(
            "UPDATE refunds SET status = 'rejected', rejected_at = NOW(), admin_id = :aid, reject_reason = :reason WHERE id = :id"
        )->execute(['aid' => $adminId, 'reason' => trim($reason), 'id' => $refundId]);

        write_audit_log('admin', $adminId, 'refund_rejected', ['refund_id' => $refundId, 'order_id' => (int) $refund['order_id'], 'reason' => $reason]);

        create_notification(
            'customer', (int) $refund['customer_id'], 'Refund request rejected',
            'Your refund request for order was reviewed and rejected: ' . trim($reason),
            'order', ['order_id' => (int) $refund['order_id'], 'refund_id' => $refundId, 'screen' => 'order_status']
        );

        return ['ok' => true];
    }
}
