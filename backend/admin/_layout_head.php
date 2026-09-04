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
 *   $activeNav   — one of 'dashboard' | 'approvals' | 'orders' | 'analytics' | 'support' | 'review_moderation' | 'customer_feedback' | 'areas' | 'cod_rules' | 'pricing_rules' | 'payment_restrictions' | 'categories' | 'banners' | 'roles' | 'commission_rules' | 'settlements' | 'rider_settlements' | 'platform_ledger' | 'payment_gateways' | 'email_providers' | 'payment_pending' | 'refunds' | 'wallet_withdrawals' | 'reconciliation' | 'offers' | 'broadcast' | 'app_settings_customer' | 'app_settings_restaurant' | 'app_settings_rider' | 'fcm_settings' | 'riders'
 *   $flash       — string|null, shown once as a toast (not a static banner)
 *   $flashType   — 'success' | 'error'
 */

$activeNav = $activeNav ?? '';
$pageTitle = $pageTitle ?? 'Anydrop Admin';

/**
 * Sidebar structure: 'dashboard' stays top-level/standalone (always the
 * first thing an admin wants), everything else is grouped under a
 * collapsible section so the sidebar doesn't run 16 flat items deep.
 * Same $navItems shape as before (key/href/perm/icon unchanged, nothing
 * moved between pages) — items just gained a 'group' tag, and standalone
 * items carry 'group' => null. admin.js expands whichever group contains
 * $activeNav on load, and remembers open/closed state per group in
 * localStorage the same way it already remembers the rail/theme prefs.
 */
$navGroups = [
    ['key' => 'operations', 'label' => 'Orders & Operations',
        'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
    ['key' => 'areas_rules', 'label' => 'Areas & Rules',
        'icon' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>'],
    ['key' => 'catalog', 'label' => 'Catalog & Marketing',
        'icon' => '<path d="M20.59 13.41L11 3.83A2 2 0 0 0 9.59 3.24H4a1 1 0 0 0-1 1v5.59a2 2 0 0 0 .59 1.41l9.58 9.58a2 2 0 0 0 2.82 0l4.6-4.6a2 2 0 0 0 0-2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/>'],
    ['key' => 'finance', 'label' => 'Finance',
        'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M2 10h20"/><circle cx="7" cy="15" r="1"/>'],
    ['key' => 'settings', 'label' => 'Settings',
        'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
];

$navItems = [
    [
        'key' => 'dashboard', 'href' => 'dashboard.php', 'label' => 'Dashboard',
        'perm' => 'dashboard_view', 'group' => null,
        'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    ],
    [
        'key' => 'approvals', 'href' => 'index.php', 'label' => 'Pending Approvals',
        'perm' => 'restaurants_view', 'group' => 'operations',
        'icon' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/><path d="M8 13a2 2 0 0 1-4 0M12 13a2 2 0 0 1-4 0M16 13a2 2 0 0 1-4 0M20 13a2 2 0 0 1-4 0"/>',
    ],
    [
        'key' => 'restaurants', 'href' => 'restaurants.php', 'label' => 'Restaurants',
        'perm' => 'restaurants_view', 'group' => 'operations',
        'icon' => '<path d="M4 3v18M4 3c0 3 3 3 3 6s-3 3-3 6M20 3v18M20 8h-4a2 2 0 0 0 0 4h4"/>',
    ],
    [
        'key' => 'riders', 'href' => 'riders.php', 'label' => 'Riders',
        'perm' => 'riders_view', 'group' => 'operations',
        'icon' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 0 1 1 1v5l3.5 4"/><path d="M9 17.5H5.5L8 10h6"/>',
    ],
    [
        'key' => 'customers', 'href' => 'customers.php', 'label' => 'Customers',
        'perm' => 'customers_view', 'group' => 'operations',
        'icon' => '<path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="8" r="5"/>',
    ],
    [
        'key' => 'orders', 'href' => 'orders.php', 'label' => 'Order Control',
        'perm' => 'orders_view', 'group' => 'operations',
        'icon' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/><path d="M9 13h6"/>',
    ],
    [
        'key' => 'analytics', 'href' => 'analytics.php', 'label' => 'Analytics',
        'perm' => 'reports_view', 'group' => 'finance',
        'icon' => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
    ],
    [
        'key' => 'support', 'href' => 'support.php', 'label' => 'Support Tickets',
        'perm' => 'support_view', 'group' => 'operations',
        'icon' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    ],
    [
        'key' => 'review_moderation', 'href' => 'review-moderation.php', 'label' => 'Review Moderation',
        'perm' => 'reviews_moderate', 'group' => 'operations',
        'icon' => '<path d="m12 17.27 6.18 3.73-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>',
    ],
    [
        'key' => 'customer_feedback', 'href' => 'customer-feedback.php', 'label' => 'Customer Feedback',
        'perm' => 'feedback_view', 'group' => 'operations',
        'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    ],
    [
        'key' => 'areas', 'href' => 'areas.php', 'label' => 'Service Areas',
        'perm' => 'areas_view', 'group' => 'areas_rules',
        'icon' => '<path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    ],
    [
        'key' => 'cod_rules', 'href' => 'cod-rules.php', 'label' => 'COD Rules',
        'perm' => 'areas_view', 'group' => 'areas_rules',
        'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
    ],
    [
        'key' => 'pricing_rules', 'href' => 'pricing-rules.php', 'label' => 'Pricing Rules',
        'perm' => 'areas_view', 'group' => 'areas_rules',
        'icon' => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    ],
    [
        'key' => 'payment_restrictions', 'href' => 'payment-restrictions.php', 'label' => 'Payment Restrictions',
        'perm' => 'areas_view', 'group' => 'areas_rules',
        'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 9h20"/><path d="M6 14l3 3 7-7"/>',
    ],
    [
        'key' => 'categories', 'href' => 'categories.php', 'label' => 'Categories',
        'perm' => 'categories_view', 'group' => 'catalog',
        'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="18" height="7" rx="1.5"/>',
    ],
    [
        'key' => 'banners', 'href' => 'banners.php', 'label' => 'Banners',
        'perm' => 'banners_view', 'group' => 'catalog',
        'icon' => '<rect x="3" y="6" width="18" height="12" rx="1.5"/><path d="M3 10h18"/>',
    ],
    [
        'key' => 'offers', 'href' => 'offers.php', 'label' => 'Offers',
        'perm' => 'offers_view', 'group' => 'catalog',
        'icon' => '<path d="M20.59 13.41L11 3.83A2 2 0 0 0 9.59 3.24H4a1 1 0 0 0-1 1v5.59a2 2 0 0 0 .59 1.41l9.58 9.58a2 2 0 0 0 2.82 0l4.6-4.6a2 2 0 0 0 0-2.82z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    ],
    [
        'key' => 'payment_gateways', 'href' => 'payment-gateways.php', 'label' => 'Payment Gateways',
        'perm' => 'payment_providers_manage', 'group' => 'finance',
        'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
    ],
    [
        'key' => 'email_providers', 'href' => 'email-providers.php', 'label' => 'Email Providers',
        'perm' => 'email_providers_manage', 'group' => 'finance',
        'icon' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
    ],
    [
        'key' => 'payment_pending', 'href' => 'payment-pending.php', 'label' => 'Pending UPI Payments',
        'perm' => 'payment_providers_manage', 'group' => 'finance',
        'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    ],
    // Refunds and Wallet Withdrawals are grouped back-to-back here on
    // purpose — both are "money OUT to the customer" flows (the
    // former reactive/admin-initiated after a cancelled order, the
    // latter customer-initiated out of their wallet balance), and sit
    // right after Pending UPI Payments (money IN) so this whole block
    // reads top-to-bottom as the customer-side money lifecycle before
    // the restaurant/rider payout block below.
    [
        'key' => 'refunds', 'href' => 'refunds.php', 'label' => 'Refunds',
        'perm' => 'refunds_view', 'group' => 'finance',
        'icon' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 5v5h5"/>',
    ],
    [
        'key' => 'wallet_withdrawals', 'href' => 'wallet-withdrawals.php', 'label' => 'Wallet Withdrawals',
        'perm' => 'wallet_withdrawals_view', 'group' => 'finance',
        'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M16 12h.01"/>',
    ],
    [
        'key' => 'commission_rules', 'href' => 'commission-rules.php', 'label' => 'Commission Rules',
        'perm' => 'payouts_view', 'group' => 'finance',
        'icon' => '<circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/>',
    ],
    [
        'key' => 'settlements', 'href' => 'settlements.php', 'label' => 'Settlements',
        'perm' => 'payouts_view', 'group' => 'finance',
        'icon' => '<path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    ],
    [
        'key' => 'rider_settlements', 'href' => 'rider-settlements.php', 'label' => 'Rider Settlements',
        'perm' => 'payouts_view', 'group' => 'finance',
        'icon' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 0 1 1 1v5l3.5 4"/><path d="M9 17.5H5.5L8 10h6"/>',
    ],
    [
        'key' => 'platform_ledger', 'href' => 'platform-ledger.php', 'label' => 'Platform Cash Flow',
        'perm' => 'payouts_view', 'group' => 'finance',
        'icon' => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M2 10h20"/><circle cx="7" cy="15" r="1"/>',
    ],
    [
        'key' => 'reconciliation', 'href' => 'reconciliation.php', 'label' => 'Reconciliation',
        'perm' => 'reconciliation_view', 'group' => 'finance',
        'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    ],
    [
        'key' => 'roles', 'href' => 'roles.php', 'label' => 'Roles & Admins',
        'perm' => 'roles_manage', 'group' => 'settings',
        'icon' => '<path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/>',
    ],
    // App Settings is one shared file (admin/app-settings.php) with a
    // tab per app, but rendered here as three separate sidebar entries
    // (person-requested this session) so Restaurant/Customer/Rider
    // each read as their own category rather than one page you have to
    // already be on to discover the other two apps' settings.
    [
        'key' => 'app_settings_customer', 'href' => 'app-settings.php?app=customer', 'label' => 'Customer App',
        'perm' => 'app_version_manage', 'group' => 'settings',
        'icon' => '<path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="8" r="5"/>',
    ],
    [
        'key' => 'app_settings_restaurant', 'href' => 'app-settings.php?app=restaurant', 'label' => 'Restaurant App',
        'perm' => 'app_version_manage', 'group' => 'settings',
        'icon' => '<path d="M4 3v18M4 3c0 3 3 3 3 6s-3 3-3 6M20 3v18M20 8h-4a2 2 0 0 0 0 4h4"/>',
    ],
    [
        'key' => 'app_settings_rider', 'href' => 'app-settings.php?app=rider', 'label' => 'Rider App',
        'perm' => 'app_version_manage', 'group' => 'settings',
        'icon' => '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 0 1 1 1v5l3.5 4"/><path d="M9 17.5H5.5L8 10h6"/>',
    ],
    [
        'key' => 'fcm_settings', 'href' => 'fcm-settings.php', 'label' => 'FCM Settings',
        'perm' => 'settings_manage', 'group' => 'settings',
        'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    ],
    [
        'key' => 'broadcast', 'href' => 'broadcast.php', 'label' => 'Push Notifications',
        'perm' => 'notifications_send', 'group' => 'catalog',
        'icon' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
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
<link rel="stylesheet" href="assets/admin.css?v=<?= @filemtime(__DIR__ . '/assets/admin.css') ?: '1' ?>">
<script>
    /* Set theme before first paint to avoid a light/dark flash. */
    (function(){var t=localStorage.getItem('anydrop_admin_theme');
        if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
        document.documentElement.setAttribute('data-theme', t);
        /* Rail is a desktop-only preference — only apply it pre-paint above
           640px, so a rail state saved from a desktop session never causes
           a mobile drawer to render icon-only (see admin.js's matching
           isMobile() guard, and admin.css's mobile media query, which is
           now also a defensive second layer against this). */
        var isDesktopWidth = !window.matchMedia || window.matchMedia('(min-width: 641px)').matches;
        if(isDesktopWidth && localStorage.getItem('anydrop_admin_sidebar_expanded')==='0'){document.documentElement.classList.add('rail-pref');}
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
        <?php
        // Standalone items (group === null, currently just Dashboard)
        // render directly, same as before grouping existed.
        foreach ($navItems as $item):
            if ($item['group'] !== null) continue;
            if (!admin_has_permission($admin['id'], $item['perm'])) continue;
        ?>
            <a class="nav-item icon-<?= $item['key'] ?> <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= $item['href'] ?>">
                <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg></span>
                <span class="label"><?= admin_escape($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <?php foreach ($navGroups as $group):
            $groupItems = array_filter($navItems, fn($i) => $i['group'] === $group['key'] && admin_has_permission($admin['id'], $i['perm']));
            if (!$groupItems) continue; // whole group hidden if admin has no permission for anything in it
            $groupHasActive = in_array($activeNav, array_column($groupItems, 'key'), true);
        ?>
            <div class="nav-group <?= $groupHasActive ? 'open' : '' ?>" data-nav-group="<?= $group['key'] ?>">
                <button type="button" class="nav-group-toggle" aria-expanded="<?= $groupHasActive ? 'true' : 'false' ?>">
                    <span class="nav-group-toggle-left">
                        <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $group['icon'] ?></svg></span>
                        <span class="label"><?= admin_escape($group['label']) ?></span>
                    </span>
                    <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="nav-group-items">
                    <div class="nav-group-items-inner">
                    <?php foreach ($groupItems as $item): ?>
                        <a class="nav-item icon-<?= $item['key'] ?> <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= $item['href'] ?>">
                            <span class="icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg></span>
                            <span class="label"><?= admin_escape($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
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
