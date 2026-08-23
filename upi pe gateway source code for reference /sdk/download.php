<?php
/**
 * SDK Download — Logged-in user ki API key autofill karke ZIP deta hai
 */
session_start();
require_once __DIR__ . '/../config/db.php';

$apiKey = trim($_GET['apikey'] ?? $_SESSION['qrpay_apikey'] ?? '');
if (!$apiKey || !preg_match('/^[a-zA-Z0-9_-]{10,80}$/', $apiKey)) {
    http_response_code(400);
    echo 'Invalid or missing API key.';
    exit;
}

$zipName = 'UpiPeSDK_' . substr($apiKey, 0, 6) . '.zip';
$tmpDir  = (is_writable('/tmp') ? '/tmp' : (is_writable(__DIR__) ? __DIR__ : sys_get_temp_dir()));
$tmpZip  = $tmpDir . '/' . $zipName;

// ─── SDK class ────────────────────────────────────────────────
$sdkFile = __DIR__ . '/UpiPeSDK/UpiPe.php';
if (!file_exists($sdkFile)) {
    http_response_code(500);
    echo 'SDK file not found on server. Contact support.';
    exit;
}
$sdkContent = file_get_contents($sdkFile);

// ─── Example files (only what exists) ────────────────────────
$exampleFiles = [
    '01_basic_order.php'        => __DIR__ . '/examples/01_basic_order.php',
    '02_subscription_auto.php'  => __DIR__ . '/examples/02_subscription_auto.php',
    '03_download_after_pay.php' => __DIR__ . '/examples/03_download_after_pay.php',
    '04_wallet_fund_add.php'    => __DIR__ . '/examples/04_wallet_fund_add.php',
    'README.md'                 => __DIR__ . '/examples/README.md',
];

foreach ($exampleFiles as $name => $path) {
    if (!file_exists($path)) {
        http_response_code(500);
        echo "Missing file: examples/{$name} — Please re-upload the SDK folder.";
        exit;
    }
}

// ─── Autofilled quickstart ────────────────────────────────────
$quickStart = <<<PHP
<?php
/**
 * UpiPe Quick Start
 * API Key already filled: {$apiKey}
 * Upload this file to your server and use it directly.
 *
 * Auto verify: Save your Paytm MID in Panel → Settings → MID
 * Manual verify: Customer submits UTR after 5 minutes
 */
require_once __DIR__ . '/UpiPeSDK/UpiPe.php';

\$upi = new UpiPe(
    '{$apiKey}',
    'https://yourapi.42web.io/api/upi/api'
);

// ── CREATE ORDER ─────────────────────────────────────────
\$order = \$upi->createOrder(
    499,         // Amount in ₹
    'user_123',  // Your user/order ID
    'My Order',  // Note shown to customer
    'auto'       // 'auto' or 'manual'
);

if (\$order['status'] !== 'success') {
    die('Order failed: ' . \$order['message']);
}

echo "QR: "       . \$order['qr_url']   . PHP_EOL;
echo "Order ID: " . \$order['order_id'] . PHP_EOL;

// ── VERIFY (poll every 5 sec in real app) ────────────────
sleep(15);
\$result = \$upi->verifyPayment(\$order['order_id']);

if (\$result['status'] === 'paid') {
    echo "✅ Payment Verified! UTR: " . \$result['utr'];

} elseif (\$result['status'] === 'not_paid') {
    // Check if 5 min passed and UTR option is available
    if (\$result['manual_utr_available'] ?? false) {
        \$utr    = '123456789012'; // Get from customer
        \$result = \$upi->verifyPayment(\$order['order_id'], \$utr);
        echo "UTR submitted. Status: " . \$result['status'];
    } else {
        echo "Not paid yet. Retry in " . (\$result['manual_allowed_in'] ?? '?') . " seconds.";
    }
}
PHP;

// ─── Create ZIP ───────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Could not create ZIP in: ' . $tmpDir . ' — contact hosting support to enable write access.';
    exit;
}

$zip->addFromString('UpiPeSDK/UpiPe.php', $sdkContent);
$zip->addFromString('quickstart.php', $quickStart);

foreach ($exampleFiles as $zipPath => $realPath) {
    $zip->addFromString('examples/' . $zipPath, file_get_contents($realPath));
}

// README
$readme  = "# UpiPe PHP SDK\n\n";
$readme .= "**API Key (autofilled):** {$apiKey}\n";
$readme .= "**Base URL:** https://yourapi.42web.io/api/upi/api\n\n";
$readme .= "## Verification Flow\n\n";
$readme .= "- **0–5 min:** Auto verify (Paytm) runs every poll — `not_paid` + `manual_utr_available: false`\n";
$readme .= "- **5 min+:** Auto verify STILL runs + UTR input unlocks — `not_paid` + `manual_utr_available: true`\n";
$readme .= "- **UTR submitted:** Status → `manual_pending` → merchant approves → `paid`\n\n";
$readme .= "## Quick Start\n";
$readme .= "```php\nrequire 'UpiPeSDK/UpiPe.php';\n\$upi   = new UpiPe('{$apiKey}');\n\$order = \$upi->createOrder(499, 'user_123');\n\$res   = \$upi->verifyPayment(\$order['order_id']);\n```\n\n";
$readme .= "## Methods\n";
$readme .= "- `createOrder(\$amount, \$customerId, \$note, \$mode)` — create new order\n";
$readme .= "- `verifyPayment(\$orderId, \$utr='')` — auto verify or submit UTR (after 5 min)\n";
$readme .= "- `isPaid(\$orderId)` → bool\n";
$readme .= "- `getOrderStatus(\$orderId)` — fetch current DB status\n";
$readme .= "- `getManualOrders(\$status)` — list manual pending orders\n";
$readme .= "- `manualAction(\$orderId, \$action, \$reason)` — approve/reject\n\n";
$readme .= "## Examples\n";
$readme .= "- `quickstart.php` — ready to use with your API key\n";
$readme .= "- `examples/01_basic_order.php` — pay to unlock content\n";
$readme .= "- `examples/02_subscription_auto.php` — subscription with auto polling\n";
$readme .= "- `examples/03_download_after_pay.php` — digital file download\n";
$readme .= "- `examples/04_wallet_fund_add.php` — wallet top-up (manual UTR)\n";

$zip->addFromString('README.md', $readme);
$zip->close();

// ─── Send file ────────────────────────────────────────────────
if (!file_exists($tmpZip)) {
    http_response_code(500);
    echo 'ZIP generation failed.';
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
readfile($tmpZip);
unlink($tmpZip);
exit;
