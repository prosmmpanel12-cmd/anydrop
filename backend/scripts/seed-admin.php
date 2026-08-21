<?php
/**
 * Anydrop — One-Time Admin Seed Script
 *
 * Run this ONCE by visiting it in your browser:
 *   https://yourdomain.infinityfreeapp.com/scripts/seed-admin.php?key=SEED_ME&username=admin&password=YourStrongPassword
 *
 * Then DELETE this file (or rename it) — leaving it live is a security risk.
 *
 * Since migration 29 (admin RBAC, sql/29_migration_admin_rbac.sql):
 * `admins.role` (the old ENUM) is gone — this now assigns the seeded
 * admin the "Super Admin" role_id (every permission) instead. Requires
 * migration 29 to have been run first (else the Super Admin role row
 * won't exist yet and this script will report that clearly rather than
 * inserting a broken admins row).
 */

require_once __DIR__ . '/../config/database.php';

$seedKey = $_GET['key'] ?? '';
if ($seedKey !== 'SEED_ME') {
    http_response_code(403);
    echo 'Forbidden. Pass ?key=SEED_ME to run this script (edit the key in this file first for safety).';
    exit;
}

$username = $_GET['username'] ?? '';
$password = $_GET['password'] ?? '';

if (!$username || !$password) {
    echo 'Usage: seed-admin.php?key=SEED_ME&username=admin&password=YourStrongPassword';
    exit;
}

$db = Database::get();

$check = $db->prepare('SELECT id FROM admins WHERE username = :u LIMIT 1');
$check->execute(['u' => $username]);
if ($check->fetch()) {
    echo "Admin '{$username}' already exists. Nothing done.";
    exit;
}

$roleStmt = $db->prepare("SELECT id FROM admin_roles WHERE name = 'Super Admin' LIMIT 1");
$roleStmt->execute();
$role = $roleStmt->fetch();

if (!$role) {
    http_response_code(500);
    echo 'Super Admin role not found — run backend/sql/29_migration_admin_rbac.sql first, then retry.';
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare('INSERT INTO admins (username, password_hash, role_id, is_active) VALUES (:u, :p, :r, 1)');
$stmt->execute(['u' => $username, 'p' => $hash, 'r' => (int) $role['id']]);

echo "Admin '{$username}' created successfully as Super Admin. DELETE THIS FILE NOW.";
