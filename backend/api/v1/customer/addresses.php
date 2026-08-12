<?php
/**
 * GET    /api/v1/customer/addresses           — list this customer's saved addresses
 * POST   /api/v1/customer/addresses           — add a new address
 * PUT    /api/v1/customer/addresses/{id}      — edit an address
 * DELETE /api/v1/customer/addresses/{id}      — delete an address
 * Auth: Customer token
 *
 * Structured address form (§1.8/§2.6) — address_type (home/work/other),
 * house_flat_no, floor, landmark, receiver_name, receiver_phone, plus the
 * original label/full_address/latitude/longitude/is_default. `full_address`
 * is kept as a computed display string built from the structured fields on
 * every write, so anything still reading the plain field keeps working.
 *
 * Shared by both Checkout's "add address" flow and the Profile → Address
 * Book screen (§2.7) — same editor, same endpoint.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../lib/response.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Access-Control-Allow-Origin: *');

$owner = require_auth('customer');
$customerId = $owner['owner_id'];
$db = Database::get();

/** Builds the backward-compatible concatenated display string. */
function build_full_address(array $b): string
{
    $parts = array_filter([
        $b['house_flat_no'] ?? null,
        $b['floor'] ?? null,
        trim((string) ($b['full_address'] ?? '')),
        !empty($b['landmark']) ? 'Near ' . $b['landmark'] : null,
    ]);
    return implode(', ', $parts);
}

function format_address(array $a): array
{
    return [
        'id' => (int) $a['id'],
        'label' => $a['label'],
        'address_type' => $a['address_type'] ?? 'home',
        'full_address' => $a['full_address'],
        'house_flat_no' => $a['house_flat_no'],
        'floor' => $a['floor'],
        'landmark' => $a['landmark'],
        'receiver_name' => $a['receiver_name'],
        'receiver_phone' => $a['receiver_phone'],
        'photo_url' => $a['photo_url'] ?? null,
        'latitude' => $a['latitude'] !== null ? (float) $a['latitude'] : null,
        'longitude' => $a['longitude'] !== null ? (float) $a['longitude'] : null,
        'is_default' => (bool) $a['is_default'],
    ];
}

// Address id, when present, comes from the router as ?id= (PUT/DELETE),
// same "direct file path with ?id=" convention as other Phase 3 endpoints.
$addressId = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        'SELECT * FROM customer_addresses WHERE customer_id = :cid ORDER BY is_default DESC, id DESC'
    );
    $stmt->execute(['cid' => $customerId]);
    $rows = $stmt->fetchAll();

    respond_ok(['addresses' => array_map('format_address', $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = get_json_body();
    require_fields($body, ['full_address']);

    $isDefault = !empty($body['is_default']);
    if ($isDefault) {
        $clear = $db->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid');
        $clear->execute(['cid' => $customerId]);
    }

    $displayAddress = build_full_address($body);

    $ins = $db->prepare(
        'INSERT INTO customer_addresses
            (customer_id, label, address_type, full_address, house_flat_no, floor, landmark, receiver_name, receiver_phone, photo_url, latitude, longitude, is_default)
         VALUES
            (:cid, :label, :type, :addr, :house, :floor, :landmark, :rname, :rphone, :photo, :lat, :lng, :def)'
    );
    $ins->execute([
        'cid' => $customerId,
        'label' => $body['label'] ?? ucfirst($body['address_type'] ?? 'Home'),
        'type' => $body['address_type'] ?? 'home',
        'addr' => $displayAddress !== '' ? $displayAddress : trim($body['full_address']),
        'house' => $body['house_flat_no'] ?? null,
        'floor' => $body['floor'] ?? null,
        'landmark' => $body['landmark'] ?? null,
        'rname' => $body['receiver_name'] ?? null,
        'rphone' => $body['receiver_phone'] ?? null,
        'photo' => $body['photo_url'] ?? null,
        'lat' => $body['latitude'] ?? null,
        'lng' => $body['longitude'] ?? null,
        'def' => $isDefault ? 1 : 0,
    ]);

    respond_ok(['id' => (int) $db->lastInsertId()], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (!$addressId) {
        respond_error('validation_error', 422, ['fields' => ['id']]);
    }
    $owned = $db->prepare('SELECT id FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
    $owned->execute(['id' => $addressId, 'cid' => $customerId]);
    if (!$owned->fetch()) {
        respond_error('not_found', 404);
    }

    $body = get_json_body();
    require_fields($body, ['full_address']);

    $isDefault = !empty($body['is_default']);
    if ($isDefault) {
        $clear = $db->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = :cid');
        $clear->execute(['cid' => $customerId]);
    }

    $displayAddress = build_full_address($body);

    // photo_url: only overwritten when the client actually sends one —
    // editing an address via the plain form (AddressEditorBottomSheet,
    // which has no photo field) must not wipe out a photo saved earlier
    // via the map pin-drop screen.
    $photoUrl = array_key_exists('photo_url', $body) ? $body['photo_url'] : null;
    if ($photoUrl === null) {
        $existingPhoto = $db->prepare('SELECT photo_url FROM customer_addresses WHERE id = :id AND customer_id = :cid LIMIT 1');
        $existingPhoto->execute(['id' => $addressId, 'cid' => $customerId]);
        $photoUrl = $existingPhoto->fetchColumn() ?: null;
    }

    $upd = $db->prepare(
        'UPDATE customer_addresses SET
            label = :label, address_type = :type, full_address = :addr, house_flat_no = :house,
            floor = :floor, landmark = :landmark, receiver_name = :rname, receiver_phone = :rphone,
            photo_url = :photo, latitude = :lat, longitude = :lng, is_default = :def
         WHERE id = :id AND customer_id = :cid'
    );
    $upd->execute([
        'label' => $body['label'] ?? ucfirst($body['address_type'] ?? 'Home'),
        'type' => $body['address_type'] ?? 'home',
        'addr' => $displayAddress !== '' ? $displayAddress : trim($body['full_address']),
        'house' => $body['house_flat_no'] ?? null,
        'floor' => $body['floor'] ?? null,
        'landmark' => $body['landmark'] ?? null,
        'rname' => $body['receiver_name'] ?? null,
        'rphone' => $body['receiver_phone'] ?? null,
        'photo' => $photoUrl,
        'lat' => $body['latitude'] ?? null,
        'lng' => $body['longitude'] ?? null,
        'def' => $isDefault ? 1 : 0,
        'id' => $addressId,
        'cid' => $customerId,
    ]);

    respond_ok(['id' => $addressId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!$addressId) {
        respond_error('validation_error', 422, ['fields' => ['id']]);
    }
    $del = $db->prepare('DELETE FROM customer_addresses WHERE id = :id AND customer_id = :cid');
    $del->execute(['id' => $addressId, 'cid' => $customerId]);
    respond_ok(['deleted' => true]);
}

respond_error('method_not_allowed', 405);
