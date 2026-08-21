<?php
/**
 * Anydrop — Admin Web UI: Roles & Permissions
 *
 * Implements docs/19_Admin_Panel_Full_Spec_And_Payment_Email_Architecture_
 * 2026-08-14.md §1's "checkbox grid the app owner described" — a named
 * role (Finance Admin, Restaurant Manager, ...) mapped to a subset of
 * admin_permissions, plus assigning admins onto roles / activating-
 * deactivating them. Reads/writes the schema from
 * backend/sql/29_migration_admin_rbac.sql.
 *
 * Gated on the `roles_manage` permission itself (not just "logged in")
 * — see backend/lib/admin_auth.php. Super Admin has every permission
 * including this one; a narrower role only reaches this page if the
 * app owner explicitly grants it `roles_manage`, which is the whole
 * point of the grid (nobody should be able to hand themselves more
 * permissions unless already granted that specific right).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'roles_manage');
$db = Database::get();

$flash = null;
$flashType = 'success';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'create_role') {
            $name = trim($_POST['role_name'] ?? '');
            if ($name === '') {
                $flash = 'Role name is required.';
                $flashType = 'error';
            } else {
                $exists = $db->prepare('SELECT id FROM admin_roles WHERE name = :n LIMIT 1');
                $exists->execute(['n' => $name]);
                if ($exists->fetch()) {
                    $flash = 'A role named "' . admin_escape($name) . '" already exists.';
                    $flashType = 'error';
                } else {
                    $ins = $db->prepare('INSERT INTO admin_roles (name, is_system_role) VALUES (:n, 0)');
                    $ins->execute(['n' => $name]);
                    write_audit_log('admin', $admin['id'], 'admin_role_created', ['role' => $name]);
                    $flash = 'Role "' . admin_escape($name) . '" created. Set its permissions below.';
                }
            }
        } elseif ($formAction === 'update_permissions') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $roleStmt = $db->prepare('SELECT id, name, is_system_role FROM admin_roles WHERE id = :id LIMIT 1');
            $roleStmt->execute(['id' => $roleId]);
            $role = $roleStmt->fetch();

            if (!$role) {
                $flash = 'Role not found.';
                $flashType = 'error';
            } elseif ($role['is_system_role']) {
                $flash = 'Super Admin always has every permission and can\'t be edited.';
                $flashType = 'error';
            } else {
                $selectedKeys = $_POST['permissions'] ?? [];
                $allPerms = $db->query('SELECT id, `key` FROM admin_permissions')->fetchAll();
                $keyToId = array_column($allPerms, 'id', 'key');

                $db->beginTransaction();
                $db->prepare('DELETE FROM admin_role_permissions WHERE role_id = :r')->execute(['r' => $roleId]);
                $insPerm = $db->prepare('INSERT INTO admin_role_permissions (role_id, permission_id) VALUES (:r, :p)');
                foreach ($selectedKeys as $key) {
                    if (isset($keyToId[$key])) {
                        $insPerm->execute(['r' => $roleId, 'p' => $keyToId[$key]]);
                    }
                }
                $db->commit();

                write_audit_log('admin', $admin['id'], 'admin_role_permissions_updated', [
                    'role_id' => $roleId,
                    'permissions' => array_values($selectedKeys),
                ]);
                $flash = 'Permissions updated for "' . admin_escape($role['name']) . '".';
            }
        } elseif ($formAction === 'create_admin') {
            $newUsername = trim($_POST['new_username'] ?? '');
            $newName = trim($_POST['new_name'] ?? '');
            $newEmail = trim($_POST['new_email'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            $newRoleId = (int) ($_POST['new_admin_role_id'] ?? 0);

            $roleCheck = $db->prepare('SELECT id, name FROM admin_roles WHERE id = :id LIMIT 1');
            $roleCheck->execute(['id' => $newRoleId]);
            $chosenRole = $roleCheck->fetch();

            if ($newUsername === '' || $newPassword === '') {
                $flash = 'Username and password are required.';
                $flashType = 'error';
            } elseif (strlen($newPassword) < 8) {
                $flash = 'Password must be at least 8 characters.';
                $flashType = 'error';
            } elseif (!$chosenRole) {
                $flash = 'Select a valid role.';
                $flashType = 'error';
            } else {
                $dupe = $db->prepare('SELECT id FROM admins WHERE username = :u LIMIT 1');
                $dupe->execute(['u' => $newUsername]);
                if ($dupe->fetch()) {
                    $flash = 'Username "' . admin_escape($newUsername) . '" is already taken.';
                    $flashType = 'error';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO admins (username, password_hash, role_id, name, email, is_active)
                         VALUES (:u, :p, :r, :n, :e, 1)'
                    );
                    $ins->execute([
                        'u' => $newUsername,
                        'p' => password_hash($newPassword, PASSWORD_BCRYPT),
                        'r' => $newRoleId,
                        'n' => $newName !== '' ? $newName : null,
                        'e' => $newEmail !== '' ? $newEmail : null,
                    ]);
                    write_audit_log('admin', $admin['id'], 'admin_created', [
                        'new_admin_username' => $newUsername,
                        'role_id' => $newRoleId,
                        'role_name' => $chosenRole['name'],
                    ]);
                    $flash = 'Admin "' . admin_escape($newUsername) . '" created with role "' . admin_escape($chosenRole['name']) . '".';
                }
            }
        } elseif ($formAction === 'assign_admin_role') {
            $targetAdminId = (int) ($_POST['admin_id'] ?? 0);
            $newRoleId = (int) ($_POST['new_role_id'] ?? 0);

            if ($targetAdminId === $admin['id']) {
                $flash = 'You can\'t change your own role from here (avoids accidentally locking yourself out).';
                $flashType = 'error';
            } else {
                $roleCheck = $db->prepare('SELECT id FROM admin_roles WHERE id = :id LIMIT 1');
                $roleCheck->execute(['id' => $newRoleId]);
                if (!$roleCheck->fetch()) {
                    $flash = 'Selected role not found.';
                    $flashType = 'error';
                } else {
                    $upd = $db->prepare('UPDATE admins SET role_id = :r WHERE id = :id');
                    $upd->execute(['r' => $newRoleId, 'id' => $targetAdminId]);
                    write_audit_log('admin', $admin['id'], 'admin_role_reassigned', [
                        'target_admin_id' => $targetAdminId,
                        'new_role_id' => $newRoleId,
                    ]);
                    $flash = 'Admin\'s role updated.';
                }
            }
        } elseif ($formAction === 'toggle_active') {
            $targetAdminId = (int) ($_POST['admin_id'] ?? 0);
            if ($targetAdminId === $admin['id']) {
                $flash = 'You can\'t deactivate your own account.';
                $flashType = 'error';
            } else {
                $upd = $db->prepare('UPDATE admins SET is_active = NOT is_active WHERE id = :id');
                $upd->execute(['id' => $targetAdminId]);
                write_audit_log('admin', $admin['id'], 'admin_active_toggled', ['target_admin_id' => $targetAdminId]);
                $flash = 'Admin status updated.';
            }
        }
    }
}

// ---------- Data for rendering ----------
$roles = $db->query('SELECT id, name, is_system_role FROM admin_roles ORDER BY is_system_role DESC, name ASC')->fetchAll();

$allPermissions = $db->query('SELECT id, `key`, module, action FROM admin_permissions ORDER BY module ASC, action ASC')->fetchAll();
$permissionsByModule = [];
foreach ($allPermissions as $p) {
    $permissionsByModule[$p['module']][] = $p;
}

$rolePermissionKeys = []; // role_id => [key => true]
$rpRows = $db->query(
    "SELECT rp.role_id, p.`key` FROM admin_role_permissions rp JOIN admin_permissions p ON p.id = rp.permission_id"
)->fetchAll();
foreach ($rpRows as $row) {
    $rolePermissionKeys[$row['role_id']][$row['key']] = true;
}

$editingRoleId = isset($_GET['edit_role']) ? (int) $_GET['edit_role'] : null;
$editingRole = null;
if ($editingRoleId) {
    foreach ($roles as $r) {
        if ((int) $r['id'] === $editingRoleId) {
            $editingRole = $r;
            break;
        }
    }
}

$admins = $db->query(
    'SELECT a.id, a.username, a.name, a.email, a.is_active, a.role_id, r.name AS role_name
     FROM admins a LEFT JOIN admin_roles r ON r.id = a.role_id
     ORDER BY a.username ASC'
)->fetchAll();

$csrf = admin_csrf_token();

$pageTitle = 'Roles & Permissions';
$activeNav = 'roles';
require __DIR__ . '/_layout_head.php';
?>
    <div class="section">
    <div class="card">
        <h2>Roles</h2>
        <div class="table-responsive">
        <table>
            <tr><th>Name</th><th>Type</th><th>Permissions</th><th></th></tr>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td><?= admin_escape($r['name']) ?></td>
                    <td><?= $r['is_system_role'] ? '<span class="badge system">System</span>' : 'Custom' ?></td>
                    <td><?= count($rolePermissionKeys[$r['id']] ?? []) ?> / <?= count($allPermissions) ?></td>
                    <td>
                        <?php if ($r['is_system_role']): ?>
                            <span class="muted">always full access</span>
                        <?php else: ?>
                            <a class="btn btn-outline" href="roles.php?edit_role=<?= (int) $r['id'] ?>">Edit permissions</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <form method="post" class="form-grid" style="margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="create_role">
            <input type="text" name="role_name" placeholder="New role name, e.g. Finance Admin" required>
            <button type="submit" class="btn btn-primary">Create role</button>
        </form>
    </div>
    </div>

    <?php if ($editingRole): ?>
    <div class="section">
    <div class="card">
        <h2>Edit permissions — <?= admin_escape($editingRole['name']) ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="update_permissions">
            <input type="hidden" name="role_id" value="<?= (int) $editingRole['id'] ?>">
            <?php foreach ($permissionsByModule as $module => $perms): ?>
                <div class="module" style="margin-bottom:14px;">
                    <h3 class="section-title" style="margin-bottom:6px;"><?= admin_escape($module) ?></h3>
                    <div class="perm-grid" style="display:flex; flex-wrap:wrap; gap:10px 18px;">
                        <?php foreach ($perms as $p): ?>
                            <label style="font-size:13px; display:flex; align-items:center; gap:5px;">
                                <input type="checkbox" name="permissions[]" value="<?= admin_escape($p['key']) ?>"
                                    <?= isset($rolePermissionKeys[$editingRole['id']][$p['key']]) ? 'checked' : '' ?>>
                                <?= admin_escape($p['action']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save permissions</button>
        </form>
    </div>
    </div>
    <?php endif; ?>

    <div class="section">
    <div class="card">
        <h2>Add Admin</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
            <input type="hidden" name="form_action" value="create_admin">
            <div>
                <label class="field-label">Username</label>
                <input type="text" name="new_username" required>
            </div>
            <div>
                <label class="field-label">Password</label>
                <input type="password" name="new_password" required minlength="8" placeholder="min 8 characters">
            </div>
            <div>
                <label class="field-label">Full name (optional)</label>
                <input type="text" name="new_name">
            </div>
            <div>
                <label class="field-label">Email (optional)</label>
                <input type="text" name="new_email">
            </div>
            <div>
                <label class="field-label">Role</label>
                <select name="new_admin_role_id" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['id'] ?>"><?= admin_escape($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Create admin</button>
        </form>
    </div>
    </div>

    <div class="section">
    <div class="card">
        <h2>Admins</h2>
        <div class="table-responsive">
        <table>
            <tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr>
            <?php foreach ($admins as $a): ?>
                <tr>
                    <td><?= admin_escape($a['username']) ?><?= $a['id'] === $admin['id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
                    <td><?= admin_escape($a['email'] ?: '—') ?></td>
                    <td>
                        <?php if ($a['id'] === $admin['id']): ?>
                            <?= admin_escape($a['role_name'] ?: '—') ?>
                        <?php else: ?>
                            <form method="post" style="display:flex; gap:6px; align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                                <input type="hidden" name="form_action" value="assign_admin_role">
                                <input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                <select name="new_role_id" onchange="this.form.submit()" data-no-loading>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === (int) $a['role_id'] ? 'selected' : '' ?>>
                                            <?= admin_escape($r['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $a['is_active'] ? 'active' : 'inactive' ?>"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td>
                        <?php if ($a['id'] !== $admin['id']): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                                <input type="hidden" name="form_action" value="toggle_active">
                                <input type="hidden" name="admin_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-outline"
                                    data-confirm-title="<?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= admin_escape($a['username']) ?>?"
                                    data-confirm-text="<?= $a['is_active'] ? 'They will not be able to log in until reactivated.' : 'They will be able to log in again.' ?>"
                                    data-confirm-ok-label="<?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?>">
                                    <?= $a['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    </div>
<?php require __DIR__ . '/_layout_foot.php'; ?>
