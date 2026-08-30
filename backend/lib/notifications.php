<?php
/**
 * Anydrop — Notification Writer Helper
 *
 * Type 1 scope only (docs/Status.md 2026-08-20 "Notification bell" item) —
 * system-generated notifications fired automatically off real events
 * (new order, order accepted/rejected/status change). Type 2 (admin
 * broadcast — image, area/radius targeting, specific-buyer targeting) is a
 * deliberately separate, not-yet-built system; this helper's single-
 * recipient shape is intentionally too narrow for that and shouldn't be
 * stretched to fit it later — see the note at the bottom of this file.
 *
 * Writes into the `notifications` table (01_schema.sql §7) — that table
 * already existed, fully designed (recipient polymorphism, is_read,
 * data_json for deep-linking), but nothing ever wrote to it before this.
 * Same "record it, don't let a failure here break the real action it's
 * attached to" spirit as write_audit_log() — call this *after* the
 * triggering DB write/transaction has already committed, never inside it,
 * so a notification-insert failure can never roll back a real order.
 *
 * FCM PUSH (this session): after the bell-row insert, this function also
 * looks up the recipient's fcm_token (customers/restaurants/riders — see
 * migration 60 for the first two, riders already had it) and fires a
 * real push via lib/fcm.php if one exists. This is why every existing
 * Type 1 call site (order accept/reject, new order, review reply, etc)
 * gets real push for free with zero call-site changes — the fan-out
 * point was always this one function.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/fcm.php';

/**
 * Write one notification for one recipient.
 *
 * @param string $recipientType 'customer' | 'restaurant' | 'rider' | 'admin'
 * @param int    $recipientId   The customer_id / restaurant_id / rider_id / admin_id
 * @param string $title         Short heading, shown in the bell list and as
 *                               the push notification title (FCM, this
 *                               session) — keep it short.
 * @param string|null $body     Optional longer text.
 * @param string $type          'order' | 'promo' | 'system' | 'security' — matches
 *                               the schema's ENUM; used for icon/filtering client-side.
 * @param array  $data          Arbitrary deep-link payload, e.g. ['order_id' => 123,
 *                               'screen' => 'order_detail']. json_encode()'d into
 *                               data_json. Client reads this to know where tapping
 *                               the notification should navigate.
 *
 * Never throws outward — a notification is a nice-to-have, not something
 * that should be able to fail an order/status-change request that already
 * succeeded. Logs to error_log() on failure so it's not silently invisible
 * either.
 */
function create_notification(
    string $recipientType,
    int $recipientId,
    string $title,
    ?string $body = null,
    string $type = 'system',
    array $data = []
): void {
    try {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO notifications (recipient_type, recipient_id, title, body, type, data_json)
             VALUES (:rt, :rid, :title, :body, :type, :data)'
        );
        $stmt->execute([
            'rt' => $recipientType,
            'rid' => $recipientId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'data' => empty($data) ? null : json_encode($data),
        ]);
    } catch (Throwable $e) {
        error_log('create_notification failed: ' . $e->getMessage());
    }

    // FCM push (this session) — fires after the bell-row insert above,
    // never inside its try/catch: a push failure must never look like
    // a bell-write failure, and a bell-write failure must never skip
    // the push attempt (independent failure domains, same "record it,
    // don't let a failure here break the real action" spirit as the
    // rest of this file). Only customers/restaurants/riders can ever
    // have a real device token — 'admin' recipients never do, so this
    // silently no-ops for those without an extra branch.
    if (in_array($recipientType, ['customer', 'restaurant', 'rider'], true)) {
        try {
            $tokenColumn = 'fcm_token';
            $tableMap = ['customer' => 'customers', 'restaurant' => 'restaurants', 'rider' => 'riders'];
            $table = $tableMap[$recipientType];
            $db = Database::get();
            $tokenStmt = $db->prepare("SELECT $tokenColumn FROM $table WHERE id = :id LIMIT 1");
            $tokenStmt->execute(['id' => $recipientId]);
            $token = $tokenStmt->fetch()[$tokenColumn] ?? null;

            if ($token) {
                // FCM data payload requires string values; deep-link
                // fields (screen/*_id) come through as-is from callers
                // (mostly already strings/ints) — fcm_send_to_token()
                // itself stringifies defensively too, this cast is just
                // the first line of that same defense.
                $fcmData = array_map(static fn($v): string => (string) $v, $data);
                $fcmData['notification_type'] = $type;
                fcm_send_to_token($token, $title, $body ?? '', $fcmData);
            }
        } catch (Throwable $e) {
            error_log('create_notification: FCM push step failed (non-fatal): ' . $e->getMessage());
        }
    }
}

/**
 * Fetch a paginated notification list for one recipient, newest first.
 * Shared by the customer/restaurant "GET .../notifications" endpoints so
 * the pagination/filter logic only lives in one place.
 *
 * @param bool|null $unreadOnly null = all, true = only is_read=0, false = only is_read=1
 * @return array{items: array, has_more: bool, unread_count: int}
 */
function fetch_notifications(
    string $recipientType,
    int $recipientId,
    int $page = 1,
    int $perPage = 20,
    ?bool $unreadOnly = null
): array {
    $db = Database::get();
    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = 'recipient_type = :rt AND recipient_id = :rid';
    $params = ['rt' => $recipientType, 'rid' => $recipientId];
    if ($unreadOnly === true) {
        $where .= ' AND is_read = 0';
    } elseif ($unreadOnly === false) {
        $where .= ' AND is_read = 1';
    }

    // Fetch one extra row to know if there's a next page, same
    // has_more-via-overfetch convention orders/list.php already uses —
    // avoids a separate COUNT(*) query just for pagination.
    $stmt = $db->prepare(
        "SELECT id, title, body, type, is_read, data_json, created_at
         FROM notifications
         WHERE $where
         ORDER BY created_at DESC, id DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue(":$k", $v);
    }
    $stmt->bindValue(':lim', $perPage + 1, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $perPage;
    $rows = array_slice($rows, 0, $perPage);

    $items = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'body' => $row['body'],
            'type' => $row['type'],
            'is_read' => (bool) $row['is_read'],
            'data' => $row['data_json'] !== null ? json_decode($row['data_json'], true) : null,
            'created_at' => $row['created_at'],
        ];
    }, $rows);

    $countStmt = $db->prepare(
        'SELECT COUNT(*) AS c FROM notifications WHERE recipient_type = :rt AND recipient_id = :rid AND is_read = 0'
    );
    $countStmt->execute(['rt' => $recipientType, 'rid' => $recipientId]);
    $unreadCount = (int) $countStmt->fetch()['c'];

    return ['items' => $items, 'has_more' => $hasMore, 'unread_count' => $unreadCount];
}

/**
 * Mark one notification read. Returns false (caller should 404) if it
 * doesn't exist or doesn't belong to this recipient — never lets one
 * recipient mark another's notification read via a guessed id.
 */
function mark_notification_read(string $recipientType, int $recipientId, int $notificationId): bool
{
    $db = Database::get();
    $stmt = $db->prepare(
        'UPDATE notifications SET is_read = 1
         WHERE id = :id AND recipient_type = :rt AND recipient_id = :rid'
    );
    $stmt->execute(['id' => $notificationId, 'rt' => $recipientType, 'rid' => $recipientId]);
    return $stmt->rowCount() > 0;
}

/** Marks every notification for this recipient read in one query — backs
 * the bell list's "Mark all read" action. Returns how many rows changed. */
function mark_all_notifications_read(string $recipientType, int $recipientId): int
{
    $db = Database::get();
    $stmt = $db->prepare(
        'UPDATE notifications SET is_read = 1
         WHERE recipient_type = :rt AND recipient_id = :rid AND is_read = 0'
    );
    $stmt->execute(['rt' => $recipientType, 'rid' => $recipientId]);
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------
// NOTE for whoever builds Type 2 (admin broadcast — image, area/radius
// targeting, specific-buyer targeting) later: don't extend
// create_notification() itself to take a list of recipients or an image —
// that's a different shape (fan-out to N rows from one admin action, plus
// an image_url column the schema doesn't have yet). Build a separate
// admin-facing function/endpoint that resolves the target audience (all /
// by area / by specific customer_ids) into a list of recipient_ids and
// calls create_notification() once per recipient — this file's job stays
// "write one notification for one recipient," which every Type 1 call
// site already assumes.
// ---------------------------------------------------------------------
