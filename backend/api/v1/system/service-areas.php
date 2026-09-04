<?php
/**
 * GET /api/v1/system/service-areas
 * Response: { "areas": [ { "id", "parent_id", "level", "name" }, ... ] }
 *
 * Public, unauthenticated — flat list of every ACTIVE service_areas row
 * (migration 30: State -> District -> City/Village -> Area hierarchy),
 * sorted by level then name. Deliberately flat rather than pre-nested:
 * admin/areas.php's own dropdown-building already works this way
 * client-side (it fetches everything and groups by parent_id in PHP/JS
 * rather than the backend doing it), so the rider app's cascading
 * State -> District -> City/Village -> Area picker does the same
 * grouping on-device. Keeps this endpoint a single simple query and
 * reusable by both the rider-signup dropdown and, if useful later, the
 * customer/restaurant apps' own area pickers — nothing here is
 * rider-specific.
 *
 * Only `is_active = 1` rows are returned — a disabled area shouldn't
 * be selectable at signup even though it still exists for historical
 * records (existing restaurants/addresses already assigned to it keep
 * working; this endpoint just isn't where NEW selections should be
 * able to pick it from).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$db = Database::get();

$areas = $db->query(
    "SELECT id, parent_id, level, name FROM service_areas
     WHERE is_active = 1
     ORDER BY FIELD(level, 'state','district','city_village','area'), name"
)->fetchAll();

respond_ok(['areas' => $areas]);
