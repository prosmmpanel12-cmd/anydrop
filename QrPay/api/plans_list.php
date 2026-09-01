<?php
/**
 * QrPay — GET /api/plans_list.php
 *
 * Public, unauthenticated: active plans with monthly/yearly pricing,
 * yearly discount, and payment limits, for the dashboard's pricing
 * cards (Phase 6 — Billing screen) and any other pricing display.
 * Never returns inactive/deactivated plans.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/billing.php';

$plans = get_active_plans($pdo);

success(['plans' => $plans]);
