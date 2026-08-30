<?php
/**
 * Anydrop — App Settings Helper
 * Reads config values from the `app_settings` table instead of hardcoding them.
 * Cached per-request in a static array to avoid repeated queries.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Shared cache store for get_setting()/set_setting(), returned by
 * reference so a write in one call is immediately visible to a read
 * in another within the same request — a plain function-local static
 * can't be shared across two different functions, so this tiny
 * indirection is what makes that possible.
 */
function &settings_cache_ref(): array
{
    static $cache = [];
    return $cache;
}

function get_setting(string $key, $default = null)
{
    $cache = &settings_cache_ref();

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

/**
 * Upsert a single app_settings row and refresh get_setting()'s shared
 * cache in the same call, so a set_setting() followed by a
 * get_setting() on the same key later in the same request (e.g.
 * rendering a settings page right after saving it) never reads a
 * stale cached value. Used by the admin panel's settings screens
 * (app-settings.php, fcm-settings.php) instead of each page
 * hand-rolling its own INSERT ... ON DUPLICATE KEY UPDATE.
 */
function set_setting(string $key, string $value): void
{
    $db = Database::get();
    $stmt = $db->prepare(
        'INSERT INTO app_settings (`key`, `value`) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE `value` = :v2'
    );
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);

    $cache = &settings_cache_ref();
    $cache[$key] = $value;
}
