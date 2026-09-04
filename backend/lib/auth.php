<?php
/**
 * Anydrop — Auth Token Helpers
 *
 * Simple opaque Bearer tokens stored (hashed) in auth_tokens table.
 * Not JWT — deliberately simple since we control both client and server,
 * and this avoids extra library dependencies InfinityFree may not support well.
 */

require_once __DIR__ . '/../config/database.php';

const TOKEN_LIFETIME_DAYS = 30;

/**
 * Creates a new auth token for the given owner and stores its hash.
 * Returns the raw token (only shown once to the client).
 *
 * @param int|null $staffId Migration 63 (Restaurant Staff/RBAC) — set
 *                  only for a staff login (`owner_type = 'restaurant'`,
 *                  `owner_id` still the restaurant's own id, never the
 *                  staff row's id — see migration 63's own header for
 *                  why). NULL for every other login, including a
 *                  restaurant owner's own login, unchanged from before
 *                  this parameter existed.
 */
function create_auth_token(string $ownerType, int $ownerId, ?int $staffId = null): string
{
    $db = Database::get();
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . TOKEN_LIFETIME_DAYS . ' days'));

    $stmt = $db->prepare(
        'INSERT INTO auth_tokens (owner_type, owner_id, staff_id, token_hash, expires_at) VALUES (:t, :id, :sid, :h, :e)'
    );
    $stmt->execute([
        't' => $ownerType,
        'id' => $ownerId,
        'sid' => $staffId,
        'h' => $tokenHash,
        'e' => $expiresAt,
    ]);

    return $rawToken;
}

/**
 * Reads the Authorization: Bearer <token> header, validates it,
 * and returns ['owner_type' => ..., 'owner_id' => ..., 'staff_id' => ...]
 * or null if invalid/expired. `staff_id` (migration 63) is null for
 * every non-staff token, unchanged from before that column existed.
 */
function get_authenticated_owner(): ?array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
        return null;
    }

    $rawToken = trim(substr($authHeader, 7));
    if (!$rawToken) {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT owner_type, owner_id, staff_id, expires_at FROM auth_tokens WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }

    return [
        'owner_type' => $row['owner_type'],
        'owner_id' => (int) $row['owner_id'],
        'staff_id' => $row['staff_id'] !== null ? (int) $row['staff_id'] : null,
    ];
}

/**
 * Call at the top of any protected endpoint. Halts request with 401 if
 * not authenticated, or 403 `account_suspended` if the token is valid
 * but the account behind it has since been suspended/deactivated.
 *
 * Doc 25: previously a valid (unexpired) token was treated as
 * sufficient on its own — restaurants.status / customers.is_active was
 * only ever checked at login (restaurant-login.php,
 * customer-verify-otp.php). That meant an admin suspending an
 * already-logged-in restaurant or customer had no practical effect
 * until their token's TOKEN_LIFETIME_DAYS expiry. This re-checks on
 * every authenticated request instead — one indexed lookup by primary
 * key, kept deliberately minimal since it runs on every call.
 *
 * Migration 63 (Restaurant Staff/RBAC): for `owner_type = 'restaurant'`,
 * the returned array now always includes a `role` key — `'owner'` for
 * the restaurant's own login (`staff_id` null, the original/unchanged
 * case), or the logged-in staff member's `restaurant_staff.role`
 * (`manager`/`kitchen`/`cashier`). A staff token whose
 * `restaurant_staff` row has since been deactivated (owner turned them
 * off) or soft-deleted is rejected here with 403 `staff_disabled` —
 * same "re-check every request, don't wait for token expiry" principle
 * already applied to `restaurants.status` above, extended to the new
 * staff layer. `owner_id` is unaffected either way — see migration
 * 63's own header for why it's always the restaurant id, never the
 * staff row's id.
 */
function require_auth(string $expectedOwnerType): array
{
    $owner = get_authenticated_owner();
    if (!$owner || $owner['owner_type'] !== $expectedOwnerType) {
        respond_error('unauthorized', 401);
    }

    $db = Database::get();

    if ($expectedOwnerType === 'restaurant') {
        $stmt = $db->prepare(
            'SELECT status, rejection_reason FROM restaurants WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $owner['owner_id']]);
        $row = $stmt->fetch();
        // No row = either soft-deleted or the id vanished — same
        // "can't use this account anymore" outcome as suspended.
        // `pending`/`rejected` aren't checked here: login already
        // blocks both, and a currently-logged-in restaurant (i.e. one
        // that has a valid token at all) can only have reached that
        // state via approved -> suspended, never retroactively back to
        // pending/rejected — see doc 25.
        if (!$row || $row['status'] === 'suspended') {
            respond_error('account_suspended', 403, ['reason' => $row['rejection_reason'] ?? null]);
        }

        if ($owner['staff_id'] === null) {
            $owner['role'] = 'owner';
        } else {
            $staffStmt = $db->prepare(
                'SELECT role, is_active FROM restaurant_staff WHERE id = :id AND restaurant_id = :rid AND deleted_at IS NULL LIMIT 1'
            );
            $staffStmt->execute(['id' => $owner['staff_id'], 'rid' => $owner['owner_id']]);
            $staffRow = $staffStmt->fetch();
            if (!$staffRow || !$staffRow['is_active']) {
                respond_error('staff_disabled', 403);
            }
            $owner['role'] = $staffRow['role'];
        }
    } elseif ($expectedOwnerType === 'customer') {
        $stmt = $db->prepare(
            'SELECT is_active, suspension_reason FROM customers WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $owner['owner_id']]);
        $row = $stmt->fetch();
        if (!$row || !$row['is_active']) {
            respond_error('account_suspended', 403, ['reason' => $row['suspension_reason'] ?? null]);
        }
    } elseif ($expectedOwnerType === 'rider') {
        // Migration 69 (rider self-signup) — same re-check-on-every-request
        // principle as restaurant/customer above, extended to riders.
        // `pending`/`rejected` are deliberately NOT blocked here, unlike
        // restaurant's equivalent: a pending rider still needs to reach
        // authenticated "application submitted" / profile screens after
        // rider-signup.php's immediate token issue (see that file's own
        // header for why). Endpoints that actually require full approval
        // (going online, accepting orders, earnings) check
        // `$owner['status'] === 'approved'` themselves — this function
        // only blocks the account-is-gone/suspended case every owner type
        // shares.
        $stmt = $db->prepare(
            'SELECT status, rejection_reason, is_active FROM riders WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $owner['owner_id']]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] === 'suspended' || !$row['is_active']) {
            respond_error('account_suspended', 403, ['reason' => $row['rejection_reason'] ?? null]);
        }
        $owner['status'] = $row['status'];
    }

    return $owner;
}
