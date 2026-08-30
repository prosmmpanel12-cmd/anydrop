<?php
/**
 * POST /api/v1/restaurant/fcm-token-update.php
 * Auth: Restaurant token
 * Request: { "fcm_token": "..." }
 * Response: { "ok": true }
 *
 * FCM push (this session) — writes the device's current FCM
 * registration token to restaurants.fcm_token (migration 60), which
 * create_notification() (lib/notifications.php) reads to actually
 * send a push. Called from the Android app's FirebaseMessagingService
 * .onNewToken() override and once more on login (a token minted before
 * login has no restaurant_id to attach to yet).
 *
 * Deliberately a plain overwrite, not an upsert-if-different check —
 * FCM tokens can legitimately be sent to the same value repeatedly
 * (app restart, token-refresh callback firing without an actual
 * change) and a redundant UPDATE is cheap; there's no meaningful
 * "history" of past tokens worth preserving here, only the current
 * one matters for delivery.
 *
 * No token-format validation beyond non-empty — FCM tokens have no
 * publicly stable format contract to validate against, and an invalid
 * token simply fails silently at send time (fcm_send_to_token()
 * treats any non-200 FCM response as a logged, non-fatal failure).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['fcm_token']);

$token = trim((string) $body['fcm_token']);
if ($token === '') {
    respond_error('validation_error', 422, ['fields' => ['fcm_token']]);
}

$db = Database::get();
$upd = $db->prepare('UPDATE restaurants SET fcm_token = :t WHERE id = :id');
$upd->execute(['t' => $token, 'id' => $restaurantId]);

respond_ok(['ok' => true]);
