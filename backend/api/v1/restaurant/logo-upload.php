<?php
/**
 * POST /api/v1/restaurant/logo-upload.php — upload/replace the
 * restaurant's logo for the Account tab's Edit Profile screen
 * (docs/restorent/19 §7 / §10 item 5).
 *
 * multipart/form-data, field name "logo". Auth: Restaurant token.
 * Returns { logo_url: "uploads/restaurant_logos/<file>.jpg" } — a path
 * relative to the backend root, same shape and same reason as H6's
 * address-photo.php (see that file's kdoc): the app derives the full
 * static-file URL via ApiClient.baseUrlForStaticFiles() rather than this
 * endpoint returning a full URL itself, so it keeps working if BASE_URL's
 * host/scheme ever changes.
 *
 * This endpoint only uploads the file and returns its path — it does NOT
 * write logo_url onto the restaurants row itself. The app is expected to
 * send the returned path to profile-update.php as an ordinary
 * { "logo_url": "..." } field alongside the rest of the profile form, same
 * split as address-photo.php + addresses.php. Kept separate rather than
 * writing the DB here too so a user who picks a new logo but then cancels
 * out of Edit Profile without saving doesn't leave a half-applied change —
 * the uploaded file exists on disk either way (an orphaned upload is a
 * cheap, harmless cost; a stray partial DB write is not).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    respond_error('validation_error', 422, ['fields' => ['logo']]);
}

$file = $_FILES['logo'];

// 5 MB cap — same reasoning/limit as address-photo.php.
const MAX_BYTES = 5 * 1024 * 1024;
if ($file['size'] > MAX_BYTES) {
    respond_error('file_too_large', 422);
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$mime])) {
    respond_error('unsupported_file_type', 422);
}
$ext = $allowed[$mime];

$uploadDir = __DIR__ . '/../../../uploads/restaurant_logos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'logo_' . $restaurantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('upload_failed', 500);
}

respond_ok(['logo_url' => 'uploads/restaurant_logos/' . $filename], 201);
