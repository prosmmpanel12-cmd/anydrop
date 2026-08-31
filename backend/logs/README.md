# logs/

Written to by `lib/error_handler.php` — one file per day,
`php-error-YYYY-MM-DD.log`. Created automatically on first error; this
directory ships empty (this file + `.htaccess` + `.gitignore` only
exist to make sure the folder itself is present).

Each line: `[ISO-8601 timestamp] SEVERITY: message in file:line | METHOD /request/path`

Request bodies/query strings are deliberately never logged — see
`lib/error_handler.php`'s kdoc.

If this directory isn't writable by the PHP process, entries fall back
to PHP's own `error_log()` destination instead of being lost silently.
