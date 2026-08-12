<?php
/**
 * GET /api/v1/system/app-version.php?platform=customer
 * No auth required — called at splash/startup, before login.
 * Reads target version info from app_settings (nothing hardcoded).
 *
 * app_settings keys used (seed these via admin panel / seed script),
 * following the existing `min_app_version_customer` naming convention:
 *   min_app_version_{platform}      already seeded in 01_schema.sql — versions below this are force-updated
 *   latest_app_version_{platform}   newest available version code
 *   latest_app_version_name_{platform}  newest version's display name, e.g. "1.2"
 *   update_message_{platform}       shown in the in-app update popup
 *   update_url_{platform}           direct APK / Play Store link
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/settings.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$platform = $_GET['platform'] ?? 'customer';
$allowed = ['customer', 'restaurant', 'rider'];
if (!in_array($platform, $allowed, true)) {
    respond_error('validation_error', 422, ['fields' => ['platform']]);
}

respond_ok([
    'latest_version_code' => (int) get_setting("latest_app_version_{$platform}", 1),
    'latest_version_name' => get_setting("latest_app_version_name_{$platform}", '1.0'),
    'min_version_code' => (int) get_setting("min_app_version_{$platform}", 1),
    'update_message' => get_setting("update_message_{$platform}", 'A new version of Anydrop is available.'),
    'update_url' => get_setting("update_url_{$platform}", ''),
]);
