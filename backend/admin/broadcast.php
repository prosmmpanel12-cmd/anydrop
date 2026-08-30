<?php
/**
 * Anydrop — Admin Web UI: Push Notification Broadcast
 *
 * This session's build of what lib/notifications.php's own long-
 * standing kdoc called "Type 2 — admin broadcast/targeting, not yet
 * built." Person-requested variants: with/without image, with/without
 * link, and area-wise targeting — all covered below.
 *
 * DELIVERY MECHANISM: this page never calls lib/fcm.php directly. It
 * resolves the target audience (all customers / all restaurants / a
 * specific area's customers / a specific area's restaurants) into a
 * list of recipient ids, then loops calling create_notification() once
 * per recipient — the exact same function every other notification in
 * this codebase already goes through (order accept, review reply,
 * etc). That function already does the bell-row write AND the real
 * FCM push (migration 60's change) — this page adds no second
 * delivery path, it just has a different, admin-driven way of picking
 * who create_notification() gets called for. This is also why a
 * broadcast shows up in the recipient's in-app notification bell too,
 * not just as a push — same as every other notification type.
 *
 * LINK HANDLING: link_url rides in the FCM `data` payload (see
 * create_notification()'s $data param) as `data.link`. Deciding what a
 * tap on a broadcast notification actually does with that link (open
 * in-app webview vs external browser vs a specific screen) is an
 * Android-side decision — flagged in this session's handover doc as a
 * "still open" item for CustomerFirebaseMessagingService /
 * RestaurantFirebaseMessagingService, since neither app has a generic
 * "open this arbitrary URL" screen to route to yet, only specific
 * screens (order detail, home). Sending the link now, even before that
 * routing exists, means no backend/database change is needed later —
 * only the Android tap-handler needs to grow.
 *
 * IMAGE HANDLING: reuses banners.php's save_banner_image() upload
 * validation (size cap, real-content MIME sniff, same allowed types)
 * — see that function's own kdoc for why. The one addition here:
 * FCM needs an ABSOLUTE, publicly-fetchable URL (Google's own servers
 * fetch the image, not the recipient device via this backend's
 * relative-path convention every other image_url/logo_url in this
 * schema uses) — see the new app_base_url setting below.
 *
 * app_base_url: this codebase has never needed an absolute base URL
 * before (checked — no email-sending feature, no other external-
 * fetch-a-URL feature exists anywhere in backend/lib/). Rather than
 * build a whole new general Settings admin page for one value, it's
 * configured right on this page (stored in app_settings, read via
 * get_setting()) — a real gap, not a design choice, flagged as such in
 * the settings card below and in the handover doc.
 *
 * Gated on notifications_send (migration 29 — this key already
 * existed, unused until now) for sending, notifications_view isn't
 * separately used here since anyone who can view the send form and
 * history is, by definition, someone with send access on this page —
 * there's no read-only "view broadcasts" role split requested.
 *
 * STATUS: 🆕 BUILT 2026-08-29 (doc 66) — NOT build/device-verified (no
 * PHP CLI or live DB in this sandbox, same standing gap as every other
 * admin page this session). Needs migration 60 + 61 run live, then:
 * set app_base_url, send a test broadcast with an image to "All
 * customers" on a real device with the Customer app installed and
 * logged in, confirm the push arrives with the image visible
 * (BigPictureStyle) and the notification_broadcasts row's
 * recipient_count/delivered_count look sane.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/notifications.php';

$admin = admin_require_login();
admin_require_permission($admin, 'notifications_send');
$db = Database::get();

const MAX_BROADCAST_IMAGE_BYTES = 5 * 1024 * 1024; // 5 MB — same cap as banners.php
const BROADCAST_IMAGE_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * Saves an optional broadcast image. Mirrors banners.php's
 * save_banner_image() exactly (size cap, finfo MIME sniff, never trust
 * the extension) minus the crop-rect support — a broadcast image is
 * used as-is, no in-admin cropping tool for this page. Returns the
 * *relative* stored path (same convention as every other image column
 * in this schema) — the absolute-URL conversion for FCM happens
 * separately, at send time, not here, so this function's contract
 * stays identical to banners.php's regardless of what a future caller
 * needs the URL for.
 */
function save_broadcast_image(array $file, ?string &$error): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed.';
        return null;
    }
    if ($file['size'] > MAX_BROADCAST_IMAGE_BYTES) {
        $error = 'Image is too large (max 5 MB).';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(BROADCAST_IMAGE_MIME[$mime])) {
        $error = 'Unsupported file type — use JPG, PNG, or WEBP.';
        return null;
    }
    $ext = BROADCAST_IMAGE_MIME[$mime];

    $uploadDir = __DIR__ . '/../uploads/admin_broadcasts';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'broadcast_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $error = 'Could not save the uploaded file.';
        return null;
    }
    return 'uploads/admin_broadcasts/' . $filename;
}

/**
 * Every service_areas row, id-keyed, for the same in-PHP tree-walk
 * convention areas.php already uses (this codebase avoids
 * WITH RECURSIVE — see Status.md's own note about restricted
 * MariaDB/hosting setups not supporting it — everywhere else areas
 * are traversed, so this follows suit rather than introducing the
 * first recursive-CTE dependency).
 */
$allAreas = $db->query('SELECT * FROM service_areas ORDER BY level, name')->fetchAll();
$areaById = [];
foreach ($allAreas as $a) {
    $areaById[(int) $a['id']] = $a;
}

/**
 * Given one area id, returns that area's id plus every descendant's id
 * (children, grandchildren, ...) — broadcasting to a State should
 * reach every District/City/Area under it, not just rows with that
 * exact area_id. Iterative queue-based walk, not recursion, so a
 * pathological cycle in parent_id (shouldn't be possible given the FK,
 * but this function makes no assumption about that) can't blow the
 * PHP call stack.
 */
function area_and_descendant_ids(int $rootId, array $allAreas): array
{
    $childrenByParent = [];
    foreach ($allAreas as $a) {
        if ($a['parent_id'] !== null) {
            $childrenByParent[(int) $a['parent_id']][] = (int) $a['id'];
        }
    }
    $result = [$rootId];
    $queue = [$rootId];
    while ($queue) {
        $current = array_shift($queue);
        foreach ($childrenByParent[$current] ?? [] as $childId) {
            $result[] = $childId;
            $queue[] = $childId;
        }
    }
    return $result;
}

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'save_base_url') {
            $newBaseUrl = trim((string) ($_POST['app_base_url'] ?? ''));
            if ($newBaseUrl !== '' && !filter_var($newBaseUrl, FILTER_VALIDATE_URL)) {
                $flash = 'Enter a valid URL (e.g. https://yourdomain.com/anydrop/).';
                $flashType = 'error';
            } else {
                $upsert = $db->prepare(
                    "INSERT INTO app_settings (`key`, `value`, description) VALUES ('app_base_url', :v, 'Public base URL used to build absolute image links for FCM push notifications')
                     ON DUPLICATE KEY UPDATE `value` = :v2"
                );
                $upsert->execute(['v' => $newBaseUrl, 'v2' => $newBaseUrl]);
                write_audit_log('admin', $admin['id'], 'settings.app_base_url_updated', ['value' => $newBaseUrl]);
                $flash = 'Base URL saved.';
            }
        } elseif ($formAction === 'send_broadcast') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $linkUrl = trim((string) ($_POST['link_url'] ?? ''));
            $targetType = (string) ($_POST['target_type'] ?? '');
            $targetAreaId = trim((string) ($_POST['target_area_id'] ?? '')) !== '' ? (int) $_POST['target_area_id'] : null;

            $allowedTargets = ['all_customers', 'all_restaurants', 'area_customers', 'area_restaurants'];

            if ($title === '' || $body === '') {
                $flash = 'Title and message are both required.';
                $flashType = 'error';
            } elseif (!in_array($targetType, $allowedTargets, true)) {
                $flash = 'Choose who this broadcast is for.';
                $flashType = 'error';
            } elseif (str_starts_with($targetType, 'area_') && $targetAreaId === null) {
                $flash = 'Choose an area for an area-targeted broadcast.';
                $flashType = 'error';
            } elseif ($linkUrl !== '' && !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
                $flash = 'Link must be a valid URL, or left blank.';
                $flashType = 'error';
            } else {
                $imageError = null;
                $imagePath = isset($_FILES['image']) ? save_broadcast_image($_FILES['image'], $imageError) : null;

                if ($imageError !== null) {
                    $flash = $imageError;
                    $flashType = 'error';
                } else {
                    $imageAbsoluteUrl = null;
                    if ($imagePath !== null) {
                        $baseUrl = rtrim((string) get_setting('app_base_url', ''), '/');
                        if ($baseUrl === '') {
                            $flash = 'Set the Base URL below before sending a broadcast with an image — FCM needs a full public link to fetch it.';
                            $flashType = 'error';
                        } else {
                            $imageAbsoluteUrl = $baseUrl . '/' . $imagePath;
                        }
                    }

                    if ($flash === null) {
                        // ---- Resolve the recipient id list ----
                        $recipientType = str_ends_with($targetType, '_customers') ? 'customer' : 'restaurant';
                        $recipientIds = [];

                        if ($targetType === 'all_customers') {
                            $recipientIds = array_column(
                                $db->query('SELECT id FROM customers WHERE deleted_at IS NULL')->fetchAll(),
                                'id'
                            );
                        } elseif ($targetType === 'all_restaurants') {
                            $recipientIds = array_column(
                                $db->query("SELECT id FROM restaurants WHERE deleted_at IS NULL AND status = 'approved'")->fetchAll(),
                                'id'
                            );
                        } elseif ($targetType === 'area_customers') {
                            $areaIds = area_and_descendant_ids($targetAreaId, $allAreas);
                            $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
                            // DISTINCT — a customer can have more than one
                            // address in the targeted area (or its
                            // descendants), and must only receive the
                            // broadcast once, not once per matching address.
                            $stmt = $db->prepare(
                                "SELECT DISTINCT c.id
                                 FROM customers c
                                 JOIN customer_addresses ca ON ca.customer_id = c.id
                                 WHERE c.deleted_at IS NULL AND ca.area_id IN ($placeholders)"
                            );
                            $stmt->execute($areaIds);
                            $recipientIds = array_column($stmt->fetchAll(), 'id');
                        } else { // area_restaurants
                            $areaIds = area_and_descendant_ids($targetAreaId, $allAreas);
                            $placeholders = implode(',', array_fill(0, count($areaIds), '?'));
                            $stmt = $db->prepare(
                                "SELECT id FROM restaurants
                                 WHERE deleted_at IS NULL AND status = 'approved' AND area_id IN ($placeholders)"
                            );
                            $stmt->execute($areaIds);
                            $recipientIds = array_column($stmt->fetchAll(), 'id');
                        }

                        $recipientIds = array_map('intval', $recipientIds);
                        $recipientCount = count($recipientIds);

                        // ---- Fire one create_notification() per recipient ----
                        // Same function every other notification in this
                        // codebase uses — see this file's header for why
                        // there's no second delivery path here.
                        $deliveredCount = 0;
                        $notificationData = [];
                        if ($linkUrl !== '') {
                            $notificationData['link'] = $linkUrl;
                        }
                        // create_notification() has no direct return value
                        // to tell this loop whether the FCM push itself
                        // succeeded per recipient (it's fire-and-forget by
                        // design — see that function's own kdoc). This
                        // page approximates delivered_count as "had a
                        // token to try" rather than "confirmed delivered,"
                        // by checking the same column create_notification()
                        // itself reads — an honest approximation, not a
                        // guaranteed delivery receipt.
                        $tokenColumn = 'fcm_token';
                        $tokenTable = $recipientType === 'customer' ? 'customers' : 'restaurants';
                        $tokenCounts = [];
                        if ($recipientCount > 0) {
                            $placeholders = implode(',', array_fill(0, $recipientCount, '?'));
                            $tokenStmt = $db->prepare(
                                "SELECT id FROM $tokenTable WHERE id IN ($placeholders) AND $tokenColumn IS NOT NULL"
                            );
                            $tokenStmt->execute($recipientIds);
                            $tokenCounts = array_column($tokenStmt->fetchAll(), 'id');
                            $deliveredCount = count($tokenCounts);
                        }

                        foreach ($recipientIds as $rid) {
                            create_notification($recipientType, $rid, $title, $body, 'promo', array_merge(
                                $notificationData,
                                $imageAbsoluteUrl !== null ? ['image_url' => $imageAbsoluteUrl] : []
                            ));
                        }

                        $insert = $db->prepare(
                            'INSERT INTO notification_broadcasts
                                (admin_id, title, body, image_url, link_url, target_type, target_area_id, recipient_count, delivered_count)
                             VALUES (:admin_id, :title, :body, :image_url, :link_url, :target_type, :target_area_id, :recipient_count, :delivered_count)'
                        );
                        $insert->execute([
                            'admin_id' => $admin['id'],
                            'title' => $title,
                            'body' => $body,
                            'image_url' => $imageAbsoluteUrl,
                            'link_url' => $linkUrl !== '' ? $linkUrl : null,
                            'target_type' => $targetType,
                            'target_area_id' => $targetAreaId,
                            'recipient_count' => $recipientCount,
                            'delivered_count' => $deliveredCount,
                        ]);

                        write_audit_log('admin', $admin['id'], 'broadcast.sent', [
                            'broadcast_id' => (int) $db->lastInsertId(),
                            'target_type' => $targetType,
                            'recipient_count' => $recipientCount,
                        ]);

                        $flash = "Sent to $recipientCount recipient(s) — $deliveredCount had a device registered to receive the push (everyone still sees it in their in-app notification bell either way).";
                    }
                }
            }
        }
    }
}

$csrf = admin_csrf_token();
$appBaseUrl = (string) get_setting('app_base_url', '');

// Only area/city_village/district/state nodes that could plausibly be
// picked from a flat <select> — same list areas.php's own pickers use,
// no restriction to leaf-level only, since broadcasting to an entire
// District (reaching every City/Area under it via
// area_and_descendant_ids()) is a legitimate, likely common choice.
$areaOptions = $allAreas;

$recentBroadcasts = $db->query(
    "SELECT nb.*, a.username AS admin_username, sa.name AS area_name
     FROM notification_broadcasts nb
     LEFT JOIN admins a ON a.id = nb.admin_id
     LEFT JOIN service_areas sa ON sa.id = nb.target_area_id
     ORDER BY nb.created_at DESC
     LIMIT 20"
)->fetchAll();

$pageTitle = 'Push Notification Broadcast';
$activeNav = 'broadcast';
require __DIR__ . '/_layout_head.php';
?>

<div class="page-header">
    <h1>Push Notification Broadcast</h1>
</div>

<?php if (!$appBaseUrl): ?>
<div class="card" style="border-left: 4px solid #e0a800;">
    <h2>Base URL not set</h2>
    <p class="muted">Needed only if a broadcast includes an image — FCM fetches the image directly from a public URL, not through the app. Text-only broadcasts work without this.</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_base_url">
        <input type="url" name="app_base_url" placeholder="https://yourdomain.com/anydrop/" style="width: 100%; max-width: 480px;" required>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <h2>Base URL</h2>
    <form method="post" style="display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="save_base_url">
        <input type="url" name="app_base_url" value="<?= admin_escape($appBaseUrl) ?>" style="width: 100%; max-width: 480px;" required>
        <button type="submit" class="btn btn-outline">Update</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Send a broadcast</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="send_broadcast">

        <div class="form-group">
            <label>Title</label>
            <input type="text" name="title" maxlength="150" required>
        </div>

        <div class="form-group">
            <label>Message</label>
            <textarea name="body" rows="3" required></textarea>
        </div>

        <div class="form-group">
            <label>Image (optional)</label>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
        </div>

        <div class="form-group">
            <label>Link (optional)</label>
            <input type="url" name="link_url" placeholder="https://...">
        </div>

        <div class="form-group">
            <label>Send to</label>
            <select name="target_type" id="broadcastTargetType" required>
                <option value="all_customers">All customers</option>
                <option value="all_restaurants">All restaurants</option>
                <option value="area_customers">Customers in a specific area</option>
                <option value="area_restaurants">Restaurants in a specific area</option>
            </select>
        </div>

        <div class="form-group" id="broadcastAreaGroup" style="display:none;">
            <label>Area</label>
            <select name="target_area_id">
                <option value="">— Choose an area —</option>
                <?php foreach ($areaOptions as $area): ?>
                    <option value="<?= (int) $area['id'] ?>"><?= admin_escape(str_repeat('— ', ['state' => 0, 'district' => 1, 'city_village' => 2, 'area' => 3][$area['level']] ?? 0) . $area['name']) ?> (<?= admin_escape($area['level']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <p class="muted">Includes every area nested under the one you pick — choosing a State reaches every District/City/Area within it.</p>
        </div>

        <button type="submit" class="btn btn-primary" data-confirm-title="Send this broadcast?" data-confirm-text="This sends a real push notification immediately — there's no draft/schedule step." data-confirm-ok-label="Send">Send broadcast</button>
    </form>
</div>

<script>
document.getElementById('broadcastTargetType').addEventListener('change', function () {
    document.getElementById('broadcastAreaGroup').style.display =
        this.value.startsWith('area_') ? 'block' : 'none';
});
</script>

<div class="card">
    <h2>Recent broadcasts</h2>
    <?php if (empty($recentBroadcasts)): ?>
        <p class="muted">No broadcasts sent yet.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Sent</th><th>Title</th><th>Target</th><th>Recipients</th><th>Delivered*</th><th>By</th></tr>
        <?php foreach ($recentBroadcasts as $b): ?>
        <tr>
            <td><?= admin_escape($b['created_at']) ?></td>
            <td><?= admin_escape($b['title']) ?></td>
            <td>
                <?php
                $targetLabel = [
                    'all_customers' => 'All customers',
                    'all_restaurants' => 'All restaurants',
                    'area_customers' => 'Customers in ' . ($b['area_name'] ?? '—'),
                    'area_restaurants' => 'Restaurants in ' . ($b['area_name'] ?? '—'),
                ][$b['target_type']] ?? $b['target_type'];
                echo admin_escape($targetLabel);
                ?>
            </td>
            <td><?= (int) $b['recipient_count'] ?></td>
            <td><?= (int) $b['delivered_count'] ?></td>
            <td><?= admin_escape($b['admin_username'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p class="muted">*Delivered = had a device registered for push at send time, not a confirmed-read receipt. Everyone in Recipients also received the message in their in-app notification bell regardless.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
