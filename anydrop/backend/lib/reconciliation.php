<?php
/**
 * Anydrop — Payment / Refund / Settlement Reconciliation
 * (PENDING.md item 24, recall.md section 28, doc 21 §5.6/§5.7,
 * migration 66).
 *
 * This is deliberately a DETECTION layer, not a correction layer —
 * every check here is a read-only SELECT. Nothing in this file ever
 * writes to orders/payment_transactions/refunds/*_ledger; when a
 * mismatch is found, the only write is a row in reconciliation_flags
 * for an admin to look at and decide what (if anything) to fix. This
 * mirrors doc 21 §5.6's own instruction — "never trust only the
 * client-side success response" — extended to the admin side: this
 * scan doesn't correct anything automatically either, because an
 * automatic financial correction based on a heuristic is exactly the
 * kind of silent-drift risk reconciliation exists to catch, not cause.
 *
 * Every check below is intentionally narrow and independently
 * readable — add a new one as its own function + its own entry in
 * run_reconciliation_scan()'s returned array, never fold two concerns
 * into one query. That keeps a false positive traceable to one exact
 * rule instead of a tangle of joins.
 */

require_once __DIR__ . '/../config/database.php';

if (!function_exists('recon_check_payment_confirmed_order_not_paid')) {
    /**
     * A payment_transactions row already flipped to 'success' (the
     * authoritative signal PaymentService::promoteOrderIfNeeded() acts
     * on) but the order's own payment_status never followed. Should be
     * structurally impossible given promoteOrderIfNeeded()'s own
     * transaction, but "should be impossible" is exactly what a
     * reconciliation scan exists to verify, not assume.
     */
    function recon_check_payment_confirmed_order_not_paid(PDO $db): array
    {
        $rows = $db->query(
            "SELECT t.id AS txn_id, t.order_id, t.provider_txn_id, o.order_code, o.payment_status, o.restaurant_id
             FROM payment_transactions t
             JOIN orders o ON o.id = t.order_id
             WHERE t.status = 'success' AND o.payment_status NOT IN ('paid', 'refunded')"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'payment_confirmed_order_not_paid',
            'severity' => 'critical',
            'entity_type' => 'order',
            'entity_id' => (int) $r['order_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Order ' . $r['order_code'] . ' has a successful payment (txn #' . $r['txn_id'] . ', ref ' . $r['provider_txn_id'] . ') but payment_status never flipped to paid.',
            'expected_value' => 'paid',
            'actual_value' => $r['payment_status'],
        ], $rows);
    }
}

if (!function_exists('recon_check_paid_upi_order_missing_transaction')) {
    /**
     * The reverse of the check above: an order marked paid via UPI
     * with no successful (or since-refunded) payment_transactions row
     * to back that claim up. A paid order's payment_status must always
     * be traceable to a real transaction — this is "never trust only
     * the client-side success response" (doc 21 §5.6) applied as a
     * standing check rather than a one-time code review.
     */
    function recon_check_paid_upi_order_missing_transaction(PDO $db): array
    {
        $rows = $db->query(
            "SELECT o.id AS order_id, o.order_code, o.payment_status, o.restaurant_id
             FROM orders o
             WHERE o.payment_method = 'upi' AND o.payment_status IN ('paid', 'refunded')
               AND NOT EXISTS (
                   SELECT 1 FROM payment_transactions t
                   WHERE t.order_id = o.id AND t.status IN ('success', 'refunded')
               )"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'paid_upi_order_missing_transaction',
            'severity' => 'critical',
            'entity_type' => 'order',
            'entity_id' => (int) $r['order_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Order ' . $r['order_code'] . ' is marked ' . $r['payment_status'] . ' via UPI but has no successful payment_transactions row to back it.',
            'expected_value' => 'a payment_transactions row with status success/refunded',
            'actual_value' => 'none found',
        ], $rows);
    }
}

if (!function_exists('recon_check_wallet_order_missing_debit')) {
    /**
     * An order paid by wallet must have exactly one matching
     * wallet_transactions debit row (reason='order_payment', same
     * order_id) — debit_wallet_for_order() writes this inside the same
     * transaction as the order insert (orders/create.php §D.12), so a
     * paid wallet order with none means that transaction partially
     * failed in a way nothing since has caught.
     */
    function recon_check_wallet_order_missing_debit(PDO $db): array
    {
        $rows = $db->query(
            "SELECT o.id AS order_id, o.order_code, o.restaurant_id, o.grand_total
             FROM orders o
             WHERE o.payment_method = 'wallet' AND o.payment_status = 'paid'
               AND NOT EXISTS (
                   SELECT 1 FROM wallet_transactions w
                   WHERE w.order_id = o.id AND w.type = 'debit' AND w.reason = 'order_payment'
               )"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'wallet_order_missing_debit',
            'severity' => 'critical',
            'entity_type' => 'order',
            'entity_id' => (int) $r['order_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Order ' . $r['order_code'] . ' is paid via wallet but no wallet_transactions debit row exists for it — customer may not actually have been charged.',
            'expected_value' => 'one wallet debit row, order_payment',
            'actual_value' => 'none found',
        ], $rows);
    }
}

if (!function_exists('recon_check_order_multiple_successful_transactions')) {
    /**
     * More than one 'success'/'refunded' payment_transactions row for
     * the same order. The UNIQUE constraints on utr/provider_bank_ref
     * (migrations 40/42) stop the same bank reference being reused
     * across DIFFERENT orders, but say nothing about two genuinely
     * distinct successful payments landing on the SAME order — which
     * would mean a customer got double-charged, or a stale/duplicate
     * transaction row was left behind by a retry path.
     */
    function recon_check_order_multiple_successful_transactions(PDO $db): array
    {
        $rows = $db->query(
            "SELECT t.order_id, o.order_code, o.restaurant_id, COUNT(*) AS txn_count
             FROM payment_transactions t
             JOIN orders o ON o.id = t.order_id
             WHERE t.status IN ('success', 'refunded')
             GROUP BY t.order_id
             HAVING COUNT(*) > 1"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'order_multiple_successful_transactions',
            'severity' => 'critical',
            'entity_type' => 'order',
            'entity_id' => (int) $r['order_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Order ' . $r['order_code'] . ' has ' . $r['txn_count'] . ' successful/refunded payment transactions — possible double charge.',
            'expected_value' => '1',
            'actual_value' => (string) $r['txn_count'],
        ], $rows);
    }
}

if (!function_exists('recon_check_refund_missing_ledger_entry')) {
    /**
     * A completed manual-transfer refund (method='manual_upi_bank_
     * transfer') should have exactly one platform_ledger 'refund_out'
     * row for its order — complete_refund() (lib/refunds.php) writes
     * both inside the same transaction, so a refunded row with no
     * matching ledger entry means that transaction didn't fully land,
     * or a ledger row was later deleted/edited outside this code path.
     */
    function recon_check_refund_missing_ledger_entry(PDO $db): array
    {
        $rows = $db->query(
            "SELECT r.id AS refund_id, r.order_id, o.order_code, o.restaurant_id, r.amount
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             WHERE r.status = 'refunded' AND r.method = 'manual_upi_bank_transfer'
               AND NOT EXISTS (
                   SELECT 1 FROM platform_ledger pl
                   WHERE pl.order_id = r.order_id AND pl.entry_type = 'refund_out'
               )"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'refund_missing_ledger_entry',
            'severity' => 'critical',
            'entity_type' => 'refund',
            'entity_id' => (int) $r['refund_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Refund #' . $r['refund_id'] . ' for order ' . $r['order_code'] . ' is marked refunded (manual transfer) but has no platform_ledger refund_out entry.',
            'expected_value' => 'one platform_ledger refund_out row, amount ' . number_format((float) $r['amount'], 2),
            'actual_value' => 'none found',
        ], $rows);
    }
}

if (!function_exists('recon_check_wallet_refund_missing_credit')) {
    /**
     * The wallet-method mirror of the check above: a completed
     * wallet refund should have exactly one wallet_transactions credit
     * row (reason='refund', same order_id) — complete_refund_to_wallet()
     * and auto_wallet_refund_on_cancel() (both lib/refunds.php) write
     * this inside the same transaction as the refund's own status flip.
     */
    function recon_check_wallet_refund_missing_credit(PDO $db): array
    {
        $rows = $db->query(
            "SELECT r.id AS refund_id, r.order_id, o.order_code, o.restaurant_id, r.amount
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             WHERE r.status = 'refunded' AND r.method = 'wallet'
               AND NOT EXISTS (
                   SELECT 1 FROM wallet_transactions w
                   WHERE w.order_id = r.order_id AND w.type = 'credit' AND w.reason = 'refund'
               )"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'wallet_refund_missing_credit',
            'severity' => 'critical',
            'entity_type' => 'refund',
            'entity_id' => (int) $r['refund_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Refund #' . $r['refund_id'] . ' for order ' . $r['order_code'] . ' is marked refunded to wallet but no matching wallet_transactions credit row exists — customer may not have actually received the money.',
            'expected_value' => 'one wallet credit row, reason refund',
            'actual_value' => 'none found',
        ], $rows);
    }
}

if (!function_exists('recon_check_wallet_refund_unexpected_ledger_entry')) {
    /**
     * The other direction of the wallet/manual split: a wallet refund
     * must NOT have a platform_ledger refund_out row — that entry
     * represents money actually leaving the platform's bank account,
     * which a wallet credit deliberately does not do (see
     * complete_refund_to_wallet()'s own kdoc). Finding one here would
     * mean the platform's books show money leaving twice for the same
     * refund — once real (if it also has a manual entry) or once
     * phantom (double-counting a refund that only ever moved into the
     * customer's wallet).
     */
    function recon_check_wallet_refund_unexpected_ledger_entry(PDO $db): array
    {
        $rows = $db->query(
            "SELECT r.id AS refund_id, r.order_id, o.order_code, o.restaurant_id, pl.id AS ledger_id, pl.amount
             FROM refunds r
             JOIN orders o ON o.id = r.order_id
             JOIN platform_ledger pl ON pl.order_id = r.order_id AND pl.entry_type = 'refund_out'
             WHERE r.status = 'refunded' AND r.method = 'wallet'"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'wallet_refund_unexpected_ledger_entry',
            'severity' => 'critical',
            'entity_type' => 'refund',
            'entity_id' => (int) $r['refund_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Refund #' . $r['refund_id'] . ' for order ' . $r['order_code'] . ' was refunded to wallet but platform_ledger row #' . $r['ledger_id'] . ' (refund_out, ₹' . number_format((float) $r['amount'], 2) . ') also exists for the same order — money may be double-counted as leaving the platform.',
            'expected_value' => 'no refund_out row for this order',
            'actual_value' => 'platform_ledger #' . $r['ledger_id'],
        ], $rows);
    }
}

if (!function_exists('recon_check_order_refunded_no_refund_record')) {
    /**
     * An order marked payment_status='refunded' with no refunds row
     * at all — every code path that sets this status (complete_refund(),
     * complete_refund_to_wallet(), auto_wallet_refund_on_cancel()) does
     * so from inside a refunds-row-owning transaction, so this should
     * never happen without either a bug or a manual DB edit.
     */
    function recon_check_order_refunded_no_refund_record(PDO $db): array
    {
        $rows = $db->query(
            "SELECT o.id AS order_id, o.order_code, o.restaurant_id
             FROM orders o
             WHERE o.payment_status = 'refunded'
               AND NOT EXISTS (SELECT 1 FROM refunds r WHERE r.order_id = o.id)"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'order_refunded_no_refund_record',
            'severity' => 'critical',
            'entity_type' => 'order',
            'entity_id' => (int) $r['order_id'],
            'order_id' => (int) $r['order_id'],
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Order ' . $r['order_code'] . ' is marked refunded but has no refunds table row explaining why or how much.',
            'expected_value' => 'one refunds row',
            'actual_value' => 'none found',
        ], $rows);
    }
}

if (!function_exists('recon_check_settlement_missing_ledger_entry')) {
    /**
     * A verified restaurant_payments row (Pay Now) with no matching
     * restaurant_due_ledger entry — record_settlement() (lib/ledger.php)
     * writes both inside one transaction, keyed together via the new
     * restaurant_payment_id column (migration 66). A verified payment
     * with nothing linked means either it predates migration 66's
     * backfill window (>120s drift from its ledger row — a genuinely
     * ambiguous historical case, worth a human's eyes) or the ledger
     * half of that transaction never actually landed.
     */
    function recon_check_settlement_missing_ledger_entry(PDO $db): array
    {
        $rows = $db->query(
            "SELECT p.id AS payment_id, p.restaurant_id, r.name AS restaurant_name, p.amount, p.direction
             FROM restaurant_payments p
             JOIN restaurants r ON r.id = p.restaurant_id
             WHERE p.status = 'verified'
               AND NOT EXISTS (
                   SELECT 1 FROM restaurant_due_ledger l WHERE l.restaurant_payment_id = p.id
               )"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'settlement_missing_ledger_entry',
            'severity' => 'warning',
            'entity_type' => 'restaurant_payment',
            'entity_id' => (int) $r['payment_id'],
            'order_id' => null,
            'restaurant_id' => (int) $r['restaurant_id'],
            'description' => 'Settlement #' . $r['payment_id'] . ' (' . $r['direction'] . ', ₹' . number_format((float) $r['amount'], 2) . ') to/from ' . $r['restaurant_name'] . ' has no linked restaurant_due_ledger entry.',
            'expected_value' => 'one linked restaurant_due_ledger row',
            'actual_value' => 'none found (or predates migration 66 and could not be auto-matched)',
        ], $rows);
    }
}

if (!function_exists('recon_check_wallet_balance_drift')) {
    /**
     * Every customer_wallets.balance should equal
     * SUM(credit) - SUM(debit) over that customer's own
     * wallet_transactions — exactly the check wallet_transactions'
     * own `balance_after` column comment (migration 43) flagged as
     * "a future reconciliation job" before this file existed.
     * credit_wallet()/debit_wallet() row-lock and update both in one
     * statement pair, so drift here means either a direct DB edit
     * bypassed those functions, or a genuine bug in one of them.
     */
    function recon_check_wallet_balance_drift(PDO $db): array
    {
        $rows = $db->query(
            "SELECT w.customer_id, c.name AS customer_name, cw.balance AS stored_balance,
                    COALESCE(SUM(CASE WHEN w.type = 'credit' THEN w.amount ELSE -w.amount END), 0) AS computed_balance
             FROM wallet_transactions w
             JOIN customer_wallets cw ON cw.customer_id = w.customer_id
             LEFT JOIN customers c ON c.id = w.customer_id
             GROUP BY w.customer_id, c.name, cw.balance
             HAVING ABS(cw.balance - computed_balance) > 0.01"
        )->fetchAll();

        return array_map(static fn($r) => [
            'flag_type' => 'wallet_balance_drift',
            'severity' => 'critical',
            'entity_type' => 'customer_wallet',
            'entity_id' => (int) $r['customer_id'],
            'order_id' => null,
            'restaurant_id' => null,
            'description' => 'Wallet for customer ' . ($r['customer_name'] ?? ('#' . $r['customer_id'])) . ' shows ₹' . number_format((float) $r['stored_balance'], 2) . ' but its own transaction history sums to ₹' . number_format((float) $r['computed_balance'], 2) . '.',
            'expected_value' => number_format((float) $r['computed_balance'], 2),
            'actual_value' => number_format((float) $r['stored_balance'], 2),
        ], $rows);
    }
}

if (!function_exists('recon_check_platform_balance_mismatch')) {
    /**
     * Project-wide version of the same idea admin/platform-ledger.php
     * already shows inline (Net Balance Held vs -1×SUM(negative
     * current_due)) — pulled in here too so it appears in the same
     * persisted, resolvable queue as every other check instead of
     * being a number a page-load-only banner. entity_id is always 0 —
     * this check has no single row to point at, it's a whole-platform
     * total.
     */
    function recon_check_platform_balance_mismatch(PDO $db): array
    {
        $totals = $db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN entry_type IN ('customer_payment_in','restaurant_settlement_in') THEN amount ELSE 0 END), 0) AS total_in,
                COALESCE(SUM(CASE WHEN entry_type IN ('restaurant_payout_out','refund_out') THEN ABS(amount) ELSE 0 END), 0) AS total_out
             FROM platform_ledger"
        )->fetch();
        $netHeld = round((float) $totals['total_in'] - (float) $totals['total_out'], 2);

        $owedOut = (float) $db->query(
            "SELECT COALESCE(SUM(current_due), 0) FROM restaurants WHERE current_due < 0 AND deleted_at IS NULL"
        )->fetchColumn();
        $expectedHeld = round(-1 * $owedOut, 2);
        $diff = round($netHeld - $expectedHeld, 2);

        if (abs($diff) < 0.5) {
            return [];
        }

        return [[
            'flag_type' => 'platform_balance_mismatch',
            'severity' => 'critical',
            'entity_type' => 'platform',
            'entity_id' => 0,
            'order_id' => null,
            'restaurant_id' => null,
            'description' => 'Platform Cash Flow\'s Net Balance Held (₹' . number_format($netHeld, 2) . ') does not match -1×SUM(negative current_due) across all restaurants (₹' . number_format($expectedHeld, 2) . ') — see Platform Cash Flow for the full entry list.',
            'expected_value' => number_format($expectedHeld, 2),
            'actual_value' => number_format($netHeld, 2),
        ]];
    }
}

if (!function_exists('run_reconciliation_scan')) {
    /** Runs every check above and returns their combined, unpersisted results. */
    function run_reconciliation_scan(PDO $db): array
    {
        return array_merge(
            recon_check_payment_confirmed_order_not_paid($db),
            recon_check_paid_upi_order_missing_transaction($db),
            recon_check_wallet_order_missing_debit($db),
            recon_check_order_multiple_successful_transactions($db),
            recon_check_refund_missing_ledger_entry($db),
            recon_check_wallet_refund_missing_credit($db),
            recon_check_wallet_refund_unexpected_ledger_entry($db),
            recon_check_order_refunded_no_refund_record($db),
            recon_check_settlement_missing_ledger_entry($db),
            recon_check_wallet_balance_drift($db),
            recon_check_platform_balance_mismatch($db)
        );
    }
}

if (!function_exists('persist_reconciliation_flags')) {
    /**
     * Upserts a freshly-run scan's results into reconciliation_flags,
     * deduped on (flag_type, entity_type, entity_id) per migration 66's
     * unique key. A flag already 'open' just gets its last_seen_at/
     * description refreshed. A flag previously 'resolved' that's been
     * detected again is reopened (the underlying problem recurred —
     * silently leaving it resolved would hide that) but keeps its
     * resolution history in resolution_note, prefixed to explain the
     * reopen. A flag an admin explicitly marked 'ignored' is left
     * alone — that was a deliberate "not a real problem" call, not a
     * fix, and re-detecting the same known-and-accepted condition on
     * every scan shouldn't keep re-surfacing it.
     *
     * Returns ['new' => int, 'reopened' => int, 'refreshed' => int, 'total_open' => int].
     */
    function persist_reconciliation_flags(PDO $db, array $flags): array
    {
        $stats = ['new' => 0, 'reopened' => 0, 'refreshed' => 0];

        $find = $db->prepare(
            'SELECT id, status, resolution_note FROM reconciliation_flags
             WHERE flag_type = :ft AND entity_type = :et AND entity_id = :eid LIMIT 1'
        );
        $insert = $db->prepare(
            'INSERT INTO reconciliation_flags
                (flag_type, severity, entity_type, entity_id, order_id, restaurant_id, description, expected_value, actual_value, status)
             VALUES
                (:ft, :sev, :et, :eid, :oid, :rid, :desc, :exp, :act, \'open\')'
        );
        $refresh = $db->prepare(
            'UPDATE reconciliation_flags SET description = :desc, expected_value = :exp, actual_value = :act
             WHERE id = :id'
        );
        $reopen = $db->prepare(
            "UPDATE reconciliation_flags
             SET status = 'open', description = :desc, expected_value = :exp, actual_value = :act,
                 resolution_note = CONCAT(COALESCE(resolution_note, ''), ' [reopened: issue detected again]')
             WHERE id = :id"
        );

        foreach ($flags as $f) {
            $find->execute(['ft' => $f['flag_type'], 'et' => $f['entity_type'], 'eid' => $f['entity_id']]);
            $existing = $find->fetch();

            if (!$existing) {
                $insert->execute([
                    'ft' => $f['flag_type'], 'sev' => $f['severity'], 'et' => $f['entity_type'], 'eid' => $f['entity_id'],
                    'oid' => $f['order_id'] ?? null, 'rid' => $f['restaurant_id'] ?? null,
                    'desc' => $f['description'], 'exp' => $f['expected_value'] ?? null, 'act' => $f['actual_value'] ?? null,
                ]);
                $stats['new']++;
                continue;
            }

            if ($existing['status'] === 'ignored') {
                continue; // admin already reviewed and dismissed this exact condition
            }

            if ($existing['status'] === 'resolved') {
                $reopen->execute([
                    'desc' => $f['description'], 'exp' => $f['expected_value'] ?? null, 'act' => $f['actual_value'] ?? null,
                    'id' => $existing['id'],
                ]);
                $stats['reopened']++;
                continue;
            }

            // already 'open' — just refresh the current numbers (an
            // amount can change if, e.g., a follow-up manual edit
            // altered the underlying row without fixing the root cause).
            $refresh->execute([
                'desc' => $f['description'], 'exp' => $f['expected_value'] ?? null, 'act' => $f['actual_value'] ?? null,
                'id' => $existing['id'],
            ]);
            $stats['refreshed']++;
        }

        $stats['total_open'] = (int) $db->query("SELECT COUNT(*) FROM reconciliation_flags WHERE status = 'open'")->fetchColumn();
        return $stats;
    }
}

if (!function_exists('resolve_reconciliation_flag')) {
    function resolve_reconciliation_flag(PDO $db, int $flagId, int $adminId, string $note): bool
    {
        $stmt = $db->prepare(
            "UPDATE reconciliation_flags
             SET status = 'resolved', resolved_by_admin_id = :aid, resolved_at = NOW(), resolution_note = :note
             WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['aid' => $adminId, 'note' => $note, 'id' => $flagId]);
        if ($stmt->rowCount() > 0) {
            write_audit_log('admin', $adminId, 'reconciliation_flag_resolved', ['flag_id' => $flagId, 'note' => $note]);
            return true;
        }
        return false;
    }
}

if (!function_exists('ignore_reconciliation_flag')) {
    function ignore_reconciliation_flag(PDO $db, int $flagId, int $adminId, string $note): bool
    {
        $stmt = $db->prepare(
            "UPDATE reconciliation_flags
             SET status = 'ignored', resolved_by_admin_id = :aid, resolved_at = NOW(), resolution_note = :note
             WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['aid' => $adminId, 'note' => $note, 'id' => $flagId]);
        if ($stmt->rowCount() > 0) {
            write_audit_log('admin', $adminId, 'reconciliation_flag_ignored', ['flag_id' => $flagId, 'note' => $note]);
            return true;
        }
        return false;
    }
}
