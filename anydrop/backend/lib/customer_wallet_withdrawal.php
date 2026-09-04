<?php
/**
 * Anydrop — Customer Wallet Withdrawal (app owner request, 2026-08-30;
 * migration 65). Same "one function everyone calls" shape as
 * lib/wallet.php/lib/refunds.php.
 *
 * Two concerns live here:
 *   1. customer_bank_details — the customer's saved payout details
 *      (same shape as lib/restaurant_bank.php's restaurant version,
 *      duplicated rather than shared because the two are validated/
 *      displayed to different actors with different rules — see
 *      validate_wallet_payout_fields()'s own kdoc for the one real
 *      difference: bank fields are optional here since UPI-only is a
 *      valid payout method).
 *   2. wallet_withdrawals — the actual withdrawal request lifecycle.
 *      request_wallet_withdrawal() is the only place a withdrawal is
 *      created, and it ALWAYS debits the wallet immediately via the
 *      existing row-locked debit_wallet() (lib/wallet.php) before the
 *      request row exists — see migration 65's own header for why an
 *      up-front hold, not a hold-at-approval-time, is the safe design
 *      (closes the double-spend window a later hold would leave open).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

if (!function_exists('validate_wallet_payout_fields')) {
    /**
     * $payoutMethod is 'bank' or 'upi' — decides which fields are
     * required. Same loose-format IFSC/account-number checks
     * lib/restaurant_bank.php's validate_bank_fields() uses (this
     * container has no live bank-registry lookup either way); kept as
     * a separate function rather than a shared import because the two
     * call sites validate different actors' input under different
     * required-field rules (a restaurant always needs full bank
     * details; a customer withdrawal can be UPI-only with no bank
     * fields at all).
     */
    function validate_wallet_payout_fields(
        string $payoutMethod,
        string $accountHolderName,
        ?string $bankName,
        ?string $accountNumber,
        ?string $ifscCode,
        ?string $upiId
    ): array {
        if (!in_array($payoutMethod, ['bank', 'upi'], true)) {
            respond_error('validation_error', 422, ['fields' => ['payout_method']]);
        }

        $accountHolderName = trim($accountHolderName);
        $bankName = $bankName !== null ? trim($bankName) : null;
        $accountNumber = $accountNumber !== null ? trim($accountNumber) : null;
        $ifscCode = $ifscCode !== null ? strtoupper(trim($ifscCode)) : null;
        $upiId = $upiId !== null ? trim($upiId) : null;

        $invalidFields = [];

        if ($accountHolderName === '' || mb_strlen($accountHolderName) > 100) {
            $invalidFields[] = 'account_holder_name';
        }

        if ($payoutMethod === 'bank') {
            if ($bankName === null || $bankName === '' || mb_strlen($bankName) > 100) {
                $invalidFields[] = 'bank_name';
            }
            if ($accountNumber === null || !preg_match('/^[0-9]{9,18}$/', $accountNumber)) {
                $invalidFields[] = 'account_number';
            }
            if ($ifscCode === null || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscCode)) {
                $invalidFields[] = 'ifsc_code';
            }
            $upiId = null; // ignore any stray upi_id on a bank-method request
        } else { // 'upi'
            if ($upiId === null || $upiId === '' || !preg_match('/^[\w.\-]{2,256}@[\w]{2,64}$/', $upiId)) {
                $invalidFields[] = 'upi_id';
            }
            $bankName = null;
            $accountNumber = null;
            $ifscCode = null;
        }

        if (!empty($invalidFields)) {
            respond_error('validation_error', 422, ['fields' => $invalidFields]);
        }

        return [$accountHolderName, $bankName, $accountNumber, $ifscCode, $upiId];
    }
}

if (!function_exists('save_customer_bank_details')) {
    function save_customer_bank_details(
        PDO $db,
        int $customerId,
        string $accountHolderName,
        ?string $bankName,
        ?string $accountNumber,
        ?string $ifscCode,
        ?string $upiId
    ): array {
        $stmt = $db->prepare(
            'INSERT INTO customer_bank_details
                (customer_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id)
             VALUES
                (:cid, :holder, :bank, :acc, :ifsc, :upi)
             ON DUPLICATE KEY UPDATE
                account_holder_name = :holder2, bank_name = :bank2, account_number = :acc2, ifsc_code = :ifsc2, upi_id = :upi2'
        );
        $stmt->execute([
            'cid' => $customerId,
            'holder' => $accountHolderName, 'bank' => $bankName, 'acc' => $accountNumber, 'ifsc' => $ifscCode, 'upi' => $upiId,
            'holder2' => $accountHolderName, 'bank2' => $bankName, 'acc2' => $accountNumber, 'ifsc2' => $ifscCode, 'upi2' => $upiId,
        ]);

        write_audit_log('customer', $customerId, 'wallet_bank_details_saved', [
            'customer_id' => $customerId,
            'has_bank' => $accountNumber !== null,
            'has_upi' => $upiId !== null,
            'account_number_last4' => $accountNumber !== null ? substr($accountNumber, -4) : null,
        ]);

        return get_customer_bank_details($db, $customerId);
    }
}

if (!function_exists('get_customer_bank_details')) {
    function get_customer_bank_details(PDO $db, int $customerId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM customer_bank_details WHERE customer_id = :cid LIMIT 1');
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('serialize_customer_bank_details')) {
    /** Masked, same "don't echo sensitive data back on every read" reasoning as restaurant_bank.php's version. */
    function serialize_customer_bank_details(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $accountNumber = $row['account_number'] !== null ? (string) $row['account_number'] : null;
        $masked = $accountNumber !== null
            ? (strlen($accountNumber) > 4 ? str_repeat('X', strlen($accountNumber) - 4) . substr($accountNumber, -4) : $accountNumber)
            : null;

        return [
            'account_holder_name' => $row['account_holder_name'],
            'bank_name' => $row['bank_name'],
            'account_number_masked' => $masked,
            'ifsc_code' => $row['ifsc_code'],
            'upi_id' => $row['upi_id'],
            'updated_at' => $row['updated_at'],
        ];
    }
}

if (!function_exists('request_wallet_withdrawal')) {
    /**
     * The only entry point that creates a wallet_withdrawals row.
     * Debits the wallet FIRST (see migration 65's header for why),
     * using the exact same debit_wallet() every other wallet debit in
     * this codebase already trusts for its row-locked balance check —
     * no new locking logic written here.
     *
     * Returns ['ok' => false, 'error' => 'insufficient_balance', ...]
     * on a balance that can't cover the request (checked, not
     * assumed — debit_wallet()'s own SELECT ... FOR UPDATE is the real
     * guard against a race with a concurrent order/withdrawal, not
     * this function's own no-op pre-check-free call).
     */
    function request_wallet_withdrawal(
        PDO $db,
        int $customerId,
        float $amount,
        string $payoutMethod,
        string $accountHolderName,
        ?string $bankName,
        ?string $accountNumber,
        ?string $ifscCode,
        ?string $upiId
    ): array {
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'invalid_amount'];
        }
        $minAmount = (float) get_setting('wallet_withdrawal_min_amount', 100);
        if ($minAmount > 0 && $amount < $minAmount) {
            return ['ok' => false, 'error' => 'below_minimum_amount', 'minimum' => $minAmount];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $debitResult = debit_wallet(
                $db, $customerId, $amount, 'withdrawal', null,
                'Wallet withdrawal request', 'system', null
            );

            if (!$debitResult['ok']) {
                // debit_wallet() only rolls back a transaction it opened
                // itself (its own $ownTransaction) — since we opened
                // ours above, insufficient balance leaves our
                // transaction open and untouched, so it's rolled back
                // here instead.
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return $debitResult;
            }

            $ins = $db->prepare(
                'INSERT INTO wallet_withdrawals
                    (customer_id, wallet_debit_txn_id, amount, payout_method, account_holder_name, bank_name, account_number, ifsc_code, upi_id, status)
                 VALUES
                    (:cid, :txnid, :amount, :method, :holder, :bank, :acc, :ifsc, :upi, \'requested\')'
            );
            $ins->execute([
                'cid' => $customerId,
                'txnid' => $debitResult['txn_id'],
                'amount' => $amount,
                'method' => $payoutMethod,
                'holder' => $accountHolderName,
                'bank' => $bankName,
                'acc' => $accountNumber,
                'ifsc' => $ifscCode,
                'upi' => $upiId,
            ]);
            $withdrawalId = (int) $db->lastInsertId();

            write_audit_log('customer', $customerId, 'wallet_withdrawal_requested', [
                'withdrawal_id' => $withdrawalId,
                'amount' => $amount,
                'payout_method' => $payoutMethod,
                'wallet_txn_id' => $debitResult['txn_id'],
            ]);

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'withdrawal_id' => $withdrawalId, 'balance' => $debitResult['balance']];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('list_wallet_withdrawals_for_customer')) {
    function list_wallet_withdrawals_for_customer(PDO $db, int $customerId, int $limit = 50): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM wallet_withdrawals WHERE customer_id = :cid ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

if (!function_exists('admin_list_wallet_withdrawals')) {
    /** @param string|null $statusFilter One status, or null for everything not yet terminal. */
    function admin_list_wallet_withdrawals(PDO $db, ?string $statusFilter = null): array
    {
        if ($statusFilter !== null) {
            $stmt = $db->prepare(
                'SELECT w.*, c.name AS customer_name, c.mobile AS customer_phone
                 FROM wallet_withdrawals w
                 JOIN customers c ON c.id = w.customer_id
                 WHERE w.status = :status
                 ORDER BY w.requested_at ASC'
            );
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt = $db->query(
                "SELECT w.*, c.name AS customer_name, c.mobile AS customer_phone
                 FROM wallet_withdrawals w
                 JOIN customers c ON c.id = w.customer_id
                 WHERE w.status IN ('requested','approved','processing')
                 ORDER BY w.requested_at ASC"
            );
        }
        return $stmt->fetchAll();
    }
}

if (!function_exists('approve_wallet_withdrawal')) {
    function approve_wallet_withdrawal(PDO $db, int $withdrawalId, int $adminId): array
    {
        $stmt = $db->prepare("SELECT * FROM wallet_withdrawals WHERE id = :id AND status = 'requested' LIMIT 1");
        $stmt->execute(['id' => $withdrawalId]);
        $w = $stmt->fetch();
        if (!$w) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE wallet_withdrawals SET status = 'approved', approved_at = NOW(), admin_id = :aid WHERE id = :id AND status = 'requested'"
        );
        $upd->execute(['aid' => $adminId, 'id' => $withdrawalId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        write_audit_log('admin', $adminId, 'wallet_withdrawal_approved', ['withdrawal_id' => $withdrawalId, 'customer_id' => (int) $w['customer_id']]);

        create_notification(
            'customer', (int) $w['customer_id'], 'Withdrawal approved',
            'Your wallet withdrawal request of ₹' . $w['amount'] . ' has been approved and is being processed.',
            'wallet', ['withdrawal_id' => $withdrawalId, 'screen' => 'wallet']
        );

        return ['ok' => true];
    }
}

if (!function_exists('mark_wallet_withdrawal_processing')) {
    function mark_wallet_withdrawal_processing(PDO $db, int $withdrawalId, int $adminId, string $reference): array
    {
        if (trim($reference) === '') {
            return ['ok' => false, 'error' => 'reference_required'];
        }

        $stmt = $db->prepare("SELECT * FROM wallet_withdrawals WHERE id = :id AND status = 'approved' LIMIT 1");
        $stmt->execute(['id' => $withdrawalId]);
        $w = $stmt->fetch();
        if (!$w) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE wallet_withdrawals SET status = 'processing', processing_at = NOW(), admin_id = :aid, payout_reference = :ref WHERE id = :id AND status = 'approved'"
        );
        $upd->execute(['aid' => $adminId, 'ref' => trim($reference), 'id' => $withdrawalId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        write_audit_log('admin', $adminId, 'wallet_withdrawal_processing', ['withdrawal_id' => $withdrawalId, 'customer_id' => (int) $w['customer_id'], 'reference' => $reference]);

        return ['ok' => true];
    }
}

if (!function_exists('complete_wallet_withdrawal')) {
    /**
     * Terminal success step — admin confirms the transfer landed
     * (same procedural trust model as complete_refund()/
     * settlements.php's Pay Now flow — no automatic bank
     * confirmation exists). The wallet balance was already reduced at
     * REQUEST time (see request_wallet_withdrawal()); this step does
     * not touch the wallet again — it only writes the platform_ledger
     * 'wallet_withdrawal_out' entry recording that real money left the
     * platform's bank account, and flips this row to 'completed'.
     */
    function complete_wallet_withdrawal(PDO $db, int $withdrawalId, int $adminId): array
    {
        $stmt = $db->prepare("SELECT * FROM wallet_withdrawals WHERE id = :id AND status = 'processing' LIMIT 1");
        $stmt->execute(['id' => $withdrawalId]);
        $w = $stmt->fetch();
        if (!$w) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $upd = $db->prepare(
                "UPDATE wallet_withdrawals SET status = 'completed', completed_at = NOW(), admin_id = :aid WHERE id = :id AND status = 'processing'"
            );
            $upd->execute(['aid' => $adminId, 'id' => $withdrawalId]);
            if ($upd->rowCount() === 0) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
            }

            write_platform_ledger_entry(
                $db, 'wallet_withdrawal_out', -(float) $w['amount'], null, null, null,
                'Wallet withdrawal payout for customer #' . $w['customer_id'] . ' (ref ' . $w['payout_reference'] . ')',
                'admin', $adminId
            );

            write_audit_log('admin', $adminId, 'wallet_withdrawal_completed', [
                'withdrawal_id' => $withdrawalId,
                'customer_id' => (int) $w['customer_id'],
                'amount' => (float) $w['amount'],
                'reference' => $w['payout_reference'],
            ]);

            create_notification(
                'customer', (int) $w['customer_id'], 'Withdrawal completed',
                'Your wallet withdrawal of ₹' . $w['amount'] . ' has been sent.',
                'wallet', ['withdrawal_id' => $withdrawalId, 'screen' => 'wallet']
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

if (!function_exists('reject_wallet_withdrawal')) {
    /**
     * Only valid from 'requested' or 'approved' — NOT from
     * 'processing', since by then the admin has already sent real
     * money externally (same reasoning refund_reject can't happen
     * after a refund reaches 'processing' either). Reverses the
     * up-front hold by crediting the wallet back — the customer's
     * balance ends up exactly where it was before the request, as if
     * it never happened, other than the audit/history trail.
     */
    function reject_wallet_withdrawal(PDO $db, int $withdrawalId, int $adminId, string $reason): array
    {
        if (trim($reason) === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        $stmt = $db->prepare("SELECT * FROM wallet_withdrawals WHERE id = :id AND status IN ('requested','approved') LIMIT 1");
        $stmt->execute(['id' => $withdrawalId]);
        $w = $stmt->fetch();
        if (!$w) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $upd = $db->prepare(
                "UPDATE wallet_withdrawals SET status = 'rejected', rejected_at = NOW(), admin_id = :aid, reject_reason = :reason WHERE id = :id AND status IN ('requested','approved')"
            );
            $upd->execute(['aid' => $adminId, 'reason' => trim($reason), 'id' => $withdrawalId]);
            if ($upd->rowCount() === 0) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
            }

            credit_wallet(
                $db, (int) $w['customer_id'], (float) $w['amount'], 'withdrawal', null,
                'Withdrawal request rejected: ' . trim($reason), 'admin', $adminId
            );

            write_audit_log('admin', $adminId, 'wallet_withdrawal_rejected', [
                'withdrawal_id' => $withdrawalId,
                'customer_id' => (int) $w['customer_id'],
                'amount' => (float) $w['amount'],
                'reason' => $reason,
            ]);

            // credit_wallet() already sends its own "Wallet credited"
            // notification — that, plus this one, tells the customer
            // both WHY (rejected) and THAT the money is back, same
            // two-notification shape reject_refund() doesn't need
            // (a rejected refund never took money out of the wallet
            // to begin with) but this case does.
            create_notification(
                'customer', (int) $w['customer_id'], 'Withdrawal rejected',
                'Your wallet withdrawal request was rejected: ' . trim($reason) . '. The amount has been credited back to your wallet.',
                'wallet', ['withdrawal_id' => $withdrawalId, 'screen' => 'wallet']
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
