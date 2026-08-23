<?php
/**
 * Anydrop — Admin Web UI: Banner Manager
 *
 * Implements recall.md item 17 / docs/19_Admin_Panel_Full_Spec_And_Payment_
 * Email_Architecture_2026-08-14.md §5. Schema: backend/sql/
 * 33_migration_banners.sql.
 *
 * NOT the same thing as a restaurant's own `restaurant_banners` (that's
 * restaurant-managed, per-restaurant, no area targeting — see
 * backend/api/v1/restaurant/banner-upload.php). This page manages the
 * platform-wide `banners` table: Home/Offer/Festival/Popup banners the
 * ADMIN controls, optionally scoped to one service area.
 *
 * Area targeting (area_id NULL = all areas, or one specific
 * service_areas node) is the app owner's explicit requirement from doc
 * 19 §5 — "promotional posters ho wo us area ke customer ko hi dikhe."
 * The actual customer-facing banner-fetch endpoint that filters by the
 * customer's resolved area is NOT built here — this page only manages
 * the banners table; wiring the customer app's fetch to respect area_id
 * is separate, still-pending work (depends on recall.md item 3, address
 * → area resolution, which is also still pending).
 *
 * Image upload reuses the same validation as every other photo-upload
 * endpoint in this project (backend/api/v1/restaurant/banner-upload.php
 * etc.): 5 MB cap, jpg/png/webp only, checked via finfo (actual file
 * content, not just the extension/claimed MIME type). Uploads land in
 * backend/uploads/admin_banners/ — a new subdirectory alongside the
 * existing backend/uploads/address_photos/.
 *
 * Delete is hard (no other table references a banner row, unlike
 * areas/categories) — it also best-effort removes the uploaded image
 * file from disk. A failed unlink is silently ignored (orphaned file,
 * not a broken app) rather than blocking the DB delete over a disk
 * quirk.
 *
 * Gated on `banners_view`/`banners_edit`/`banners_delete` — all three
 * keys already existed in migration 29's permission seed (unused until
 * now, same as categories_*).
 *
 * STATUS: 🟡 IMPLEMENTED 2026-08-21 — TEST PENDING, same standing rule
 * as every other admin page built this cycle (see done.md): needs
 * migration 33 run on the live DB and a live click-through (add with
 * image, edit, replace image, area-scope vs platform-wide, deactivate,
 * delete) before this moves to ✅ DONE.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/audit.php';

$admin = admin_require_login();
admin_require_permission($admin, 'banners_view');
$canEdit = admin_has_permission($admin['id'], 'banners_edit');
$canDelete = admin_has_permission($admin['id'], 'banners_delete');
$db = Database::get();

const BANNER_TYPES = ['home', 'offer', 'festival', 'popup'];
const BANNER_TYPE_LABEL = ['home' => 'Home', 'offer' => 'Offer', 'festival' => 'Festival', 'popup' => 'Popup'];
const MAX_BANNER_BYTES = 5 * 1024 * 1024; // 5 MB — same cap as banner-upload.php
const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * Validates and saves an uploaded banner image, returns the stored
 * relative image_url, or null (with $error set) if anything's wrong.
 * Mirrors backend/api/v1/restaurant/banner-upload.php's checks exactly
 * (size cap, real-content MIME sniff via finfo, not just extension).
 *
 * 2026-08-21 (app owner request): added optional crop support. If
 * $cropX/$cropY/$cropW/$cropH are given (all in ORIGINAL-image pixel
 * coordinates — that's what the crop-preview widget in the Add/Edit
 * form below sends), the image is cropped to that rectangle via GD
 * before saving, instead of saving the raw upload untouched. This is
 * what backs the "kitna part aayega, kitna nahi" crop-preview screen —
 * the widget shows a fixed 3:1 frame (see its own comment for why 3:1)
 * and the admin drags the image under it; the frame's visible area in
 * ORIGINAL pixel coordinates is exactly this crop rectangle.
 *
 * Falls back to saving the untouched original if: GD isn't available,
 * this specific format has no GD read function on this server (rare,
 * but WebP GD support isn't universal), or no valid crop rect was sent
 * (e.g. JS failed to load, or an edit where the image wasn't replaced)
 * — a banner without a perfect crop is far better than a banner that
 * failed to upload at all.
 */
function save_banner_image(
    array $file,
    ?string &$error,
    ?float $cropX = null,
    ?float $cropY = null,
    ?float $cropW = null,
    ?float $cropH = null
): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed.';
        return null;
    }
    if ($file['size'] > MAX_BANNER_BYTES) {
        $error = 'Image is too large (max 5 MB).';
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset(ALLOWED_MIME[$mime])) {
        $error = 'Unsupported file type — use JPG, PNG, or WEBP.';
        return null;
    }
    $ext = ALLOWED_MIME[$mime];

    $uploadDir = __DIR__ . '/../uploads/admin_banners';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $filename;

    $haveCropRect = $cropX !== null && $cropY !== null && $cropW !== null && $cropH !== null
        && $cropW > 0 && $cropH > 0;

    if ($haveCropRect && extension_loaded('gd')) {
        $src = null;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $src = @imagecreatefromjpeg($file['tmp_name']);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($file['tmp_name']);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($file['tmp_name']);
        }

        if ($src) {
            $srcW = imagesx($src);
            $srcH = imagesy($src);
            // Defensive clamp — the browser computes this rect, but never
            // trust client-sent geometry against the actual file's real
            // dimensions before cropping.
            $cx = (int) max(0, min($cropX, $srcW - 1));
            $cy = (int) max(0, min($cropY, $srcH - 1));
            $cw = (int) max(1, min($cropW, $srcW - $cx));
            $ch = (int) max(1, min($cropH, $srcH - $cy));

            $dst = imagecreatetruecolor($cw, $ch);
            if ($mime === 'image/png') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $cw, $ch, $cw, $ch);

            $saved = false;
            switch ($mime) {
                case 'image/jpeg':
                    $saved = imagejpeg($dst, $destPath, 90);
                    break;
                case 'image/png':
                    $saved = imagepng($dst, $destPath);
                    break;
                case 'image/webp':
                    $saved = imagewebp($dst, $destPath, 90);
                    break;
            }
            imagedestroy($dst);
            imagedestroy($src);

            if ($saved) {
                return 'uploads/admin_banners/' . $filename;
            }
            // fall through to raw save below if the GD save somehow failed
        }
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        $error = 'Could not save the uploaded file.';
        return null;
    }
    return 'uploads/admin_banners/' . $filename;
}

$flash = null;
$flashType = 'success';

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_verify_csrf($_POST['csrf_token'] ?? '')) {
        $flash = 'Session expired — please try again.';
        $flashType = 'error';
    } else {
        $formAction = $_POST['form_action'] ?? '';

        if ($formAction === 'create_banner') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to add banners.';
                $flashType = 'error';
            } else {
                $title = trim($_POST['title'] ?? '') !== '' ? trim($_POST['title']) : null;
                $bannerType = in_array($_POST['banner_type'] ?? '', BANNER_TYPES, true) ? $_POST['banner_type'] : 'home';
                $deepLink = trim($_POST['deep_link'] ?? '') !== '' ? trim($_POST['deep_link']) : null;
                $areaId = trim($_POST['area_id'] ?? '') !== '' ? (int) $_POST['area_id'] : null;
                $priority = trim($_POST['priority'] ?? '') !== '' ? (int) $_POST['priority'] : 0;
                $startDate = trim($_POST['start_date'] ?? '') !== '' ? $_POST['start_date'] : null;
                $endDate = trim($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;

                $uploadError = null;
                $cropX = trim($_POST['crop_x'] ?? '') !== '' ? (float) $_POST['crop_x'] : null;
                $cropY = trim($_POST['crop_y'] ?? '') !== '' ? (float) $_POST['crop_y'] : null;
                $cropW = trim($_POST['crop_w'] ?? '') !== '' ? (float) $_POST['crop_w'] : null;
                $cropH = trim($_POST['crop_h'] ?? '') !== '' ? (float) $_POST['crop_h'] : null;
                $imageUrl = isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
                    ? save_banner_image($_FILES['image'], $uploadError, $cropX, $cropY, $cropW, $cropH)
                    : null;

                if (!$imageUrl) {
                    $flash = $uploadError ?? 'An image is required.';
                    $flashType = 'error';
                } elseif ($startDate && $endDate && $startDate > $endDate) {
                    $flash = 'Start date can\'t be after end date.';
                    $flashType = 'error';
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO banners (title, image_url, banner_type, deep_link, area_id, priority, start_date, end_date, is_active)
                         VALUES (:t, :img, :bt, :dl, :a, :p, :sd, :ed, 1)'
                    );
                    $ins->execute([
                        't' => $title, 'img' => $imageUrl, 'bt' => $bannerType, 'dl' => $deepLink,
                        'a' => $areaId, 'p' => $priority, 'sd' => $startDate, 'ed' => $endDate,
                    ]);
                    write_audit_log('admin', $admin['id'], 'banner_created', [
                        'banner_id' => (int) $db->lastInsertId(), 'title' => $title, 'type' => $bannerType,
                    ]);
                    $flash = 'Banner created.';
                }
            }
        } elseif ($formAction === 'update_banner') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit banners.';
                $flashType = 'error';
            } else {
                $bannerId = (int) ($_POST['banner_id'] ?? 0);
                $title = trim($_POST['title'] ?? '') !== '' ? trim($_POST['title']) : null;
                $bannerType = in_array($_POST['banner_type'] ?? '', BANNER_TYPES, true) ? $_POST['banner_type'] : 'home';
                $deepLink = trim($_POST['deep_link'] ?? '') !== '' ? trim($_POST['deep_link']) : null;
                $areaId = trim($_POST['area_id'] ?? '') !== '' ? (int) $_POST['area_id'] : null;
                $priority = trim($_POST['priority'] ?? '') !== '' ? (int) $_POST['priority'] : 0;
                $startDate = trim($_POST['start_date'] ?? '') !== '' ? $_POST['start_date'] : null;
                $endDate = trim($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;

                if ($startDate && $endDate && $startDate > $endDate) {
                    $flash = 'Start date can\'t be after end date.';
                    $flashType = 'error';
                } else {
                    $uploadError = null;
                    $newImageUrl = null;
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $cropX = trim($_POST['crop_x'] ?? '') !== '' ? (float) $_POST['crop_x'] : null;
                        $cropY = trim($_POST['crop_y'] ?? '') !== '' ? (float) $_POST['crop_y'] : null;
                        $cropW = trim($_POST['crop_w'] ?? '') !== '' ? (float) $_POST['crop_w'] : null;
                        $cropH = trim($_POST['crop_h'] ?? '') !== '' ? (float) $_POST['crop_h'] : null;
                        $newImageUrl = save_banner_image($_FILES['image'], $uploadError, $cropX, $cropY, $cropW, $cropH);
                        if (!$newImageUrl) {
                            $flash = $uploadError;
                            $flashType = 'error';
                        }
                    }

                    if ($flashType !== 'error') {
                        if ($newImageUrl) {
                            // Best-effort cleanup of the old file — an
                            // orphaned image is harmless, so a failed
                            // unlink is silently ignored, not surfaced
                            // as an error (the DB update is what matters).
                            $oldStmt = $db->prepare('SELECT image_url FROM banners WHERE id = :id');
                            $oldStmt->execute(['id' => $bannerId]);
                            $oldRow = $oldStmt->fetch();
                            if ($oldRow && !empty($oldRow['image_url'])) {
                                @unlink(__DIR__ . '/../' . $oldRow['image_url']);
                            }
                            $upd = $db->prepare(
                                'UPDATE banners SET title = :t, image_url = :img, banner_type = :bt, deep_link = :dl,
                                 area_id = :a, priority = :p, start_date = :sd, end_date = :ed WHERE id = :id'
                            );
                            $upd->execute([
                                't' => $title, 'img' => $newImageUrl, 'bt' => $bannerType, 'dl' => $deepLink,
                                'a' => $areaId, 'p' => $priority, 'sd' => $startDate, 'ed' => $endDate, 'id' => $bannerId,
                            ]);
                        } else {
                            $upd = $db->prepare(
                                'UPDATE banners SET title = :t, banner_type = :bt, deep_link = :dl,
                                 area_id = :a, priority = :p, start_date = :sd, end_date = :ed WHERE id = :id'
                            );
                            $upd->execute([
                                't' => $title, 'bt' => $bannerType, 'dl' => $deepLink,
                                'a' => $areaId, 'p' => $priority, 'sd' => $startDate, 'ed' => $endDate, 'id' => $bannerId,
                            ]);
                        }
                        write_audit_log('admin', $admin['id'], 'banner_updated', ['banner_id' => $bannerId]);
                        $flash = 'Banner updated.';
                    }
                }
            }
        } elseif ($formAction === 'toggle_active') {
            if (!$canEdit) {
                $flash = 'You don\'t have permission to edit banners.';
                $flashType = 'error';
            } else {
                $bannerId = (int) ($_POST['banner_id'] ?? 0);
                $db->prepare('UPDATE banners SET is_active = NOT is_active WHERE id = :id')->execute(['id' => $bannerId]);
                write_audit_log('admin', $admin['id'], 'banner_active_toggled', ['banner_id' => $bannerId]);
                $flash = 'Banner status updated.';
            }
        } elseif ($formAction === 'delete_banner') {
            if (!$canDelete) {
                $flash = 'You don\'t have permission to delete banners.';
                $flashType = 'error';
            } else {
                $bannerId = (int) ($_POST['banner_id'] ?? 0);
                $stmt = $db->prepare('SELECT image_url FROM banners WHERE id = :id');
                $stmt->execute(['id' => $bannerId]);
                $row = $stmt->fetch();
                if ($row) {
                    $db->prepare('DELETE FROM banners WHERE id = :id')->execute(['id' => $bannerId]);
                    if (!empty($row['image_url'])) {
                        @unlink(__DIR__ . '/../' . $row['image_url']);
                    }
                    write_audit_log('admin', $admin['id'], 'banner_deleted', ['banner_id' => $bannerId]);
                    $flash = 'Banner deleted.';
                }
            }
        }
    }
}

// ---------- Data for rendering ----------
$allBanners = $db->query('SELECT * FROM banners ORDER BY priority DESC, created_at DESC')->fetchAll();

// Area dropdown: any city_village/area node (whichever is meaningful to
// scope to), same "both levels assignable" reasoning as restaurants.php.
$areaOptions = $db->query(
    "SELECT id, name, level FROM service_areas WHERE level IN ('city_village','area') AND is_active = 1 ORDER BY name"
)->fetchAll();
// Full node map, for admin_area_breadcrumb_compact() to walk parent_id
// chains — same reasoning/pattern as restaurants.php's $areaNodeById.
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}
$areaNameById = [];
foreach ($areaOptions as $a) {
    $areaNameById[$a['id']] = admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById) . ' (' . ($a['level'] === 'area' ? 'Area' : 'City/Village') . ')';
}

$editingId = isset($_GET['edit_banner']) ? (int) $_GET['edit_banner'] : null;
$editingBanner = null;
if ($editingId) {
    foreach ($allBanners as $b) {
        if ((int) $b['id'] === $editingId) {
            $editingBanner = $b;
            break;
        }
    }
}

$csrf = admin_csrf_token();
$today = date('Y-m-d');

$pageTitle = 'Banners';
$activeNav = 'banners';
require __DIR__ . '/_layout_head.php';
?>

<div class="section">
<div class="card">
    <h2>Banners</h2>
    <?php if (empty($allBanners)): ?>
        <p class="muted">No banners yet — add one below.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table>
        <tr><th>Image</th><th>Title</th><th>Type</th><th>Area</th><th>Priority</th><th>Dates</th><th>Status</th><th></th></tr>
        <?php foreach ($allBanners as $b): ?>
        <?php
            $isExpired = !empty($b['end_date']) && $b['end_date'] < $today;
            $isScheduled = !empty($b['start_date']) && $b['start_date'] > $today;
        ?>
        <tr>
            <td><img src="../<?= admin_escape($b['image_url']) ?>" alt="" style="width:64px; height:36px; object-fit:cover; border-radius:4px;"></td>
            <td><?= admin_escape($b['title'] ?? '') ?: '<span class="muted">—</span>' ?></td>
            <td><?= BANNER_TYPE_LABEL[$b['banner_type']] ?></td>
            <td><?= $b['area_id'] ? admin_escape($areaNameById[$b['area_id']] ?? ('#' . $b['area_id'])) : '<span class="muted">All areas</span>' ?></td>
            <td><?= (int) $b['priority'] ?></td>
            <td class="muted" style="font-size:12px;">
                <?= admin_escape($b['start_date'] ?? '…') ?> → <?= admin_escape($b['end_date'] ?? '…') ?>
                <?php if ($isExpired): ?><br><span class="badge inactive">Expired</span><?php elseif ($isScheduled): ?><br><span class="badge level-district">Scheduled</span><?php endif; ?>
            </td>
            <td><span class="badge <?= $b['is_active'] ? 'active' : 'inactive' ?>"><?= $b['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="row-actions">
                <?php if ($canEdit): ?>
                    <a class="btn btn-outline" href="?edit_banner=<?= (int) $b['id'] ?>">Edit</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="toggle_active">
                        <input type="hidden" name="banner_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="btn btn-outline"><?= $b['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
                        <input type="hidden" name="form_action" value="delete_banner">
                        <input type="hidden" name="banner_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="btn btn-outline danger"
                            data-confirm-title="Delete this banner?"
                            data-confirm-text="This can't be undone — the image file is removed too."
                            data-confirm-ok-label="Delete">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<?php if ($canEdit): ?>
<div class="section">
<div class="card">
    <h2><?= $editingBanner ? 'Edit banner' : 'Add banner' ?></h2>
    <p class="hint">Leave Area empty for a platform-wide banner (all customers). Leave Start/End date empty for no schedule limit.</p>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= admin_escape($csrf) ?>">
        <input type="hidden" name="form_action" value="<?= $editingBanner ? 'update_banner' : 'create_banner' ?>">
        <?php if ($editingBanner): ?>
            <input type="hidden" name="banner_id" value="<?= (int) $editingBanner['id'] ?>">
        <?php endif; ?>
        <div>
            <label class="field-label">Title (optional, internal)</label>
            <input type="text" name="title" value="<?= admin_escape($editingBanner['title'] ?? '') ?>" placeholder="e.g. Diwali Sale">
        </div>
        <div>
            <label class="field-label">Image <?= $editingBanner ? '(leave empty to keep current)' : '' ?></label>
            <input type="file" id="bannerImageInput" name="image" accept="image/jpeg,image/png,image/webp" <?= $editingBanner ? '' : 'required' ?>>
            <?php if ($editingBanner): ?>
                <div style="margin-top:6px;"><img src="../<?= admin_escape($editingBanner['image_url']) ?>" alt="" style="width:120px; height:auto; border-radius:4px;"></div>
            <?php endif; ?>

            <div id="cropSection" style="display:none; margin-top:10px;">
                <label class="field-label">Crop preview (3:1 banner frame) — drag the image to reposition; only what's inside the frame will actually show</label>
                <div id="cropStage" style="width:100%; max-width:420px; aspect-ratio:3/1; overflow:hidden; position:relative; border:1px solid var(--border,#ddd); border-radius:6px; touch-action:none; cursor:grab; background:#eee;">
                    <img id="cropImg" alt="" style="position:absolute; left:0; top:0; max-width:none; user-select:none;">
                </div>
                <p class="muted" style="font-size:12px; margin-top:4px;">Whatever's outside this frame is cropped away and never shown — matches the home-screen banner's actual display shape.</p>
                <input type="hidden" name="crop_x" id="cropX">
                <input type="hidden" name="crop_y" id="cropY">
                <input type="hidden" name="crop_w" id="cropW">
                <input type="hidden" name="crop_h" id="cropH">
            </div>
        </div>
        <div>
            <label class="field-label">Type</label>
            <select name="banner_type">
                <?php foreach (BANNER_TYPES as $t): ?>
                    <option value="<?= $t ?>" <?= ($editingBanner['banner_type'] ?? 'home') === $t ? 'selected' : '' ?>><?= BANNER_TYPE_LABEL[$t] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">Deep link (optional)</label>
            <input type="text" name="deep_link" value="<?= admin_escape($editingBanner['deep_link'] ?? '') ?>" placeholder="restaurant:42, category:pizza, or a full https:// URL">
            <p class="hint" style="margin-top:4px;">
                Leave blank for a visual-only banner (no tap action). Recognized formats:
                <code>restaurant:&lt;id&gt;</code>, <code>category:&lt;slug&gt;</code>, or a full
                <code>https://</code> link. Anything else is treated as visual-only rather than guessed at —
                see <code>home/promo-banners.php</code>'s <code>deep_link_to_target()</code>. Coupon deep-links
                aren't supported yet.
            </p>
        </div>
        <div>
            <label class="field-label">Area (empty = all areas)</label>
            <select name="area_id">
                <option value="">— All areas (platform-wide) —</option>
                <?php foreach ($areaOptions as $a): ?>
                    <option value="<?= (int) $a['id'] ?>" <?= (int) ($editingBanner['area_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                        <?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?> (<?= $a['level'] === 'area' ? 'Area' : 'City/Village' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">Priority</label>
            <input type="number" name="priority" value="<?= (int) ($editingBanner['priority'] ?? 0) ?>" placeholder="0">
        </div>
        <div>
            <label class="field-label">Start date (optional)</label>
            <input type="date" name="start_date" value="<?= admin_escape($editingBanner['start_date'] ?? '') ?>">
        </div>
        <div>
            <label class="field-label">End date (optional)</label>
            <input type="date" name="end_date" value="<?= admin_escape($editingBanner['end_date'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?= $editingBanner ? 'Save' : 'Add' ?></button>
        <?php if ($editingBanner): ?>
            <a href="banners.php" class="btn btn-outline">Cancel</a>
        <?php endif; ?>
    </form>
</div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var fileInput = document.getElementById('bannerImageInput');
    var section = document.getElementById('cropSection');
    var stage = document.getElementById('cropStage');
    var img = document.getElementById('cropImg');
    var hiddenX = document.getElementById('cropX');
    var hiddenY = document.getElementById('cropY');
    var hiddenW = document.getElementById('cropW');
    var hiddenH = document.getElementById('cropH');
    if (!fileInput || !stage) return;

    var natW = 0, natH = 0, scale = 1, offX = 0, offY = 0;
    var dragging = false, startX = 0, startY = 0, startOffX = 0, startOffY = 0;

    function clampOffsets() {
        var stageW = stage.clientWidth, stageH = stage.clientHeight;
        var dW = natW * scale, dH = natH * scale;
        offX = Math.min(0, Math.max(stageW - dW, offX));
        offY = Math.min(0, Math.max(stageH - dH, offY));
    }

    function updateHidden() {
        var stageW = stage.clientWidth, stageH = stage.clientHeight;
        hiddenX.value = (-offX / scale).toFixed(2);
        hiddenY.value = (-offY / scale).toFixed(2);
        hiddenW.value = (stageW / scale).toFixed(2);
        hiddenH.value = (stageH / scale).toFixed(2);
    }

    function layout() {
        var stageW = stage.clientWidth, stageH = stage.clientHeight;
        if (!stageW || !stageH || !natW || !natH) return;
        // "cover" fit — same idea as CSS object-fit:cover / Android
        // centerCrop: scale so the image fully fills the frame on
        // whichever axis is tighter, leaving the other axis to overflow
        // (that overflow is exactly what dragging lets the admin choose
        // which side of).
        scale = Math.max(stageW / natW, stageH / natH);
        img.style.width = (natW * scale) + 'px';
        img.style.height = (natH * scale) + 'px';
        clampOffsets();
        img.style.left = offX + 'px';
        img.style.top = offY + 'px';
        updateHidden();
    }

    fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) { section.style.display = 'none'; return; }
        var reader = new FileReader();
        reader.onload = function (e) {
            img.onload = function () {
                natW = img.naturalWidth;
                natH = img.naturalHeight;
                offX = 0;
                offY = 0;
                section.style.display = 'block';
                // Stage needs to have its final rendered size (from the
                // CSS aspect-ratio box) before we can compute cover-scale.
                requestAnimationFrame(layout);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(f);
    });

    function onDown(e) {
        if (!natW) return;
        dragging = true;
        var p = e.touches ? e.touches[0] : e;
        startX = p.clientX;
        startY = p.clientY;
        startOffX = offX;
        startOffY = offY;
        stage.style.cursor = 'grabbing';
    }
    function onMove(e) {
        if (!dragging) return;
        var p = e.touches ? e.touches[0] : e;
        offX = startOffX + (p.clientX - startX);
        offY = startOffY + (p.clientY - startY);
        clampOffsets();
        img.style.left = offX + 'px';
        img.style.top = offY + 'px';
        updateHidden();
        e.preventDefault();
    }
    function onUp() {
        dragging = false;
        stage.style.cursor = 'grab';
    }

    stage.addEventListener('mousedown', onDown);
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);
    stage.addEventListener('touchstart', onDown, { passive: false });
    window.addEventListener('touchmove', onMove, { passive: false });
    window.addEventListener('touchend', onUp);
    window.addEventListener('resize', function () { if (natW) layout(); });
})();
</script>
<?php require __DIR__ . '/_layout_foot.php'; ?>
