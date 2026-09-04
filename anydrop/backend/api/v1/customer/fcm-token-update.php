<?php
/**
 * POST /api/v1/customer/fcm-token-update.php
 * Auth: Customer token
 * Request: { "fcm_token": "..." }
 * Response: { "ok": true }
 *
 * Customer-side twin of restaurant/fcm-token-update.php — see that
 * file's kdoc for the full rationale (same "plain overwrite, no format
 * validation" reasoning applies identically here). Writes to
 * customers.fcm_token (migration 60).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['fcm_token']);

$token = trim((string) $body['fcm_token']);
if ($token === '') {
    respond_error('validation_error', 422, ['fields' => ['fcm_token']]);
}

$db = Database::get();
$upd = $db->prepare('UPDATE customers SET fcm_token = :t WHERE id = :id');
$upd->execute(['t' => $token, 'id' => $customerId]);

respond_ok(['ok' => true]);
