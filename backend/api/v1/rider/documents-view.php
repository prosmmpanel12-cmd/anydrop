<?php
/**
 * GET /api/v1/rider/documents-view.php?rider_id=<id>&doc=id|vehicle
 *
 * Streams the raw bytes of a rider's private ID/vehicle document.
 * This is the ONLY way those files are ever served — see migration
 * 75's header for why they're stored outside the public uploads/ tree
 * in the first place (backend/rider_documents/, `Require all denied`).
 *
 * Two different callers, two different auth mechanisms, both accepted
 * here since this file (unlike every other endpoint in api/v1/) is
 * reached from both the native Rider app (Bearer token) AND the
 * server-rendered Admin panel (PHP session, same as everything under
 * backend/admin/ — see that folder's _bootstrap.php kdoc for why the
 * whole admin UI is session-based rather than Bearer). Checked in this
 * order:
 *
 *   1. A valid rider Bearer token whose owner_id matches the
 *      requested rider_id — a rider viewing their own submitted
 *      document back (e.g. a "here's what you submitted" preview on a
 *      re-submission screen).
 *   2. An active admin PHP session holding rider_documents_view —
 *      admin/riders.php's document-review UI links here directly as
 *      an <img>/<a> src, same as any other admin-panel asset link.
 *
 *   Anything else — no credentials, a rider token for a DIFFERENT
 *   rider's documents, or an admin session lacking the permission —
 *   gets 403. There is no public/unauthenticated path to this
 *   endpoint at all, unlike every *-upload.php sibling that returns a
 *   public uploads/ path.
 *
 * get_authenticated_owner() (lib/auth.php) is used directly here
 * instead of require_auth('rider'), because require_auth() calls
 * respond_error() itself on any failure — this endpoint needs to
 * *fall through* to the admin-session check first, not hard-fail the
 * moment there's no rider Bearer token (an admin request never sends
 * one at all).
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond_error('method_not_allowed', 405);
}

$requestedRiderId = (int) ($_GET['rider_id'] ?? 0);
$docField = $_GET['doc'] ?? '';

if ($requestedRiderId <= 0 || !in_array($docField, ['id', 'vehicle'], true)) {
    respond_error('validation_error', 422, ['fields' => ['rider_id', 'doc']]);
}

$db = Database::get();
$authorized = false;

// ---- Path 1: rider viewing their own document ----
$owner = get_authenticated_owner();
if ($owner && $owner['owner_type'] === 'rider' && (int) $owner['owner_id'] === $requestedRiderId) {
    $authorized = true;
}

// ---- Path 2: admin session with rider_documents_view ----
if (!$authorized) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!empty($_SESSION['admin_id'])) {
        require_once __DIR__ . '/../../../lib/admin_auth.php';
        $adminStmt = $db->prepare('SELECT is_active FROM admins WHERE id = :id LIMIT 1');
        $adminStmt->execute(['id' => (int) $_SESSION['admin_id']]);
        $adminRow = $adminStmt->fetch();
        if ($adminRow && $adminRow['is_active'] && admin_has_permission((int) $_SESSION['admin_id'], 'rider_documents_view')) {
            $authorized = true;
        }
    }
}

if (!$authorized) {
    respond_error('unauthorized', 403);
}

$column = $docField === 'id' ? 'id_doc_url' : 'vehicle_doc_url';
$stmt = $db->prepare("SELECT {$column} AS filename FROM riders WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmt->execute(['id' => $requestedRiderId]);
$row = $stmt->fetch();

if (!$row || !$row['filename']) {
    respond_error('not_found', 404);
}

// Filenames are always our own bin2hex()-generated names (see
// documents-upload.php) — never derived from user input — but this is
// still a path-safety guard against any future write path that isn't,
// same defensive posture as trusting nothing that ends up in a
// filesystem path built from a DB value.
$filename = basename($row['filename']);
$filePath = __DIR__ . '/../../../rider_documents/' . $requestedRiderId . '/' . $filename;
$realBase = realpath(__DIR__ . '/../../../rider_documents/' . $requestedRiderId);
$realFile = realpath($filePath);

if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    respond_error('not_found', 404);
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, no-store');
readfile($realFile);
exit;
