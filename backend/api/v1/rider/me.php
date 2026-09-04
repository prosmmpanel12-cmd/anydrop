<?php
/**
 * GET /api/v1/rider/me
 * Headers: Authorization: Bearer <token>
 * Response: { "rider": { id, name, email, mobile, status, rejection_reason,
 *                         service_area_id, service_area_name, created_at,
 *                         is_online, vehicle_type, vehicle_number },
 *             "status": "pending"|"approved"|"rejected"|"suspended" }
 *
 * Phase 3 (docs/rider/83_Plan_Phase3...) added is_online/vehicle_type/
 * vehicle_number to the response — purely additive, existing fields
 * and behavior below are unchanged. These back RiderDashboardActivity's
 * bootstrap (online switch initial state + vehicle display) so it
 * doesn't need a second round trip after login/refresh.
 *
 * Lightweight "who am I + what's my current status" endpoint for the
 * Rider app's ApplicationStatusActivity "Refresh Status" button.
 *
 * Previously there was no such endpoint — Refresh forced a full logout
 * + OTP re-login just to re-check status (a fresh verify-otp call is
 * the only authenticated path that re-reads the riders row, since
 * TokenManager caches status at login time). This endpoint lets the
 * app re-check in the background without disrupting the session.
 *
 * require_auth('rider') re-checks status on every call (same as every
 * other protected endpoint) and will 403 `account_suspended` if the
 * rider has since been suspended — so this endpoint never needs to
 * special-case that itself. It DOES need to handle `pending`/`rejected`
 * without blocking, per auth.php's own comment: only suspended/deleted
 * riders are blocked at the require_auth level; pending and rejected
 * riders reach their status screen via their token, which is by design
 * (they need to see why they were rejected, or that they're still
 * under review).
 *
 * No write side-effects — GET only, safe to call on every screen resume.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = $owner['owner_id'];

$db = Database::get();

// Join service_areas so we can surface the area name without a second
// round trip — the app shows it on the status screen as a confirmation
// of where the rider signed up for.
$stmt = $db->prepare(
    'SELECT r.id, r.name, r.email, r.mobile, r.status, r.rejection_reason,
            r.service_area_id, sa.name AS service_area_name, r.created_at,
            r.is_online, r.vehicle_type, r.vehicle_number
     FROM riders r
     LEFT JOIN service_areas sa ON sa.id = r.service_area_id
     WHERE r.id = :id AND r.deleted_at IS NULL
     LIMIT 1'
);
$stmt->execute(['id' => $riderId]);
$rider = $stmt->fetch();

if (!$rider) {
    // Should not happen — require_auth already validated the token and
    // the rider row exists. Treat it as the account being gone.
    respond_error('account_suspended', 403);
}

respond_ok([
    'rider' => [
        'id'                  => (int) $rider['id'],
        'name'                => $rider['name'],
        'email'               => $rider['email'],
        'mobile'              => $rider['mobile'],
        'status'              => $rider['status'],
        'rejection_reason'    => $rider['rejection_reason'],
        'service_area_id'     => $rider['service_area_id'] !== null ? (int) $rider['service_area_id'] : null,
        'service_area_name'   => $rider['service_area_name'],
        'created_at'          => $rider['created_at'],
        'is_online'           => (bool) $rider['is_online'],
        'vehicle_type'        => $rider['vehicle_type'],
        'vehicle_number'      => $rider['vehicle_number'],
    ],
    'status' => $rider['status'],
]);
