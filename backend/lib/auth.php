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
 */
function create_auth_token(string $ownerType, int $ownerId): string
{
    $db = Database::get();
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . TOKEN_LIFETIME_DAYS . ' days'));

    $stmt = $db->prepare(
        'INSERT INTO auth_tokens (owner_type, owner_id, token_hash, expires_at) VALUES (:t, :id, :h, :e)'
    );
    $stmt->execute([
        't' => $ownerType,
        'id' => $ownerId,
        'h' => $tokenHash,
        'e' => $expiresAt,
    ]);

    return $rawToken;
}

/**
 * Reads the Authorization: Bearer <token> header, validates it,
 * and returns ['owner_type' => ..., 'owner_id' => ...] or null if invalid/expired.
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
        'SELECT owner_type, owner_id, expires_at FROM auth_tokens WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }

    return ['owner_type' => $row['owner_type'], 'owner_id' => (int) $row['owner_id']];
}

/** Call at the top of any protected endpoint. Halts request with 401 if not authenticated. */
function require_auth(string $expectedOwnerType): array
{
    $owner = get_authenticated_owner();
    if (!$owner || $owner['owner_type'] !== $expectedOwnerType) {
        respond_error('unauthorized', 401);
    }
    return $owner;
}
