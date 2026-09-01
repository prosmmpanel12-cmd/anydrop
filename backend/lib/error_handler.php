<?php
/**
 * Anydrop — Central Error/Exception Logging + Leak Prevention
 *
 * Item 26 (Security Hardening) checklist's "Error logging" line —
 * before this, only 12 scattered error_log() calls existed across the
 * whole codebase (lib/notifications.php, lib/fcm.php, lib/support.php),
 * nothing caught uncaught exceptions or fatal errors, and
 * display_errors was never explicitly turned off — meaning a raw PHP
 * stack trace (absolute file paths, sometimes query fragments) could
 * leak straight into an API JSON response or the admin panel's HTML on
 * any unhandled crash. This file closes both gaps at once, from a
 * single include point.
 *
 * Wired in from config/config.php, which every API endpoint and every
 * admin page already loads first (via config/database.php /
 * admin/_bootstrap.php) — so this installs itself exactly once per
 * request with zero changes needed in any of the ~100+ individual
 * endpoint/page files.
 *
 * Deliberately does NOT log request bodies/query strings — those can
 * contain OTPs, passwords, or auth tokens. Method + path + error
 * message + file:line is enough to locate almost any bug; anything
 * needing more goes in a manual, per-incident debug log, not a
 * standing default.
 */

if (!defined('ANYDROP_ERROR_HANDLER_INSTALLED')) {
    define('ANYDROP_ERROR_HANDLER_INSTALLED', true);

    // Never let a raw error/stack trace reach the response body — that's
    // the actual leak this file exists to close. Logging (below) is
    // controlled separately from display, so nothing is lost, only
    // hidden from the client.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $GLOBALS['__anydrop_log_dir'] = __DIR__ . '/../logs';

    /** Human-readable name for a PHP error-severity constant. */
    function anydrop_severity_name(int $severity): string
    {
        $names = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        return $names[$severity] ?? ('UNKNOWN(' . $severity . ')');
    }

    /** "METHOD /path" only — see file kdoc for why the body is excluded. */
    function anydrop_request_context(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '(no request)';
        return "$method $uri";
    }

    function anydrop_is_api_request(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, '/api/');
    }

    /**
     * Appends one line to today's log file, AND always also calls PHP's
     * own error_log() regardless of whether the custom write succeeded.
     *
     * Originally this only fell back to error_log() when the logs/
     * write failed. On KSWEB (Android) that fallback is the ONLY path
     * that ever actually fires in practice — Android's scoped storage
     * commonly blocks a PHP process from creating/writing files outside
     * locations the app was explicitly granted, so @mkdir()/
     * @file_put_contents() below silently fail every time without ever
     * throwing, and every log line was quietly going to error_log()'s
     * destination instead of backend/logs/ — which is exactly why that
     * folder stayed empty even while real errors were happening.
     * Calling error_log() unconditionally (not just as a fallback)
     * means there's always at least one guaranteed place to look — see
     * this file's own top for where that KSWEB log actually lives.
     */
    function anydrop_log_line(string $line): void
    {
        $dir = $GLOBALS['__anydrop_log_dir'];
        $dirReady = is_dir($dir) || @mkdir($dir, 0775, true);
        $written = false;
        if ($dirReady) {
            $file = $dir . '/php-error-' . date('Y-m-d') . '.log';
            $entry = '[' . date('c') . '] ' . $line . PHP_EOL;
            $written = @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
        }
        // Always also goes to PHP's own configured error_log — on KSWEB
        // this is usually visible from inside the KSWEB app itself
        // (its own Server/Error Log viewer), independent of whatever
        // storage permission backend/logs/ does or doesn't have.
        error_log('[anydrop] ' . $line . ($written === false ? ' (also: could not write to ' . $dir . ' — check KSWEB storage permission)' : ''));
    }

    // One-time, at-boot diagnostic — not tied to any particular
    // request's error, just answers "can this process write to
    // backend/logs/ at all" directly so it doesn't have to be inferred
    // from silence. Cheap (one is_writable() call) so it's fine to run
    // on every request; only actually logs when something's wrong.
    if (!is_dir($GLOBALS['__anydrop_log_dir'])) {
        @mkdir($GLOBALS['__anydrop_log_dir'], 0775, true);
    }
    if (!is_writable($GLOBALS['__anydrop_log_dir'])) {
        error_log('[anydrop] WARNING: ' . $GLOBALS['__anydrop_log_dir'] . ' is not writable by the PHP process — '
            . 'on KSWEB (Android) this is almost always a storage-permission issue, not a code bug. '
            . 'Check: Android Settings > Apps > KSWEB > Permissions > Files/Storage (grant "Allow management of all files" '
            . 'on Android 11+), and check KSWEB\'s own PHP user/working directory has write access to this path.');
    }

    /** Generic 500 body — JSON for API routes, a plain HTML page otherwise. */
    function anydrop_send_generic_error(): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        if (anydrop_is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'data' => null, 'error' => 'internal_server_error']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><body style="font-family:sans-serif;padding:40px;text-align:center;color:#444">'
                . '<h2>Something went wrong</h2>'
                . '<p>The error has been logged. Please try again, or contact support if this keeps happening.</p>'
                . '</body></html>';
        }
    }

    // Warnings/notices/deprecations — logged, not fatal, execution
    // continues exactly as it would without this handler installed
    // (returning false keeps PHP's own internal logging path active too,
    // which with display_errors off above means "logged, never printed").
    set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $severity)) {
            // Respects a local @-suppression or a narrower
            // error_reporting() mask set elsewhere, same as PHP's
            // default behavior would.
            return false;
        }
        anydrop_log_line(sprintf(
            '%s: %s in %s:%d | %s',
            anydrop_severity_name($severity),
            $message,
            $file,
            $line,
            anydrop_request_context()
        ));
        return false;
    });

    // Any exception/Error that escapes every try/catch in the app.
    set_exception_handler(function (Throwable $e): void {
        anydrop_log_line(sprintf(
            'Uncaught %s: %s in %s:%d | %s' . PHP_EOL . '%s',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            anydrop_request_context(),
            $e->getTraceAsString()
        ));
        anydrop_send_generic_error();
    });

    // Fatal errors (E_ERROR/E_PARSE/etc.) don't reach set_error_handler
    // at all — this is the only way to catch them, checked once at the
    // very end of the request via error_get_last().
    register_shutdown_function(function (): void {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if ($error !== null && in_array($error['type'], $fatalTypes, true)) {
            anydrop_log_line(sprintf(
                'Fatal %s: %s in %s:%d | %s',
                anydrop_severity_name($error['type']),
                $error['message'],
                $error['file'],
                $error['line'],
                anydrop_request_context()
            ));
            anydrop_send_generic_error();
        }
    });
}
