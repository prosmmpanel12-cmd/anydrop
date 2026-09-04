<?php
/**
 * Anydrop — Log Diagnostics (temporary debug page)
 *
 * Open this directly in a browser on the phone running KSWEB, e.g.
 * http://localhost/check-logs.php (adjust host/port to match however
 * the app itself reaches the backend).
 *
 * Answers, in order: where backend/logs/ actually is, whether this PHP
 * process can write to it right now, and — if not — where PHP's own
 * error_log is configured to go instead (php.ini's error_log
 * directive), since that's where anydrop_log_line() falls back to.
 *
 * DELETE THIS FILE once the logging issue is resolved — it exposes an
 * absolute filesystem path and PHP's ini config, which isn't something
 * a production API should leave publicly reachable indefinitely.
 */

header('Content-Type: text/plain; charset=utf-8');

$logDir = __DIR__ . '/logs';

echo "=== Anydrop Log Diagnostics ===\n\n";

echo "1. Expected log directory:\n   $logDir\n";
echo "   Exists: " . (is_dir($logDir) ? 'yes' : 'no') . "\n";
echo "   Writable (as PHP sees it): " . (is_writable($logDir) ? 'yes' : 'no') . "\n\n";

echo "2. Live write test:\n";
$testFile = $logDir . '/write-test-' . time() . '.tmp';
if (!is_dir($logDir)) {
    $mkdirOk = @mkdir($logDir, 0775, true);
    echo "   Directory didn't exist — mkdir() " . ($mkdirOk ? 'succeeded' : 'FAILED') . "\n";
}
$writeOk = @file_put_contents($testFile, "test\n");
if ($writeOk !== false) {
    echo "   Wrote a test file successfully: $testFile\n";
    @unlink($testFile);
    echo "   (cleaned up — writing to backend/logs/ IS working)\n";
} else {
    $err = error_get_last();
    echo "   FAILED to write. Last PHP error: " . ($err['message'] ?? 'unknown') . "\n";
    echo "   This confirms backend/logs/ is not writable by this PHP process\n";
    echo "   (on KSWEB/Android this is almost always a storage-permission issue).\n";
}

echo "\n3. PHP's own error_log destination (php.ini's `error_log` setting):\n";
$iniErrorLog = ini_get('error_log');
echo "   " . ($iniErrorLog ?: '(not set — PHP uses its SAPI default, often the webserver\'s own error log)') . "\n";
echo "   If backend/logs/ isn't writable, every anydrop_log_line() call\n";
echo "   (including the staff-login crash you're chasing) is landing here\n";
echo "   instead — open this path in KSWEB's own file browser, or check\n";
echo "   KSWEB's built-in Server/Error Log viewer if it has one.\n";

echo "\n4. Current PHP user context:\n";
echo "   getmyuid(): " . (function_exists('posix_getpwuid') ? (posix_getpwuid(getmyuid())['name'] ?? getmyuid()) : getmyuid()) . "\n";
echo "   __DIR__: " . __DIR__ . "\n";

echo "\n=== End diagnostics — delete this file when done ===\n";
