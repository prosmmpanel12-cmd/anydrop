<?php
/**
 * Anydrop — Admin Web UI: Payment Gateways
 *
 * Implements docs/23_Native_UPI_Payment_Gateway_Architecture_2026-08-23.md
 * §7 — manages the `payment_providers` table (migration 39 + 40).
 * Same "mirrors the Email Providers screen: enable/disable, priority,
 * test mode" ask doc 19 §8 already made, now built for the UPIPE-
 * pattern (native UPI-QR) row specifically. New provider rows aren't
 * created from this screen yet — only the seeded UPIPE row is
 * editable — because every other driver_key (Razorpay, Cashfree, ...)
 * has no class implementing PaymentProviderInterface yet (doc 23 §9);
 * adding the "create a new row for an unimplemented driver" UI now
 * would let an admin configure a gateway that can never actually run.
 *
 * Gated on `payment_providers_manage` — already seeded by migration 29
 * (backend/sql/29_migration_admin_rbac.sql), no new RBAC migration
 * needed.
 *
 * STATUS: 🟡 BUILT 2026-08-23 — NOT build/device-verified (no PHP CLI
 * or live DB in this sandbox, same standing limitation as every other
 * session). Needs migration 40 run on the live DB, then a live
 * click-through: fill in a UPI ID, Save, confirm payment-upi-create.php
 * stops returning `method: unavailable`.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payment_providers_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'update_gateway') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $upiId = trim($_POST['upi_id'] ?? '');
            $payeeName = trim($_POST['payee_name'] ?? '') ?: 'Anydrop';
            $expirySec = max(60, (int) ($_POST['expiry_sec'] ?? 900));
            $utrWindowSec = max(0, (int) ($_POST['utr_window_sec'] ?? 300));
            $utrRequired = isset($_POST['utr_required']) ? true : false;
            $mid = trim($_POST['mid'] ?? '');
            $paytmMerchantKey = trim($_POST['paytm_merchant_key'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isTestMode = isset($_POST['is_test_mode']) ? 1 : 0;
            $priority = max(0, (int) ($_POST['priority'] ?? 0));

            $config = [
                'upi_id' => $upiId,
                'payee_name' => $payeeName,
                'expiry_sec' => $expirySec,
                'utr_window_sec' => $utrWindowSec,
                'utr_required' => $utrRequired,
                // Optional — Paytm MID-based auto-verify (doc 23 addendum
                // §A6). Leave both blank to stay manual-UTR-only, exactly
                // like the UPIPE reference source's own "mid empty →
                // silently degrade to manual" rule
                // (docs/payment_reference/upipe_source/upi/api/create_order.php).
                // IMPORTANT: this only verifies transactions Paytm itself
                // actually processed under this MID — a QR built from a
                // plain upi_id above (not a Paytm-issued collect request)
                // won't be found by Paytm's status API, so auto-verify
                // will simply never fire and every order falls through to
                // the UTR window untouched. Only fill these in if your
                // UPI collection is genuinely running through this Paytm
                // MID.
                'mid' => $mid,
                'paytm_merchant_key' => $paytmMerchantKey,
            ];

            $upd = $db->prepare(
                'UPDATE payment_providers SET config_json = :cfg, is_active = :act, is_test_mode = :test, priority = :pr WHERE id = :id'
            );
            $upd->execute([
                'cfg' => json_encode($config),
                'act' => $isActive,
                'test' => $isTestMode,
                'pr' => $priority,
                'id' => $providerId,
            ]);
            write_audit_log('admin', $admin['id'], 'payment_provider_updated', ['provider_id' => $providerId, 'upi_id_set' => $upiId !== '', 'is_active' => $isActive, 'is_test_mode' => $isTestMode]);
            $flash = 'Payment gateway updated.';
        }
    }
}

$providers = $db->query('SELECT * FROM payment_providers ORDER BY priority DESC, id ASC')->fetchAll();

$csrf = admin_csrf_token();
$pageTitle = 'Payment Gateways';
$activeNav = 'payment_gateways';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Payment Gateways</h2>
    <p class="muted">
        The <strong>UPIPE</strong> row below is Anydrop's own native
        UPI-QR collection (doc 23) — money lands directly in the UPI
        ID configured here, in the admin's own account. It never calls
        any external UPIPE/third-party API. Leave <strong>UPI ID</strong>
        blank to keep online payment showing as unavailable (customers
        will see Cash on Delivery only) — same "stub-safe" default this
        table has always had.
    </p>
</div>

<?php foreach ($providers as $p): ?>
<?php $cfg = json_decode($p['config_json'] ?? '{}', true) ?: []; ?>
<div class="card">
    <h2><?= admin_escape($p['name']) ?> <span class="badge <?= $p['is_active'] ? 'active' : 'inactive' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
        <?php if ($p['is_test_mode']): ?><span class="badge inactive">Test Mode</span><?php endif; ?>
    </h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="update_gateway">
        <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
        <div class="form-grid">
            <?php if ($p['driver_key'] === 'upipe'): ?>
            <label>UPI ID (e.g. merchantname@upi)
                <input type="text" name="upi_id" value="<?= admin_escape($cfg['upi_id'] ?? '') ?>" placeholder="Required — leave blank to disable online payment">
            </label>
            <label>Payee display name (shown to customers)
                <input type="text" name="payee_name" value="<?= admin_escape($cfg['payee_name'] ?? 'Anydrop') ?>">
            </label>
            <label>QR expiry (seconds)
                <input type="number" name="expiry_sec" min="60" value="<?= (int) ($cfg['expiry_sec'] ?? 900) ?>">
            </label>
            <label>UTR submission window (seconds after order creation)
                <input type="number" name="utr_window_sec" min="0" value="<?= (int) ($cfg['utr_window_sec'] ?? 300) ?>">
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="utr_required" <?= ($cfg['utr_required'] ?? true) ? 'checked' : '' ?>>
                Require a customer-submitted UTR before it shows in the review queue
            </label>
            <label>Paytm MID (enables auto-verify)
                <input type="text" name="mid" value="<?= admin_escape($cfg['mid'] ?? '') ?>" placeholder="Leave blank to stay manual-only (UTR + admin approval)">
            </label>
            <label>Paytm Merchant Key (optional — only used for refunds, not needed for auto-verify)
                <input type="text" name="paytm_merchant_key" value="<?= admin_escape($cfg['paytm_merchant_key'] ?? '') ?>" placeholder="From your Paytm merchant dashboard — leave blank if not doing refunds through this">
            </label>
            <p class="muted">
                MID + Merchant Key only auto-verify payments Paytm itself
                actually processed under that MID. If your UPI collection
                isn't running through Paytm, leave both blank — auto-verify
                will just never fire otherwise, and orders will sit on the
                UTR window with nothing checking them.
            </p>
            <?php else: ?>
            <p class="muted">Driver key <code><?= admin_escape($p['driver_key']) ?></code> has no
                implementing class yet — see doc 23 §9. Nothing to configure until it's built.</p>
            <?php endif; ?>
            <label>Priority (higher = tried first when multiple gateways are active)
                <input type="number" name="priority" min="0" value="<?= (int) $p['priority'] ?>">
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="is_active" <?= $p['is_active'] ? 'checked' : '' ?>>
                Active
            </label>
            <label class="checkbox-row">
                <input type="checkbox" name="is_test_mode" <?= $p['is_test_mode'] ? 'checked' : '' ?>>
                Test mode (watermarks the payment screen — turn off only when ready to accept real payments)
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
<?php endforeach; ?>

<div class="card">
    <h2>Pending Verifications</h2>
    <p class="muted">UPI payments waiting on manual approval live on a separate screen.</p>
    <a class="btn btn-outline" href="payment-pending.php">Go to Pending UPI Payments →</a>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
