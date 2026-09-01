<?php
/**
 * QrPay — GET /panel/logout.php
 * Destroys the developer's dashboard session. Does not touch the
 * apikey (that's a separate, unrelated credential — see auth/verify_otp.php).
 */

require_once __DIR__ . '/../core/session.php';

qrpay_session_start();

$_SESSION = [];

// Clear the session cookie itself, not just the server-side data.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: /panel/login.php');
exit;
