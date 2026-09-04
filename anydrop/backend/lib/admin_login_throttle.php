<?php
/**
 * Anydrop — Admin Login Rate Limiting
 *
 * Implements docs/AnyDrop_Admin_Management_Plan.md §27 P1.2 (Login
 * Rate Limiting): failed-attempt counter, temporary lockout, IP
 * throttling, audit log. Schema: backend/sql/51_migration_admin_login_
 * rate_limit.sql (admins.failed_login_attempts / admins.locked_until +
 * new admin_login_attempts table).
 *
 * Two independent layers — see that migration's header for why both
 * are needed (per-account lockout alone doesn't stop one IP spraying
 * many usernames; per-IP throttling alone doesn't stop a targeted
 * attack against one known username from many IPs).
 *
 * Thresholds are constants here rather than admin-configurable
 * settings — this is a security control, not a business rule; doc 19's
 * settings_manage permission is for things like delivery charge/OTP
 * expiry, not "how many login attempts before lockout".
 */

require_once __DIR__ . '/../config/database.php';

/** Per-account: consecutive failures before lockout. */
const ADMIN_LOGIN_MAX_ACCOUNT_ATTEMPTS = 5;
/** Per-account: lockout duration once the threshold is hit. */
const ADMIN_LOGIN_ACCOUNT_LOCKOUT_MINUTES = 15;
/** Per-IP: failed attempts (across any usernames) allowed in the window below. */
const ADMIN_LOGIN_MAX_IP_ATTEMPTS = 20;
/** Per-IP: rolling window the above threshold is measured over. */
const ADMIN_LOGIN_IP_WINDOW_MINUTES = 15;

function admin_login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * True if this IP has thrown too many failed attempts (any username)
 * in the trailing window. Checked before touching the admins table at
 * all, so a spray attack never even reaches a per-account check.
 */
function admin_login_ip_is_throttled(string $ip): bool
{
    $db = Database::get();
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS c FROM admin_login_attempts
         WHERE ip_address = :ip AND was_successful = 0
           AND created_at > (NOW() - INTERVAL :mins MINUTE)"
    );
    $stmt->execute(['ip' => $ip, 'mins' => ADMIN_LOGIN_IP_WINDOW_MINUTES]);
    $row = $stmt->fetch();
    return ((int) ($row['c'] ?? 0)) >= ADMIN_LOGIN_MAX_IP_ATTEMPTS;
}

/** Records one login attempt (success or failure) for IP-level throttling. */
function admin_login_record_attempt(string $ip, string $username, bool $successful): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        'INSERT INTO admin_login_attempts (ip_address, username, was_successful) VALUES (:ip, :u, :s)'
    );
    $stmt->execute(['ip' => $ip, 'u' => $username, 's' => $successful ? 1 : 0]);
}

/**
 * True if this specific admin row is currently locked out
 * (locked_until is set and still in the future).
 */
function admin_login_account_is_locked(array $admin): bool
{
    return !empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time();
}

/** Minutes remaining on an active lockout, rounded up, minimum 1. */
function admin_login_lockout_minutes_remaining(array $admin): int
{
    $seconds = strtotime((string) $admin['locked_until']) - time();
    return max(1, (int) ceil($seconds / 60));
}

/**
 * Called on a failed password check for an admin row that exists.
 * Increments the counter; once it reaches the threshold, sets
 * locked_until and resets the counter to 0 (so the next successful
 * login after the lockout expires starts counting fresh, not already
 * primed to re-lock on its first later mistake).
 */
function admin_login_register_failure(int $adminId): void
{
    $db = Database::get();
    $stmt = $db->prepare('SELECT failed_login_attempts FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $adminId]);
    $row = $stmt->fetch();
    $attempts = ((int) ($row['failed_login_attempts'] ?? 0)) + 1;

    if ($attempts >= ADMIN_LOGIN_MAX_ACCOUNT_ATTEMPTS) {
        $upd = $db->prepare(
            'UPDATE admins SET failed_login_attempts = 0,
                locked_until = (NOW() + INTERVAL :mins MINUTE)
             WHERE id = :id'
        );
        $upd->execute(['mins' => ADMIN_LOGIN_ACCOUNT_LOCKOUT_MINUTES, 'id' => $adminId]);
    } else {
        $upd = $db->prepare('UPDATE admins SET failed_login_attempts = :a WHERE id = :id');
        $upd->execute(['a' => $attempts, 'id' => $adminId]);
    }
}

/** Called on a successful login: clears any counter/lockout state. */
function admin_login_register_success(int $adminId): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        'UPDATE admins SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
    );
    $stmt->execute(['id' => $adminId]);
}
