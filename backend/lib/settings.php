<?php
/**
 * Anydrop — App Settings Helper
 * Reads config values from the `app_settings` table instead of hardcoding them.
 * Cached per-request in a static array to avoid repeated queries.
 */

require_once __DIR__ . '/../config/database.php';

function get_setting(string $key, $default = null)
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $db = Database::get();
    $stmt = $db->prepare('SELECT `value` FROM app_settings WHERE `key` = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();

    $value = $row ? $row['value'] : $default;
    $cache[$key] = $value;
    return $value;
}
