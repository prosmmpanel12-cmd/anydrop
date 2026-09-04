# One small patch needed in `admin/_layout_head.php`

This file is shared by every admin page, so instead of overwriting it,
apply these two small edits to your existing copy:

## 1. Add `'email_providers'` to the `$activeNav` docblock (line ~19)

Find:
```
 *   $activeNav   — one of 'dashboard' | ... | 'payment_gateways' | 'payment_pending' | ...
```
Add `'email_providers' |` anywhere in that list (cosmetic/documentation only).

## 2. Add the nav item, right after the `payment_gateways` entry (~line 155)

Find:
```php
    [
        'key' => 'payment_gateways', 'href' => 'payment-gateways.php', 'label' => 'Payment Gateways',
        'perm' => 'payment_providers_manage', 'group' => 'finance',
        'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
    ],
```

Add immediately after it:
```php
    [
        'key' => 'email_providers', 'href' => 'email-providers.php', 'label' => 'Email Providers',
        'perm' => 'email_providers_manage', 'group' => 'finance',
        'icon' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
    ],
```

That's it — `email_providers_manage` is already a seeded RBAC permission
(migration 29), so any role that already has it will see the link
immediately; no other file needs to change for the nav to appear.
