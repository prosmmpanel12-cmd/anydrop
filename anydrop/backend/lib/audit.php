<?php
/**
 * Anydrop — Audit Log Helper
 * Every sensitive action (login, password reset, status override) writes here.
 */

require_once __DIR__ . '/../config/database.php';

function write_audit_log(string $actorType, ?int $actorId, string $action, array $details = []): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (actor_type, actor_id, action, details_json, ip_address) VALUES (:t, :id, :a, :d, :ip)'
    );
    $stmt->execute([
        't' => $actorType,
        'id' => $actorId,
        'a' => $action,
        'd' => json_encode($details),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
