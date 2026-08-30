<?php
/**
 * POST /api/v1/restaurant/status-update.php
 * Auth: Restaurant token
 * Request: { "operational_status": "open" | "busy" | "temp_closed",
 *            "resume_at"?: "YYYY-MM-DD HH:mm:ss" }
 * Response: { "operational_status": "...", "temp_closed_until": "..."|null }
 *
 * §3, today.md 2026-08-28 / migration 58 — `resume_at` is the optional
 * "closed until [date/time]" piece of the full closure-scheduling ask.
 * Only meaningful (and only accepted) alongside operational_status =
 * "temp_closed"; a future timestamp is stored as temp_closed_until and
 * read back by compute_restaurant_status() (lib/restaurant_status.php)
 * to auto-stop showing the pause as active once it lapses. Omitting it
 * keeps the exact pre-migration-58 behavior — indefinite pause until
 * manually resumed. Switching to "open"/"busy" always clears
 * temp_closed_until, even if the caller didn't ask — a resume-time
 * belonging to a pause that's no longer active would be stale data with
 * no meaning.
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
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_restaurant_profile');
$restaurantId = $owner['owner_id'];

$body = get_json_body();
require_fields($body, ['operational_status']);

$newStatus = $body['operational_status'];
$allowedStatuses = ['open', 'busy', 'temp_closed'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    respond_error('invalid_operational_status', 422, ['allowed' => $allowedStatuses]);
}

$resumeAt = null;
if ($newStatus === 'temp_closed' && !empty($body['resume_at'])) {
    $parsed = DateTime::createFromFormat('Y-m-d H:i:s', (string) $body['resume_at']);
    if (!$parsed) {
        respond_error('validation_error', 422, ['fields' => ['resume_at']]);
    }
    if ($parsed <= new DateTime()) {
        // A resume time in the past isn't a "closed until" at all —
        // rejecting rather than silently accepting it and immediately
        // auto-expiring, which would look like the toggle just didn't
        // work.
        respond_error('validation_error', 422, ['fields' => ['resume_at']]);
    }
    $resumeAt = $parsed->format('Y-m-d H:i:s');
}

$db = Database::get();
$upd = $db->prepare(
    'UPDATE restaurants SET operational_status = :s, temp_closed_until = :until WHERE id = :id'
);
$upd->execute(['s' => $newStatus, 'until' => $resumeAt, 'id' => $restaurantId]);

respond_ok(['operational_status' => $newStatus, 'temp_closed_until' => $resumeAt]);
