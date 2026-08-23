<?php
// Har panel page ke top pe include karo
session_start();
if (empty($_SESSION['qrpay_apikey'])) {
    header('Location: login.php');
    exit;
}
$PANEL_APIKEY   = $_SESSION['qrpay_apikey'];
$PANEL_DAILY    = $_SESSION['qrpay_daily_left']   ?? '—';
$PANEL_MONTHLY  = $_SESSION['qrpay_monthly_left'] ?? '—';
$PANEL_EXPIRY   = $_SESSION['qrpay_expiry']       ?? '—';
$PANEL_PROVIDER = $_SESSION['qrpay_provider']     ?? '—';
$PANEL_DEV      = $_SESSION['qrpay_developer']    ?? '—';
