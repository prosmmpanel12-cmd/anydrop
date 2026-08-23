<?php
/**
 * Anydrop — Customer Wallet (recall.md item 26, section 18; doc 19
 * §3/§12). Migration 43.
 *
 * Same "one function everyone calls" shape as lib/ledger.php and
 * lib/refunds.php — every wallet balance change goes through this
 * file so customer_wallets.balance and its wallet_transactions
 * ledger row can never drift apart the way a separate inline
 * UPDATE customer_wallets at each call site would risk. Every
 * function here either returns a definite result or throws; nothing
 * silently no-ops on a race the way a bare UPDATE could.
 *
 * IMPORTANT — locking: every write acquires the wallet row with
 * SELECT ... FOR UPDATE first (get_or_create_wallet()'s $forUpdate
 * flag) and expects to run inside a transaction the caller already
 * opened, OR opens its own if none is open (same $ownTransaction
 * pattern as lib/refunds.php's complete_refund()). This matters here
 * specifically because two concurrent credits/debits on the same
 * wallet (e.g. a refund landing at the same moment as a wallet-order
 * debit) must serialize, not both read the same stale balance and
 * both write from it — the exact race bugs.md #1.3 already
 * documents for coupon usage, same shape here.
 *
 * SPLIT-PAYMENT NOTE: v1 has no partial-wallet-plus-UPI model — see
 * migration 43's header. debit_wallet_for_order() either covers a
 * whole order or refuses (insufficient balance); it never debits a
 * partial amount.
 *
 * CASHBACK EXPIRY: wallet_cashback_expiry_days (app_settings) is
 * stored so a future admin screen or scheduled job can enforce it,
 * but nothing in this file currently expires a credit — that needs
 * a cron-style entry point this sandbox can't build or run (no
 * scheduler exists in this codebase yet for any feature). Flagged
 * here rather than silently assumed working.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('get_or_create_wallet')) {
    /**
     * Returns the customer_wallets row, creating it (balance 0) on
     * first touch — a customer's wallet is implicit until the first
     * credit/debit, no separate "activate wallet" step. Pass
     * $forUpdate = true from inside a transaction immediately before
     * a balance-changing write, to lock the row for the rest of that
     * transaction (see class kdoc on why this matters).
     */
    function get_or_create_wallet(PDO $db, int $customerId, bool $forUpdate = false): array
    {
        $sql = 'SELECT * FROM customer_wallets WHERE customer_id = :cid' . ($forUpdate ? ' FOR UPDATE' : '') . ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        // Not found — create it. A concurrent first-touch by another
        // request could race here; INSERT IGNORE + re-select handles
        // that the same way get_effective_cod_rule()-style "create if
        // missing" helpers elsewhere in this codebase do.
        $db->prepare('INSERT IGNORE INTO customer_wallets (customer_id, balance) VALUES (:cid, 0.00)')
            ->execute(['cid' => $customerId]);

        $stmt = $db->prepare($sql);
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : ['customer_id' => $customerId, 'balance' => 0.00];
    }
}

if (!function_exists('get_wallet_balance')) {
    function get_wallet_balance(PDO $db, int $customerId): float
    {
        return (float) get_or_create_wallet($db, $customerId)['balance'];
    }
}

if (!function_exists('list_wallet_transactions')) {
    /** Most recent first — same convention as order_status_history/admin lists elsewhere. */
    function list_wallet_transactions(PDO $db, int $customerId, int $limit = 50): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM wallet_transactions WHERE customer_id = :cid ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

if (!function_exists('credit_wallet')) {
    /**
     * Adds money to a customer's wallet. $reason must be one of
     * wallet_transactions.reason's ENUM values. $orderId/$adminId are
     * both optional and mutually informative (a 'refund' credit sets
     * $orderId, an 'admin_adjustment' sets $adminId — not enforced
     * here, callers pass what applies to their own trigger).
     *
     * Runs inside the caller's already-open transaction if one exists
     * (checked via $db->inTransaction()), otherwise opens its own —
     * same $ownTransaction pattern as lib/refunds.php's
     * complete_refund(), so a caller like refund-to-wallet (future
     * hookup, see lib/refunds.php's method='wallet' TODO) can credit
     * the wallet as one atomic step alongside its own refunds-table
     * UPDATE without this function's commit/rollback stepping on it.
     */
    function credit_wallet(
        PDO $db,
        int $customerId,
        float $amount,
        string $reason,
        ?int $orderId = null,
        ?string $note = null,
        string $createdBy = 'system',
        ?int $adminId = null
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount_must_be_positive');
        }
        if (!in_array($reason, ['refund', 'admin_adjustment', 'cashback', 'order_payment'], true)) {
            throw new InvalidArgumentException('invalid_reason');
        }
        if (!in_array($createdBy, ['system', 'admin'], true)) {
            throw new InvalidArgumentException('invalid_created_by');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $wallet = get_or_create_wallet($db, $customerId, true);
            $newBalance = round((float) $wallet['balance'] + $amount, 2);

            $db->prepare('UPDATE customer_wallets SET balance = :bal WHERE customer_id = :cid')
                ->execute(['bal' => $newBalance, 'cid' => $customerId]);

            $ins = $db->prepare(
                'INSERT INTO wallet_transactions
                    (customer_id, order_id, type, amount, reason, note, balance_after, created_by, admin_id)
                 VALUES
                    (:cid, :oid, \'credit\', :amount, :reason, :note, :bal, :by, :aid)'
            );
            $ins->execute([
                'cid' => $customerId,
                'oid' => $orderId,
                'amount' => $amount,
                'reason' => $reason,
                'note' => $note,
                'bal' => $newBalance,
                'by' => $createdBy,
                'aid' => $adminId,
            ]);
            $txnId = (int) $db->lastInsertId();

            if ($createdBy === 'admin') {
                write_audit_log('admin', $adminId, 'wallet_credit', [
                    'customer_id' => $customerId, 'amount' => $amount, 'reason' => $reason, 'txn_id' => $txnId,
                ]);
            }

            create_notification(
                'customer', $customerId, 'Wallet credited',
                '₹' . number_format($amount, 2) . ' added to your Anydrop Wallet' . ($note ? " ($note)" : '') . '.',
                'wallet', ['txn_id' => $txnId, 'screen' => 'wallet']
            );

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'txn_id' => $txnId, 'balance' => $newBalance];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('debit_wallet')) {
    /**
     * Removes money from a customer's wallet. Fails cleanly (does not
     * throw) on insufficient balance — callers should check
     * `['ok']` rather than relying on an exception, since "not enough
     * balance" is an expected, common outcome (e.g. a wallet-order
     * attempt), not a programming error.
     */
    function debit_wallet(
        PDO $db,
        int $customerId,
        float $amount,
        string $reason,
        ?int $orderId = null,
        ?string $note = null,
        string $createdBy = 'system',
        ?int $adminId = null
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount_must_be_positive');
        }
        if (!in_array($reason, ['refund', 'admin_adjustment', 'cashback', 'order_payment'], true)) {
            throw new InvalidArgumentException('invalid_reason');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $wallet = get_or_create_wallet($db, $customerId, true);
            $currentBalance = (float) $wallet['balance'];

            if ($currentBalance < $amount) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'insufficient_balance', 'balance' => $currentBalance];
            }

            $newBalance = round($currentBalance - $amount, 2);

            $db->prepare('UPDATE customer_wallets SET balance = :bal WHERE customer_id = :cid')
                ->execute(['bal' => $newBalance, 'cid' => $customerId]);

            $ins = $db->prepare(
                'INSERT INTO wallet_transactions
                    (customer_id, order_id, type, amount, reason, note, balance_after, created_by, admin_id)
                 VALUES
                    (:cid, :oid, \'debit\', :amount, :reason, :note, :bal, :by, :aid)'
            );
            $ins->execute([
                'cid' => $customerId,
                'oid' => $orderId,
                'amount' => $amount,
                'reason' => $reason,
                'note' => $note,
                'bal' => $newBalance,
                'by' => $createdBy,
                'aid' => $adminId,
            ]);
            $txnId = (int) $db->lastInsertId();

            if ($createdBy === 'admin') {
                write_audit_log('admin', $adminId, 'wallet_debit', [
                    'customer_id' => $customerId, 'amount' => $amount, 'reason' => $reason, 'txn_id' => $txnId,
                ]);
            }

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'txn_id' => $txnId, 'balance' => $newBalance];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('debit_wallet_for_order')) {
    /**
     * Wired as of item 26 §D.12 — orders/create.php's 'wallet' branch
     * calls this inside its insert transaction (the authoritative
     * row-locked check via this function -> debit_wallet()'s
     * SELECT ... FOR UPDATE; orders/create.php's own pre-check against
     * get_wallet_balance() is only a fail-fast convenience, not the
     * real guard). Whole-order-amount debit, refused if the balance
     * can't cover the full amount (no partial-wallet model).
     */
    function debit_wallet_for_order(PDO $db, int $customerId, int $orderId, float $orderAmount): array
    {
        return debit_wallet($db, $customerId, $orderAmount, 'order_payment', $orderId, null, 'system');
    }
}
