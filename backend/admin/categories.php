<?php
/**
 * Anydrop — Admin Web UI: Category Management
 *
 * Implements recall.md item 16 / docs/19_Admin_Panel_Full_Spec_And_Payment_
 * Email_Architecture_2026-08-14.md — Category Management section.
 *
 * Two DELIBERATELY SEPARATE lists on one page (tabs, not a merged table)
 * because they're different concepts that recall.md item 16 specifically
 * warns not to conflate:
 *
 *   Restaurant Types — business TYPE (Cafe/Bakery/Sweet Shop/Pharmacy/
 *   Grocery/Restaurant). One per restaurant (restaurants.
 *   restaurant_category_id, migration 32). Used for restaurant-side
 *   classification/filtering, not a Home-screen chip.
 *
 *   Food Categories — Home-screen food-type chips (Pizza/Burger/Biryani).
 *   Table already existed (migration 05) but was DB-seeded only with no
 *   admin UI — this page is what adds that UI on top of the existing
 *   food_categories/menu_item_categories schema, nothing new added to
 *   that table here.
 *
 * Neither of these is `menu_categories` (migration 22/28) — that's a
 * restaurant's own menu sections (Starters/Mains), restaurant-managed,
 * out of scope for this page.
 *
 * Delete is hard but guarded — a Restaurant Type can't be deleted while
 * any restaurant still has that restaurant_category_id, and a Food
 * Category can't be deleted while any menu item still carries it
 * (menu_item_categories). Deactivate (is_active) is the safe way to
 * retire either one without breaking a live FK reference, same
 * reasoning as areas.php's delete guard.
 *
 * Gated on `categories_view` to see this page, `categories_edit` to
 * create/edit/toggle, `categories_delete` to hard-delete — all three
 * keys already existed in migration 29's permission seed (unused until
 * now), so no new RBAC migration was needed for this page.
 *
 * STATUS: 🟡 IMPLEMENTED 2026-08-21 — TEST PENDING, same as every other
 * admin page built this cycle. Needs migration 32 run on the live DB and
 * a live click-through (add/edit/deactivate/delete-blocked/delete-empty,
 * both tabs) before this moves to ✅ DONE — do not mark it done without
 * that, per the project's own standing rule (see done.md).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'categories_view');
$canEdit = admin_has_permission($admin['id'], 'categories_edit');
$canDelete = admin_has_permission($admin['id'], 'categories_delete');
$db = Database::get();

$tab = ($_GET['tab'] ?? '') === 'food' ? 'food' : 'restaurant';
$table = $tab === 'food' ? 'food_categories' : 'restaurant_categories';

function slugify(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
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
        $postTab = ($_POST['tab'] ?? '') === 'food' ? 'food' : 'restaurant';
        $postTable = $postTab === 'food' ? 'food_categories' : 'restaurant_categories';
        $label = $postTab === 'food' ? 'Food category' : 'Restaurant type';

        if ($formAction === 'create_category') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to add categories.';
                $flashType = 'error';
            } else {
                $name = trim($_POST['name'] ?? '');
                $iconUrl = trim($_POST['icon_url'] ?? '') !== '' ? trim($_POST['icon_url']) : null;
                $sortOrder = trim($_POST['sort_order'] ?? '') !== '' ? (int) $_POST['sort_order'] : 0;

                if ($name === '') {
                    $flash = 'Name is required.';
                    $flashType = 'error';
                } else {
                    $slug = slugify($name);
                    $dupStmt = $db->prepare("SELECT id FROM {$postTable} WHERE slug = :s LIMIT 1");
                    $dupStmt->execute(['s' => $slug]);
                    if ($dupStmt->fetch()) {
                        $flash = 'A category with that name (or a very similar one) already exists.';
                        $flashType = 'error';
                    } else {
                        $ins = $db->prepare(
                            "INSERT INTO {$postTable} (name, slug, icon_url, sort_order, is_active)
                             VALUES (:n, :s, :i, :so, 1)"
                        );
                        $ins->execute(['n' => $name, 's' => $slug, 'i' => $iconUrl, 'so' => $sortOrder]);
                        write_audit_log('admin', $admin['id'], 'category_created', [
                            'table' => $postTable, 'name' => $name,
                        ]);
                        $flash = "{$label} \"" . admin_escape($name) . '" created.';
                    }
                }
            }
        } elseif ($formAction === 'update_category') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit categories.';
                $flashType = 'error';
            } else {
                $catId = (int) ($_POST['category_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $iconUrl = trim($_POST['icon_url'] ?? '') !== '' ? trim($_POST['icon_url']) : null;
                $sortOrder = trim($_POST['sort_order'] ?? '') !== '' ? (int) $_POST['sort_order'] : 0;

                if ($name === '') {
                    $flash = 'Name is required.';
                    $flashType = 'error';
                } else {
                    $upd = $db->prepare(
                        "UPDATE {$postTable} SET name = :n, icon_url = :i, sort_order = :so WHERE id = :id"
                    );
                    $upd->execute(['n' => $name, 'i' => $iconUrl, 'so' => $sortOrder, 'id' => $catId]);
                    write_audit_log('admin', $admin['id'], 'category_updated', [
                        'table' => $postTable, 'category_id' => $catId, 'name' => $name,
                    ]);
                    $flash = "{$label} updated.";
                }
            }
        } elseif ($formAction === 'toggle_active') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit categories.';
                $flashType = 'error';
            } else {
                $catId = (int) ($_POST['category_id'] ?? 0);
                $db->prepare("UPDATE {$postTable} SET is_active = NOT is_active WHERE id = :id")
                    ->execute(['id' => $catId]);
                write_audit_log('admin', $admin['id'], 'category_active_toggled', [
                    'table' => $postTable, 'category_id' => $catId,
                ]);
                $flash = "{$label} status updated.";
            }
        } elseif ($formAction === 'delete_category') {
            if (!$canDelete) {
                $flash = 'You don\'t have permission to delete categories.';
                $flashType = 'error';
            } else {
                $catId = (int) ($_POST['category_id'] ?? 0);

                if ($postTab === 'food') {
                    $refStmt = $db->prepare('SELECT COUNT(*) AS c FROM menu_item_categories WHERE food_category_id = :id');
                } else {
                    $refStmt = $db->prepare('SELECT COUNT(*) AS c FROM restaurants WHERE restaurant_category_id = :id');
                }
                $refStmt->execute(['id' => $catId]);
                $refCount = (int) $refStmt->fetch()['c'];

                if ($refCount > 0) {
                    $what = $postTab === 'food' ? 'menu item(s)' : 'restaurant(s)';
                    $flash = "Can't delete — {$refCount} {$what} still use this category. Deactivate it instead, or reassign those first.";
                    $flashType = 'error';
                } else {
                    $db->prepare("DELETE FROM {$postTable} WHERE id = :id")->execute(['id' => $catId]);
                    write_audit_log('admin', $admin['id'], 'category_deleted', [
                        'table' => $postTable, 'category_id' => $catId,
                    ]);
                    $flash = "{$label} deleted.";
                }
            }
        }
    }
}

// ---------- Data for rendering ----------
$allCategories = $db->query("SELECT * FROM {$table} ORDER BY sort_order, name")->fetchAll();

// Usage counts, so the list shows why a delete might be blocked before the admin even tries.
$usageCounts = [];
if ($tab === 'food') {
    foreach ($db->query('SELECT food_category_id, COUNT(*) AS c FROM menu_item_categories GROUP BY food_category_id')->fetchAll() as $row) {
        $usageCounts[$row['food_category_id']] = (int) $row['c'];
    }
} else {
    foreach ($db->query('SELECT restaurant_category_id, COUNT(*) AS c FROM restaurants WHERE restaurant_category_id IS NOT NULL GROUP BY restaurant_category_id')->fetchAll() as $row) {
        $usageCounts[$row['restaurant_category_id']] = (int) $row['c'];
    }
}

$editingId = isset($_GET['edit_category']) ? (int) $_GET['edit_category'] : null;
$editingCategory = null;
if ($editingId) {
    foreach ($allCategories as $c) {
        if ((int) $c['id'] === $editingId) {
            $editingCategory = $c;
            break;
        }
    }
}

$csrf = admin_csrf_token();

$pageTitle = 'Categories';
$activeNav = 'categories';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <div style="display:flex; gap:8px; margin-bottom:12px;">
        <a class="btn <?= $tab === 'restaurant' ? 'btn-primary' : 'btn-outline' ?>" href="?tab=restaurant">Restaurant Types</a>
        <a class="btn <?= $tab === 'food' ? 'btn-primary' : 'btn-outline' ?>" href="?tab=food">Food Categories</a>
    </div>
    <p class="hint">
        <?php if ($tab === 'restaurant'): ?>
            Business type shown on a restaurant's profile/onboarding (Cafe, Bakery, Sweet Shop...). One per restaurant.
        <?php else: ?>
            Home-screen chips customers tap to browse by food type (Pizza, Burger, Biryani...). Restaurants tag menu items with these — they can't create new ones.
        <?php endif; ?>
    </p>

    <?php if (empty($allCategories)): ?>
        <p class="muted">No categories yet — add one below to get started.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Name</th><th>Icon</th><th>Order</th><th>In use</th><th>Status</th><th></th></tr>
        <?php foreach ($allCategories as $c): ?>
        <tr>
            <td><?= admin_escape($c['name']) ?></td>
            <td>
                <?php if (!empty($c['icon_url'])): ?>
                    <img src="<?= admin_escape($c['icon_url']) ?>" alt="" style="width:24px; height:24px; object-fit:contain;">
                <?php else: ?>
                    <span class="muted">none</span>
                <?php endif; ?>
            </td>
            <td><?= (int) $c['sort_order'] ?></td>
            <td><?= (int) ($usageCounts[$c['id']] ?? 0) ?></td>
            <td><span class="badge <?= $c['is_active'] ? 'active' : 'inactive' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="row-actions">
                <?php if ($canEdit): ?>
                    <a class="btn btn-outline" href="?tab=<?= $tab ?>&edit_category=<?= (int) $c['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="toggle_active">
                        <input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn btn-outline"><?= $c['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="delete_category">
                        <input type="hidden" name="tab" value="<?= $tab ?>">
                        <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn btn-outline danger"
                            data-confirm-title="Delete &quot;<?= admin_escape($c['name']) ?>&quot;?"
                            data-confirm-text="Only works if nothing is using this category."
                            data-confirm-ok-label="Delete">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php if ($canEdit): ?>
<div class="section">
<div class="card">
    <h2><?= $editingCategory ? 'Edit category' : 'Add category' ?></h2>
    <form method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="<?= $editingCategory ? 'update_category' : 'create_category' ?>">
        <input type="hidden" name="tab" value="<?= $tab ?>">
        <?php if ($editingCategory): ?>
            <input type="hidden" name="category_id" value="<?= (int) $editingCategory['id'] ?>">
        <?php endif; ?>
        <div>
            <label class="field-label">Name</label>
            <input type="text" name="name" required value="<?= admin_escape($editingCategory['name'] ?? '') ?>"
                   placeholder="<?= $tab === 'restaurant' ? 'e.g. Bakery' : 'e.g. Biryani' ?>">
        </div>
        <div>
            <label class="field-label">Icon URL (optional)</label>
            <input type="text" name="icon_url" value="<?= admin_escape($editingCategory['icon_url'] ?? '') ?>" placeholder="https://...">
        </div>
        <div>
            <label class="field-label">Sort order</label>
            <input type="number" name="sort_order" value="<?= (int) ($editingCategory['sort_order'] ?? 0) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?= $editingCategory ? 'Save' : 'Add' ?></button>
        <?php if ($editingCategory): ?>
            <a href="?tab=<?= $tab ?>" class="btn btn-outline">Cancel</a>
        <?php endif; ?>
    </form>
</div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_foot.php'; ?>
