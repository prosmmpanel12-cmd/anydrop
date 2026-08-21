<?php
/**
 * Anydrop — Admin Web UI: Login
 * Plain PHP session login against the existing `admins` table (same
 * table/password hashes the seed-admin.php script and the JSON
 * /api/v1/auth/admin/login endpoint both use) — see _bootstrap.php's
 * kdoc for why this uses sessions instead of the Bearer-token system.
 *
 * Since migration 29 (admin RBAC): also rejects deactivated admins at
 * login (not just on subsequent page loads) and stamps last_login_at.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$error = null;
if (isset($_GET['deactivated'])) {
    $error = 'Your admin account has been deactivated. Contact a Super Admin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter both username and password.';
    } else {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, username, password_hash, is_active FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash']) && !$admin['is_active']) {
            $error = 'Your admin account has been deactivated. Contact a Super Admin.';
            write_audit_log('admin', (int) $admin['id'], 'admin_login_blocked_inactive', ['username' => $username]);
        } elseif ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];

            $upd = $db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id');
            $upd->execute(['id' => (int) $admin['id']]);

            write_audit_log('admin', (int) $admin['id'], 'admin_login', ['username' => $username]);
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anydrop Admin — Login</title>
<link rel="stylesheet" href="assets/admin.css">
<script>
    (function(){var t=localStorage.getItem('anydrop_admin_theme');
        if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
<style>
    body.login-body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .login-card {
        padding: 34px 32px; width: 100%; max-width: 340px;
        animation: login-in 320ms cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes login-in { from { opacity: 0; transform: translateY(10px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    .login-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 22px; }
    .login-brand .mark { width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--accent), var(--accent-hover)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; }
    .login-brand h1 { font-size: 18px; margin: 0; }
    .login-card label.field-label { margin-top: 14px; }
    .login-card input { width: 100%; }
    .login-card button[type=submit] { width: 100%; margin-top: 22px; padding: 11px; font-size: 14.5px; justify-content: center; }
    .login-error { background: var(--danger-soft); color: var(--danger); padding: 10px 12px; border-radius: var(--radius-sm); font-size: 13px; margin-top: 16px; }
    .theme-toggle-corner { position: fixed; top: 16px; right: 16px; }
</style>
</head>
<body class="login-body">
    <button type="button" class="icon-btn theme-toggle-corner" id="themeToggleBtn" aria-label="Toggle dark mode">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sun"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-moon"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    </button>
    <div class="card login-card">
        <div class="login-brand">
            <span class="mark">AD</span>
            <h1>Anydrop Admin</h1>
        </div>
        <?php if ($error): ?>
            <div class="login-error"><?= admin_escape($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <label class="field-label" for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" autofocus required>
            <label class="field-label" for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit" class="btn btn-primary">Sign in</button>
        </form>
    </div>
    <script src="assets/admin.js"></script>
</body>
</html>
