<?php
/**
 * POST /api/v1/restaurant/banner-upload.php — add a new restaurant banner
 * (app-owner feedback item #3, 2026-08-17: "restaurant open ke baad
 * restaurant banners dikhenge, multiple with a transition, single ho to
 * fixed"). multipart/form-data, field name "banner". Auth: Restaurant
 * token. Returns the created row: { id, image_url }.
 *
 * Unlike logo-upload.php / menu-item-photo-upload.php, this endpoint
 * writes straight to the DB (INSERT into restaurant_banners) instead of
 * only returning a path for some other "Save" endpoint to persist —
 * banners aren't part of the profile form, they're their own standalone
 * add/remove list (same shape as a photo gallery manager), so there's no
 * separate save step to defer to and no cancel-without-saving case to
 * protect against; each upload is its own complete action, same as
 * banner-delete.php is for removing one.
 *
 * sort_order is assigned as (current max for this restaurant + 1) so new
 * banners append to the end of the carousel by default; reordering (if
 * ever wanted) would be a separate endpoint, not built here as it wasn't
 * part of the ask.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/permissions.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('restaurant');
require_restaurant_permission($owner, 'manage_restaurant_profile');
$restaurantId = $owner['owner_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

if (!isset($_FILES['banner']) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
    respond_error('validation_error', 422, ['fields' => ['banner']]);
}

$file = $_FILES['banner'];

// 5 MB cap — same limit/reasoning as every other photo-upload endpoint
// in this file group (logo-upload.php, menu-item-photo-upload.php).
const MAX_BYTES = 5 * 1024 * 1024;
if ($file['size'] > MAX_BYTES) {
    respond_error('file_too_large', 422);
}

// Soft cap on banner count — a handful is a curated carousel, dozens
// would be an unreadable status-bar strip of segments in the app UI.
const MAX_BANNERS_PER_RESTAURANT = 10;

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

$db = Database::get();

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM restaurant_banners WHERE restaurant_id = ?');
$countStmt->execute([$restaurantId]);
$existingCount = (int) $countStmt->fetch()['c'];
if ($existingCount >= MAX_BANNERS_PER_RESTAURANT) {
    respond_error('banner_limit_reached', 422, ['limit' => MAX_BANNERS_PER_RESTAURANT]);
}

$uploadDir = __DIR__ . '/../../../uploads/restaurant_banners';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'banner_' . $restaurantId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_error('upload_failed', 500);
}

$imageUrl = 'uploads/restaurant_banners/' . $filename;

$nextSortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort FROM restaurant_banners WHERE restaurant_id = ?');
$nextSortStmt->execute([$restaurantId]);
$nextSort = (int) $nextSortStmt->fetch()['next_sort'];

$insertStmt = $db->prepare(
    'INSERT INTO restaurant_banners (restaurant_id, image_url, sort_order) VALUES (?, ?, ?)'
);
$insertStmt->execute([$restaurantId, $imageUrl, $nextSort]);
$newId = (int) $db->lastInsertId();

respond_ok(['id' => $newId, 'image_url' => $imageUrl], 201);
