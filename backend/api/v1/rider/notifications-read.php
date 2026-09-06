<?php
/**
 * POST /api/v1/rider/notifications-read.php?id=123
 * Auth: Rider token
 * Response: { "id": 123, "is_read": true }
 *
 * Marks one rider notification read. Thin wrapper around
 * lib/notifications.php's mark_notification_read() — same helper
 * customer/restaurant's notifications.php?action=read already calls,
 * here as its own flat file per doc 99's rider-endpoint convention (see
 * notifications-list.php's kdoc for the full rationale).
 *
 * mark_notification_read() itself scopes the UPDATE to
 * recipient_type='rider' AND recipient_id=$riderId, so a rider can never
 * mark another recipient's (or another rider's) notification read by
 * guessing an id — a mismatched/foreign/missing id just returns false
 * here, which this endpoint reports as 404 rather than silently
 * succeeding.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$notificationId = (int) ($_GET['id'] ?? 0);
if ($notificationId <= 0 || !mark_notification_read('rider', $riderId, $notificationId)) {
    respond_error('not_found', 404);
}

respond_ok(['id' => $notificationId, 'is_read' => true]);
