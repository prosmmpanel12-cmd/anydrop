<?php
/**
 * Anydrop — Native UPI-QR Provider ("UPIPE" driver_key, doc 23 —
 * Native UPI Payment Gateway Architecture, 2026-08-23).
 *
 * IMPORTANT — despite the driver_key/name, this class never calls
 * the UPIPE company's hosted API (yourapi.42web.io). It IS the
 * gateway: it builds the `upi://pay?...` intent string from the
 * admin's own UPI ID (payment_providers.config_json), and Anydrop's
 * own `payment_transactions` table is the sole source of truth for
 * verify(). See docs/23_Native_UPI_Payment_Gateway_Architecture_
 * 2026-08-23.md §0 for why, and §10 for what the actual UPIPE
 * source (docs/payment_reference/upipe_source/) is/isn't used for.
 *
 * QR RENDERING NOTE: the UPIPE reference source's own QR generator
 * (docs/payment_reference/upipe_source/upi/lib/phpqrcode/qrlib.php)
 * turned out to be a hash-noise placeholder, NOT a real QR encoder —
 * its own `textToMatrix()` admits as much in a comment ("not
 * spec-perfect, but scannable for UPI" — it is not scannable at all,
 * there's no error-correction or real data encoding in it). Rather
 * than ship a payment screen with a QR that can never actually be
 * scanned, this class does NOT generate a QR image at all — it
 * returns the raw `upi_link` string, and the customer app renders
 * the actual scannable QR client-side with a real, battle-tested
 * library (Android: ZXing `com.google.zxing:core`, fully offline, no
 * server round-trip). This is arguably the more correct architecture
 * anyway — see doc 23 addendum.
 *
 * No deep links are ever produced or returned — per the app owner's
 * instruction, only the QR-encodable string exists; there is no
 * `deep_links` field anywhere in this class's output.
 */

require_once __DIR__ . '/PaymentProviderInterface.php';
require_once __DIR__ . '/ManualVerificationProviderInterface.php';
require_once __DIR__ . '/PaytmStatusClient.php';
require_once __DIR__ . '/../../config/database.php';

class UpipeProvider implements PaymentProviderInterface, ManualVerificationProviderInterface
{
    /** See submitUtr()'s anti-spoof note below. Public so PaymentService can surface "attempts remaining" to the client without duplicating the number. */
    public const MAX_UTR_ATTEMPTS = 8;

    public function initiate(float $amount, int $orderId, string $orderCode, array $config): array
    {
        $upiId = trim((string) ($config['upi_id'] ?? ''));
        $payeeName = trim((string) ($config['payee_name'] ?? 'Anydrop'));

        if ($upiId === '') {
            return [
                'provider_txn_id' => null,
                'status' => 'initiated',
                'raw_response' => [
                    'native' => true,
                    'note' => 'UPI ID not configured — admin must set it in Admin -> Payment Gateways before UPI checkout can work.',
                ],
                'client_payload' => [
                    'method' => 'unavailable',
                    'message' => 'Online payment is not yet available — please choose Cash on Delivery.',
                ],
            ];
        }

        $txnRef = 'AD' . strtoupper(bin2hex(random_bytes(6)));
        $expirySec = (int) ($config['expiry_sec'] ?? 900);
        $utrWindowSec = (int) ($config['utr_window_sec'] ?? 300);
        $utrRequired = (bool) ($config['utr_required'] ?? true);

        $upiLink = 'upi://pay'
            . '?pa=' . rawurlencode($upiId)
            . '&pn=' . rawurlencode($payeeName)
            . '&am=' . number_format($amount, 2, '.', '')
            . '&cu=INR'
            . '&tn=' . rawurlencode('Order ' . $orderCode)
            . '&tr=' . rawurlencode($txnRef);

        return [
            'provider_txn_id' => $txnRef,
            'status' => 'initiated',
            'raw_response' => [
                'native' => true,
                'note' => 'QR is rendered client-side from upi_link; no outbound call made to any gateway.',
            ],
            'client_payload' => [
                'method' => 'upi_qr',
                'txn_ref' => $txnRef,
                'upi_link' => $upiLink,
                'upi_id' => $upiId,
                'payee_name' => $payeeName,
                'amount' => $amount,
                'expires_in_sec' => $expirySec,
                'utr_required' => $utrRequired,
                'utr_window_sec' => $utrWindowSec,
                'poll_interval_sec' => 10,
                'instructions' => [
                    'Screenshot this QR, or scan it directly from another device.',
                    'Open any UPI app — Paytm, PhonePe, Google Pay, BHIM, or your bank app.',
                    'Pay the exact amount shown.',
                    'Keep this screen open — it checks automatically every few seconds.',
                ],
            ],
        ];
    }

    public function verify(string $providerTxnId, array $config): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM payment_transactions WHERE provider_txn_id = :ref ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['ref' => $providerTxnId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['status' => 'failed', 'raw_response' => ['native' => true, 'reason' => 'not_found']];
        }

        if (in_array($row['status'], ['initiated', 'utr_submitted'], true)
            && $row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
            $db->prepare('UPDATE payment_transactions SET status = :s WHERE id = :id AND status = :old')
                ->execute(['s' => 'failed', 'id' => $row['id'], 'old' => $row['status']]);
            return [
                'status' => 'expired',
                'raw_response' => ['native' => true, 'reason' => 'expired', 'txn_id' => (int) $row['id']],
            ];
        }

        // AUTO-VERIFY (mid present) — exactly the same fallback order as
        // the UPIPE reference source: try auto first, and if it doesn't
        // resolve, fall straight through to the existing UTR-window logic
        // below (no behavior change for accounts with no MID configured).
        $mid = trim((string) ($config['mid'] ?? ''));
        $merchantKey = trim((string) ($config['paytm_merchant_key'] ?? ''));
        // Merchant key is NOT required for the status check itself —
        // in the reference source (lib/encdec_paytm.php) it's only ever
        // used to compute a CHECKSUM for initiateTxnRefund(); the KEY
        // field getTxnStatusNew() sends is a plain passthrough, no
        // hashing. MID alone is what identifies which merchant's
        // transaction to look up.
        if ($row['status'] === 'initiated' && $mid !== '') {
            $auto = $this->tryAutoVerify($db, $row, $mid, $merchantKey, (bool) ($config['is_test_mode'] ?? false));
            if ($auto !== null) {
                return $auto;
            }
            // Auto check ran and didn't resolve (PENDING/ERROR/mismatch) —
            // re-fetch, since tryAutoVerify may have already flipped the
            // row to 'expired' via the same expiry path failing requests
            // pass through, then continue to the normal UTR-window flow.
            $stmt->execute(['ref' => $providerTxnId]);
            $row = $stmt->fetch() ?: $row;
        }

        return [
            'status' => $row['status'],
            'raw_response' => [
                'native' => true,
                'txn_id' => (int) $row['id'],
                'utr' => $row['utr'],
                'reject_reason' => $row['reject_reason'],
            ],
        ];
    }

    /**
     * Calls Paytm's real merchant-status API for this transaction's
     * order ref. Returns a verify()-shaped result ONLY when Paytm gives
     * a definitive TXN_SUCCESS (and the amount matches) — every other
     * outcome (PENDING, TXN_FAILURE, ERROR, not-found) returns null so
     * verify() falls through to the ordinary UTR-window path untouched.
     * Never marks a transaction 'success' on anything but a real,
     * amount-matched Paytm response — same amount-mismatch rule
     * adminDecision() already enforces for the manual path.
     */
    private function tryAutoVerify(PDO $db, array $row, string $mid, string $merchantKey, bool $testMode): ?array
    {
        $db->prepare('UPDATE payment_transactions SET utr_attempts = utr_attempts + 1 WHERE id = :id AND status = :s')
            ->execute(['id' => $row['id'], 's' => 'initiated']);

        $result = PaytmStatusClient::checkStatus($mid, (string) $row['provider_txn_id'], $merchantKey, $testMode);

        if ($result['status'] !== 'TXN_SUCCESS') {
            // PENDING / TXN_FAILURE / ERROR / anything else — not a
            // verified payment. Don't touch the row; let the normal
            // UTR-window / expiry logic in verify() keep handling it.
            return null;
        }

        $txnAmount = (float) ($result['raw']['TXNAMOUNT'] ?? 0);
        if (abs($txnAmount - (float) $row['amount']) > 0.01) {
            // Paytm says success but the amount doesn't match this
            // order's own amount — same guard adminDecision() applies
            // manually. Do not auto-mark paid; leave it for a human.
            return [
                'status' => $row['status'],
                'raw_response' => ['native' => true, 'reason' => 'auto_amount_mismatch', 'paytm_amount' => $txnAmount],
            ];
        }

        // Dedupe (doc 23 addendum §A6 / migration 42) — same rule the
        // UPIPE reference source applies to BANKTXNID even on its own
        // auto-verify branch: a single real bank transaction can never
        // be allowed to pay off two different orders. Paytm's own
        // reference (BANKTXNID, falling back to TXNID) is the unique
        // key here — enforced by uq_ptxn_provider_bank_ref, not just
        // this pre-check, to close the same race a concurrent request
        // could otherwise slip through.
        $bankRef = trim((string) ($result['raw']['BANKTXNID'] ?? $result['raw']['TXNID'] ?? ''));
        if ($bankRef === '') {
            // Paytm said success but gave us nothing to dedupe against —
            // same as the reference source's "Transaction ID not found.
            // Please try again." Don't trust a bare STATUS alone.
            return [
                'status' => $row['status'],
                'raw_response' => ['native' => true, 'reason' => 'auto_missing_bank_ref'],
            ];
        }

        try {
            $stmt = $db->prepare(
                "UPDATE payment_transactions
                 SET status = 'success', provider_bank_ref = :ref, amount_confirmed = :ac, raw_response_json = :raw
                 WHERE id = :id AND status = 'initiated'"
            );
            $stmt->execute([
                'ref' => $bankRef,
                'ac' => $txnAmount,
                'raw' => json_encode(['native' => true, 'auto' => true, 'paytm' => $result['raw']], JSON_UNESCAPED_SLASHES),
                'id' => $row['id'],
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'uq_ptxn_provider_bank_ref')) {
                // This exact bank transaction already paid off a
                // different order — refuse, same as the manual UTR
                // path's own uq_ptxn_utr collision handling.
                return [
                    'status' => $row['status'],
                    'raw_response' => ['native' => true, 'reason' => 'auto_bank_ref_already_used'],
                ];
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            // Lost a race (e.g. admin approved manually a moment
            // earlier) — re-fetch and report whatever actually stuck.
            $fresh = $db->prepare('SELECT status FROM payment_transactions WHERE id = :id');
            $fresh->execute(['id' => $row['id']]);
            return ['status' => $fresh->fetchColumn() ?: $row['status'], 'raw_response' => ['native' => true, 'reason' => 'race_lost']];
        }

        return [
            'status' => 'success',
            'raw_response' => ['native' => true, 'auto' => true, 'txn_id' => (int) $row['id']],
        ];
    }

    public function refund(string $providerTxnId, float $amount, array $config): array
    {
        return [
            'status' => 'failed',
            'raw_response' => [
                'native' => true,
                'reason' => 'manual_refund_required',
                'note' => 'Native UPI collection has no refund API — process refunds as a manual bank transfer and log it separately.',
            ],
        ];
    }

    /**
     * ANTI-SPOOF NOTES (app owner ask, 2026-08-23 — "no loophole"):
     *  - UTR format is checked (12 digits) — rejects garbage without
     *    even touching the DB.
     *  - UTR window (utr_window_sec) blocks a UTR from being accepted
     *    before enough time has passed for a real payment to have
     *    happened at all — a customer can't submit a UTR the instant
     *    the QR renders.
     *  - The UNIQUE index on `utr` (migration 40) means the same
     *    UTR can never be attached to two transactions — closes the
     *    "reuse one real payment's UTR across many orders" loophole.
     *  - `utr_attempts` (migration 41) caps a single transaction to
     *    MAX_UTR_ATTEMPTS tries total (valid or not) — closes the
     *    "hammer this endpoint with random 12-digit numbers hoping to
     *    guess/collide with a real UTR" loophole. Once exhausted, the
     *    transaction is left in whatever state it was in and needs a
     *    fresh order (new transaction, fresh attempt budget) — it is
     *    NOT auto-failed, so a genuine customer who mistyped a few
     *    times doesn't lose an already-real payment; an admin can
     *    still see and resolve it manually from the pending queue.
     *  - Submitting a UTR only ever reaches 'utr_submitted' — never
     *    'success'. Only adminDecision() (a human, behind an admin
     *    session) can mark a transaction paid. There is no code path
     *    anywhere that lets a client request move a transaction to
     *    'success' by itself.
     */
    public function submitUtr(array $transaction, string $utr, array $config): array
    {
        $db = Database::get();

        if ((int) $transaction['utr_attempts'] >= self::MAX_UTR_ATTEMPTS) {
            return ['status' => 'too_many_attempts', 'raw_response' => ['native' => true]];
        }
        // Count this attempt regardless of outcome — including a
        // malformed UTR — so retries can't be free just by sending
        // garbage first to "reset" anything (they can't reset it
        // anyway; this just makes the accounting honest either way).
        $db->prepare('UPDATE payment_transactions SET utr_attempts = utr_attempts + 1 WHERE id = :id')
            ->execute(['id' => $transaction['id']]);

        if (!preg_match('/^\d{12}$/', $utr)) {
            return ['status' => 'invalid_utr', 'raw_response' => ['native' => true, 'reason' => 'utr_must_be_12_digits']];
        }

        $utrWindowSec = (int) ($config['utr_window_sec'] ?? 300);
        $openAt = strtotime($transaction['created_at']) + $utrWindowSec;
        if (time() < $openAt) {
            return [
                'status' => 'utr_window_not_open',
                'raw_response' => ['native' => true, 'utr_allowed_in_sec' => $openAt - time()],
            ];
        }

        if ($transaction['status'] !== 'initiated') {
            return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'not_in_initiated_state']];
        }

        try {
            $stmt = $db->prepare(
                "UPDATE payment_transactions SET utr = :utr, status = 'utr_submitted' WHERE id = :id AND status = 'initiated'"
            );
            $stmt->execute(['utr' => $utr, 'id' => $transaction['id']]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'uq_ptxn_utr')) {
                return ['status' => 'utr_already_used', 'raw_response' => ['native' => true]];
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'race_lost']];
        }

        return ['status' => 'utr_submitted', 'raw_response' => ['native' => true]];
    }

    /**
     * ANTI-SPOOF NOTE — amount confirmation (2026-08-23):
     * $amountConfirmed is what the admin says they actually see
     * credited in their own bank/UPI app, typed in at approval time —
     * NOT trusted from anywhere client-side. An 'approve' is REFUSED
     * (not silently downgraded) if it doesn't match the order's
     * grand_total, closing the "QR says ₹500 but the payer's UPI app
     * let them edit the amount down to ₹1 before paying, and a
     * careless admin approves anyway" loophole. A genuine short/partial
     * payment must be handled outside this flow (reject here, resolve
     * the difference manually) — this system does not model partial
     * payments.
     */
    public function adminDecision(array $transaction, string $decision, ?string $reason, int $adminId, array $config, ?float $amountConfirmed = null, ?float $expectedAmount = null): array
    {
        $db = Database::get();
        if ($decision === 'approve') {
            if ($amountConfirmed === null || $expectedAmount === null || abs($amountConfirmed - $expectedAmount) > 0.01) {
                return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'amount_mismatch']];
            }
            $stmt = $db->prepare(
                "UPDATE payment_transactions SET status = 'success', verified_by_admin_id = :a, amount_confirmed = :ac
                 WHERE id = :id AND status IN ('initiated','utr_submitted')"
            );
            $stmt->execute(['a' => $adminId, 'ac' => $amountConfirmed, 'id' => $transaction['id']]);
            if ($stmt->rowCount() === 0) {
                return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'already_resolved']];
            }
            return ['status' => 'success', 'raw_response' => ['native' => true]];
        }

        if ($decision === 'reject') {
            $stmt = $db->prepare(
                "UPDATE payment_transactions SET status = 'failed', verified_by_admin_id = :a, reject_reason = :r
                 WHERE id = :id AND status IN ('initiated','utr_submitted')"
            );
            $stmt->execute(['a' => $adminId, 'r' => $reason, 'id' => $transaction['id']]);
            if ($stmt->rowCount() === 0) {
                return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'already_resolved']];
            }
            return ['status' => 'failed', 'raw_response' => ['native' => true]];
        }

        return ['status' => $transaction['status'], 'raw_response' => ['native' => true, 'reason' => 'unknown_decision']];
    }
}
