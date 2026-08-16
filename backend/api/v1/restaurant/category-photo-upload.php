<?php
/**
 * POST /api/v1/restaurant/category-photo-upload.php — upload/replace a
 * category photo for the Menu tab's add/edit category dialog
 * (docs/restorent/00_Status.md, app-owner real-device-feedback item 4 of
 * 4, larger half: menu_categories had no image column at all before
 * backend/sql/22_migration_category_image.sql).
 *
 * multipart/form-data, field name "photo". Auth: Restaurant token.
 * Returns { image_url: "uploads/category_photos/<file>.jpg" } — same
 * relative-path shape as logo-upload.php / menu-item-photo-upload.php.
 *
 * Same upload-then-save split as menu-item-photo-upload.php: only uploads
 * and returns a path, doesn't write image_url onto the menu_categories row
 * itself — the app sends the returned path to categories-create.php /
 * categories-update.php alongside the rest of the category form.
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

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    respond_error('validation_error', 422, ['fields' => ['photo']]);
}

$file = $_FILES['photo'];

// 5 MB cap — same limit/reasoning as logo-upload.php.
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

$uploadDir = __DIR__ . '/../../../uploads/category_photos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'category_' . $restaurantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('upload_failed', 500);
}

respond_ok(['image_url' => 'uploads/category_photos/' . $filename], 201);
