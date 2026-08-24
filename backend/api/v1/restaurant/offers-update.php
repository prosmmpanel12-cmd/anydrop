<?php
/**
 * POST /api/v1/restaurant/offers-update.php?id=
 * Auth: Restaurant token
 * Request: partial — any of { "status": "active"|"paused",
 *   "title", "min_order_amount", "max_discount_amount",
 *   "start_date", "end_date", "start_time", "end_time", "weekdays",
 *   "daily_limit", "total_limit", "per_customer_limit", "is_deleted" }.
 *   Same null-skip partial-update convention as coupons-update.php —
 *   only fields present in the body are touched.
 * Response: { "offer": {...format_offer()} }
 *
 * Ownership-checked (offer must belong to the calling restaurant),
 * same pattern as coupons-update.php/menu-items-update.php.
 *
 * A restaurant can only ever set status to 'active' or 'paused' — the
 * third value, 'disabled', is admin-only (see admin/offers.php),
 * enforced here by rejecting any other value outright rather than
 * silently clamping it, so a bad client payload never accidentally
 * unpauses something an admin deliberately disabled.
 *
 * offer_type, scope, menu_item_id, food_category_id, and the
 * type-specific mechanic fields (required_qty/get_qty/offer_price/
 * discount_percent/discount_flat) are deliberately NOT editable here —
 * same "delete and recreate instead" reasoning coupons-update.php
 * documents for code/discount_type: changing the mechanic of an offer
 * that already has offer_usages history against it would make that
 * history impossible to interpret correctly later (a discount_amount
 * snapshot on an old usage row would no longer match what the current
 * offer config implies).
 *
 * "Delete" is a soft delete (deleted_at stamped, never a hard DELETE)
 * for the same reason coupons-update.php's is_archived exists —
 * offer_usages rows (and orders.offer_id) must keep resolving to a
 * real row for historical order display, never a dangling FK.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/offers.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    respond_error('validation_error', 422, ['fields' => ['id']]);
}

$db = Database::get();

$existing = $db->prepare('SELECT * FROM promo_offers WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL');
$existing->execute(['id' => $id, 'rid' => $restaurantId]);
$row = $existing->fetch();
if (!$row) {
    respond_error('not_found', 404);
}

$body = get_json_body();

// Soft delete short-circuits every other field — a restaurant deleting
// an offer isn't also trying to edit it in the same call.
if (!empty($body['is_deleted'])) {
    $db->prepare('UPDATE promo_offers SET deleted_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    respond_ok(['deleted' => true]);
}

$fieldsSql = [];
$params = ['id' => $id];

if (array_key_exists('status', $body)) {
    $status = (string) $body['status'];
    if (!in_array($status, ['active', 'paused'], true)) {
        respond_error('validation_error', 422, ['fields' => ['status']]);
    }
    // Guard: don't let a restaurant "resume" an offer an admin
    // deliberately disabled — they'd need to contact the admin, same
    // as a rejected restaurant can't self-reactivate elsewhere in
    // this codebase.
    if ($row['status'] === 'disabled') {
        respond_error('offer_disabled_by_admin', 403);
    }
    $fieldsSql[] = 'status = :status';
    $params['status'] = $status;
}
if (array_key_exists('title', $body)) {
    $title = trim((string) $body['title']);
    if ($title === '' || mb_strlen($title) > 150) {
        respond_error('validation_error', 422, ['fields' => ['title']]);
    }
    $fieldsSql[] = 'title = :title';
    $params['title'] = $title;
}
if (array_key_exists('min_order_amount', $body)) {
    $fieldsSql[] = 'min_order_amount = :min_order_amount';
    $params['min_order_amount'] = max(0.0, (float) $body['min_order_amount']);
}
if (array_key_exists('max_discount_amount', $body)) {
    $fieldsSql[] = 'max_discount_amount = :max_discount_amount';
    $params['max_discount_amount'] = $body['max_discount_amount'] !== null ? (float) $body['max_discount_amount'] : null;
}
if (array_key_exists('start_date', $body)) {
    $fieldsSql[] = 'start_date = :start_date';
    $params['start_date'] = !empty($body['start_date']) ? (string) $body['start_date'] : null;
}
if (array_key_exists('end_date', $body)) {
    $fieldsSql[] = 'end_date = :end_date';
    $params['end_date'] = !empty($body['end_date']) ? (string) $body['end_date'] : null;
}
if (array_key_exists('start_time', $body)) {
    $fieldsSql[] = 'start_time = :start_time';
    $params['start_time'] = !empty($body['start_time']) ? (string) $body['start_time'] : null;
}
if (array_key_exists('end_time', $body)) {
    $fieldsSql[] = 'end_time = :end_time';
    $params['end_time'] = !empty($body['end_time']) ? (string) $body['end_time'] : null;
}
if (array_key_exists('weekdays', $body)) {
    $weekdays = null;
    if (!empty($body['weekdays'])) {
        $rawDays = is_array($body['weekdays']) ? $body['weekdays'] : explode(',', (string) $body['weekdays']);
        $cleanDays = array_values(array_unique(array_filter(array_map(function ($d) {
            $d = (int) trim((string) $d);
            return ($d >= 1 && $d <= 7) ? $d : null;
        }, $rawDays), fn ($d) => $d !== null)));
        if (!empty($cleanDays)) {
            $weekdays = implode(',', $cleanDays);
        }
    }
    $fieldsSql[] = 'weekdays = :weekdays';
    $params['weekdays'] = $weekdays;
}
if (array_key_exists('daily_limit', $body)) {
    $fieldsSql[] = 'daily_limit = :daily_limit';
    $params['daily_limit'] = $body['daily_limit'] !== null ? (int) $body['daily_limit'] : null;
}
if (array_key_exists('total_limit', $body)) {
    $fieldsSql[] = 'total_limit = :total_limit';
    $params['total_limit'] = $body['total_limit'] !== null ? (int) $body['total_limit'] : null;
}
if (array_key_exists('per_customer_limit', $body)) {
    $fieldsSql[] = 'per_customer_limit = :per_customer_limit';
    $params['per_customer_limit'] = $body['per_customer_limit'] !== null ? (int) $body['per_customer_limit'] : null;
}

if (!empty($fieldsSql)) {
    $sql = 'UPDATE promo_offers SET ' . implode(', ', $fieldsSql) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);
}

$fetchStmt = $db->prepare('SELECT * FROM promo_offers WHERE id = :id LIMIT 1');
$fetchStmt->execute(['id' => $id]);

respond_ok(['offer' => format_offer($fetchStmt->fetch())]);
