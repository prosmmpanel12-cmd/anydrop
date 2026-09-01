<?php
/**
 * QrPay — Session bootstrap
 *
 * Single place that starts the session with hardened cookie params.
 * Every entry point (auth endpoints, panel pages) should require this
 * INSTEAD OF calling session_start() directly, so the flags can never
 * drift out of sync between files.
 *
 * Session identity is developer_id (email/OTP login) — completely
 * separate from the API key used for /api/* Authorization headers.
 * A leaked API key can no longer log anyone into the dashboard.
 */

function qrpay_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // true in production (HTTPS); allow http locally for dev
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('qrpay_session');
    session_start();
}

/**
 * Call at the top of any panel page. Redirects to login if there's no
 * authenticated developer session, and exposes $QRPAY_DEVELOPER_ID /
 * $QRPAY_DEVELOPER_EMAIL for the rest of the page.
 */
function qrpay_require_login(): array {
    qrpay_session_start();

    if (empty($_SESSION['developer_id'])) {
        header('Location: /panel/login.php');
        exit;
    }

    return [
        'developer_id' => (int) $_SESSION['developer_id'],
        'email'        => $_SESSION['developer_email'] ?? '',
        'is_admin'     => (bool) ($_SESSION['is_admin'] ?? false),
    ];
}

/**
 * Same check as qrpay_require_login(), but for JSON API endpoints that
 * are called from dashboard JS (e.g. api/subscribe.php, Phase 5) rather
 * than rendered panel pages — a redirect makes no sense for a fetch()
 * call, so this exits with a JSON 401 instead.
 *
 * Still DASHBOARD session identity only, same as qrpay_require_login();
 * has nothing to do with the /api/* apikey mechanism.
 */
function qrpay_require_login_json(): array {
    qrpay_session_start();

    if (empty($_SESSION['developer_id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Not logged in. Please log in again.']);
        exit;
    }

    return [
        'developer_id' => (int) $_SESSION['developer_id'],
        'email'        => $_SESSION['developer_email'] ?? '',
        'is_admin'     => (bool) ($_SESSION['is_admin'] ?? false),
    ];
}
