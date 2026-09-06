<?php
/**
 * POST /api/v1/rider/documents-upload.php — submit/re-submit compliance
 * documents (deep-plan §22). Auth: Rider token.
 *
 * multipart/form-data. Required file field: "id_doc". Optional file
 * fields: "vehicle_doc", "profile_photo". Also accepts the plain-text
 * fields "vehicle_type"/"vehicle_number" (deep-plan §22 groups these
 * with the document submission step, not the original signup form —
 * see migration 75's own header and the 2026-09-01 Phase 2 session
 * note in memory for why vehicle_type/vehicle_number were deferred off
 * signup in the first place).
 *
 * vehicle_doc is optional on THIS endpoint's validation even though
 * deep-plan §22 lists it as a required document, because a rider who
 * hasn't bought/registered a vehicle yet may still need to submit
 * their ID first — the ACCOUNT-approval decision (admin/riders.php) is
 * what actually enforces "both documents present" before approving,
 * same separation as vehicle_type/vehicle_number already being
 * nullable columns. id_doc is the one hard requirement here since a
 * rider with literally zero documents submitted has nothing for an
 * admin to review at all.
 *
 * Storage is PRIVATE — see migration 75's header for the full
 * reasoning. Files land in backend/rider_documents/<rider_id>/, a
 * directory covered by rider_documents/.htaccess's `Require all
 * denied`; nothing outside PHP running server-side can read them. The
 * response returns no URL for id_doc/vehicle_doc (there is no public
 * URL) — the app instead re-fetches them for display via
 * documents-view.php (Bearer-token gated, streams the bytes). Only
 * profile_photo_url is returned as a real path, because that file was
 * saved to the ordinary public uploads/rider_profile_photos/ tree
 * instead (same convention as restaurant logos) — see migration 75.
 *
 * Every submission (whether this is the rider's first submission or a
 * re-submission after a rejection) resets documents_status to
 * 'pending' and clears documents_reject_reason — a fresh submission
 * always needs a fresh look, same "clear the old reason on any new
 * state-changing action" convention rejection_reason itself already
 * follows on the riders.status column.
 *
 * Old files are deliberately left on disk when replaced rather than
 * deleted — same "an orphaned upload is a cheap, harmless cost" call
 * documented in restaurant/logo-upload.php's own kdoc, and here it
 * additionally preserves whatever an admin was actively looking at in
 * another tab mid-review.
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
$db = Database::get();

if (!isset($_FILES['id_doc']) || $_FILES['id_doc']['error'] !== UPLOAD_ERR_OK) {
    respond_error('validation_error', 422, ['fields' => ['id_doc']]);
}

const MAX_BYTES = 5 * 1024 * 1024; // 5 MB — same cap every other upload endpoint in this codebase uses.
$allowedImage = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
// ID/vehicle documents may reasonably be a scanned PDF, unlike a
// profile photo — allow that in addition to images for those two
// fields only.
$allowedDoc = $allowedImage + ['application/pdf' => 'pdf'];

/**
 * Validates and saves one uploaded file into the private
 * rider_documents/<rider_id>/ directory. Returns the stored filename
 * (not a full path — the DB column only ever needs the relative name,
 * same "app derives the rest" split logo-upload.php's own kdoc
 * describes for the public-upload case).
 */
function save_private_rider_doc(array $file, int $riderId, array $allowed): string
{
    if ($file['size'] > MAX_BYTES) {
        respond_error('file_too_large', 422);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        respond_error('unsupported_file_type', 422);
    }
    $ext = $allowed[$mime];

    $riderDir = __DIR__ . '/../../../rider_documents/' . $riderId;
    if (!is_dir($riderDir)) {
        mkdir($riderDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $riderDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        respond_error('upload_failed', 500);
    }

    return $filename;
}

$idDocFilename = save_private_rider_doc($_FILES['id_doc'], $riderId, $allowedDoc);

$vehicleDocFilename = null;
if (isset($_FILES['vehicle_doc']) && $_FILES['vehicle_doc']['error'] === UPLOAD_ERR_OK) {
    $vehicleDocFilename = save_private_rider_doc($_FILES['vehicle_doc'], $riderId, $allowedDoc);
}

$profilePhotoUrl = null;
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
    $photoFile = $_FILES['profile_photo'];
    if ($photoFile['size'] > MAX_BYTES) {
        respond_error('file_too_large', 422);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $photoFile['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedImage[$mime])) {
        respond_error('unsupported_file_type', 422);
    }
    $ext = $allowedImage[$mime];

    $photoDir = __DIR__ . '/../../../uploads/rider_profile_photos';
    if (!is_dir($photoDir)) {
        mkdir($photoDir, 0755, true);
    }
    $photoFilename = 'rider_' . $riderId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($photoFile['tmp_name'], $photoDir . '/' . $photoFilename)) {
        respond_error('upload_failed', 500);
    }
    $profilePhotoUrl = 'uploads/rider_profile_photos/' . $photoFilename;
}

$vehicleType = isset($_POST['vehicle_type']) ? trim((string) $_POST['vehicle_type']) : null;
$vehicleNumber = isset($_POST['vehicle_number']) ? trim((string) $_POST['vehicle_number']) : null;

$sets = [
    'id_doc_url = :id_doc',
    'documents_status = :status',
    'documents_reject_reason = NULL',
    'documents_submitted_at = NOW()',
    'documents_verified_by_admin_id = NULL',
    'documents_verified_at = NULL',
];
$params = [
    'id_doc' => $idDocFilename,
    'status' => 'pending',
    'id' => $riderId,
];

if ($vehicleDocFilename !== null) {
    $sets[] = 'vehicle_doc_url = :vehicle_doc';
    $params['vehicle_doc'] = $vehicleDocFilename;
}
if ($profilePhotoUrl !== null) {
    $sets[] = 'profile_photo_url = :photo';
    $params['photo'] = $profilePhotoUrl;
}
if ($vehicleType !== null && $vehicleType !== '') {
    $sets[] = 'vehicle_type = :vtype';
    $params['vtype'] = $vehicleType;
}
if ($vehicleNumber !== null && $vehicleNumber !== '') {
    $sets[] = 'vehicle_number = :vnum';
    $params['vnum'] = $vehicleNumber;
}

$sql = 'UPDATE riders SET ' . implode(', ', $sets) . ' WHERE id = :id';
$stmt = $db->prepare($sql);
$stmt->execute($params);

respond_ok([
    'documents_status' => 'pending',
    'profile_photo_url' => $profilePhotoUrl,
], 200);
