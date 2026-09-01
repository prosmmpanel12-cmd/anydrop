<?php
/**
 * QrPay — Database Connection
 *
 * All credentials come from environment variables ONLY.
 * Never hardcode DB_HOST / DB_NAME / DB_USER / DB_PASS in this file.
 *
 * Populate the environment via your host's env-var settings, a
 * process manager, or an untracked .env loaded earlier in the
 * bootstrap (e.g. with vlucas/phpdotenv) — never commit real values.
 * See config/env.example for the full list of required variables.
 */

function qrpay_env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

$requiredEnv = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
$missing = [];
foreach ($requiredEnv as $key) {
    if (qrpay_env($key) === null) {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Server misconfigured: missing required environment variable(s): ' . implode(', ', $missing),
    ]);
    exit;
}

define('DB_HOST', qrpay_env('DB_HOST'));
define('DB_NAME', qrpay_env('DB_NAME'));
define('DB_USER', qrpay_env('DB_USER'));
define('DB_PASS', qrpay_env('DB_PASS'));
define('DB_PORT', qrpay_env('DB_PORT', '3306'));

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    // Never leak $e->getMessage() to the client — may contain host/user/dsn details.
    error_log('QrPay DB connection failed: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
    exit;
}

/**
 * QrPay's own payout identity (UPI ID / MID it receives subscription
 * payments on) lives ONLY in the admin_settings table — never in env,
 * never hardcoded. This is the single place every other file should
 * call through, so there's one source of truth and one place to fix
 * if the singleton row is ever missing.
 *
 * @return array{owner_upi_id:string,owner_mid:?string,display_name:string,email_verification_enabled:bool}|null
 */
function qrpay_admin_settings(PDO $pdo): ?array {
    $stmt = $pdo->prepare(
        'SELECT owner_upi_id, owner_mid, display_name, email_verification_enabled FROM admin_settings WHERE id = 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['email_verification_enabled'] = (bool) $row['email_verification_enabled'];
    return $row;
}

/**
 * Convenience wrapper — just the boolean. Used by auth/signup.php and
 * auth/login.php to decide whether a fresh signup needs to click an
 * emailed link before they can log in. System-wide (admin-controlled),
 * NOT per-user — contrast with developers.two_fa_enabled, which is.
 */
function qrpay_email_verification_required(PDO $pdo): bool {
    $settings = qrpay_admin_settings($pdo);
    return $settings !== null && $settings['email_verification_enabled'];
}
