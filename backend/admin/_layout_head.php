<?php
/**
 * Anydrop — Admin Web UI: shared page shell (sidebar + topbar + <main> open tag)
 *
 * Included by every admin/*.php page AFTER it has already called
 * admin_require_login() / admin_require_permission() and set up its own
 * $flash / $flashType / $pageTitle / $activeNav. Pairs with
 * _layout_foot.php, which closes what this file opens and loads
 * assets/admin.js.
 *
 * Centralizing this (instead of each page carrying its own <style>/nav
 * copy, which is how dashboard.php/index.php/roles.php/areas.php
 * started out) means a design change is now a one-line edit in
 * assets/admin.css instead of four.
 *
 * Expects, from the including page:
 *   $admin       — array from admin_require_login()
 *   $pageTitle   — string, shown in <title> and the topbar
 *   $activeNav   — one of 'dashboard' | 'approvals' | 'areas' | 'roles'
 *   $flash       — string|null, shown once as a toast (not a static banner)
 *   $flashType   — 'success' | 'error'
 */

$activeNav = $activeNav ?? '';
$pageTitle = $pageTitle ?? 'Anydrop Admin';

$navItems = [
    [
        'key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard',
        'perm' => 'dashboard_view',
        'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    ],
    [
        'key' => 'approvals', 'href' => 'index.php', 'label' => 'Pending Approvals',
        'perm' => 'restaurants_view',
        'icon' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/><path d="M8 13a2 2 0 0 1-4 0M12 13a2 2 0 0 1-4 0M16 13a2 2 0 0 1-4 0M20 13a2 2 0 0 1-4 0"/>',
    ],
    [
        'key' => 'areas', 'href' => 'areas.php', 'label' => 'Service Areas',
        'perm' => 'areas_view',
        'icon' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    ],
    [
        'key' => 'roles', 'href' => 'roles.php', 'label' => 'Roles & Admins',
        'perm' => 'roles_manage',
        'icon' => '<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/>',
    ],
];

$initials = strtoupper(substr($admin['username'] ?? '?', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anydrop Admin — <?= admin_escape($pageTitle) ?></title>
<link rel="stylesheet" href="assets/admin.css">
<script>
    /* Set theme before first paint to avoid a light/dark flash. */
    (function(){var t=localStorage.getItem('anydrop_admin_theme');
        if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
        document.documentElement.setAttribute('data-theme', t);
        if(localStorage.getItem('anydrop_admin_sidebar_expanded')==='0'){document.documentElement.classList.add('rail-pref');}
    })();
</script>
</head>
<body>
<div class="app-shell" id="appShell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span class="mark">AD</span>
            <span class="label">Anydrop Admin</span>
        </div>
        <?php foreach ($navItems as $item): ?>
            <?php if (admin_has_permission($admin['id'], $item['perm'])): ?>
                <a class="nav-item icon-<?= $item['key'] ?> <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= $item['href'] ?>">
                    <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg></span>
                    <span class="label"><?= admin_escape($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="sidebar-footer label">Anydrop Admin Panel</div>
    </aside>
    <div class="sidebar-overlay"></div>

    <div class="shell-main">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="icon-btn menu-btn" aria-label="Toggle menu">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <button type="button" class="icon-btn desktop-only" id="sidebarCollapseBtn" aria-label="Collapse sidebar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="14" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
                </button>
                <span class="topbar-title"><?= admin_escape($pageTitle) ?></span>
            </div>
            <div class="topbar-right">
                <button type="button" class="icon-btn" id="themeToggleBtn" aria-label="Toggle dark mode" aria-pressed="false">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-sun"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-moon"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                </button>
                <div class="user-chip">
                    <span class="user-avatar"><?= admin_escape($initials) ?></span>
                    <span class="user-name"><?= admin_escape($admin['username']) ?></span>
                </div>
                <a class="icon-btn" href="logout.php" aria-label="Log out" title="Log out">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </a>
            </div>
        </header>
        <main class="content">
            <div id="serverFlash" data-message="<?= $flash ? admin_escape($flash) : '' ?>" data-type="<?= admin_escape($flashType ?? 'success') ?>" style="display:none;"></div>
