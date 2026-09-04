<?php
/**
 * GET /api/v1/home/categories.php
 * (Mapped from clean URL GET /home/categories per 02_API_Contract.md)
 * Auth: Customer token
 *
 * Returns the platform-wide category chip row shown on Home under the
 * search bar (screenshot reference: All / Pizza / Rolls / Burger). Backed
 * by `food_categories` (see 05_migration_categories_and_tags.sql), so new
 * categories can be added from the Admin Panel later without an app update.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');
header('Cache-Control: max-age=600');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

require_auth('customer');

$db = Database::get();

$stmt = $db->query(
    "SELECT id, name, slug, icon_url, sort_order
     FROM food_categories
     WHERE is_active = 1
     ORDER BY sort_order ASC"
);
$rows = $stmt->fetchAll();

$categories = array_map(fn($c) => [
    'id' => (int) $c['id'],
    'name' => $c['name'],
    'slug' => $c['slug'],
    'icon_url' => $c['icon_url'],
], $rows);

respond_ok($categories);
