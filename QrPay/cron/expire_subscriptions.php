<?php
/**
 * QrPay — cron/expire_subscriptions.php
 *
 * Run daily: 0 0 * * * php /path/to/cron/expire_subscriptions.php
 *
 * Flips any 'active' subscription past its expires_at to 'expired'.
 * That's the ENTIRE job — it does not touch payment_orders or
 * usage_counters. Once a subscription flips to expired,
 * core/plan_limits.php::get_plan_status() naturally stops finding an
 * active subscription for that developer on the next create_order.php
 * call, so they fall back to "no active plan" (blocked).
 *
 * The free plan's own subscription row is given a 100-year expiry at
 * signup (auth/signup.php) specifically so this query never touches
 * it — no special-casing needed here.
 *
 * Safe to run from CLI only — refuses to run over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare(
    "UPDATE subscriptions
     SET status = 'expired'
     WHERE status = 'active' AND expires_at <= NOW()"
);
$stmt->execute();

$count = $stmt->rowCount();
echo date('Y-m-d H:i:s') . " — expired {$count} subscription(s).\n";
