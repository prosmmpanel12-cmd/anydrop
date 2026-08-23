<?php
// ─── QRPay Auth Config ────────────────────────────────────────────────────────
// Tumhara YourApi verify endpoint
define('VERIFY_URL', 'https://yourapi.42web.io/api/auth/verify.php');

// ⚠️ YAHAN APNI KEY_ID AUR SECRET DAALO
define('KEY_ID',  '804808339cc20c69');
define('SECRET',  '9b1c510e8df306b016de980724cf5851');

function verifyApiKey(PDO $pdo, string $apiKey, string $keyId = '', string $secret = ''): array {
    if (!$apiKey || !preg_match('/^[a-zA-Z0-9_-]{10,80}$/', $apiKey)) {
        return ['valid' => false, 'message' => 'Invalid API key format'];
    }

    // KEY_ID / SECRET: passed args se lo, warna constants se
    $kid = $keyId  ?: (defined('KEY_ID') ? KEY_ID : '');
    $sec = $secret ?: (defined('SECRET') ? SECRET : '');

    $verify = httpPost(VERIFY_URL, [
        'key_id'  => $kid,
        'api_key' => $apiKey,
        'secret'  => $sec,
    ]);

    if (!$verify) return ['valid' => false, 'message' => 'Auth server unreachable'];
    if (!($verify['valid'] ?? false)) return ['valid' => false, 'message' => $verify['message'] ?? 'Invalid API key'];

    return ['valid' => true, 'info' => $verify];
}
