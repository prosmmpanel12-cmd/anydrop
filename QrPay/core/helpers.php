<?php
/**
 * QrPay — Shared Helpers
 * success() / fail() response envelopes + outbound HTTP calls.
 *
 * SSL verification is forced ON for every outbound call, including
 * Paytm status/refund calls. No exceptions — this was the one line
 * that mattered most in the old code's httpGet/httpPost.
 */

function success(array $data = [], string $msg = 'Success'): void {
    echo json_encode(array_merge(['status' => 'success', 'message' => $msg], $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function fail(string $msg, int $code = 400, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['status' => 'error', 'message' => $msg], $extra),
        JSON_UNESCAPED_SLASHES);
    exit;
}

function httpPost(string $url, array $fields, array $headers = [], int $timeoutSec = 10): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('httpPost cURL error [' . $url . ']: ' . curl_error($ch));
    }
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function httpGet(string $url, array $headers = [], int $timeoutSec = 10): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('httpGet cURL error [' . $url . ']: ' . curl_error($ch));
    }
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

/**
 * Generates a cryptographically random numeric OTP of the given length.
 * Used by auth/request_otp.php (Phase 3) — kept here since it's a
 * small stateless utility other things may also need.
 */
function generateOtp(int $length = 6): string {
    $max = (int) str_repeat('9', $length);
    $otp = random_int(0, $max);
    return str_pad((string) $otp, $length, '0', STR_PAD_LEFT);
}

/**
 * Constant-time-ish OTP hash check helper (hash first, then compare
 * with hash_equals to avoid timing leaks on the comparison itself).
 */
function verifyOtpHash(string $plainOtp, string $storedHash): bool {
    return hash_equals($storedHash, hash('sha256', $plainOtp));
}

function hashOtp(string $plainOtp): string {
    return hash('sha256', $plainOtp);
}

/**
 * ── Password helpers (auth/signup.php, auth/login.php, auth/reset_password.php) ──
 * Uses PHP's built-in password_hash/verify (bcrypt by default on this
 * PHP build) — never roll your own hashing. The cost factor is PHP's
 * current default, which password_hash() keeps sane on its own; no
 * need to pin it manually.
 */
function hashPassword(string $plainPassword): string {
    return password_hash($plainPassword, PASSWORD_DEFAULT);
}

function verifyPassword(string $plainPassword, string $storedHash): bool {
    return password_verify($plainPassword, $storedHash);
}

/**
 * Minimum bar for a new/reset password. Deliberately simple (length
 * only) — composition rules (must have a symbol, etc.) push people
 * toward predictable patterns more than they help; length is what
 * actually matters for brute-force resistance.
 */
function isPasswordStrongEnough(string $plainPassword): bool {
    return strlen($plainPassword) >= 8;
}

/**
 * ── Secure link tokens (email verification + password reset) ──
 * The random token is what goes in the emailed URL / is shown to the
 * user; only its HASH is ever stored, same pattern as OTP codes, so a
 * DB read alone can never be used to forge a valid link.
 */
function generateSecureToken(): string {
    return bin2hex(random_bytes(32));
}

function hashSecureToken(string $plainToken): string {
    return hash('sha256', $plainToken);
}

/**
 * Very forgiving mobile number check — 10 to 15 digits, optional
 * leading +. Intentionally not India-only (^[6-9]\d{9}$) so it doesn't
 * reject legitimate numbers; stricter per-country validation can be
 * layered on later in the panel if needed.
 */
function isValidMobileNumber(string $mobile): bool {
    return (bool) preg_match('/^\+?[0-9]{10,15}$/', $mobile);
}
