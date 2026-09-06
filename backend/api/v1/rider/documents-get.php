<?php
/**
 * GET /api/v1/rider/documents-get.php — current document-submission
 * state for the logged-in rider. Auth: Rider token.
 *
 * Response: { documents_status, documents_reject_reason,
 *   has_id_doc, has_vehicle_doc, profile_photo_url,
 *   vehicle_type, vehicle_number }
 *
 * Deliberately does NOT return id_doc_url/vehicle_doc_url themselves
 * (those are private filenames, not paths any client should construct
 * a URL from directly) — only boolean presence flags, same "tell the
 * UI what to render, not how to fetch the file" split every other
 * private-resource endpoint in this session's new work follows. The
 * app fetches the actual image bytes via documents-view.php when it
 * needs to show a submitted document back to the rider (e.g. on a
 * re-submission screen's "current file" preview).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('rider');
$riderId = (int) $owner['owner_id'];
$db = Database::get();

$stmt = $db->prepare(
    'SELECT documents_status, documents_reject_reason, id_doc_url, vehicle_doc_url,
            profile_photo_url, vehicle_type, vehicle_number
     FROM riders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute(['id' => $riderId]);
$rider = $stmt->fetch();

if (!$rider) {
    respond_error('account_suspended', 403);
}

respond_ok([
    'documents_status' => $rider['documents_status'],
    'documents_reject_reason' => $rider['documents_reject_reason'],
    'has_id_doc' => $rider['id_doc_url'] !== null,
    'has_vehicle_doc' => $rider['vehicle_doc_url'] !== null,
    'profile_photo_url' => $rider['profile_photo_url'],
    'vehicle_type' => $rider['vehicle_type'],
    'vehicle_number' => $rider['vehicle_number'],
]);
