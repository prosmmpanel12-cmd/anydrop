<?php
/**
 * Anydrop — Rider Self-Service Payout Requests (deep-plan §21,
 * migration 74). Same "one function everyone calls" shape as
 * lib/customer_wallet_withdrawal.php, which this file mirrors closely
 * — see migration 74's own header for the full reasoning on why the
 * design (up-front balance hold, snapshot payout details, admin
 * lifecycle) is copied rather than reinvented.
 *
 * Two concerns live here:
 *   1. rider_bank_details — the rider's saved payout details (same
 *      shape as customer_bank_details, duplicated rather than shared
 *      for the same reason that file gives — different actor,
 *      independently validated).
 *   2. rider_payout_requests — the actual request lifecycle.
 *      request_rider_payout() is the only place a request is created,
 *      and it ALWAYS debits riders.earnings_balance immediately via
 *      lib/rider_earnings.php's existing row-locked
 *      write_rider_earnings_ledger_entry() before the request row
 *      exists — see migration 74's header for why an up-front hold,
 *      not a hold-at-approval-time, is the safe design.
 *
 * Deliberately does NOT touch lib/rider_earnings.php's
 * record_rider_payout() (the admin's own direct "I paid this rider
 * out" button on admin/rider-earnings.php) — that manual flow and this
 * self-service request flow both write the same entry_type='payout'
 * ledger rows and both correctly update the same earnings_balance
 * column, but they are two independent entry points into the same
 * ledger, same as this file's customer-side equivalent coexists with
 * admin-initiated wallet adjustments.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/rider_earnings.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ledger.php';

if (!function_exists('validate_rider_payout_fields')) {
    /**
     * $payoutMethod is 'bank' or 'upi' — decides which fields are
     * required. Same loose-format IFSC/account-number checks
     * lib/customer_wallet_withdrawal.php's validate_wallet_payout_fields()
     * uses (this container has no live bank-registry lookup either
     * way) — deliberately not shared/imported from that file since the
     * two validate different actors' input, even though the rules
     * happen to be identical today; a future divergence (e.g. a
     * rider-only minimum account-number length) shouldn't have to
     * touch the customer-side function.
     */
    function validate_rider_payout_fields(
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

if (!function_exists('save_rider_bank_details')) {
    function save_rider_bank_details(
        PDO $db,
        int $riderId,
        string $accountHolderName,
        ?string $bankName,
        ?string $accountNumber,
        ?string $ifscCode,
        ?string $upiId
    ): array {
        $stmt = $db->prepare(
            'INSERT INTO rider_bank_details
                (rider_id, account_holder_name, bank_name, account_number, ifsc_code, upi_id)
             VALUES
                (:rid, :holder, :bank, :acc, :ifsc, :upi)
             ON DUPLICATE KEY UPDATE
                account_holder_name = :holder2, bank_name = :bank2, account_number = :acc2, ifsc_code = :ifsc2, upi_id = :upi2'
        );
        $stmt->execute([
            'rid' => $riderId,
            'holder' => $accountHolderName, 'bank' => $bankName, 'acc' => $accountNumber, 'ifsc' => $ifscCode, 'upi' => $upiId,
            'holder2' => $accountHolderName, 'bank2' => $bankName, 'acc2' => $accountNumber, 'ifsc2' => $ifscCode, 'upi2' => $upiId,
        ]);

        write_audit_log('rider', $riderId, 'rider_bank_details_saved', [
            'rider_id' => $riderId,
            'has_bank' => $accountNumber !== null,
            'has_upi' => $upiId !== null,
            'account_number_last4' => $accountNumber !== null ? substr($accountNumber, -4) : null,
        ]);

        return get_rider_bank_details($db, $riderId);
    }
}

if (!function_exists('get_rider_bank_details')) {
    function get_rider_bank_details(PDO $db, int $riderId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM rider_bank_details WHERE rider_id = :rid LIMIT 1');
        $stmt->execute(['rid' => $riderId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}

if (!function_exists('serialize_rider_bank_details')) {
    /** Masked, same "don't echo sensitive data back on every read" reasoning as the customer/restaurant versions. */
    function serialize_rider_bank_details(?array $row): ?array
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

if (!function_exists('rider_payout_min_amount')) {
    function rider_payout_min_amount(): float
    {
        return max(0.0, (float) get_setting('rider_payout_min_amount', 100));
    }
}

if (!function_exists('request_rider_payout')) {
    /**
     * The only entry point that creates a rider_payout_requests row.
     * Checks earnings_balance under a row lock FIRST (so a rider can
     * never request more than they're currently owed), then debits it
     * via write_rider_earnings_ledger_entry() (lib/rider_earnings.php)
     * — that function re-locks the same row inside the same
     * transaction, which is harmless (MySQL row locks don't conflict
     * with themselves within one transaction) and keeps this function
     * from duplicating that writer's own UPDATE+INSERT logic, same
     * "single write path so the ledger row and balance can't drift
     * apart" principle every writer in lib/rider_earnings.php already
     * follows.
     *
     * Returns ['ok' => false, 'error' => ...] on any failure — never
     * throws for an ordinary insufficient-balance/below-minimum case.
     */
    function request_rider_payout(
        PDO $db,
        int $riderId,
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
        $minAmount = rider_payout_min_amount();
        if ($minAmount > 0 && $amount < $minAmount) {
            return ['ok' => false, 'error' => 'below_minimum_amount', 'minimum' => $minAmount];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $lockStmt = $db->prepare('SELECT earnings_balance FROM riders WHERE id = :id FOR UPDATE');
            $lockStmt->execute(['id' => $riderId]);
            $riderRow = $lockStmt->fetch();
            $currentBalance = $riderRow !== false ? (float) $riderRow['earnings_balance'] : 0.0;

            if ($currentBalance < $amount) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'insufficient_balance', 'balance' => $currentBalance];
            }

            $ledgerId = write_rider_earnings_ledger_entry(
                $db, $riderId, null, 'payout', -$amount,
                'Rider-requested payout — pending admin review', 'system'
            );

            $ins = $db->prepare(
                'INSERT INTO rider_payout_requests
                    (rider_id, earnings_debit_ledger_id, amount, payout_method, account_holder_name, bank_name, account_number, ifsc_code, upi_id, status)
                 VALUES
                    (:rid, :ledgerid, :amount, :method, :holder, :bank, :acc, :ifsc, :upi, \'requested\')'
            );
            $ins->execute([
                'rid' => $riderId,
                'ledgerid' => $ledgerId,
                'amount' => $amount,
                'method' => $payoutMethod,
                'holder' => $accountHolderName,
                'bank' => $bankName,
                'acc' => $accountNumber,
                'ifsc' => $ifscCode,
                'upi' => $upiId,
            ]);
            $requestId = (int) $db->lastInsertId();

            write_audit_log('rider', $riderId, 'rider_payout_requested', [
                'request_id' => $requestId,
                'amount' => $amount,
                'payout_method' => $payoutMethod,
                'earnings_ledger_id' => $ledgerId,
            ]);

            if ($ownTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'request_id' => $requestId, 'balance' => round($currentBalance - $amount, 2)];
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('list_rider_payout_requests_for_rider')) {
    function list_rider_payout_requests_for_rider(PDO $db, int $riderId, int $limit = 50): array
    {
        $stmt = $db->prepare(
            'SELECT * FROM rider_payout_requests WHERE rider_id = :rid ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':rid', $riderId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

if (!function_exists('admin_list_rider_payout_requests')) {
    /** @param string|null $statusFilter One status, or null for everything not yet terminal. */
    function admin_list_rider_payout_requests(PDO $db, ?string $statusFilter = null): array
    {
        if ($statusFilter !== null) {
            $stmt = $db->prepare(
                'SELECT p.*, r.name AS rider_name, r.mobile AS rider_mobile
                 FROM rider_payout_requests p
                 JOIN riders r ON r.id = p.rider_id
                 WHERE p.status = :status
                 ORDER BY p.requested_at ASC'
            );
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt = $db->query(
                "SELECT p.*, r.name AS rider_name, r.mobile AS rider_mobile
                 FROM rider_payout_requests p
                 JOIN riders r ON r.id = p.rider_id
                 WHERE p.status IN ('requested','approved','processing')
                 ORDER BY p.requested_at ASC"
            );
        }
        return $stmt->fetchAll();
    }
}

if (!function_exists('approve_rider_payout_request')) {
    function approve_rider_payout_request(PDO $db, int $requestId, int $adminId): array
    {
        $stmt = $db->prepare("SELECT * FROM rider_payout_requests WHERE id = :id AND status = 'requested' LIMIT 1");
        $stmt->execute(['id' => $requestId]);
        $p = $stmt->fetch();
        if (!$p) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE rider_payout_requests SET status = 'approved', approved_at = NOW(), admin_id = :aid WHERE id = :id AND status = 'requested'"
        );
        $upd->execute(['aid' => $adminId, 'id' => $requestId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        write_audit_log('admin', $adminId, 'rider_payout_approved', ['request_id' => $requestId, 'rider_id' => (int) $p['rider_id']]);

        create_notification(
            'rider', (int) $p['rider_id'], 'Payout approved',
            'Your payout request of ₹' . $p['amount'] . ' has been approved and is being processed.',
            'payout', ['request_id' => $requestId, 'screen' => 'earnings']
        );

        return ['ok' => true];
    }
}

if (!function_exists('mark_rider_payout_request_processing')) {
    function mark_rider_payout_request_processing(PDO $db, int $requestId, int $adminId, string $reference): array
    {
        if (trim($reference) === '') {
            return ['ok' => false, 'error' => 'reference_required'];
        }

        $stmt = $db->prepare("SELECT * FROM rider_payout_requests WHERE id = :id AND status = 'approved' LIMIT 1");
        $stmt->execute(['id' => $requestId]);
        $p = $stmt->fetch();
        if (!$p) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $upd = $db->prepare(
            "UPDATE rider_payout_requests SET status = 'processing', processing_at = NOW(), admin_id = :aid, payout_reference = :ref WHERE id = :id AND status = 'approved'"
        );
        $upd->execute(['aid' => $adminId, 'ref' => trim($reference), 'id' => $requestId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        write_audit_log('admin', $adminId, 'rider_payout_processing', ['request_id' => $requestId, 'rider_id' => (int) $p['rider_id'], 'reference' => $reference]);

        return ['ok' => true];
    }
}

if (!function_exists('complete_rider_payout_request')) {
    /**
     * Terminal success step — admin confirms the transfer landed (same
     * procedural trust model as complete_wallet_withdrawal() — no
     * automatic bank confirmation exists). The earnings balance was
     * already reduced at REQUEST time; this step does not touch it
     * again — it only writes the platform_ledger 'rider_payout_out'
     * entry recording that real money left the platform's bank
     * account, and flips this row to 'completed'.
     */
    function complete_rider_payout_request(PDO $db, int $requestId, int $adminId): array
    {
        $stmt = $db->prepare("SELECT * FROM rider_payout_requests WHERE id = :id AND status = 'processing' LIMIT 1");
        $stmt->execute(['id' => $requestId]);
        $p = $stmt->fetch();
        if (!$p) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $upd = $db->prepare(
                "UPDATE rider_payout_requests SET status = 'completed', completed_at = NOW(), admin_id = :aid WHERE id = :id AND status = 'processing'"
            );
            $upd->execute(['aid' => $adminId, 'id' => $requestId]);
            if ($upd->rowCount() === 0) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
            }

            write_platform_ledger_entry(
                $db, 'rider_payout_out', -(float) $p['amount'], null, null, null,
                'Rider payout for rider #' . $p['rider_id'] . ' (ref ' . $p['payout_reference'] . ')',
                'admin', $adminId
            );

            write_audit_log('admin', $adminId, 'rider_payout_completed', [
                'request_id' => $requestId,
                'rider_id' => (int) $p['rider_id'],
                'amount' => (float) $p['amount'],
                'reference' => $p['payout_reference'],
            ]);

            create_notification(
                'rider', (int) $p['rider_id'], 'Payout completed',
                'Your payout of ₹' . $p['amount'] . ' has been sent.',
                'payout', ['request_id' => $requestId, 'screen' => 'earnings']
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

if (!function_exists('reject_rider_payout_request')) {
    /**
     * Only valid from 'requested' or 'approved' — NOT from
     * 'processing', since by then the admin has already sent real
     * money externally (same reasoning reject_wallet_withdrawal()
     * uses). Reverses the up-front hold via
     * record_rider_earnings_adjustment()'s existing 'adjustment_credit'
     * entry type (lib/rider_earnings.php) rather than a bespoke
     * reversal write — the rider's balance ends up exactly where it
     * was before the request, as if it never happened, other than the
     * audit/ledger trail (which correctly shows both the hold and its
     * reversal as two distinct rows, same as every other ledger in
     * this codebase does for a reversed action).
     */
    function reject_rider_payout_request(PDO $db, int $requestId, int $adminId, string $reason): array
    {
        if (trim($reason) === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        $stmt = $db->prepare("SELECT * FROM rider_payout_requests WHERE id = :id AND status IN ('requested','approved') LIMIT 1");
        $stmt->execute(['id' => $requestId]);
        $p = $stmt->fetch();
        if (!$p) {
            return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $upd = $db->prepare(
                "UPDATE rider_payout_requests SET status = 'rejected', rejected_at = NOW(), admin_id = :aid, reject_reason = :reason WHERE id = :id AND status IN ('requested','approved')"
            );
            $upd->execute(['aid' => $adminId, 'reason' => trim($reason), 'id' => $requestId]);
            if ($upd->rowCount() === 0) {
                if ($ownTransaction) {
                    $db->rollBack();
                }
                return ['ok' => false, 'error' => 'not_found_or_wrong_state'];
            }

            record_rider_earnings_adjustment(
                $db, (int) $p['rider_id'], (float) $p['amount'], true, $adminId,
                'Payout request #' . $requestId . ' rejected: ' . trim($reason)
            );

            write_audit_log('admin', $adminId, 'rider_payout_rejected', [
                'request_id' => $requestId,
                'rider_id' => (int) $p['rider_id'],
                'amount' => (float) $p['amount'],
                'reason' => $reason,
            ]);

            // record_rider_earnings_adjustment() does not itself send a
            // notification (it's a general-purpose admin correction
            // tool, not payout-specific) — this is the one place that
            // tells the rider both WHY (rejected) and THAT the balance
            // is back, same two-purpose notification
            // reject_wallet_withdrawal() sends for the same reason.
            create_notification(
                'rider', (int) $p['rider_id'], 'Payout rejected',
                'Your payout request was rejected: ' . trim($reason) . '. The amount has been credited back to your earnings balance.',
                'payout', ['request_id' => $requestId, 'screen' => 'earnings']
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
