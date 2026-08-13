<?php
/**
 * POST /api/v1/restaurant/status-update.php
 * Auth: Restaurant token
 * Request: { "operational_status": "open" | "busy" | "temp_closed" }
 * Response: { "operational_status": "..." }
 *
 * docs/16_Handover_I4_Followups_And_Order_Toggle.md Part B — lets a
 * restaurant pause/resume accepting new orders on demand, independent of
 * its fixed opening_time/closing_time schedule. restaurants.operational_status
 * already gates restaurants/list.php's is_open_now (see that file) and
 * orders/create.php's new accepting-orders guard (see that file) — this
 * endpoint is just the missing write path.
 *
 * Deliberately restricted to a restaurant-safe subset of the column's full
 * enum ('open','closed','busy','vacation','temp_closed','admin_disabled' —
 * see 01_Database_Schema.md). 'admin_disabled' is a platform-only
 * enforcement state and 'vacation' reads as a longer, planned closure than
 * what this on-demand toggle is for — neither is settable here. 'closed'
 * is deliberately left out too: it's not this toggle's job to contradict
 * the restaurant's own opening/closing-hours schedule, only to pause on
 * top of it. Restaurant App's Part B UI only ever sends 'open' or 'busy'
 * (per the handover doc's recommended plain ON/OFF scope) — 'temp_closed'
 * is accepted here too since it's the schema's other clear on-demand-pause
 * value, in case a future UI wants to distinguish the two.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['operational_status']);

$newStatus = $body['operational_status'];
$allowedStatuses = ['open', 'busy', 'temp_closed'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    respond_error('invalid_operational_status', 422, ['allowed' => $allowedStatuses]);
}

$db = Database::get();
$upd = $db->prepare('UPDATE restaurants SET operational_status = :s WHERE id = :id');
$upd->execute(['s' => $newStatus, 'id' => $restaurantId]);

respond_ok(['operational_status' => $newStatus]);
