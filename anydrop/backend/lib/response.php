<?php
/**
 * Anydrop — Standard JSON Response Helper
 * Every endpoint responds: { "success": bool, "data": ..., "error": null|string }
 */

function respond(bool $success, $data = null, ?string $error = null, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error,
    ]);
    exit;
}

function respond_ok($data = null, int $httpCode = 200): void
{
    respond(true, $data, null, $httpCode);
}

function respond_error(string $error, int $httpCode = 400, $data = null): void
{
    respond(false, $data, $error, $httpCode);
}

/** Reads and JSON-decodes the request body. Returns [] if empty/invalid. */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** Require specific fields to be present (non-null, non-empty-string) in an array. */
function require_fields(array $body, array $fields): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        respond_error('validation_error', 422, ['fields' => $missing]);
    }
}
