<?php
/**
 * POST /api/v1/restaurant/menu-item-photo-upload.php — upload/replace a
 * dish photo for the Menu tab's add/edit item dialog (docs/restorent/
 * 00_Status.md, app-owner real-device-feedback item 4 of 4, smaller half:
 * `menu_items.image_url` already existed in the schema, this just wires
 * it up).
 *
 * multipart/form-data, field name "photo". Auth: Restaurant token.
 * Returns { image_url: "uploads/restaurant_dish_photos/<file>.jpg" } — a
 * path relative to the backend root, same shape/reasoning as
 * logo-upload.php: the app derives the full static-file URL via
 * ApiClient.baseUrlForStaticFiles() rather than this endpoint returning a
 * full URL itself.
 *
 * Upload-then-save split, same as logo-upload.php: this endpoint only
 * uploads the file and returns its path — it does NOT write image_url onto
 * the menu_items row itself. The app sends the returned path to
 * menu-items-create.php / menu-items-update.php as an ordinary
 * { "image_url": "..." } field alongside the rest of the item form. Kept
 * separate so a user who picks a new photo but then cancels out of the
 * add/edit dialog doesn't leave a half-applied change — the uploaded file
 * exists on disk either way (an orphaned upload is a cheap, harmless cost;
 * a stray partial DB write is not). This also means a brand-new item (not
 * yet created, no id yet) can still get a photo uploaded before the item
 * itself exists — the filename is timestamp/random-keyed, not item-id-keyed,
 * same non-issue logo-upload.php already has for a restaurant's first logo.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_menu');
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

$uploadDir = __DIR__ . '/../../../uploads/restaurant_dish_photos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'dish_' . $restaurantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('upload_failed', 500);
}

respond_ok(['image_url' => 'uploads/restaurant_dish_photos/' . $filename], 201);
