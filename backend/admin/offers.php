<?php
/**
 * Anydrop — Admin Web UI: Offers (recall.md Phase D item 28,
 * migration 47, doc 20 §15).
 *
 * v1 scope, matching migration 47's own header: admin sees every
 * restaurant's offers and can Pause/Resume or Disable/Re-enable any
 * of them. There is NO pre-publish approval queue yet (doc 20 §15's
 * "Approve/Reject" actions) — every restaurant-created offer goes
 * live the instant offers-create.php succeeds; this page is
 * after-the-fact moderation only, same relationship
 * admin/banners.php or admin/categories.php has to their respective
 * restaurant/self-service creation paths elsewhere in this codebase.
 *
 * Disable vs Pause: Pause is what the RESTAURANT'S OWN
 * offers-update.php lets them toggle themselves (temporarily hide an
 * offer without losing its config/history) — this page can do that
 * too, on any restaurant's behalf. Disable is admin-only (restaurant
 * offers-update.php explicitly refuses to move a 'disabled' offer back
 * to 'active' — see that file's own guard) — the lever for "this
 * offer is not allowed to run, full stop, until I say otherwise."
 *
 * Gated on `offers_view` (list) / `offers_manage` (act) — migration
 * 47's new permission pair, granted alongside `coupons_edit`.
 *
 * STATUS: 🟡 BUILT 2026-08-24 — NOT build/device-verified, same
 * standing sandbox limitation as every other admin page in this repo
 * (no PHP CLI/live DB here). Needs migration 47 run, then a live
 * click-through: create an offer via a restaurant token (curl/Postman
 * is enough, no Android app needed since the Restaurant App's own
 * Offers screen isn't built yet — see docs/29) -> confirm it appears
 * here -> Pause it -> confirm price_cart() stops applying it (offer no
 * longer shows on a /cart/validate preview against a matching cart)
 * -> Resume -> Disable -> confirm the restaurant's own
 * offers-update.php now refuses to re-activate it (403
 * offer_disabled_by_admin) -> Re-enable from here and confirm the
 * restaurant CAN resume it again afterward.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/offers.php';

$admin = admin_require_login();
admin_require_permission($admin, 'offers_view');
$db = Database::get();

$canManage = admin_has_permission((int) $admin['id'], 'offers_manage');

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_permission($admin, 'offers_manage');
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $offerId = (int) ($_POST['offer_id'] ?? 0);
        $formAction = $_POST['form_action'] ?? '';
        $newStatus = null;
        if ($formAction === 'pause') {
            $newStatus = 'paused';
        } elseif ($formAction === 'resume') {
            $newStatus = 'active';
        } elseif ($formAction === 'disable') {
            $newStatus = 'disabled';
        } elseif ($formAction === 're_enable') {
            // Admin re-enabling a disabled offer restores it to
            // 'active' directly (not back to 'paused') — matches how
            // admin/restaurants.php's reactivate already restores
            // straight to 'approved', not some intermediate state.
            $newStatus = 'active';
        }

        if ($newStatus !== null && $offerId > 0) {
            $stmt = $db->prepare('UPDATE promo_offers SET status = :status WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['status' => $newStatus, 'id' => $offerId]);
            $flash = 'Offer updated.';
        } else {
            $flash = 'Nothing to do.';
            $flashType = 'error';
        }
    }
}

$restaurantFilter = isset($_GET['restaurant_id']) ? (int) $_GET['restaurant_id'] : 0;
$statusFilter = $_GET['status'] ?? '';

$where = ['o.deleted_at IS NULL'];
$params = [];
if ($restaurantFilter > 0) {
    $where[] = 'o.restaurant_id = :rid';
    $params['rid'] = $restaurantFilter;
}
if (in_array($statusFilter, ['active', 'paused', 'disabled'], true)) {
    $where[] = 'o.status = :status';
    $params['status'] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT o.*, r.name AS restaurant_name,
            (SELECT COUNT(*) FROM offer_usages u WHERE u.offer_id = o.id) AS times_used
     FROM promo_offers o
     JOIN restaurants r ON r.id = o.restaurant_id
     WHERE $whereSql
     ORDER BY o.id DESC
     LIMIT 200"
);
$stmt->execute($params);
$offers = $stmt->fetchAll();

$offerTypeLabels = [
    'quantity_deal' => 'Quantity Deal',
    'buy_x_for_y' => 'Buy X for ₹Y',
    'buy_x_get_y' => 'Buy X Get Y',
    'percent_discount' => '% Discount',
    'flat_discount' => 'Flat Discount',
    'free_delivery' => 'Free Delivery',
    'combo' => 'Combo/Bundle',
];

// docs/40 Step 5 — per-combo item list for the Type column. Batched
// single query (same "IN() batch, not N+1" pattern offers-create.php's
// own ownership check already uses) against just the combo offer ids
// present on this page, joined to menu_items for a display name —
// get_offer_combo_items() in lib/offers.php deliberately returns ids
// only (it's used for matching, not display), so this page reads
// offer_combo_items directly instead of calling it.
$comboItemsByOffer = [];
$comboOfferIds = array_values(array_unique(array_map(
    fn ($o) => (int) $o['id'],
    array_filter($offers, fn ($o) => $o['offer_type'] === 'combo')
)));
if (!empty($comboOfferIds)) {
    $placeholders = implode(',', array_fill(0, count($comboOfferIds), '?'));
    $stmt = $db->prepare(
        "SELECT oci.offer_id, oci.required_qty, m.name AS item_name
         FROM offer_combo_items oci
         JOIN menu_items m ON m.id = oci.menu_item_id
         WHERE oci.offer_id IN ($placeholders)
         ORDER BY oci.offer_id, m.name"
    );
    $stmt->execute($comboOfferIds);
    foreach ($stmt->fetchAll() as $row) {
        $comboItemsByOffer[(int) $row['offer_id']][] = [
            'name' => $row['item_name'],
            'required_qty' => (int) $row['required_qty'],
        ];
    }
}

$csrf = admin_csrf_token();
$pageTitle = 'Offers';
$activeNav = 'offers';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Restaurant Offers</h2>
    <p class="muted">
        Every offer here is restaurant-created — created via the
        Restaurant App/API, live the moment it's created. This page is
        after-the-fact moderation: Pause/Resume toggles the same flag
        the restaurant can flip themselves; Disable is an admin-only
        override the restaurant cannot undo on their own.
    </p>
    <p class="muted">
        Migration 49 — an offer's <strong>Mode</strong> is either
        Default (auto-applied at checkout, no code entry) or Coupon
        Based (only applies when a customer types the offer's own
        code, same input box Coupons use). A Coupon Based offer is
        also either Public (listed on the customer app's "View all
        offers" screen) or Private (code-only, not listed anywhere —
        same as a private Coupon). Mode and code are set once at
        creation and cannot be changed afterward from the Restaurant
        App; Public/Private can be changed any time.
    </p>

    <form method="get" class="filters-row">
        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="paused" <?= $statusFilter === 'paused' ? 'selected' : '' ?>>Paused</option>
            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
        </select>
        <input type="number" name="restaurant_id" placeholder="Restaurant ID" value="<?= $restaurantFilter > 0 ? (int) $restaurantFilter : '' ?>">
        <button type="submit" class="btn btn-outline">Filter</button>
    </form>

    <?php if (empty($offers)): ?>
        <p class="muted">No offers match this filter.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Restaurant</th><th>Title</th><th>Type</th><th>Mode</th><th>Scope</th><th>Min Order</th><th>Valid</th><th>Used</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
        <?php foreach ($offers as $o): ?>
        <tr>
            <td><?= admin_escape($o['restaurant_name']) ?></td>
            <td><?= admin_escape($o['title']) ?></td>
            <td>
                <?= admin_escape($offerTypeLabels[$o['offer_type']] ?? $o['offer_type']) ?>
                <?php if ($o['offer_type'] === 'combo'): ?>
                    <?php $comboItems = $comboItemsByOffer[(int) $o['id']] ?? []; ?>
                    <?php if (!empty($comboItems)): ?>
                        <br><span class="muted" style="font-size:12px;">
                        <?php foreach ($comboItems as $i => $ci): ?>
                            <?= $i > 0 ? ', ' : '' ?><?= admin_escape($ci['name']) ?> ×<?= (int) $ci['required_qty'] ?>
                        <?php endforeach; ?>
                        </span>
                    <?php else: ?>
                        <br><span class="muted" style="font-size:12px;">(no items on file)</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (($o['apply_mode'] ?? 'default') === 'coupon_based'): ?>
                    <span class="badge active">Coupon Based</span>
                    <br><code><?= admin_escape((string) $o['code']) ?></code>
                    <br><span class="muted"><?= !empty($o['is_public']) ? 'Public' : 'Private' ?></span>
                <?php else: ?>
                    <span class="muted">Default</span>
                <?php endif; ?>
            </td>
            <td><?= admin_escape($o['scope']) ?></td>
            <td>₹<?= admin_escape((string) $o['min_order_amount']) ?></td>
            <td>
                <?php if ($o['start_date'] || $o['end_date']): ?>
                    <?= admin_escape($o['start_date'] ?: '…') ?> – <?= admin_escape($o['end_date'] ?: '…') ?>
                <?php else: ?>
                    <span class="muted">No end date</span>
                <?php endif; ?>
                <?php if ($o['start_time'] && $o['end_time']): ?>
                    <br><span class="muted"><?= admin_escape(substr($o['start_time'], 0, 5)) ?>–<?= admin_escape(substr($o['end_time'], 0, 5)) ?></span>
                <?php endif; ?>
            </td>
            <td><?= (int) $o['times_used'] ?></td>
            <td><span class="badge <?= $o['status'] === 'active' ? 'active' : 'inactive' ?>"><?= admin_escape(ucfirst($o['status'])) ?></span></td>
            <?php if ($canManage): ?>
            <td class="row-actions">
                <?php if ($o['status'] === 'active'): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="pause">
                    <input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-outline">Pause</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Disable this offer? The restaurant will not be able to re-enable it themselves.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="disable">
                    <input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-outline danger">Disable</button>
                </form>
                <?php elseif ($o['status'] === 'paused'): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="resume">
                    <input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-primary">Resume</button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Disable this offer? The restaurant will not be able to re-enable it themselves.');">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="disable">
                    <input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-outline danger">Disable</button>
                </form>
                <?php else: ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                    <input type="hidden" name="form_action" value="re_enable">
                    <input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-primary">Re-enable</button>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
