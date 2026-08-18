<?php
/**
 * Anydrop — Admin Web UI bootstrap (session helpers, shared by every
 * page in this folder).
 *
 * Deliberately session-based, not Bearer-token — this matches
 * docs/02_API_Contract.md §6's own heading verbatim: "Admin Panel (web,
 * session-auth instead of Bearer token since it's server-rendered)".
 * The Bearer-token `auth_tokens` system (lib/auth.php) is what the
 * Customer/Restaurant *native apps* authenticate with; a plain
 * server-rendered, multi-page-reload HTML admin UI (no JS build step,
 * nothing to install in a sandbox with no network access, works by just
 * pointing a browser at it) calls for PHP native sessions instead, same
 * as any classic PHP admin panel.
 *
 * This page queries the DB directly rather than calling a JSON API over
 * HTTP (calling your own server over the network from itself is a
 * needless extra hop for same-process code, and this environment can't
 * self-test outbound HTTP anyway). If a future JSON-driven admin
 * SPA/native app is ever built, it would need its own Bearer-token
 * endpoints added at that point — none exist yet, intentionally, since
 * nothing consumes them today and doc 02 didn't ask for them.
 *
 * Full RBAC (doc 19 §1 — admin_roles/admin_permissions/named roles) is
 * still planning-only; this only checks "is *some* valid admin logged
 * in", same scope as the JSON endpoints' require_auth('admin').
 */

session_start();

require_once __DIR__ . '/../config/database.php';

/** Redirects to login.php unless an admin is currently signed in. */
function admin_require_login(): array
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
    return [
        'id' => (int) $_SESSION['admin_id'],
        'username' => (string) ($_SESSION['admin_username'] ?? ''),
    ];
}

/** One CSRF token per session, reused across this session's forms. */
function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function admin_verify_csrf(string $submitted): bool
{
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

function admin_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
