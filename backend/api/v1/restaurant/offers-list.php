<?php
/**
 * GET /api/v1/restaurant/offers-list.php
 * Auth: Restaurant token
 * Response: { "offers": [{ ...format_offer() fields..., times_used, is_currently_active }] }
 *
 * Scoped strictly to promo_offers.restaurant_id = the logged-in
 * restaurant — same ownership boundary coupons-list.php already
 * enforces for coupons. Returns every non-deleted offer regardless of
 * status (active/paused/disabled) so the Restaurant App's own
 * Active/Scheduled/Expired/Paused tabs (doc 20 §14 — not built this
 * session, see docs/29) can group them client-side from one call,
 * same "return everything, let the client bucket it" pattern
 * coupons-list.php already uses for active-vs-archived.
 *
 * is_currently_active is a derived convenience flag (status='active'
 * AND today is within start_date/end_date AND, if a happy-hour window
 * is set, right now is within it) — NOT stored, computed fresh on
 * every read via lib/offers.php's own eligibility helpers, so it can
 * never drift from what price_cart() would actually apply.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/offers.php';

header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$owner = require_auth('restaurant');
$restaurantId = $owner['owner_id'];

$db = Database::get();

$stmt = $db->prepare(
    'SELECT o.*,
            (SELECT COUNT(*) FROM offer_usages u WHERE u.offer_id = o.id) AS times_used
     FROM promo_offers o
     WHERE o.restaurant_id = :rid AND o.deleted_at IS NULL
     ORDER BY o.status = \'active\' DESC, o.id DESC'
);
$stmt->execute(['rid' => $restaurantId]);
$rows = $stmt->fetchAll();

$offers = array_map(function ($r) use ($db) {
    $formatted = format_offer($r, $db);
    $formatted['times_used'] = (int) $r['times_used'];
    $formatted['is_currently_active'] = $r['status'] === 'active'
        && (empty($r['start_date']) || $r['start_date'] <= date('Y-m-d'))
        && (empty($r['end_date']) || $r['end_date'] >= date('Y-m-d'))
        && is_offer_time_eligible($r);
    return $formatted;
}, $rows);

respond_ok(['offers' => $offers]);
