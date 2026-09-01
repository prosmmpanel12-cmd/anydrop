<?php
/**
 * QrPay — Panel auth guard
 * Include at the top of every panel/*.php page (except login.php itself).
 *
 * Redirects to login.php if there's no authenticated developer session.
 * On success, exposes:
 *   $QRPAY_DEVELOPER_ID    (int)
 *   $QRPAY_DEVELOPER_EMAIL (string)
 *   $QRPAY_IS_ADMIN        (bool)
 *
 * Note: this checks DASHBOARD session identity only. It has nothing to
 * do with the apikey used on /api/* — those are separate credentials
 * by design (see auth/verify_otp.php).
 */

require_once __DIR__ . '/../core/session.php';

$qrpaySessionInfo = qrpay_require_login(); // redirects + exits if not logged in

$QRPAY_DEVELOPER_ID    = $qrpaySessionInfo['developer_id'];
$QRPAY_DEVELOPER_EMAIL = $qrpaySessionInfo['email'];
$QRPAY_IS_ADMIN        = $qrpaySessionInfo['is_admin'];
