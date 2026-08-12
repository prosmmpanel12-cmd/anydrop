<?php
/**
 * Anydrop — One-Time Admin Seed Script
 *
 * Run this ONCE by visiting it in your browser:
 *   https://yourdomain.infinityfreeapp.com/scripts/seed-admin.php?key=SEED_ME&username=admin&password=YourStrongPassword
 *
 * Then DELETE this file (or rename it) — leaving it live is a security risk.
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

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare('INSERT INTO admins (username, password_hash, role) VALUES (:u, :p, :r)');
$stmt->execute(['u' => $username, 'p' => $hash, 'r' => 'super_admin']);

echo "Admin '{$username}' created successfully. DELETE THIS FILE NOW.";
