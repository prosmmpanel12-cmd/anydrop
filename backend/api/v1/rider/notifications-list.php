<?php
/**
 * GET /api/v1/rider/notifications-list.php
 *     Query: ?page=1&per_page=20&unread_only=1 (optional)
 * Auth: Rider token
 *
 * Response: { "items": [ { id, title, body, type, is_read, data,
 *   created_at }, ... ], "has_more": bool, "unread_count": int }
 *
 * Rider bell-list read-side (deep-plan §23, docs 99's "Still open").
 * Thin wrapper around lib/notifications.php's fetch_notifications() —
 * the exact same helper customer/restaurant's notifications.php already
 * calls, just with recipient_type = 'rider'. That helper's shape is
 * already recipient-agnostic, so this endpoint adds zero new query
 * logic, same division of responsibility every other rider endpoint in
 * this codebase follows.
 *
 * Split into three separate files (this one + notifications-read.php +
 * notifications-read-all.php) rather than one ?action=-routed file like
 * customer/restaurant's notifications.php, because rider endpoints in
 * this codebase follow a flatter one-file-per-action convention with no
 * .htaccess rewrite (confirmed against payout.php / payout-bank-
 * details-get.php / payout-bank-details-save.php's naming and Retrofit's
 * exact call strings — see doc 99). Same underlying lib/notifications.php
 * functions either way; only the routing shape differs.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
$unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1' ? true : null;

$result = fetch_notifications('rider', $riderId, $page, $perPage, $unreadOnly);

respond_ok($result);
