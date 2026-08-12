<?php
/**
 * GET /api/v1/customer/faqs.php
 * (Mapped from clean URL GET /customer/faqs per Phase 3.6 §2.7)
 * Auth: Customer token
 *
 * Static-content-but-DB-driven FAQ list for Profile → FAQs, so entries can
 * be edited/added directly in the `faqs` table without an app update.
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
    'SELECT id, question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
);
$rows = $stmt->fetchAll();

respond_ok(['faqs' => array_map(fn($f) => [
    'id' => (int) $f['id'],
    'question' => $f['question'],
    'answer' => $f['answer'],
], $rows)]);
