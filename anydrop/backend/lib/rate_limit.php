<?php
/**
 * Anydrop — Generic per-IP signup rate limiting (migration 70).
 *
 * Built for the gap doc 79 flagged on rider-signup.php ("no rate-limit
 * guard beyond the existing OTP cooldown") — the OTP cooldown only
 * throttles repeat requests for the *same email*; it does nothing to
 * stop one IP from cycling through many different emails (each
 * needing its own real OTP email sent, so this is also a
 * cost/abuse concern for the email-provider quota, not just a
 * fake-account concern).
 *
 * Deliberately written generic (not `rider_signup_rate_limit_check()`)
 * since restaurant-signup.php has the identical gap per doc 79's own
 * note — this can be dropped into that endpoint later with a
 * different `$endpoint` string and no other changes.
 *
 * Configurable via app_settings (get_setting() already returns the
 * given default when no row exists, so no seed migration is needed
 * for these two keys to work out of the box):
 *   - signup_rate_limit_max_attempts   (default 5)
 *   - signup_rate_limit_window_minutes (default 60)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/response.php';

/**
 * Checks whether the current request's IP has exceeded the configured
 * signup attempt threshold for the given endpoint within the
 * configured rolling window. Sends a 429 response and exits (same
 * shape as rider-request-otp.php's `otp_request_cooldown` error) if
 * the limit is exceeded — callers don't need to handle a return value
 * for the "over limit" case, only call this before doing any signup
 * work.
 */
function rate_limit_check_signup(string $endpoint): void
{
    $maxAttempts = (int) get_setting('signup_rate_limit_max_attempts', 5);
    $windowMinutes = (int) get_setting('signup_rate_limit_window_minutes', 60);

    if ($maxAttempts <= 0) {
        return; // 0 or negative = rate limiting disabled for this deployment
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db = Database::get();

    $since = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS c, MIN(created_at) AS oldest FROM signup_attempts
         WHERE endpoint = :endpoint AND ip_address = :ip AND created_at >= :since'
    );
    $stmt->execute(['endpoint' => $endpoint, 'ip' => $ip, 'since' => $since]);
    $row = $stmt->fetch();
    $count = (int) ($row['c'] ?? 0);

    if ($count >= $maxAttempts) {
        // Retry-after = time until the oldest attempt in the window ages out,
        // same "tell them exactly how long to wait" courtesy as the OTP cooldown.
        $oldestTimestamp = strtotime($row['oldest']);
        $retryAfterSeconds = max(1, ($oldestTimestamp + $windowMinutes * 60) - time());
        respond_error('signup_rate_limited', 429, [
            'retry_after_seconds' => $retryAfterSeconds,
        ]);
    }
}

/**
 * Logs a signup attempt (call after the outcome is known — success or
 * a rejection past the rate-limit check itself, e.g. validation_error,
 * email_already_registered). Deliberately NOT logged on the
 * rate-limit rejection itself — that would let a blocked IP keep
 * pushing its own window further out forever and never recover;
 * only genuine attempts against the real signup logic count.
 */
function rate_limit_log_signup(string $endpoint, ?string $email, bool $wasSuccessful): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $db = Database::get();
    $stmt = $db->prepare(
        'INSERT INTO signup_attempts (endpoint, ip_address, email, was_successful) VALUES (:endpoint, :ip, :email, :ok)'
    );
    $stmt->execute([
        'endpoint' => $endpoint,
        'ip' => $ip,
        'email' => $email,
        'ok' => $wasSuccessful ? 1 : 0,
    ]);
}
