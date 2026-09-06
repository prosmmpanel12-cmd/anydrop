<?php
/**
 * POST /api/v1/rider/fcm-token-update.php
 * Auth: Rider token
 * Request: { "fcm_token": "..." }
 * Response: { "ok": true }
 *
 * Rider-side twin of customer/restaurant fcm-token-update.php — same
 * "plain overwrite, no format validation" reasoning applies identically
 * here. Writes to riders.fcm_token (column added ahead of the Rider App
 * itself, migration 60's own header — see doc 99's "Still open" for why
 * this endpoint didn't exist until now: nothing had ever written to that
 * column, so every rider-facing create_notification() push step has been
 * a silent no-op reading an always-NULL token).
 *
 * Once the Android side calls this on FCM token refresh (same pattern as
 * customer/restaurant's own FirebaseMessagingService), the push half of
 * every notification in lib/notifications.php starts working for riders
 * with zero changes needed anywhere else — create_notification() already
 * reads riders.fcm_token, it just never had anything to find.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['fcm_token']);

$token = trim((string) $body['fcm_token']);
if ($token === '') {
    respond_error('validation_error', 422, ['fields' => ['fcm_token']]);
}

$db = Database::get();
$upd = $db->prepare('UPDATE riders SET fcm_token = :t WHERE id = :id');
$upd->execute(['t' => $token, 'id' => $riderId]);

respond_ok(['ok' => true]);
