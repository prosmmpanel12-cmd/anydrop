<?php
/**
 * POST /api/v1/customer/address-photo.php — upload a door/building photo
 * for the map pin-drop screen (H6 part 2, see
 * docs/12_Handover_H6_Map_PinDrop_Photo.md). New endpoint — grepped the
 * rest of the backend first (move_uploaded_file / $_FILES / base64_decode)
 * and found no existing image-upload pattern to reuse (logo_url/image_url
 * on restaurants/menu_items are plain VARCHAR columns set directly via SQL
 * seeding, never uploaded through the app), so this establishes the
 * pattern rather than following one.
 *
 * multipart/form-data, field name "photo". Auth: Customer token.
 * Returns { photo_url: "uploads/address_photos/<file>.jpg" } — a path
 * relative to the backend root (matches how the app's BASE_URL already
 * points at ".../anydrop/api/v1/"; the app derives the full static-file URL
 * by swapping "api/v1/" for this relative path — see
 * MapPinDropActivity.kt's absoluteUrl()).
 *
 * Saving the photo here (rather than folding it into addresses.php's
 * JSON body as base64) keeps the address save call small and reuses plain
 * multipart handling instead of inflating request bodies ~33% via base64.
 * The returned photo_url is then passed to POST/PUT customer/addresses.php
 * as a normal string field, same as every other address field.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = $owner['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    respond_error('validation_error', 422, ['fields' => ['photo']]);
}

$file = $_FILES['photo'];

// 5 MB cap — a phone camera door/building photo at reasonable quality,
// generous enough for typical uploads without letting the endpoint accept
// arbitrarily large payloads.
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

$uploadDir = __DIR__ . '/../../../uploads/address_photos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'addr_' . $customerId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('upload_failed', 500);
}

respond_ok(['photo_url' => 'uploads/address_photos/' . $filename], 201);
