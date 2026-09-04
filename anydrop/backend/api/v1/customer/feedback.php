<?php
/**
 * POST /api/v1/customer/feedback.php
 * (Mapped from clean URL POST /customer/feedback per Phase 3.6 §2.7)
 * Auth: Customer token
 * Request: { "message": "...", "rating": 1-5 (optional) }
 *
 * Simple capture-and-store — no workflow yet. Reviewable directly in the
 * `feedback` table (or a future Admin Panel screen, Phase 5).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('customer');
$body = get_json_body();
require_fields($body, ['message']);

$rating = null;
if (isset($body['rating']) && $body['rating'] !== '') {
    $rating = (int) $body['rating'];
    if ($rating < 1 || $rating > 5) {
        respond_error('validation_error', 422, ['fields' => ['rating']]);
    }
}

$db = Database::get();
$stmt = $db->prepare(
    'INSERT INTO feedback (customer_id, message, rating) VALUES (:cid, :msg, :rating)'
);
$stmt->execute([
    'cid' => $owner['owner_id'],
    'msg' => trim($body['message']),
    'rating' => $rating,
]);

respond_ok(['id' => (int) $db->lastInsertId()], 201);
