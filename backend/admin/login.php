<?php
/**
 * Anydrop — Admin Web UI: Login
 * Plain PHP session login against the existing `admins` table (same
 * table/password hashes the seed-admin.php script and the JSON
 * /api/v1/auth/admin/login endpoint both use) — see _bootstrap.php's
 * kdoc for why this uses sessions instead of the Bearer-token system.
 */

require_once __DIR__ . '/_bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter both username and password.';
    } else {
        $db = Database::get();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anydrop Admin — Login</title>
<style>
    :root { color-scheme: light; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #f4f5f7; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
    .card { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); width: 100%; max-width: 340px; }
    h1 { font-size: 20px; margin: 0 0 20px; color: #1a1a1a; }
    label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; margin-top: 14px; }
    input[type=text], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
    button { width: 100%; margin-top: 22px; padding: 11px; background: #e6521f; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
    button:hover { background: #cc4816; }
    .error { background: #fdecea; color: #b3261e; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-top: 16px; }
</style>
</head>
<body>
    <div class="card">
        <h1>Anydrop Admin</h1>
        <?php if ($error): ?>
            <div class="error"><?= admin_escape($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" autofocus required>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
