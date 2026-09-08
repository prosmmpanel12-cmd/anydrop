<?php
/**
 * Anydrop — Admin Web UI: Live Rider Map (deep-plan §25, "Live map"
 * sub-section) — the third and last of §25; "Rider list" (doc 108) and
 * "Rider detail" (doc 109) shipped first since both were smaller,
 * data-already-exists slices. This is the one §25 sub-section with
 * genuinely zero prior admin-side map infrastructure to extend — the
 * only maps anywhere in this codebase before this session were
 * areas.php's Leaflet "choose a point" picker (admin, single-point,
 * OSM tiles, no API key) and the customer/rider Android apps' Google
 * Maps SDK views. Neither is a "plot many live markers" surface, so
 * this is a fresh build, not an extension.
 *
 * Deliberately Leaflet + OSM raster tiles, same choice areas.php
 * already made for the same reason (its own kdoc: "no API key needed,
 * unlike the customer app's Google Maps pin-drop... low-traffic
 * internal admin tool") — checked `directions-settings.php` first to
 * see if `google_directions_api_key` could double as a browser-side
 * Maps JavaScript API key instead of pulling in a second mapping
 * stack, but that key is a server-side Directions API credential
 * (used only from `route.php`'s server-to-server call) with no
 * confirmed HTTP-referrer restriction suited to being embedded in
 * admin-panel page source — reusing it client-side would risk
 * exposing a key that was never scoped for that, so this page follows
 * areas.php's existing no-API-key precedent instead of introducing
 * that risk for what's an internal ops view.
 *
 * Markers are plain Leaflet circleMarkers (not the default pin icon)
 * — deliberately, because Leaflet's default marker relies on
 * marker-icon.png/marker-icon-2x.png/marker-shadow.png being resolved
 * relative to wherever it thinks its own JS file lives, which is not
 * reliably auto-detected off a `leaflet.min.js` (vs `leaflet.js`)
 * filename on a CDN — a circleMarker needs no external image asset at
 * all, side-stepping that class of failure entirely rather than
 * risking silently-invisible/broken markers on the one page whose
 * entire purpose is showing markers.
 *
 * Scope, per deep-plan §25's own wording ("online riders, active
 * deliveries, last location, stale-location warning"):
 *   - Every currently-online platform rider (is_online = 1), PLUS any
 *     rider with a live active-delivery order even if their is_online
 *     flag is somehow stale/off (a real inconsistency worth surfacing
 *     to an admin, not hiding).
 *   - Colour-coded: green = online & idle, orange = online with an
 *     active delivery, red = "stale" (last_location_at older than
 *     STALE_MINUTES while still flagged online).
 *   - A rider matching the above with no last_lat/last_lng yet (never
 *     sent a single location ping) can't be plotted — listed in a
 *     separate "No location yet" panel instead of silently vanishing.
 *   - Auto-refresh: plain `location.reload()` on a timer, not a new
 *     polling JSON endpoint — this page already re-runs the same
 *     query on every load, so a periodic full reload is the same cost
 *     as an admin manually refreshing, without building and
 *     maintaining a second data path for what genuinely is a
 *     low-traffic internal tool.
 *
 * Deliberately NOT built: route polylines to each rider's active
 * delivery destination (would need `route.php`'s Directions API call
 * fanned out per visible rider on every page load/refresh — a real
 * cost against Google's API on a page that may auto-refresh every
 * 30s, not something to add without the app owner explicitly wanting
 * it and accepting that cost); clustering for a large rider count
 * (this platform's current rider volume doesn't warrant it yet, and
 * premature clustering logic is its own bug surface); historical
 * playback (that's `rider-detail.php`'s Location History table, doc
 * 109 — this page is live/current-state only, not a scrubber).
 *
 * 2026-09-07 (app owner request): click-anywhere-for-address — same
 * bonik.in reverse-geocode-on-click behaviour areas.php's "Choose on
 * map" picker already has (see that file's fetchBonikAddress()).
 * Clicking any point on this map (not just a rider circleMarker) drops
 * a plain pin and shows the reverse-geocoded address in a popup — lets
 * an admin looking at where a rider actually is right now immediately
 * see what locality that point is in, without leaving this page.
 * Read-only lookup only; unlike areas.php's picker there's nothing to
 * "use this point" for here, so no dialog/form, just the popup.
 * Rider circleMarkers get `bubblingMouseEvents: false` so opening a
 * rider's own popup doesn't also drop an address pin on top of it —
 * Leaflet's vector layers (circleMarker included) bubble click events
 * up to the map by default, unlike L.Marker.
 *
 * Gated on `riders_view` — same base gate `riders.php`/
 * `rider-detail.php` both already use, since this is the same
 * rider-location data just plotted instead of tabulated, not a new
 * data category needing its own permission key.
 *
 * NOT tested end-to-end (no PHP/MySQL/browser in this sandbox) — same
 * standing caveat as every prior page in this project. This page in
 * particular has never had its Leaflet rendering visually confirmed
 * in an actual browser (areas.php's own map, by contrast, has at
 * least had its shape reviewed across several sessions) — flagging
 * this as the single highest-risk-of-a-silent-rendering-bug piece of
 * this session's work.
 */

require_once __DIR__ . '/_bootstrap.php';

$admin = admin_require_login();
admin_require_permission($admin, 'riders_view');
$db = Database::get();

// Same coarse bucket boundary as admin_time_ago()'s own hour bucket —
// "stale" here means the rider is flagged online but their last
// position update is old enough that an admin should not trust it as
// "where they are right now".
const STALE_MINUTES = 10;

$areaFilter = trim($_GET['area_id'] ?? '') !== '' ? (int) $_GET['area_id'] : null;

$where = ["r.deleted_at IS NULL", "r.restaurant_id IS NULL", "r.status = 'approved'"];
$params = [];
if ($areaFilter !== null) {
    $where[] = 'r.service_area_id = :area_id';
    $params['area_id'] = $areaFilter;
}
$whereSql = implode(' AND ', $where);

// Same correlated-subquery current-order pattern doc 108 introduced in
// riders.php — reused verbatim rather than a plain join, for the same
// "never silently duplicate a rider row" reason given there. Riders
// shown here: currently online, OR carrying an active order regardless
// of their is_online flag (see file header — that mismatch is itself
// worth surfacing, not filtering out).
$stmt = $db->prepare(
    "SELECT r.id, r.name, r.mobile, r.is_online, r.last_lat, r.last_lng, r.last_location_at,
            r.service_area_id,
            co.order_code AS current_order_code, co.status AS current_order_status
     FROM riders r
     LEFT JOIN orders co ON co.id = (
         SELECT o.id FROM orders o
         WHERE o.rider_id = r.id AND o.status IN ('rider_assigned', 'picked_up', 'out_for_delivery')
         ORDER BY o.id DESC LIMIT 1
     )
     WHERE {$whereSql} AND (r.is_online = 1 OR co.id IS NOT NULL)
     ORDER BY r.name"
);
$stmt->execute($params);
$riders = $stmt->fetchAll();

$currentOrderStatusLabels = [
    'rider_assigned' => 'Rider Assigned',
    'picked_up' => 'Picked Up',
    'out_for_delivery' => 'Out for Delivery',
];

$staleCutoff = time() - (STALE_MINUTES * 60);
$mapPoints = [];
$noLocationRiders = [];
foreach ($riders as $r) {
    $hasLocation = $r['last_lat'] !== null && $r['last_lng'] !== null;
    if (!$hasLocation) {
        $noLocationRiders[] = $r;
        continue;
    }
    $lastSeenTs = $r['last_location_at'] ? strtotime($r['last_location_at']) : false;
    $isStale = ((int) $r['is_online']) === 1 && ($lastSeenTs === false || $lastSeenTs < $staleCutoff);
    $hasActiveOrder = $r['current_order_code'] !== null;

    if ($isStale) {
        $color = '#c0392b'; // red
        $state = 'Stale';
    } elseif ($hasActiveOrder) {
        $color = '#e6521f'; // orange (matches this admin theme's accent)
        $state = 'On delivery';
    } else {
        $color = '#1b8a3c'; // green
        $state = 'Online, idle';
    }

    $mapPoints[] = [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'mobile' => $r['mobile'],
        'lat' => (float) $r['last_lat'],
        'lng' => (float) $r['last_lng'],
        'color' => $color,
        'state' => $state,
        'lastSeen' => admin_time_ago($r['last_location_at']),
        'orderCode' => $r['current_order_code'],
        'orderStatus' => $r['current_order_code']
            ? ($currentOrderStatusLabels[$r['current_order_status']] ?? $r['current_order_status'])
            : null,
        'detailUrl' => 'rider-detail.php?rider_id=' . (int) $r['id'],
    ];
}

$areaOptions = $db->query(
    'SELECT id, name FROM service_areas WHERE is_active = 1 ORDER BY name'
)->fetchAll();
$areaNodeById = [];
foreach ($db->query('SELECT id, name, parent_id FROM service_areas')->fetchAll() as $row) {
    $areaNodeById[(int) $row['id']] = $row;
}

$pageTitle = 'Rider Map (' . count($riders) . ')';
$activeNav = 'rider_map';
require __DIR__ . '/_layout_head.php';
?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

    <div class="card" style="margin-bottom:16px;">
        <form method="get" class="form-grid">
            <div>
                <label class="field-label">Area</label>
                <select name="area_id">
                    <option value="">All areas</option>
                    <?php foreach ($areaOptions as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= $areaFilter === (int) $a['id'] ? 'selected' : '' ?>><?= admin_escape(admin_area_breadcrumb_compact($areaNodeById[(int) $a['id']] ?? $a, $areaNodeById)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" data-no-loading>Filter</button>
                <?php if ($areaFilter !== null): ?>
                    <a href="rider-map.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
            <div>
                <label class="field-label">Auto-refresh</label>
                <select id="autoRefreshSelect">
                    <option value="0">Off</option>
                    <option value="30">Every 30s</option>
                    <option value="60" selected>Every 60s</option>
                </select>
            </div>
        </form>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <div class="row-actions" style="margin-bottom:10px;">
            <span><span class="badge" style="background:#1b8a3c22;color:#1b8a3c;">● Online, idle</span></span>
            <span><span class="badge" style="background:#e6521f22;color:#e6521f;">● On delivery</span></span>
            <span><span class="badge" style="background:#c0392b22;color:#c0392b;">● Stale (no update &gt; <?= STALE_MINUTES ?>m)</span></span>
        </div>
        <p class="muted" style="margin:0 0 10px; font-size:13px;">Click anywhere on the map to look up that point's address.</p>
        <div id="riderMapCanvas" style="height:520px; border-radius:8px; overflow:hidden;"></div>
        <?php if (empty($mapPoints)): ?>
            <p class="muted" style="margin-top:10px;">No online rider currently has a plottable location.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($noLocationRiders)): ?>
    <div class="card" style="margin-bottom:16px;">
        <h2>Online but no location yet (<?= count($noLocationRiders) ?>)</h2>
        <p class="muted">Flagged online (or carrying an active order) but hasn't sent a single GPS ping yet — can't be plotted.</p>
        <div class="table-responsive">
            <table>
                <tr><th>Rider</th><th>Mobile</th><th>Current order</th><th></th></tr>
                <?php foreach ($noLocationRiders as $r): ?>
                <tr>
                    <td><?= admin_escape($r['name']) ?></td>
                    <td class="muted"><?= admin_escape($r['mobile'] ?: '—') ?></td>
                    <td>
                        <?php if ($r['current_order_code']): ?>
                            <span class="badge system">#<?= admin_escape($r['current_order_code']) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-outline" href="rider-detail.php?rider_id=<?= (int) $r['id'] ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        'use strict';
        var points = <?= json_encode(array_values($mapPoints), JSON_UNESCAPED_UNICODE) ?>;

        // Osian, Jodhpur — same regional fallback center areas.php's own
        // map picker uses, for a consistent default viewport when there's
        // nothing else to center on.
        var DEFAULT_CENTER = [26.7213, 72.9166];
        var DEFAULT_ZOOM = 12;

        var canvas = document.getElementById('riderMapCanvas');
        if (!canvas) return;

        var map = L.map('riderMapCanvas');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        if (points.length === 0) {
            map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
        } else {
            var bounds = [];
            points.forEach(function (p) {
                var marker = L.circleMarker([p.lat, p.lng], {
                    radius: 9,
                    color: p.color,
                    fillColor: p.color,
                    fillOpacity: 0.85,
                    weight: 2,
                    bubblingMouseEvents: false
                }).addTo(map);

                var popupHtml = '<strong>' + escapeHtml(p.name) + '</strong><br>'
                    + escapeHtml(p.state) + ' &middot; seen ' + escapeHtml(p.lastSeen) + '<br>';
                if (p.orderCode) {
                    popupHtml += 'Order #' + escapeHtml(p.orderCode) + ' (' + escapeHtml(p.orderStatus) + ')<br>';
                }
                popupHtml += '<a href="' + p.detailUrl + '">View rider detail</a>';
                marker.bindPopup(popupHtml);

                bounds.push([p.lat, p.lng]);
            });
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // Click-anywhere-for-address — same bonik.in reverse-geocode
        // areas.php's "Choose on map" picker uses (see this file's own
        // header). Read-only: drops/moves one plain pin and shows the
        // address in its popup, nothing to save. bubblingMouseEvents:false
        // on the rider circleMarkers above keeps opening a rider's own
        // popup from also firing this.
        var pickMarker = null;
        var addressFetchToken = 0;
        map.on('click', function (e) {
            var lat = e.latlng.lat.toFixed(6);
            var lng = e.latlng.lng.toFixed(6);
            var loadingHtml = '<div style="font-size:13px;">Loading address…</div>';

            if (pickMarker) {
                pickMarker.setLatLng(e.latlng);
            } else {
                pickMarker = L.marker(e.latlng).addTo(map);
            }
            pickMarker.bindPopup(loadingHtml).openPopup();

            var token = ++addressFetchToken;
            fetch('https://bonik.in/api/google/address?lat=' + lat + '&lng=' + lng)
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (token !== addressFetchToken || !pickMarker) return; // stale/superseded click
                    var line1 = data.formatted_address || data.address1 || '';
                    var line2 = [data.address2, data.city, data.state].filter(Boolean).join(', ');
                    var line3 = [data.country, data.pincode].filter(Boolean).join(' - ');
                    var html = '<div style="font-size:13px; max-width:220px;">'
                        + '<strong>' + (line1 ? escapeHtml(line1) : 'Address unavailable') + '</strong>'
                        + (line2 ? '<br>' + escapeHtml(line2) : '')
                        + (line3 ? '<br>' + escapeHtml(line3) : '')
                        + '<br><span class="muted">' + lat + ', ' + lng + '</span>'
                        + '</div>';
                    pickMarker.setPopupContent(html);
                })
                .catch(function () {
                    if (token !== addressFetchToken || !pickMarker) return;
                    pickMarker.setPopupContent(
                        '<div style="font-size:13px;">Address lookup failed.<br><span class="muted">' + lat + ', ' + lng + '</span></div>'
                    );
                });
        });

        // Auto-refresh — plain full-page reload on a timer, see file
        // header for why this doesn't warrant a separate polling
        // endpoint. Default 60s so the dropdown reflects the page's
        // actual initial behaviour rather than silently starting a
        // faster/slower timer than what's shown selected.
        var refreshSelect = document.getElementById('autoRefreshSelect');
        var refreshTimer = null;
        function applyRefreshSetting() {
            if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
            var seconds = parseInt(refreshSelect.value, 10);
            if (seconds > 0) {
                refreshTimer = setTimeout(function () { location.reload(); }, seconds * 1000);
            }
        }
        refreshSelect.addEventListener('change', applyRefreshSetting);
        applyRefreshSetting();
    })();
    </script>
<?php require __DIR__ . '/_layout_foot.php'; ?>
