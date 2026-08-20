<?php
/**
 * GET  /api/v1/customer/notifications                — paginated list, newest first
 *      Query: ?page=1&per_page=20&unread_only=1 (optional)
 * POST /api/v1/customer/notifications/{id}/read      — mark one read
 * POST /api/v1/customer/notifications/read-all        — mark every unread one read
 * Auth: Customer token
 *
 * Backs the notification bell (docs/Status.md 2026-08-20 "Notification
 * bell" item, Type 1 — system-generated only; the admin broadcast/
 * targeting system referenced in that same conversation is a separate,
 * not-yet-built Type 2 — see lib/notifications.php's closing note).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/notifications.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = (int) $owner['owner_id'];

$action = $_GET['action'] ?? null; // 'read' | 'read-all', routed via .htaccess

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
    $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === '1' ? true : null;

    $result = fetch_notifications('customer', $customerId, $page, $perPage, $unreadOnly);
    respond_ok($result);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read-all') {
    $count = mark_all_notifications_read('customer', $customerId);
    respond_ok(['marked_read' => $count]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read') {
    $notificationId = (int) ($_GET['id'] ?? 0);
    if ($notificationId <= 0 || !mark_notification_read('customer', $customerId, $notificationId)) {
        respond_error('not_found', 404);
    }
    respond_ok(['id' => $notificationId, 'is_read' => true]);
}

respond_error('method_not_allowed', 405);
