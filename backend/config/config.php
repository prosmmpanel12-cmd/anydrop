<?php
/**
 * Anydrop — Database Configuration
 *
 * LOCAL DEV SETUP (KS Web on Android, phone-only testing)
 * MySQL: localhost / root / (no password)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'anydrop');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', 3306);

// A random secret used to sign auth tokens. Change this to any long random string.
define('APP_SECRET', 'anydrop_local_dev_secret_change_later');

// Must match the `cron_secret_key` row in app_settings (used by /system/cron/* endpoints)
define('CRON_SECRET', 'anydrop_local_cron_secret_change_later');

// Timezone — keep consistent across the whole backend
date_default_timezone_set('Asia/Kolkata');

// Item 26 (Security Hardening) — logs uncaught errors/exceptions/fatals
// and stops raw PHP stack traces from leaking into API responses or the
// admin panel. See lib/error_handler.php's own kdoc for the full
// rationale. Installed here since this file is the earliest point every
// request already loads, via config/database.php.
require_once __DIR__ . '/../lib/error_handler.php';
