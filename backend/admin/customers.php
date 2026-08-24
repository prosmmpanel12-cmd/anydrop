<?php
/**
 * Anydrop — Admin Web UI: Customer Management
 *
 * Implements recall.md Phase A item 5 (Customer side) / doc 19's Super
 * Admin "Customers" module: search, view profile (addresses + recent
 * orders), suspend/reactivate (customers.is_active — same column the
 * customer-app login already checks), and soft delete.
 *
 * Item 26 (Customer Wallet, recall.md section 18; migration 43) —
 * added 2026-08-23: each customer's detail modal now also shows their
 * wallet balance + last 5 transactions, and (gated on the separate
 * `wallets_manage` permission) an admin credit/debit adjustment form
 * (recall.md section 18's "Admin adjustment" feature). See
 * lib/wallet.php for the actual balance-changing logic — this file
 * only calls credit_wallet()/debit_wallet(), never touches
 * customer_wallets directly.
 *
 * Gated: `customers_view` to see this page; `customers_suspend` for the
 * activate/deactivate toggle; `customers_delete` for soft delete;
 * `wallets_view`/`wallets_manage` for the wallet section above. (No
 * separate customer-field edit action exists yet — there's no admin-
 * editable customer field beyond active-state and delete, so
 * `customers_edit` isn't wired to anything in this session; it stays
 * reserved for when one shows up, same as `dashboard_view` was
 * reserved before dashboard.php existed.)
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/wallet.php';

$admin = admin_require_login();
admin_require_permission($admin, 'customers_view');
$canSuspend = admin_has_permission($admin['id'], 'customers_suspend');
$canDelete = admin_has_permission($admin['id'], 'customers_delete');
// Item 26 — Customer Wallet (recall.md section 18; migration 43).
// Deliberately separate permission pair from customers_view/suspend —
// a wallet adjustment moves real money into a customer's balance,
// a distinct blast radius from suspend/delete.
$canViewWallet = admin_has_permission($admin['id'], 'wallets_view');
$canManageWallet = admin_has_permission($admin['id'], 'wallets_manage');
$db = Database::get();

// Full service_areas node map (id => row), for rendering each saved
// address's breadcrumb (recall.md item 9) — same pattern as
// restaurants.php's $areaNodeById, just for customer_addresses.area_id
// instead of restaurants.area_id.
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}

$flash = null;
$flashType = 'success';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';
        $customerId = (int) ($_POST['customer_id'] ?? 0);

        $stmt = $db->prepare('SELECT id, name, email, is_active FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $customerId]);
        $customer = $stmt->fetch();

        if (!$customer) {
            $flash = 'Customer not found.';
            $flashType = 'error';
        } elseif ($formAction === 'toggle_active') {
            if (!$canSuspend) {
                $flash = 'Your role doesn\'t have the customers_suspend permission.';
                $flashType = 'error';
            } else {
                $newState = $customer['is_active'] ? 0 : 1;
                if ($newState === 0) {
                    // Suspending — require a reason (doc 25: same
                    // account_suspended payload the app now shows comes
                    // from this column via lib/auth.php's per-request
                    // check, so an empty reason here means a blank
                    // dialog on the customer's end).
                    $reason = trim($_POST['reason'] ?? '');
                    if ($reason === '') {
                        $flash = 'A suspension reason is required.';
                        $flashType = 'error';
                    } else {
                        $db->prepare('UPDATE customers SET is_active = 0, suspension_reason = :r WHERE id = :id')
                            ->execute(['r' => $reason, 'id' => $customerId]);
                        write_audit_log('admin', $admin['id'], 'customer_suspended', ['customer_id' => $customerId, 'reason' => $reason]);
                        $flash = admin_escape($customer['name'] ?: $customer['email']) . ' suspended.';
                    }
                } else {
                    $db->prepare('UPDATE customers SET is_active = 1, suspension_reason = NULL WHERE id = :id')
                        ->execute(['id' => $customerId]);
                    write_audit_log('admin', $admin['id'], 'customer_reactivated', ['customer_id' => $customerId]);
                    $flash = admin_escape($customer['name'] ?: $customer['email']) . ' reactivated.';
                }
            }
        } elseif ($formAction === 'soft_delete') {
            if (!$canDelete) {
                $flash = 'Your role doesn\'t have the customers_delete permission.';
                $flashType = 'error';
            } else {
                $db->prepare('UPDATE customers SET deleted_at = NOW(), is_active = 0 WHERE id = :id')->execute(['id' => $customerId]);
                write_audit_log('admin', $admin['id'], 'customer_deleted', ['customer_id' => $customerId]);
                $flash = admin_escape($customer['name'] ?: $customer['email']) . ' removed.';
            }
        } elseif ($formAction === 'wallet_adjust') {
            // Item 26 — recall.md section 18's "Admin adjustment"
            // feature. direction picks credit vs debit; amount/note
            // both required (a note-less adjustment is an audit gap —
            // this is real money moving with no order/refund behind
            // it, so the reason has to be on record).
            if (!$canManageWallet) {
                $flash = 'Your role doesn\'t have the wallets_manage permission.';
                $flashType = 'error';
            } else {
                $direction = $_POST['wallet_direction'] ?? '';
                $amount = (float) ($_POST['wallet_amount'] ?? 0);
                $note = trim($_POST['wallet_note'] ?? '');

                if (!in_array($direction, ['credit', 'debit'], true) || $amount <= 0 || $note === '') {
                    $flash = 'Wallet adjustment needs a direction, a positive amount, and a note.';
                    $flashType = 'error';
                } else {
                    try {
                        $result = $direction === 'credit'
                            ? credit_wallet($db, $customerId, $amount, 'admin_adjustment', null, $note, 'admin', (int) $admin['id'])
                            : debit_wallet($db, $customerId, $amount, 'admin_adjustment', null, $note, 'admin', (int) $admin['id']);

                        if ($result['ok']) {
                            $flash = 'Wallet ' . $direction . 'ed ₹' . number_format($amount, 2)
                                . ' — new balance ₹' . number_format($result['balance'], 2) . '.';
                        } else {
                            $flash = $result['error'] === 'insufficient_balance'
                                ? 'Could not debit — customer\'s balance (₹' . number_format($result['balance'], 2) . ') is less than ₹' . number_format($amount, 2) . '.'
                                : 'Could not adjust wallet: ' . ($result['error'] ?? 'unknown');
                            $flashType = 'error';
                        }
                    } catch (Throwable $e) {
                        $flash = 'Wallet adjustment failed.';
                        $flashType = 'error';
                    }
                }
            }
        }
    }
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$activeFilter = $_GET['active'] ?? ''; // '', '1', '0'
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['deleted_at IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR email LIKE :q OR mobile LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($activeFilter === '1' || $activeFilter === '0') {
    $where[] = 'is_active = :active';
    $params['active'] = (int) $activeFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM customers WHERE {$whereSql}");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare(
    "SELECT c.id, c.name, c.email, c.mobile, c.login_type, c.is_active, c.suspension_reason, c.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
            (SELECT MAX(created_at) FROM orders o WHERE o.customer_id = c.id) AS last_order_at
     FROM customers c
     WHERE {$whereSql}
     ORDER BY c.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$customers = $listStmt->fetchAll();

// Addresses + recent orders for whichever row the admin opens in this pageview
// (fetched for every row up-front, same N+1-avoidance approach as areas.php's
// restaurant-count map — the list is capped at 20/page so this stays cheap).
$addressesByCustomer = [];
$recentOrdersByCustomer = [];
if (!empty($customers)) {
    $ids = array_map(fn($c) => (int) $c['id'], $customers);
    $inClause = implode(',', $ids);

    foreach ($db->query("SELECT id, customer_id, label, full_address, is_default, area_id FROM customer_addresses WHERE customer_id IN ({$inClause})")->fetchAll() as $a) {
        $addressesByCustomer[$a['customer_id']][] = $a;
    }

    foreach ($db->query(
        "SELECT o.id, o.customer_id, o.order_code, o.status, o.grand_total, o.created_at, r.name AS restaurant_name
         FROM orders o JOIN restaurants r ON r.id = o.restaurant_id
         WHERE o.customer_id IN ({$inClause})
         ORDER BY o.created_at DESC"
    )->fetchAll() as $o) {
        if (!isset($recentOrdersByCustomer[$o['customer_id']])) {
            $recentOrdersByCustomer[$o['customer_id']] = [];
        }
        if (count($recentOrdersByCustomer[$o['customer_id']]) < 5) {
            $recentOrdersByCustomer[$o['customer_id']][] = $o;
        }
    }
}

// Item 26 — wallet balance + last 5 transactions per customer on this
// page, same up-front-fetch-for-the-whole-page approach as addresses/
// orders above (page capped at 20 rows, so 20 extra small queries is
// cheap — no N+1 concern at this scale). Only fetched at all if the
// admin can even see wallets, to skip the extra queries entirely for
// a role that doesn't have wallets_view.
$walletByCustomer = [];
$walletTxnsByCustomer = [];
if ($canViewWallet && !empty($customers)) {
    foreach ($customers as $c) {
        $walletByCustomer[$c['id']] = get_wallet_balance($db, (int) $c['id']);
        $walletTxnsByCustomer[$c['id']] = list_wallet_transactions($db, (int) $c['id'], 5);
    }
}

$csrf = admin_csrf_token();
$pageTitle = 'Customers (' . $totalCount . ')';
$activeNav = 'customers';
require __DIR__ . '/_layout_head.php';
?>
    <div class="card" style="margin-bottom:16px;">
        <form method="get" class="form-grid">
            <div>
                <label class="field-label">Search</label>
                <input type="text" name="q" value="<?= admin_escape($q) ?>" placeholder="Name, email, mobile...">
            </div>
            <div>
                <label class="field-label">Status</label>
                <select name="active">
                    <option value="">All</option>
                    <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" data-no-loading>Filter</button>
                <?php if ($q !== '' || $activeFilter !== ''): ?>
                    <a href="customers.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($customers)): ?>
        <div class="empty">No customers match this filter.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Login</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><strong><?= admin_escape($c['name'] ?: '(no name)') ?></strong></td>
                        <td class="muted"><?= admin_escape($c['email']) ?><br><?= admin_escape($c['mobile'] ?: '—') ?></td>
                        <td><?= ucfirst($c['login_type']) ?></td>
                        <td><?= (int) $c['order_count'] ?><?php if ($c['last_order_at']): ?><br><span class="muted">last <?= admin_escape(substr($c['last_order_at'], 0, 10)) ?></span><?php endif; ?></td>
                        <td><span class="badge <?= $c['is_active'] ? 'active' : 'inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Suspended' ?></span></td>
                        <td class="muted"><?= admin_escape(substr($c['created_at'], 0, 10)) ?></td>
                        <td><button type="button" class="btn btn-outline" data-open-dialog="cust-<?= (int) $c['id'] ?>">View</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="row-actions" style="margin-top:14px; justify-content:center;">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a class="btn btn-outline <?= $p === $page ? 'active' : '' ?>"
                   href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($customers as $c): ?>
        <dialog class="modal" id="cust-<?= (int) $c['id'] ?>">
            <div class="modal-body">
                <h3 class="modal-title"><?= admin_escape($c['name'] ?: '(no name)') ?></h3>
                <p class="modal-text">
                    <?= admin_escape($c['email']) ?> · <?= admin_escape($c['mobile'] ?: 'no mobile') ?>
                    <?php if (!$c['is_active'] && $c['suspension_reason']): ?><br>Suspension reason: <?= admin_escape($c['suspension_reason']) ?><?php endif; ?>
                </p>

                <div class="section-title" style="margin-top:10px;">Saved addresses</div>
                <?php if (empty($addressesByCustomer[$c['id']])): ?>
                    <div class="muted">None saved.</div>
                <?php else: ?>
                    <div class="muted" style="line-height:1.7;">
                        <?php foreach ($addressesByCustomer[$c['id']] as $a): ?>
                            <?= admin_escape($a['label'] ?: 'Address') ?><?= $a['is_default'] ? ' (default)' : '' ?>:
                            <?= admin_escape($a['full_address']) ?>
                            <?php if ($a['area_id'] && isset($areaNodeById[(int) $a['area_id']])): ?>
                                — <span title="Resolved service area"><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['area_id']], $areaNodeById)) ?></span>
                            <?php elseif ($a['area_id'] === null): ?>
                                — <span class="muted">area: unresolved</span>
                            <?php endif; ?>
                            <br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="section-title" style="margin-top:14px;">Recent orders</div>
                <?php if (empty($recentOrdersByCustomer[$c['id']])): ?>
                    <div class="muted">No orders yet.</div>
                <?php else: ?>
                    <div class="muted" style="line-height:1.7;">
                        <?php foreach ($recentOrdersByCustomer[$c['id']] as $o): ?>
                            #<?= admin_escape($o['order_code']) ?> — <?= admin_escape($o['restaurant_name']) ?> —
                            ₹<?= number_format((float) $o['grand_total'], 2) ?> — <?= ucfirst(str_replace('_', ' ', $o['status'])) ?>
                            (<?= admin_escape(substr($o['created_at'], 0, 10)) ?>)<br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canViewWallet): ?>
                <div class="section-title" style="margin-top:14px;">Wallet</div>
                <p class="muted">Balance: <strong>₹<?= number_format($walletByCustomer[$c['id']] ?? 0.0, 2) ?></strong></p>
                <?php if (!empty($walletTxnsByCustomer[$c['id']])): ?>
                    <div class="muted" style="line-height:1.7;">
                        <?php foreach ($walletTxnsByCustomer[$c['id']] as $t): ?>
                            <?= $t['type'] === 'credit' ? '+' : '−' ?>₹<?= number_format((float) $t['amount'], 2) ?>
                            — <?= admin_escape(str_replace('_', ' ', $t['reason'])) ?><?= $t['note'] ? ' (' . admin_escape($t['note']) . ')' : '' ?>
                            (<?= admin_escape(substr($t['created_at'], 0, 10)) ?>)<br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($canManageWallet): ?>
                    <form method="post" style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="customer_id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="form_action" value="wallet_adjust">
                        <select name="wallet_direction" style="width:auto;">
                            <option value="credit">Credit</option>
                            <option value="debit">Debit</option>
                        </select>
                        <input type="number" name="wallet_amount" step="0.01" min="0.01" placeholder="Amount" style="width:110px;" required>
                        <input type="text" name="wallet_note" placeholder="Reason (required)" style="flex:1; min-width:140px;" required>
                        <button type="submit" class="btn btn-outline">Apply</button>
                    </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($canSuspend): ?>
                    <?php if ($c['is_active']): ?>
                        <form method="post" style="margin-top:16px;">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="customer_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="form_action" value="toggle_active">
                            <label class="field-label">Suspension reason</label>
                            <textarea name="reason" style="width:100%; min-height:60px;" required></textarea>
                            <button type="submit" class="btn btn-outline danger" style="width:100%; margin-top:8px;"
                                data-confirm-title="Suspend this customer?"
                                data-confirm-text="They will not be able to log in or place orders until reactivated — this now takes effect immediately, even mid-session."
                                data-confirm-ok-label="Suspend">
                                Suspend customer
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="margin-top:16px;">
                            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                            <input type="hidden" name="customer_id" value="<?= (int) $c['id'] ?>">
                            <input type="hidden" name="form_action" value="toggle_active">
                            <button type="submit" class="btn btn-approve" style="width:100%;"
                                data-confirm-title="Reactivate this customer?"
                                data-confirm-text="They will be able to log in and order again."
                                data-confirm-ok-label="Reactivate">
                                Reactivate customer
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($canDelete): ?>
                    <form method="post" style="margin-top:8px;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="customer_id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="form_action" value="soft_delete">
                        <button type="submit" class="btn btn-outline danger" style="width:100%;"
                            data-confirm-title="Remove this customer?"
                            data-confirm-text="This hides the account and suspends login. It stays in the database and can only be restored via direct DB access."
                            data-confirm-ok-label="Remove">Remove customer</button>
                    </form>
                <?php endif; ?>

                <div class="modal-actions" style="margin-top:14px;">
                    <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                </div>
            </div>
        </dialog>
    <?php endforeach; ?>
    <?php endif; ?>
<?php require __DIR__ . '/_layout_foot.php'; ?>
