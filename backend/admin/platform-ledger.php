<?php
/**
 * Anydrop — Admin Web UI: Platform Cash Flow (MERGED, 2026-09-10)
 *
 * Owner asked to merge this into one page instead of two separate
 * "cash flow" pages. Everything this file used to render (Total Money
 * In/Out, Net Balance Held, Total Platform Revenue, the reconciliation
 * check, and the filterable platform_ledger entries list) now lives in
 * `admin/cash-flow.php`'s "Platform Cash Flow (UPIPE Merchant
 * Account)" section — same queries, unchanged logic, just relocated.
 *
 * This file is kept as a thin redirect (still checks login/permission
 * first) so any existing bookmark or hardcoded link to
 * platform-ledger.php doesn't 404. Query params aren't forwarded —
 * cash-flow.php's own From/To/restaurant filters are a different
 * param naming (`pl_restaurant_id` instead of `restaurant_id`, shared
 * `from`/`to` with the rest of the page) so a straight passthrough
 * would silently not apply; landing on the unfiltered page and letting
 * the admin re-filter there is simpler than mapping params.
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'payouts_view');

header('Location: cash-flow.php', true, 302);
exit;
