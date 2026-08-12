<?php
/**
 * GET /api/v1/home/promo-banners.php
 * (Mapped from clean URL GET /home/promo-banners per Phase 3.6 §2.2)
 * Auth: Customer token
 *
 * Returns the active, ordered list of promo carousel slides from
 * `promo_banners` (see 06_migration_phase36.sql). Replaces the old static
 * single-image home_promo_* app_settings, which stay in place as a
 * fallback only — this table is what the Customer App carousel reads.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=120');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

require_auth('customer');

$db = Database::get();

$stmt = $db->query(
    "SELECT id, title, subtitle, image_url, target_type, target_value, sort_order
     FROM promo_banners
     WHERE is_active = 1
       AND (starts_at IS NULL OR starts_at <= NOW())
       AND (ends_at IS NULL OR ends_at >= NOW())
     ORDER BY sort_order ASC, id ASC
     LIMIT 10"
);
$rows = $stmt->fetchAll();

$banners = array_map(fn($b) => [
    'id' => (int) $b['id'],
    'title' => $b['title'],
    'subtitle' => $b['subtitle'],
    'image_url' => $b['image_url'],
    'target_type' => $b['target_type'],
    'target_value' => $b['target_value'],
], $rows);

respond_ok(['banners' => $banners]);
