<?php
/**
 * Anydrop — Payment Service (doc 23 — Native UPI Payment Gateway
 * Architecture, 2026-08-23; doc 19 §8's original registry pattern).
 *
 * THE ONLY caller of any PaymentProviderInterface / class in this
 * codebase — order-processing code, customer APIs, and admin pages
 * all go through this file, never `new UpipeProvider()` directly.
 * That's what makes a future real-gateway swap (Razorpay, Cashfree,
 * ...) a new class + a new payment_providers row and nothing else.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/PaymentProviderInterface.php';
require_once __DIR__ . '/ManualVerificationProviderInterface.php';
require_once __DIR__ . '/../ledger.php';
require_once __DIR__ . '/../notifications.php';
require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/../orders.php';

class PaymentService
{
    /** driver_key => class file, so adding a real gateway later is one line here + a new class file. */
    private const DRIVER_CLASSES = [
        'upipe' => __DIR__ . '/UpipeProvider.php',
    ];

    /**
     * Loads the highest-priority active provider row + an
     * instantiated driver class. Returns null if nothing is
     * configured/active (caller should surface COD-only in that case).
     */
    public static function getActiveProvider(PDO $db): ?array
    {
        $stmt = $db->query(
            "SELECT * FROM payment_providers WHERE is_active = 1 ORDER BY priority DESC, id ASC LIMIT 1"
        );
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $driverKey = $row['driver_key'];
        if (!isset(self::DRIVER_CLASSES[$driverKey])) {
            return null;
        }
        require_once self::DRIVER_CLASSES[$driverKey];

        // driver_key => class name convention: 'upipe' => 'UpipeProvider'.
        $className = ucfirst($driverKey) . 'Provider';
        if (!class_exists($className)) {
            return null;
        }

        return [
            'row' => $row,
            'config' => json_decode($row['config_json'] ?? '{}', true) ?: [],
            'instance' => new $className(),
        ];
    }

    /**
     * Starts (or idempotently re-returns) a UPI payment for an order.
     * $order is a full `orders` row (already loaded + ownership-checked
     * by the caller — this function does not re-check auth).
     */
    public static function initiatePayment(PDO $db, array $order): array
    {
        $provider = self::getActiveProvider($db);
        if (!$provider) {
            return ['ok' => false, 'error' => 'no_payment_provider_configured'];
        }

        // Idempotency — a customer re-opening the payment screen (app
        // restart, network retry, etc.) should see the SAME QR/txn_ref,
        // not a fresh one every time, as long as the old one hasn't
        // expired or already resolved. Same "reuse, don't duplicate"
        // pattern the rest of this admin panel already uses (see
        // admin/cod-rules.php's own comment on this).
        $existingStmt = $db->prepare(
            "SELECT * FROM payment_transactions
             WHERE order_id = :oid AND provider_id = :pid AND status IN ('initiated','utr_submitted')
             ORDER BY id DESC LIMIT 1"
        );
        $existingStmt->execute(['oid' => $order['id'], 'pid' => $provider['row']['id']]);
        $existing = $existingStmt->fetch();

        if ($existing && $existing['expires_at'] !== null && strtotime($existing['expires_at']) > time()) {
            $remaining = strtotime($existing['expires_at']) - time();
            $result = $provider['instance']->verify($existing['provider_txn_id'], $provider['config']);
            // Rebuild the same client_payload shape initiate() would
            // have returned, from the stored row — no new upi_link
            // needed, the old one is still valid.
            return [
                'ok' => true,
                'reused' => true,
                'txn_id' => (int) $existing['id'],
                'status' => $result['status'],
                'client_payload' => self::rebuildClientPayloadFromRow($existing, $provider['config'], $remaining, (bool) $provider['row']['is_test_mode']),
            ];
        }

        $initResult = $provider['instance']->initiate(
            (float) $order['grand_total'],
            (int) $order['id'],
            (string) $order['order_code'],
            $provider['config']
        );

        if (($initResult['client_payload']['method'] ?? '') === 'unavailable') {
            return ['ok' => false, 'error' => 'provider_unavailable', 'message' => $initResult['client_payload']['message'] ?? 'Payment unavailable'];
        }

        $initResult['client_payload']['is_test_mode'] = (bool) $provider['row']['is_test_mode'];

        $expirySec = (int) ($provider['config']['expiry_sec'] ?? 900);
        $expiresAt = date('Y-m-d H:i:s', time() + $expirySec);

        $ins = $db->prepare(
            'INSERT INTO payment_transactions (order_id, provider_id, provider_txn_id, amount, status, expires_at, raw_response_json)
             VALUES (:oid, :pid, :ref, :amt, :st, :exp, :raw)'
        );
        $ins->execute([
            'oid' => $order['id'],
            'pid' => $provider['row']['id'],
            'ref' => $initResult['provider_txn_id'],
            'amt' => $order['grand_total'],
            'st' => $initResult['status'],
            'exp' => $expiresAt,
            'raw' => json_encode($initResult['raw_response']),
        ]);

        return [
            'ok' => true,
            'reused' => false,
            'txn_id' => (int) $db->lastInsertId(),
            'status' => $initResult['status'],
            'client_payload' => $initResult['client_payload'],
        ];
    }

    private static function rebuildClientPayloadFromRow(array $txnRow, array $config, int $remainingSec, bool $isTestMode): array
    {
        $upiId = trim((string) ($config['upi_id'] ?? ''));
        $payeeName = trim((string) ($config['payee_name'] ?? 'Anydrop'));
        $upiLink = 'upi://pay'
            . '?pa=' . rawurlencode($upiId)
            . '&pn=' . rawurlencode($payeeName)
            . '&am=' . number_format((float) $txnRow['amount'], 2, '.', '')
            . '&cu=INR&tr=' . rawurlencode($txnRow['provider_txn_id']);

        return [
            'method' => 'upi_qr',
            'txn_ref' => $txnRow['provider_txn_id'],
            'upi_link' => $upiLink,
            'upi_id' => $upiId,
            'payee_name' => $payeeName,
            'amount' => (float) $txnRow['amount'],
            'expires_in_sec' => max(0, $remainingSec),
            'utr_required' => (bool) ($config['utr_required'] ?? true),
            'utr_window_sec' => (int) ($config['utr_window_sec'] ?? 300),
            'poll_interval_sec' => 10,
            'is_test_mode' => $isTestMode,
        ];
    }

    /**
     * Client-facing status vocabulary — maps the DB row + provider
     * verify() result onto exactly the states doc 23 §4 documents
     * (initiated / utr_pending_window / utr_available / utr_submitted
     * / success / failed / expired).
     */
    public static function getClientStatus(PDO $db, array $order): array
    {
        $stmt = $db->prepare(
            'SELECT t.*, p.config_json, p.driver_key, p.is_test_mode FROM payment_transactions t
             JOIN payment_providers p ON p.id = t.provider_id
             WHERE t.order_id = :oid ORDER BY t.id DESC LIMIT 1'
        );
        $stmt->execute(['oid' => $order['id']]);
        $txn = $stmt->fetch();

        if (!$txn) {
            return ['status' => 'not_started'];
        }

        $driverKey = $txn['driver_key'];
        if (!isset(self::DRIVER_CLASSES[$driverKey])) {
            return ['status' => 'failed'];
        }
        require_once self::DRIVER_CLASSES[$driverKey];
        $className = ucfirst($driverKey) . 'Provider';
        $config = json_decode($txn['config_json'] ?? '{}', true) ?: [];
        $config['is_test_mode'] = (bool) $txn['is_test_mode'];
        $provider = new $className();
        $verifyResult = $provider->verify($txn['provider_txn_id'], $config);
        $dbStatus = $verifyResult['status'];

        if ($dbStatus === 'success') {
            // First time a customer poll observes success, apply the
            // paid-order side effects if they haven't run yet
            // (verified_by_admin_id being set is our idempotency guard
            // — approveTransaction() already ran; this just propagates
            // to `orders` if that propagation hasn't happened yet).
            self::promoteOrderIfNeeded($db, $order, $txn);
            return ['status' => 'success', 'txn_ref' => $txn['provider_txn_id']];
        }

        // verify() already distinguishes a just-expired row from an
        // ordinary failure/rejection (it returns 'expired' the moment
        // it lazily flips the DB row, see UpipeProvider::verify()) —
        // no need to re-derive that here.
        if ($dbStatus === 'expired') {
            return ['status' => 'expired'];
        }

        if ($dbStatus === 'failed') {
            return ['status' => 'failed', 'reject_reason' => $txn['reject_reason']];
        }

        if ($dbStatus === 'utr_submitted') {
            return ['status' => 'utr_submitted'];
        }

        // 'initiated' — figure out whether the UTR window has opened yet.
        $utrWindowSec = (int) ($config['utr_window_sec'] ?? 300);
        $openAt = strtotime($txn['created_at']) + $utrWindowSec;
        $maxAttemptsConst = $className . '::MAX_UTR_ATTEMPTS';
        $maxAttempts = defined($maxAttemptsConst) ? constant($maxAttemptsConst) : 8;
        $attemptsLeft = max(0, $maxAttempts - (int) $txn['utr_attempts']);
        if (time() < $openAt) {
            return ['status' => 'utr_pending_window', 'utr_allowed_in_sec' => $openAt - time(), 'utr_attempts_remaining' => $attemptsLeft];
        }

        return ['status' => 'utr_available', 'utr_attempts_remaining' => $attemptsLeft];
    }

    /** Idempotent: only writes orders.payment_status once, guarded by the WHERE clause. */
    private static function promoteOrderIfNeeded(PDO $db, array $order, array $txn): void
    {
        if ($order['payment_status'] === 'paid') {
            return;
        }

        $upd = $db->prepare("UPDATE orders SET payment_status = 'paid' WHERE id = :id AND payment_status != 'paid'");
        $upd->execute(['id' => $order['id']]);
        if ($upd->rowCount() === 0) {
            return; // another request already promoted it
        }

        insert_status_history($db, (int) $order['id'], (string) $order['status'], 'system', null, 'UPI payment confirmed (txn ' . $txn['provider_txn_id'] . ')');
        record_paid_order_ledger_entries($db, $order);
        create_notification('customer', (int) $order['customer_id'], 'Payment received', 'Your UPI payment for order ' . $order['order_code'] . ' is confirmed.', 'order', ['order_id' => (int) $order['id']]);
        // UPI FIX (2026-08-23): this is the first moment a UPI order's
        // payment is genuinely confirmed — create.php deliberately held
        // back the restaurant's "New order received" notification until
        // now (see create.php's own note) so a restaurant can never be
        // alerted about, or act on, an order nobody has paid for yet.
        create_notification('restaurant', (int) $order['restaurant_id'], 'New order received', 'Order ' . $order['order_code'] . ' — ₹' . number_format((float) $order['grand_total'], 0), 'order', ['order_id' => (int) $order['id'], 'screen' => 'order_detail']);
        write_audit_log('system', null, 'order_payment_confirmed', ['order_id' => (int) $order['id'], 'txn_ref' => $txn['provider_txn_id']]);
    }

    public static function submitUtr(PDO $db, array $order, string $utr): array
    {
        $provider = self::getActiveProvider($db);
        if (!$provider || !($provider['instance'] instanceof ManualVerificationProviderInterface)) {
            return ['ok' => false, 'error' => 'utr_not_supported'];
        }

        $stmt = $db->prepare(
            "SELECT * FROM payment_transactions WHERE order_id = :oid AND provider_id = :pid
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['oid' => $order['id'], 'pid' => $provider['row']['id']]);
        $txn = $stmt->fetch();
        if (!$txn) {
            return ['ok' => false, 'error' => 'no_transaction'];
        }

        $result = $provider['instance']->submitUtr($txn, $utr, $provider['config']);
        return ['ok' => true, 'status' => $result['status'], 'raw' => $result['raw_response']];
    }

    // ---------- Admin-facing ----------

    public static function adminPendingTransactions(PDO $db): array
    {
        return $db->query(
            "SELECT t.*, o.order_code, o.grand_total, o.customer_id, pr.name AS provider_name
             FROM payment_transactions t
             JOIN orders o ON o.id = t.order_id
             JOIN payment_providers pr ON pr.id = t.provider_id
             WHERE t.status IN ('initiated','utr_submitted')
             ORDER BY t.created_at ASC"
        )->fetchAll();
    }

    public static function adminDecide(PDO $db, int $txnId, string $decision, ?string $reason, int $adminId, ?float $amountConfirmed = null): array
    {
        $stmt = $db->prepare(
            'SELECT t.*, o.* , t.id AS txn_id, t.status AS txn_status, t.amount AS txn_amount
             FROM payment_transactions t JOIN orders o ON o.id = t.order_id
             WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $txnId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $providerStmt = $db->prepare('SELECT * FROM payment_providers WHERE id = :id LIMIT 1');
        $providerStmt->execute(['id' => $row['provider_id']]);
        $providerRow = $providerStmt->fetch();
        if (!$providerRow || !isset(self::DRIVER_CLASSES[$providerRow['driver_key']])) {
            return ['ok' => false, 'error' => 'provider_unavailable'];
        }
        require_once self::DRIVER_CLASSES[$providerRow['driver_key']];
        $className = ucfirst($providerRow['driver_key']) . 'Provider';
        $providerInstance = new $className();
        if (!($providerInstance instanceof ManualVerificationProviderInterface)) {
            return ['ok' => false, 'error' => 'provider_not_manual'];
        }
        $config = json_decode($providerRow['config_json'] ?? '{}', true) ?: [];

        $txn = ['id' => $row['txn_id'], 'status' => $row['txn_status']];
        // Expected amount is what THIS system's own order/QR asked
        // for (t.amount, snapshotted at initiate() time) — never
        // anything the admin's form could be tricked into overriding;
        // only $amountConfirmed (what the admin says their bank shows)
        // is form input, and it's checked against this server-side value.
        $result = $providerInstance->adminDecision($txn, $decision, $reason, $adminId, $config, $amountConfirmed, (float) $row['txn_amount']);

        write_audit_log('admin', $adminId, 'payment_transaction_' . $decision . 'd', ['txn_id' => $txnId, 'order_id' => (int) $row['order_id'], 'reason' => $reason, 'amount_confirmed' => $amountConfirmed]);

        $failReason = $result['raw_response']['reason'] ?? null;
        if ($failReason === 'amount_mismatch') {
            return ['ok' => false, 'error' => 'amount_mismatch', 'status' => $result['status']];
        }

        if ($result['status'] === 'success') {
            $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
            $orderStmt->execute(['id' => $row['order_id']]);
            $order = $orderStmt->fetch();
            if ($order) {
                self::promoteOrderIfNeeded($db, $order, ['provider_txn_id' => $row['provider_txn_id']]);
            }
        }

        return ['ok' => true, 'status' => $result['status']];
    }
}
