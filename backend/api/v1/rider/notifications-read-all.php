<?php
/**
 * POST /api/v1/rider/notifications-read-all.php
 * Auth: Rider token
 * Response: { "marked_read": <int> }
 *
 * Marks every unread rider notification read in one query — backs the
 * bell list's "Mark all read" action. Thin wrapper around
 * lib/notifications.php's mark_all_notifications_read(), same helper
 * customer/restaurant's notifications.php?action=read-all already calls,
 * here as its own flat file per doc 99's rider-endpoint convention (see
 * notifications-list.php's kdoc for the full rationale).
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

$count = mark_all_notifications_read('rider', $riderId);

respond_ok(['marked_read' => $count]);
